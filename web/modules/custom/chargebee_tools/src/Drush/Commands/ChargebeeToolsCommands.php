<?php

namespace Drupal\chargebee_tools\Drush\Commands;

use Drupal\chargebee_tools\RunProcessor;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Chargebee tools.
 */
final class ChargebeeToolsCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly RunProcessor $processor,
  ) {
    parent::__construct();
  }

  /**
   * Sends queued Chargebee rows until the queue is empty or time runs out.
   */
  #[CLI\Command(name: 'chargebee:process', aliases: ['cb-process'])]
  #[CLI\Option(name: 'time-limit', description: 'Seconds to keep starting new rows.')]
  #[CLI\Usage(name: 'drush chargebee:process --time-limit=270', description: 'What Upsun cron runs every five minutes.')]
  public function process(array $options = ['time-limit' => 270]): void {
    $count = $this->processor->process((int) $options['time-limit']);
    if ($count === NULL) {
      $this->logger()->notice(dt('Another chargebee:process is already running.'));
      return;
    }
    $this->logger()->success(dt('Processed @count rows.', ['@count' => $count]));
  }

}
