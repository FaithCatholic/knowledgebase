<?php

namespace Drupal\upsun_dashboard\Controller;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\upsun_dashboard\DashboardCollector;
use Drupal\upsun_dashboard\UpsunClient;

/**
 * Shows the Upsun hosting dashboard and refreshes its data.
 */
class DashboardController extends ControllerBase {

  /**
   * Usage percentages at or above these get a warning or danger badge.
   */
  const WARNING_PERCENT = 80;

  const DANGER_PERCENT = 90;

  /**
   * Display names for Upsun service types.
   */
  const SERVICE_LABELS = [
    'mariadb' => 'MariaDB',
    'mysql' => 'MariaDB',
    'oracle-mysql' => 'MySQL',
    'postgresql' => 'PostgreSQL',
    'redis' => 'Redis',
    'redis-persistent' => 'Redis',
    'valkey' => 'Valkey',
    'memcached' => 'Memcached',
    'solr' => 'Solr',
    'opensearch' => 'OpenSearch',
    'elasticsearch' => 'Elasticsearch',
  ];

  public function __construct(
    protected DashboardCollector $collector,
    protected UpsunClient $client,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Renders the dashboard from the stored snapshot.
   */
  public function page(): array {
    $snapshot = $this->collector->getSnapshot();
    $costs = $snapshot['billing']['projects'];
    $rows = array_map(fn($project) => $this->buildRow($project, $costs[$project['id']] ?? []), $snapshot['projects']);
    $billing = $this->buildBilling($snapshot['billing']['profiles']);

    return [
      '#theme' => 'upsun_dashboard',
      '#rows' => $rows,
      '#summary' => $this->buildSummary($rows) + ['cost' => $billing['total']],
      '#billing' => $billing['profiles'],
      '#updated' => $snapshot['updated'] ? $this->dateFormatter->format($snapshot['updated'], 'custom', 'M j, Y g:i a') : NULL,
      '#configured' => $this->client->isConfigured(),
      '#refresh_link' => [
        '#type' => 'link',
        '#title' => $this->t('Refresh now'),
        '#url' => Url::fromRoute('upsun_dashboard.refresh'),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-sm']],
      ],
      '#attached' => ['library' => ['upsun_dashboard/dashboard']],
      '#cache' => [
        'tags' => ['upsun_dashboard'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Refreshes every project in a batch, one project per request.
   */
  public function refresh() {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Refreshing Upsun projects'))
      ->setInitMessage($this->t('Listing projects…'))
      ->addOperation([static::class, 'batchCollect'])
      ->setFinishCallback([static::class, 'batchFinished']);
    batch_set($batch->toArray());
    return batch_process(Url::fromRoute('upsun_dashboard.page'));
  }

  /**
   * Batch operation: lists the projects, then collects one per call.
   */
  public static function batchCollect(array &$context): void {
    $collector = \Drupal::service(DashboardCollector::class);
    if (!isset($context['sandbox']['queue'])) {
      $context['sandbox']['organizations'] = $collector->listOrganizations();
      $context['sandbox']['queue'] = array_values($collector->listProjects($context['sandbox']['organizations']));
      $context['sandbox']['total'] = count($context['sandbox']['queue']);
      $context['results']['projects'] = [];
    }

    if ($stub = array_shift($context['sandbox']['queue'])) {
      $previous = $collector->getSnapshot()['projects'][$stub['id']] ?? [];
      $context['results']['projects'][$stub['id']] = $collector->collectProject($stub, $previous);
      $context['message'] = $context['sandbox']['queue']
        ? t('Collected @project', ['@project' => $stub['title']])
        : t('Collecting billing…');
    }
    else {
      // Billing is one extra step after the last project.
      $context['results']['billing'] = $collector->collectBilling($context['sandbox']['organizations']);
    }

    // One step per project, plus one for billing.
    $done = $context['sandbox']['total'] - count($context['sandbox']['queue']) + (isset($context['results']['billing']) ? 1 : 0);
    $context['finished'] = $done / ($context['sandbox']['total'] + 1);
  }

  /**
   * Batch finish callback: stores the new snapshot.
   */
  public static function batchFinished(bool $success, array $results): void {
    if ($success && isset($results['projects'], $results['billing'])) {
      \Drupal::service(DashboardCollector::class)->saveSnapshot($results['projects'], $results['billing']);
      \Drupal::messenger()->addStatus(t('Refreshed @count projects.', ['@count' => count($results['projects'])]));
    }
    else {
      \Drupal::messenger()->addError(t('The refresh did not finish. The previous data is still shown.'));
    }
  }

  /**
   * Turns a stored project into template variables.
   */
  protected function buildRow(array $project, array $cost): array {
    $drupal = $project['packages']['drupal/core'] ?? NULL;
    $php = NULL;
    foreach ($project['apps'] ?? [] as $app) {
      if ($app['runtime'] === 'php') {
        $php = $app['version'];
        break;
      }
    }

    $services = [];
    foreach ($project['services'] ?? [] as $service) {
      $services[] = trim((self::SERVICE_LABELS[$service['runtime']] ?? $service['runtime']) . ' ' . $service['version']);
    }

    $containers = [];
    foreach ($project['usage'] ?? [] as $name => $usage) {
      $containers[] = [
        'name' => $name,
        'cpu' => $this->percent($usage['cpu']),
        'memory' => $this->percent($usage['memory']),
        'disk' => $this->percent($usage['disk']) + [
          'detail' => $usage['disk'] ? $this->gigabytes($usage['disk']['used']) . ' / ' . $this->gigabytes($usage['disk']['limit']) . ' GB' : NULL,
        ],
      ];
    }

    return [
      'title' => $project['title'],
      'url' => $project['url'] ?? NULL,
      'console_url' => $project['console_url'] ?? NULL,
      'org' => $project['org'] ?? '',
      'plan' => $project['plan'] ?? '',
      'region' => $project['region'] ?? '',
      'environment' => $project['environment'] ?? NULL,
      'environment_status' => $project['environment_status'] ?? NULL,
      'environment_level' => ($project['environment_status'] ?? '') === 'active' ? 'success' : 'warning',
      'commit_date' => !empty($project['commit_date']) ? $this->dateFormatter->format(strtotime($project['commit_date']), 'custom', 'Y-m-d') : NULL,
      'drupal' => $drupal,
      'drupal_level' => $this->versionLevel($drupal, 11, 10),
      'drush' => $project['packages']['drush/drush'] ?? NULL,
      'php' => $php,
      'php_level' => $this->versionLevel($php, 8.3, 8.2),
      'services' => $services,
      'containers' => $containers,
      'cost' => isset($cost['current']) ? $this->money($cost['current']) : NULL,
      'cost_previous' => isset($cost['previous']) ? $this->money($cost['previous']) : NULL,
      'error' => $project['error'] ?? NULL,
      'stale' => !empty($project['stale']),
      'warnings' => $project['warnings'] ?? [],
    ];
  }

  /**
   * Counts the things worth acting on.
   */
  protected function buildSummary(array $rows): array {
    $summary = [
      'projects' => count($rows),
      'drupal_current' => 0,
      'drupal_behind' => 0,
      'php_behind' => 0,
      'resources_high' => 0,
      'errors' => 0,
    ];
    foreach ($rows as $row) {
      if ($row['drupal_level'] === 'success') {
        $summary['drupal_current']++;
      }
      elseif ($row['drupal']) {
        $summary['drupal_behind']++;
      }
      if ($row['php'] && $row['php_level'] !== 'success') {
        $summary['php_behind']++;
      }
      foreach ($row['containers'] as $container) {
        if ($container['disk']['level'] || $container['memory']['level']) {
          $summary['resources_high']++;
          break;
        }
      }
      if ($row['error'] || $row['warnings']) {
        $summary['errors']++;
      }
    }
    return $summary;
  }

  /**
   * Builds the per-billing-profile totals shown under the table.
   *
   * @return array
   *   - profiles: One entry per billing profile.
   *   - total: This month's total across all profiles, formatted.
   */
  protected function buildBilling(array $profiles): array {
    $rows = [];
    $total = 0;
    foreach ($profiles as $profile) {
      $current = $profile['current'];
      $previous = $profile['previous'];
      $total += $current['total'] ?? 0;
      $rows[] = [
        'orgs' => implode(', ', $profile['orgs']),
        'error' => $profile['error'],
        'period' => $current ? $this->dateFormatter->format(strtotime($current['start']), 'custom', 'M Y') : NULL,
        'projects' => $current ? $this->money($current['total'] - $current['unassigned'], $current['currency']) : NULL,
        'unassigned' => $current ? $this->money($current['unassigned'], $current['currency']) : NULL,
        'total' => $current ? $this->money($current['total'], $current['currency']) : NULL,
        'previous' => $previous ? $this->money($previous['total'], $previous['currency']) : NULL,
      ];
    }
    return [
      'profiles' => $rows,
      'total' => $profiles ? $this->money($total) : NULL,
    ];
  }

  /**
   * Formats an amount in cents.
   */
  protected function money(int $cents, string $currency = 'USD'): string {
    $amount = number_format($cents / 100, 2);
    return $currency === 'USD' ? '$' . $amount : $amount . ' ' . $currency;
  }

  /**
   * Returns a used/limit pair as a percentage with a warning level.
   */
  protected function percent(?array $pair): array {
    if (empty($pair['limit']) || !isset($pair['used'])) {
      return ['percent' => NULL, 'level' => NULL];
    }
    $percent = round($pair['used'] / $pair['limit'] * 100);
    $level = match (TRUE) {
      $percent >= self::DANGER_PERCENT => 'danger',
      $percent >= self::WARNING_PERCENT => 'warning',
      default => NULL,
    };
    return ['percent' => $percent, 'level' => $level];
  }

  /**
   * Rates a version against the current and still-supported minor versions.
   */
  protected function versionLevel(?string $version, float $current, float $supported): ?string {
    if (!$version) {
      return NULL;
    }
    $minor = (float) implode('.', array_slice(explode('.', $version), 0, 2));
    return match (TRUE) {
      $minor >= $current => 'success',
      $minor >= $supported => 'warning',
      default => 'danger',
    };
  }

  /**
   * Formats bytes as gigabytes with one decimal place.
   */
  protected function gigabytes(float $bytes): string {
    return number_format($bytes / 1024 ** 3, 1);
  }

}
