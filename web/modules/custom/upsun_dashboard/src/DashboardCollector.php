<?php

namespace Drupal\upsun_dashboard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collects a snapshot of every Upsun project's production environment.
 *
 * The snapshot is kept in State so the dashboard page never calls the API.
 */
class DashboardCollector {

  const STATE_KEY = 'upsun_dashboard.snapshot';

  /**
   * Composer packages whose versions the dashboard shows.
   */
  const PACKAGES = ['drupal/core', 'drush/drush'];

  public function __construct(
    protected UpsunClient $client,
    protected StateInterface $state,
    protected TimeInterface $time,
    #[Autowire(service: 'logger.channel.upsun_dashboard')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the stored snapshot: ['updated' => int, 'projects' => array].
   */
  public function getSnapshot(): array {
    return $this->state->get(self::STATE_KEY, []) + ['updated' => 0, 'projects' => []];
  }

  /**
   * Stores a new snapshot and clears the dashboard's render cache.
   */
  public function saveSnapshot(array $projects): void {
    uasort($projects, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    $this->state->set(self::STATE_KEY, [
      'updated' => $this->time->getCurrentTime(),
      'projects' => $projects,
    ]);
    Cache::invalidateTags(['upsun_dashboard']);
  }

  /**
   * Whether the snapshot is older than the configured refresh interval.
   */
  public function isStale(int $interval): bool {
    return $this->time->getCurrentTime() - $this->getSnapshot()['updated'] >= $interval;
  }

  /**
   * Collects every project and stores the result.
   */
  public function refreshAll(): array {
    $previous = $this->getSnapshot()['projects'];
    $projects = [];
    foreach ($this->listProjects() as $id => $stub) {
      $projects[$id] = $this->collectProject($stub, $previous[$id] ?? []);
    }
    $this->saveSnapshot($projects);
    return $projects;
  }

  /**
   * Lists the projects in every organization the token's user belongs to.
   *
   * @return array
   *   Basic project details keyed by project ID.
   */
  public function listProjects(): array {
    $me = $this->client->get('/users/me');
    $projects = [];
    foreach ($this->client->getAll('/users/' . $me['id'] . '/organizations') as $org) {
      foreach ($this->client->getAll('/organizations/' . $org['id'] . '/projects') as $project) {
        $projects[$project['id']] = [
          'id' => $project['id'],
          'title' => $project['title'] ?: $project['id'],
          'org' => $org['label'] ?? $org['name'],
          'org_type' => $org['type'] ?? '',
          'region' => $project['region'] ?? '',
          'plan' => $project['plan'] ?? '',
          'status' => $project['status'] ?? '',
          'console_url' => $project['project_ui'] ?? '',
          'default_branch' => $project['default_branch'] ?? 'main',
        ];
      }
    }
    return $projects;
  }

  /**
   * Collects one project, never throwing.
   *
   * @param array $stub
   *   The project details from listProjects().
   * @param array $previous
   *   The project's row from the last snapshot, used to skip re-reading
   *   composer.lock when the commit hasn't changed, and as a fallback when
   *   the API call fails.
   */
  public function collectProject(array $stub, array $previous = []): array {
    try {
      return $this->doCollectProject($stub, $previous);
    }
    catch (\Throwable $e) {
      $message = $this->errorMessage($e);
      $this->logger->warning('Could not collect @project: @message', [
        '@project' => $stub['title'],
        '@message' => $message,
      ]);
      // Keep showing the last good data, flagged as out of date.
      return ['error' => $message, 'stale' => (bool) $previous] + $stub + $previous;
    }
  }

  /**
   * Collects one project.
   */
  protected function doCollectProject(array $stub, array $previous): array {
    $row = $stub + [
      'error' => NULL,
      'stale' => FALSE,
      'warnings' => [],
      'collected' => $this->time->getCurrentTime(),
    ];
    $base = '/projects/' . $stub['id'];

    $environments = $this->client->get($base . '/environments');
    $environment = NULL;
    foreach ($environments as $candidate) {
      if (($candidate['type'] ?? '') === 'production') {
        $environment = $candidate;
        break;
      }
      if (($candidate['id'] ?? '') === $stub['default_branch']) {
        $environment = $candidate;
      }
    }
    if (!$environment) {
      $row['error'] = 'No production environment.';
      return $row;
    }

    $env_path = $base . '/environments/' . rawurlencode($environment['id']);
    $row['environment'] = $environment['name'];
    $row['environment_status'] = $environment['status'] ?? '';
    $row['commit'] = $environment['head_commit'] ?? '';

    // Runtimes and services from the current deployment.
    $deployment = $this->client->get($env_path . '/deployments/current');
    $row['apps'] = [];
    foreach ($deployment['webapps'] ?? [] as $name => $app) {
      $row['apps'][$name] = $this->parseType($app['type'] ?? '');
    }
    $row['services'] = [];
    foreach ($deployment['services'] ?? [] as $name => $service) {
      $row['services'][$name] = $this->parseType($service['type'] ?? '');
    }
    $row['url'] = $this->primaryUrl($deployment['routes'] ?? []);

    // Package versions from composer.lock. Only re-read on a new commit.
    if ($row['commit'] && ($previous['commit'] ?? NULL) === $row['commit'] && array_key_exists('packages', $previous)) {
      $row['packages'] = $previous['packages'];
      $row['commit_date'] = $previous['commit_date'] ?? NULL;
    }
    elseif ($row['commit']) {
      try {
        $commit = $this->client->get($base . '/git/commits/' . $row['commit']);
        $row['commit_date'] = $commit['committer']['date'] ?? NULL;
        $row['packages'] = $this->readPackages($base, $commit['tree']);
      }
      catch (\Throwable $e) {
        $row['warnings'][] = 'composer.lock: ' . $this->errorMessage($e);
      }
    }

    // Recent CPU, memory and disk usage for each container.
    try {
      $row['usage'] = $this->readUsage($env_path, array_keys($row['apps'] + $row['services']));
    }
    catch (\Throwable $e) {
      $row['warnings'][] = 'Metrics: ' . $this->errorMessage($e);
    }

    return $row;
  }

  /**
   * Reads package versions from composer.lock at the root of a git tree.
   *
   * @return array|null
   *   Versions keyed by package name, or NULL if there is no composer.lock.
   */
  protected function readPackages(string $base, string $tree_sha): ?array {
    $tree = $this->client->get($base . '/git/trees/' . $tree_sha);
    $blob_sha = NULL;
    foreach ($tree['tree'] ?? [] as $entry) {
      if ($entry['path'] === 'composer.lock' && $entry['type'] === 'blob') {
        $blob_sha = $entry['sha'];
      }
    }
    if (!$blob_sha) {
      return NULL;
    }

    $blob = $this->client->get($base . '/git/blobs/' . $blob_sha);
    $content = ($blob['encoding'] ?? '') === 'base64' ? base64_decode($blob['content']) : $blob['content'];
    $lock = json_decode($content, TRUE) ?? [];

    $versions = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
      if (in_array($package['name'], self::PACKAGES, TRUE)) {
        $versions[$package['name']] = ltrim($package['version'], 'v');
      }
    }
    return $versions;
  }

  /**
   * Reads the last hour's average usage and the limits for each container.
   *
   * @return array
   *   Keyed by container name, each with cpu, memory and disk entries of
   *   ['used' => float, 'limit' => float]. CPU is in cores, the rest in bytes.
   */
  protected function readUsage(string $env_path, array $containers): array {
    $now = $this->time->getCurrentTime();
    $metrics = $this->client->get($env_path . '/observability/resources/overview', [
      'from' => $now - 3600,
      'to' => $now,
      'grain' => 3600,
      'types' => ['cpu', 'memory', 'disk'],
      'aggs' => ['avg'],
    ]);

    // Use the most recent bucket that has data.
    $services = [];
    foreach ($metrics['data'] ?? [] as $bucket) {
      if (!empty($bucket['services'])) {
        $services = $bucket['services'];
      }
    }

    $usage = [];
    foreach ($containers as $name) {
      if (!isset($services[$name])) {
        continue;
      }
      $metric = $services[$name];
      $disk = ['used' => 0, 'limit' => 0];
      foreach ($metric['mountpoints'] ?? [] as $mount => $values) {
        // Skip the root filesystem and /tmp; only persistent disk matters.
        if ($mount === '' || $mount === '/tmp') {
          continue;
        }
        $disk['used'] += $values['disk_used']['avg'] ?? 0;
        $disk['limit'] += $values['disk_limit']['max'] ?? 0;
      }
      $usage[$name] = [
        'cpu' => [
          'used' => $metric['cpu_used']['avg'] ?? NULL,
          'limit' => $metric['cpu_limit']['max'] ?? NULL,
        ],
        'memory' => [
          'used' => $metric['memory_used']['avg'] ?? NULL,
          'limit' => $metric['memory_limit']['max'] ?? NULL,
        ],
        'disk' => $disk['limit'] ? $disk : NULL,
      ];
    }
    return $usage;
  }

  /**
   * Splits a type such as "php:8.4:790" into its runtime and version.
   */
  protected function parseType(string $type): array {
    $parts = explode(':', $type);
    return [
      'runtime' => $parts[0],
      'version' => $parts[1] ?? '',
    ];
  }

  /**
   * Returns the primary route URL, preferring a custom domain.
   */
  protected function primaryUrl(array $routes): ?string {
    $primary = [];
    foreach ($routes as $url => $route) {
      if (!empty($route['primary']) && ($route['type'] ?? '') === 'upstream') {
        $primary[] = $url;
      }
    }
    foreach ($primary as $url) {
      if (!preg_match('/\.(platformsh\.site|upsun\.app)\//', $url)) {
        return $url;
      }
    }
    return $primary[0] ?? NULL;
  }

  /**
   * Turns an exception into a short message for the dashboard.
   */
  protected function errorMessage(\Throwable $e): string {
    if ($e instanceof RequestException && $e->hasResponse()) {
      $response = $e->getResponse();
      return 'HTTP ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase();
    }
    return $e->getMessage();
  }

}
