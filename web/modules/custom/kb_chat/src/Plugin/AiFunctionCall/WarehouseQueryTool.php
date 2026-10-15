<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\Warehouse\BigQueryClient;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queries the BigQuery data warehouse for the kb chat.
 *
 * Without SQL it returns the catalog: the curated views, their descriptions
 * and columns. With SQL it runs one checked SELECT (see BigQueryClient) as the
 * staff or management service account, by the asking user's permissions, so
 * BigQuery itself stops staff reading management datasets.
 */
#[FunctionCall(
  id: 'kb_chat:warehouse_query',
  function_name: 'kb_chat_warehouse_query',
  name: 'Query the data warehouse',
  description: 'Answers statistics questions from FAITH\'s BigQuery data warehouse: subscriptions (plans, active and cancelled counts, renewals, revenue, trials), store product sales, parish and diocese statistics, and Mass times listings. First call it with no sql to get the list of views, what each holds and its columns. Then call it with one BigQuery Standard SQL SELECT over those views, using full names like `faith-data-warehouse.kb_chat.subscriptions`. Aggregate in SQL (COUNT, SUM, GROUP BY) rather than fetching raw rows; results are limited to 200 rows. Follow the notes in the view descriptions, such as which rows count as sales.',
  group: 'information_tools',
  context_definitions: [
    'sql' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('SQL'),
      description: new TranslatableMarkup('One BigQuery Standard SQL SELECT over the warehouse views. Leave empty to list the views and columns.'),
      required: FALSE,
    ),
  ],
)]
class WarehouseQueryTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Longest cell value shown to the model.
   */
  const MAX_CELL = 300;

  protected ClientInterface $httpClient;

  protected AccountProxyInterface $currentUser;

  protected CacheBackendInterface $cache;

  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->httpClient = $container->get('http_client');
    $instance->currentUser = $container->get('current_user');
    $instance->cache = $container->get('cache.default');
    $instance->logger = $container->get('logger.channel.kb_chat');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $tier = $this->tier();
    if (!$tier) {
      $this->setOutput('You do not have access to the data warehouse.');
      return;
    }
    $settings = Settings::get('kb_chat') ?? [];
    $warehouse = $settings['warehouse'] ?? [];
    $key = $settings['drive']['accounts'][$tier] ?? '';
    if (empty($warehouse['project']) || !$key) {
      $this->setOutput('The data warehouse is not configured.');
      return;
    }
    $client = new BigQueryClient(
      $this->httpClient,
      $key,
      $warehouse['project'],
      $warehouse['location'] ?? 'US',
      $warehouse['datasets'][$tier] ?? [],
    );

    $sql = trim((string) ($this->getContextValue('sql') ?? ''));
    try {
      $this->setOutput($sql === '' ? $this->catalog($client, $tier) : $this->run($client, $sql, $warehouse));
    }
    catch (\InvalidArgumentException $e) {
      // A rejected or invalid query: tell the model so it can fix it.
      $this->setOutput('The query was not run: ' . $e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger->warning('Warehouse query failed: @message', ['@message' => $e->getMessage()]);
      $this->setOutput('The data warehouse could not be queried: ' . $e->getMessage());
    }
  }

  /**
   * The views this user may query, cached for an hour per tier.
   */
  protected function catalog(BigQueryClient $client, string $tier): string {
    $cid = 'kb_chat:warehouse_catalog:' . $tier;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $catalog = "Data warehouse views you can query (BigQuery Standard SQL; money in US dollars):\n\n" . $client->catalog();
    $this->cache->set($cid, $catalog, time() + 3600);
    return $catalog;
  }

  /**
   * Runs a query and formats the rows as a Markdown table.
   */
  protected function run(BigQueryClient $client, string $sql, array $warehouse): string {
    $max_rows = (int) ($warehouse['max_rows'] ?? 200);
    $result = $client->query($sql, (int) ($warehouse['max_bytes'] ?? 1000000000), $max_rows);
    if (!$result['rows']) {
      return 'The query returned no rows.';
    }
    $escape = fn($value) => $value === NULL ? '' : str_replace(['|', "\n"], ['\\|', ' '], mb_strimwidth((string) $value, 0, self::MAX_CELL, '…'));
    $lines = [
      '| ' . implode(' | ', $result['columns']) . ' |',
      '|' . str_repeat(' --- |', count($result['columns'])),
    ];
    foreach ($result['rows'] as $row) {
      $lines[] = '| ' . implode(' | ', array_map($escape, $row)) . ' |';
    }
    $note = $result['total'] > count($result['rows'])
      ? sprintf("\n\nShowing %d of %d rows. Aggregate or filter in SQL to see everything that matters.", count($result['rows']), $result['total'])
      : '';
    return implode("\n", $lines) . $note;
  }

  /**
   * The asking user's tier: management, staff, or NULL for no access.
   *
   * Uses the same permissions as the Drive document tiers.
   */
  protected function tier(): ?string {
    if ($this->currentUser->hasPermission('view kb chat management documents')) {
      return 'management';
    }
    if ($this->currentUser->hasPermission('view kb chat staff documents')) {
      return 'staff';
    }
    return NULL;
  }

}
