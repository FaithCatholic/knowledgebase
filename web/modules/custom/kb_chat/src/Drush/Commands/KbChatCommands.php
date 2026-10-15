<?php

namespace Drupal\kb_chat\Drush\Commands;

use Drupal\kb_chat\AnthropicCostReport;
use Drupal\kb_chat\CostTracker;
use Drupal\kb_chat\Drive\DriveSync;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for KB Chat.
 */
final class KbChatCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly CostTracker $costs,
    private readonly AnthropicCostReport $anthropicCosts,
    private readonly DriveSync $driveSync,
  ) {
    parent::__construct();
  }

  /**
   * Refreshes Anthropic's billed figure and prints this month's AI costs.
   */
  #[CLI\Command(name: 'kb-chat:costs', aliases: ['kb-costs'])]
  #[CLI\Usage(name: 'drush kb-costs', description: 'Show month-to-date estimates and billed totals.')]
  public function costs(): void {
    if ($this->anthropicCosts->isConfigured()) {
      $this->anthropicCosts->refresh();
    }
    else {
      $this->logger()->warning(dt('ANTHROPIC_ADMIN_API_KEY is not set, so there is no billed Claude figure.'));
    }
    $estimates = $this->costs->monthEstimates();
    foreach (['anthropic' => 'anthropic', 'gemini' => 'google'] as $provider => $billing) {
      $billed = $this->costs->billed($billing);
      $this->output()->writeln(sprintf(
        '%-10s estimate $%.2f (%d calls, %d unpriced)   billed %s',
        $provider,
        $estimates[$provider]['cost'] ?? 0,
        $estimates[$provider]['calls'] ?? 0,
        $estimates[$provider]['unpriced'] ?? 0,
        isset($billed['amount']) ? sprintf('$%.2f as of %s', $billed['amount'], date('Y-m-d H:i', $billed['as_of'])) : ($billed['error'] ?? 'not reported yet'),
      ));
    }
  }

  /**
   * Syncs Google Drive files into the kb chat's documents.
   */
  #[CLI\Command(name: 'kb-chat:drive-sync', aliases: ['kb-drive'])]
  #[CLI\Option(name: 'time-limit', description: 'Seconds to keep reading files. The rest wait for the next run.')]
  #[CLI\Option(name: 'drives', description: 'Only show which shared drives each account can see.')]
  #[CLI\Usage(name: 'drush kb-drive --time-limit=1500', description: 'What Upsun cron runs every hour.')]
  #[CLI\Usage(name: 'drush kb-drive --drives', description: 'List the shared drives each account can see.')]
  public function driveSync(array $options = ['time-limit' => 270, 'drives' => FALSE]): void {
    if ($options['drives']) {
      foreach ($this->driveSync->drives() as $tier => $drives) {
        $this->output()->writeln(sprintf('%s account sees %d shared drives:', ucfirst($tier), count($drives)));
        foreach ($drives as $name => $excluded) {
          $this->output()->writeln('  ' . $name . ($excluded ? '  (excluded)' : ''));
        }
      }
      return;
    }
    $counts = $this->driveSync->sync((int) $options['time-limit'], fn(string $message) => $this->output()->writeln($message));
    if (!empty($counts['running'])) {
      $this->logger()->notice(dt('Another Drive sync is already running.'));
      return;
    }
    $this->logger()->success(dt('Drive: @listed files. @created new, @updated changed, @retiered relabeled, @deleted removed, @skipped too large, @failed failed, @remaining left for the next run.', [
      '@listed' => $counts['listed'],
      '@created' => $counts['created'],
      '@updated' => $counts['updated'],
      '@retiered' => $counts['retiered'],
      '@deleted' => $counts['deleted'],
      '@skipped' => $counts['skipped'],
      '@failed' => $counts['failed'],
      '@remaining' => $counts['remaining'],
    ]));
  }

}
