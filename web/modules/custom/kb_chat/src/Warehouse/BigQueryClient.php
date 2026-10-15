<?php

namespace Drupal\kb_chat\Warehouse;

use Drupal\kb_chat\Google\ServiceAccountToken;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Runs read-only, checked queries against the BigQuery data warehouse.
 *
 * Every query is dry-run first. It must be a single SELECT that names no
 * dataset other than the allowed ones, and it must not scan more than the
 * byte limit; BigQuery also enforces that limit when it runs (it bills nothing
 * and fails if exceeded). Results are capped at a number of rows.
 */
class BigQueryClient {

  const API = 'https://bigquery.googleapis.com/bigquery/v2';

  const SCOPE = 'https://www.googleapis.com/auth/bigquery';

  /**
   * Access tokens for the service account.
   */
  protected ServiceAccountToken $auth;

  /**
   * The datasets this account can see; see visibleDatasets().
   */
  protected ?array $visibleDatasets = NULL;

  /**
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param string $keyJson
   *   The service account's JSON key.
   * @param string $project
   *   The warehouse project, which also runs and pays for the queries.
   * @param string $location
   *   The datasets' location, such as US.
   * @param string[] $datasets
   *   The datasets queries may read.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    string $keyJson,
    protected string $project,
    protected string $location,
    protected array $datasets,
  ) {
    $this->auth = new ServiceAccountToken($httpClient, $keyJson, self::SCOPE);
  }

  /**
   * Describes the allowed datasets' views: names, descriptions and columns.
   *
   * @return string
   *   Plain text for the model.
   */
  public function catalog(): string {
    $output = '';
    foreach ($this->datasets as $dataset) {
      $ds = "`{$this->project}.{$dataset}`";
      $tables = $this->select("SELECT table_name, option_value AS description FROM $ds.INFORMATION_SCHEMA.TABLE_OPTIONS WHERE option_name = 'description' ORDER BY table_name", 500);
      $columns = $this->select("SELECT c.table_name, c.column_name, c.data_type, p.description
        FROM $ds.INFORMATION_SCHEMA.COLUMNS c
        LEFT JOIN $ds.INFORMATION_SCHEMA.COLUMN_FIELD_PATHS p ON p.table_name = c.table_name AND p.field_path = c.column_name
        ORDER BY c.table_name, c.ordinal_position", 5000);
      $by_table = [];
      foreach ($columns['rows'] as [$table, $column, $type, $description]) {
        $by_table[$table][] = "  - $column ($type)" . ($description ? ": $description" : '');
      }
      foreach ($tables['rows'] as [$table, $description]) {
        // Option values are returned as SQL string literals.
        $description = trim((string) $description, '"');
        $output .= "View `{$this->project}.$dataset.$table`: " . stripcslashes($description) . "\n";
        $output .= implode("\n", $by_table[$table] ?? []) . "\n\n";
      }
    }
    return $output;
  }

  /**
   * Checks a query without running it.
   *
   * @return int
   *   The bytes it would scan.
   *
   * @throws \InvalidArgumentException
   *   If the query isn't a SELECT on the allowed datasets or scans too much.
   */
  public function check(string $sql, int $max_bytes): int {
    $job = $this->post("/projects/{$this->project}/jobs", [
      'configuration' => [
        'dryRun' => TRUE,
        'query' => ['query' => $sql, 'useLegacySql' => FALSE],
      ],
      'jobReference' => ['projectId' => $this->project, 'location' => $this->location],
    ]);
    $stats = $job['statistics']['query'] ?? [];
    if (($stats['statementType'] ?? '') !== 'SELECT') {
      throw new \InvalidArgumentException('Only a single SELECT query is allowed.');
    }
    // BigQuery reports the base tables under views as referenced, not the
    // views, so it can't show whether the query used the curated views. The
    // account can read the views' source datasets too (the views need it), so
    // instead reject any query that names another dataset it can see.
    foreach ($this->visibleDatasets() as $dataset) {
      if (!in_array($dataset, $this->datasets, TRUE) && preg_match('/(?<![\w-])' . preg_quote($dataset, '/') . '(?![\w-])/i', $sql)) {
        throw new \InvalidArgumentException(sprintf('Query only the views in %s; %s is not available.', implode(' and ', $this->datasets), $dataset));
      }
    }
    if (preg_match('/region-[a-z0-9-]+/i', $sql)) {
      throw new \InvalidArgumentException('Region-level INFORMATION_SCHEMA views are not available.');
    }
    $bytes = (int) ($stats['totalBytesProcessed'] ?? 0);
    if ($bytes > $max_bytes) {
      throw new \InvalidArgumentException(sprintf('The query would scan %.1f GB; the limit is %.1f GB. Filter or aggregate more.', $bytes / 1e9, $max_bytes / 1e9));
    }
    return $bytes;
  }

  /**
   * Every dataset in the project this account can see, fetched once.
   */
  protected function visibleDatasets(): array {
    if (!isset($this->visibleDatasets)) {
      $this->visibleDatasets = [];
      $query = ['maxResults' => 1000];
      do {
        $data = $this->get("/projects/{$this->project}/datasets", $query);
        foreach ($data['datasets'] ?? [] as $dataset) {
          $this->visibleDatasets[] = $dataset['datasetReference']['datasetId'];
        }
        $query['pageToken'] = $data['nextPageToken'] ?? NULL;
      } while ($query['pageToken']);
    }
    return $this->visibleDatasets;
  }

  /**
   * Checks and runs a query.
   *
   * @return array
   *   ['columns' => string[], 'rows' => array[], 'total' => int, 'bytes' => int].
   */
  public function query(string $sql, int $max_bytes, int $max_rows): array {
    $this->check($sql, $max_bytes);
    return $this->select($sql, $max_rows, $max_bytes);
  }

  /**
   * Runs a query, waiting up to a minute, and returns up to $max_rows rows.
   */
  protected function select(string $sql, int $max_rows, ?int $max_bytes = NULL): array {
    $body = [
      'query' => $sql,
      'useLegacySql' => FALSE,
      'location' => $this->location,
      'maxResults' => $max_rows,
      'timeoutMs' => 30000,
      'labels' => ['source' => 'kb_chat'],
    ];
    if ($max_bytes) {
      $body['maximumBytesBilled'] = (string) $max_bytes;
    }
    $result = $this->post("/projects/{$this->project}/queries", $body);
    $deadline = time() + 60;
    while (empty($result['jobComplete']) && time() < $deadline) {
      $job_id = $result['jobReference']['jobId'];
      $result = $this->get("/projects/{$this->project}/queries/" . rawurlencode($job_id), [
        'location' => $this->location,
        'maxResults' => $max_rows,
        'timeoutMs' => 10000,
      ]);
    }
    if (empty($result['jobComplete'])) {
      throw new \RuntimeException('The query took longer than a minute.');
    }
    $columns = array_column($result['schema']['fields'] ?? [], 'name');
    $rows = [];
    foreach (array_slice($result['rows'] ?? [], 0, $max_rows) as $row) {
      $rows[] = array_map(fn($cell) => is_array($cell['v'] ?? NULL) ? json_encode($cell['v']) : ($cell['v'] ?? NULL), $row['f']);
    }
    return [
      'columns' => $columns,
      'rows' => $rows,
      'total' => (int) ($result['totalRows'] ?? count($rows)),
      'bytes' => (int) ($result['totalBytesProcessed'] ?? 0),
    ];
  }

  /**
   * POSTs JSON to the API.
   */
  protected function post(string $path, array $body): array {
    return $this->request('POST', $path, ['json' => $body]);
  }

  /**
   * GETs from the API.
   */
  protected function get(string $path, array $query): array {
    return $this->request('GET', $path, ['query' => $query]);
  }

  /**
   * Sends a request and decodes the JSON, turning API errors into exceptions.
   */
  protected function request(string $method, string $path, array $options): array {
    try {
      $response = $this->httpClient->request($method, self::API . $path, $options + [
        'headers' => ['Authorization' => 'Bearer ' . $this->auth->token()],
        'timeout' => 90,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Could not reach BigQuery: ' . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() >= 400 || !is_array($data)) {
      // Query errors (bad SQL, unknown column) are useful to the model as-is.
      throw new \InvalidArgumentException($data['error']['message'] ?? 'BigQuery returned HTTP ' . $response->getStatusCode());
    }
    return $data;
  }

}
