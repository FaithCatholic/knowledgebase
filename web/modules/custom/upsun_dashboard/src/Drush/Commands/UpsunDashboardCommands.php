<?php

namespace Drupal\upsun_dashboard\Drush\Commands;

use Drupal\upsun_dashboard\DashboardCollector;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Upsun dashboard.
 */
final class UpsunDashboardCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly DashboardCollector $collector,
  ) {
    parent::__construct();
  }

  /**
   * Refreshes the Upsun dashboard data now.
   */
  #[CLI\Command(name: 'upsun-dashboard:refresh', aliases: ['upsun-refresh'])]
  #[CLI\Usage(name: 'drush upsun-refresh', description: 'Collect every Upsun project and update the dashboard.')]
  public function refresh(): void {
    $projects = $this->collector->refreshAll();
    $failed = array_filter($projects, fn($project) => !empty($project['error']));
    foreach ($failed as $project) {
      $this->logger()->warning(dt('@title: @error', ['@title' => $project['title'], '@error' => $project['error']]));
    }
    $this->logger()->success(dt('Refreshed @count projects (@failed with errors).', [
      '@count' => count($projects),
      '@failed' => count($failed),
    ]));
  }

}
