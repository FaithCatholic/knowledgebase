<?php

namespace Drupal\kb_chat\Drush\Commands;

use Drupal\kb_chat\AnthropicCostReport;
use Drupal\kb_chat\CostTracker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\kb_chat\Drive\DriveSync;
use Drupal\kb_chat\JobLog;
use Drupal\kb_chat\Trello\TrelloClient;
use Drupal\kb_chat\Web\WebSync;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    private readonly JobLog $jobLog,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
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
  #[CLI\Option(name: 'folders', description: 'Only show indexable files per folder (two levels deep) of this shared drive.')]
  #[CLI\Usage(name: 'drush kb-drive --time-limit=240 --max-items=100', description: 'What Upsun cron runs every 5 minutes.')]
  #[CLI\Usage(name: 'drush kb-drive --list', description: 'Re-list the drives now.')]
  #[CLI\Usage(name: 'drush kb-drive --drives', description: 'List the shared drives each account can see.')]
  #[CLI\Usage(name: 'drush kb-drive --folders="Custom Publishing"', description: 'Files per folder, to choose exclude_folders.')]
  public function driveSync(array $options = ['time-limit' => 240, 'max-items' => 100, 'list' => FALSE, 'read' => FALSE, 'drives' => FALSE, 'folders' => '']): void {
    if (!empty($options['folders'])) {
      $counts = $this->driveSync->folderSummary($options['folders']);
      $this->output()->writeln(sprintf('%s: %d indexable files', $options['folders'], array_sum($counts)));
      foreach ($counts as $path => $count) {
        $this->output()->writeln(sprintf('%8d  %s', $count, $path));
      }
      return;
    }
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
    $this->jobLog->start('drive');
    try {
      $counts = $this->driveSync->sync((int) $options['time-limit'], (int) $options['max-items'], $mode, fn(string $message) => $this->output()->writeln($message));
    }
    catch (\Throwable $e) {
      $this->jobLog->finish('drive', $e->getMessage(), [], FALSE);
      throw $e;
    }
    if (!empty($counts['running'])) {
      $this->jobLog->skipped('drive');
      $this->logger()->notice(dt('Another Drive sync is already running.'));
      return;
    }
    $message = dt('Drive: @listed listed, @queued queued, @relabeled relabeled, @deleted removed; read @created new, @updated changed, @skipped too large, @failed failed. @remaining waiting in the queue.', [
      '@listed' => $counts['listed'],
      '@queued' => $counts['queued'],
      '@relabeled' => $counts['relabeled'],
      '@deleted' => $counts['deleted'],
      '@created' => $counts['created'],
      '@updated' => $counts['updated'],
      '@skipped' => $counts['skipped'],
      '@failed' => $counts['failed'],
      '@remaining' => $counts['remaining'],
    ]);
    $this->jobLog->finish('drive', $message, $counts);
    $this->logger()->success($message);
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
  #[CLI\Option(name: 'time-limit', description: 'Seconds after which no new website is started (0 for no limit).')]
  #[CLI\Usage(name: 'drush kb-web --due --time-limit=240', description: 'What Upsun cron runs every 10 minutes.')]
  #[CLI\Usage(name: 'drush kb-web mcc_benefits', description: 'Crawl one website now.')]
  public function webSync(?string $source = NULL, array $options = ['due' => FALSE, 'time-limit' => 0]): void {
    // One-off crawls of a single website aren't the cron job's runs.
    $log = $source === NULL;
    if ($log) {
      $this->jobLog->start('web');
    }
    try {
      $results = $this->webSync->sync($source, (bool) $options['due'], fn(string $message) => $this->output()->writeln($message), (int) $options['time-limit']);
    }
    catch (\Throwable $e) {
      if ($log) {
        $this->jobLog->finish('web', $e->getMessage(), [], FALSE);
      }
      throw $e;
    }
    if (!empty($results['running'])) {
      if ($log) {
        $this->jobLog->skipped('web');
      }
      $this->logger()->notice(dt('Another website sync is already running.'));
      return;
    }
    $summary = [];
    foreach ($results as $key => $counts) {
      $summary[] = dt('@key: @pages pages, @failed failed', ['@key' => $key, '@pages' => $counts['pages'], '@failed' => $counts['failed']]);
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
    if ($log) {
      $this->jobLog->finish('web', $summary ? implode('; ', $summary) . '.' : dt('No websites were due.'), $results);
    }
  }

  /**
   * Indexes new and changed kb chat content, in small batches.
   *
   * Like search-api:index, but bounded by time and recorded for the status
   * block.
   */
  #[CLI\Command(name: 'kb-chat:index', aliases: ['kb-index'])]
  #[CLI\Option(name: 'limit', description: 'Most items to index this run.')]
  #[CLI\Option(name: 'batch-size', description: 'Items per batch.')]
  #[CLI\Option(name: 'time-limit', description: 'Seconds after which no new batch is started.')]
  #[CLI\Usage(name: 'drush kb-index --limit=100 --batch-size=25 --time-limit=240', description: 'What Upsun cron runs every 5 minutes.')]
  public function index(array $options = ['limit' => 100, 'batch-size' => 25, 'time-limit' => 240]): void {
    if (!$this->lock->acquire('kb_chat.index', 600)) {
      $this->logger()->notice(dt('Another indexing run is already going.'));
      return;
    }
    $this->jobLog->start('index');
    $started = time();
    $indexed = 0;
    $limit = max(1, (int) $options['limit']);
    $batch = max(1, (int) $options['batch-size']);
    try {
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')->load('kb_chat_content');
      if (!$index || !$index->status()) {
        throw new \RuntimeException('The kb_chat_content index is missing or disabled.');
      }
      while ($indexed < $limit && time() - $started < (int) $options['time-limit']) {
        $want = min($batch, $limit - $indexed);
        $count = $index->indexItems($want);
        $indexed += $count;
        // Nothing left, or items failed (Search API logs why).
        if ($count < $want) {
          break;
        }
      }
      $remaining = $index->getTrackerInstance()->getRemainingItemsCount();
      $message = dt('Indexed @indexed items. @remaining waiting.', ['@indexed' => $indexed, '@remaining' => $remaining]);
      $this->jobLog->finish('index', $message, ['indexed' => $indexed, 'remaining' => $remaining]);
      $this->logger()->success($message);
    }
    catch (\Throwable $e) {
      $this->jobLog->finish('index', $e->getMessage(), ['indexed' => $indexed], FALSE);
      throw $e;
    }
    finally {
      $this->lock->release('kb_chat.index');
    }
  }

}
