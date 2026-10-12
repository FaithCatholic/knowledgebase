<?php

namespace Drupal\upsun_dashboard\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\upsun_dashboard\DashboardCollector;
use Drupal\upsun_dashboard\UpsunClient;

/**
 * Hook implementations for the Upsun dashboard.
 */
class UpsunDashboardHooks {

  public function __construct(
    protected DashboardCollector $collector,
    protected UpsunClient $client,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Refreshes the snapshot once it is older than the refresh interval
   * (default one hour). kb's cron runs every 19 minutes.
   */
  #[Hook('cron')]
  public function cron(): void {
    $interval = (int) (Settings::get('upsun_dashboard')['refresh_interval'] ?? 3600);
    if ($this->client->isConfigured() && $this->collector->isStale($interval)) {
      $this->collector->refreshAll();
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'upsun_dashboard' => [
        'variables' => [
          'rows' => [],
          'summary' => [],
          'updated' => NULL,
          'configured' => FALSE,
          'refresh_link' => NULL,
        ],
      ],
    ];
  }

}
