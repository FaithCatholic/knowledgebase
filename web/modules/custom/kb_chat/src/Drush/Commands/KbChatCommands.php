<?php

namespace Drupal\kb_chat\Drush\Commands;

use Drupal\kb_chat\AnthropicCostReport;
use Drupal\kb_chat\CostTracker;
use Drupal\Core\Site\Settings;
use Drupal\kb_chat\Drive\DriveSync;
use Drupal\kb_chat\Trello\TrelloClient;
use Drupal\kb_chat\Web\WebSync;
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
    private readonly WebSync $webSync,
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
   * Syncs Google Drive files into the kb chat's documents, in small batches.
   *
   * Lists the drives when due (every few hours), queuing new or changed
   * files, then reads a batch from the queue.
   */
  #[CLI\Command(name: 'kb-chat:drive-sync', aliases: ['kb-drive'])]
  #[CLI\Option(name: 'time-limit', description: 'Seconds to keep reading files.')]
  #[CLI\Option(name: 'max-items', description: 'Most files to read this run.')]
  #[CLI\Option(name: 'list', description: 'List the drives and queue changes now, without reading.')]
  #[CLI\Option(name: 'read', description: 'Only read queued files; don\'t list even if due.')]
  #[CLI\Option(name: 'drives', description: 'Only show which shared drives each account can see.')]
  #[CLI\Usage(name: 'drush kb-drive --time-limit=240 --max-items=100', description: 'What Upsun cron runs every 5 minutes.')]
  #[CLI\Usage(name: 'drush kb-drive --list', description: 'Re-list the drives now.')]
  #[CLI\Usage(name: 'drush kb-drive --drives', description: 'List the shared drives each account can see.')]
  public function driveSync(array $options = ['time-limit' => 240, 'max-items' => 100, 'list' => FALSE, 'read' => FALSE, 'drives' => FALSE]): void {
    if ($options['drives']) {
      foreach ($this->driveSync->drives() as $tier => $drives) {
        $this->output()->writeln(sprintf('%s account sees %d shared drives:', ucfirst($tier), count($drives)));
        foreach ($drives as $name => $excluded) {
          $this->output()->writeln('  ' . $name . ($excluded ? '  (excluded)' : ''));
        }
      }
      return;
    }
    $mode = $options['list'] ? 'list' : ($options['read'] ? 'read' : 'auto');
    $counts = $this->driveSync->sync((int) $options['time-limit'], (int) $options['max-items'], $mode, fn(string $message) => $this->output()->writeln($message));
    if (!empty($counts['running'])) {
      $this->logger()->notice(dt('Another Drive sync is already running.'));
      return;
    }
    $this->logger()->success(dt('Drive: @listed listed, @queued queued, @relabeled relabeled, @deleted removed; read @created new, @updated changed, @skipped too large, @failed failed. @remaining waiting in the queue.', [
      '@listed' => $counts['listed'],
      '@queued' => $counts['queued'],
      '@relabeled' => $counts['relabeled'],
      '@deleted' => $counts['deleted'],
      '@created' => $counts['created'],
      '@updated' => $counts['updated'],
      '@skipped' => $counts['skipped'],
      '@failed' => $counts['failed'],
      '@remaining' => $counts['remaining'],
    ]));
  }

  /**
   * Shows which configured Trello boards the chat's Trello account can see.
   */
  #[CLI\Command(name: 'kb-chat:trello-check', aliases: ['kb-trello'])]
  #[CLI\Usage(name: 'drush kb-trello', description: 'Check the Trello key, token and board allowlist.')]
  public function trelloCheck(): void {
    $settings = Settings::get('kb_chat')['trello'] ?? [];
    if (empty($settings['key']) || empty($settings['token'])) {
      $this->logger()->error(dt('Set TRELLO_API_KEY and TRELLO_TOKEN.'));
      return;
    }
    foreach (['staff', 'management'] as $tier) {
      $names = $settings['boards'][$tier] ?? [];
      if (!$names) {
        continue;
      }
      $client = new TrelloClient(\Drupal::httpClient(), $settings['key'], $settings['token'], $names);
      $this->output()->writeln(sprintf('%s boards found (%d of %d):', ucfirst($tier), count($client->boards()), count($names)));
      $boards = $client->boards();
      $counts = array_count_values(array_map('mb_strtolower', $boards));
      foreach ($boards as $id => $name) {
        $duplicate = $counts[mb_strtolower($name)] > 1 ? '  <- SAME NAME AS ANOTHER BOARD: use the ID of the right one in settings' : '';
        $this->output()->writeln("  $name ($id)$duplicate");
      }
      foreach ($client->missingBoards() as $name) {
        $this->output()->writeln("  NOT FOUND: $name (add the Trello account to this board, or fix the name)");
      }
    }
  }

  /**
   * Crawls the trusted websites into the kb chat's documents.
   */
  #[CLI\Command(name: 'kb-chat:web-sync', aliases: ['kb-web'])]
  #[CLI\Argument(name: 'source', description: 'Only this trusted website (its machine name).')]
  #[CLI\Option(name: 'due', description: 'Only websites due by their change frequency, or queued with Crawl now.')]
  #[CLI\Usage(name: 'drush kb-web --due', description: 'What Upsun cron runs every hour.')]
  #[CLI\Usage(name: 'drush kb-web mcc_benefits', description: 'Crawl one website now.')]
  public function webSync(?string $source = NULL, array $options = ['due' => FALSE]): void {
    $results = $this->webSync->sync($source, (bool) $options['due'], fn(string $message) => $this->output()->writeln($message));
    if (!empty($results['running'])) {
      $this->logger()->notice(dt('Another website sync is already running.'));
      return;
    }
    foreach ($results as $key => $counts) {
      $this->logger()->success(dt('@key: @pages pages read. @created new, @updated changed, @unchanged unchanged, @deleted removed, @failed failed.', [
        '@key' => $key,
        '@pages' => $counts['pages'],
        '@created' => $counts['created'],
        '@updated' => $counts['updated'],
        '@unchanged' => $counts['unchanged'],
        '@deleted' => $counts['deleted'],
        '@failed' => $counts['failed'],
      ]));
    }
  }

}
