<?php

namespace Drupal\kb_chat\Drive;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\kb_chat\Entity\KbChatDocument;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Syncs Google Drive files into shared drive documents (kb_chat_document).
 *
 * Two service accounts decide each file's tier, so Google's own sharing rules
 * do the work: one account is a member of allemployees only, the other of
 * mgtteam only. A file the staff account can open is a staff document; a file
 * only the management account can open is a management document. Files in
 * the shared drives each account can see are synced.
 *
 * The work is split so each run is small:
 * - Listing (every few hours) goes through the drives one at a time. Files
 *   that are new or changed go on the kb_chat_drive queue; renamed or
 *   re-shared files are relabelled; documents for files that are gone or no
 *   longer shared are deleted, but only after a complete listing from both
 *   accounts, so a failed listing never removes anything.
 * - Reading (every few minutes) takes the next batch off the queue and saves
 *   each file's text, stopping at a time or item limit.
 */
class DriveSync {

  const SOURCE = 'drive';

  const LOCK = 'kb_chat.drive_sync';

  const QUEUE = 'kb_chat_drive';

  /**
   * State key: when the last complete listing finished.
   */
  const LISTED = 'kb_chat.drive_listed';

  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TextExtractor $extractor,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether both service account keys are configured.
   */
  public function isConfigured(): bool {
    $accounts = $this->settings()['accounts'] ?? [];
    return !empty($accounts['staff']) && !empty($accounts['management']);
  }

  /**
   * The shared drives each account can see, without listing their files.
   *
   * @return array
   *   [tier => [drive name => excluded (bool)]].
   */
  public function drives(): array {
    $this->assertConfigured();
    $settings = $this->settings();
    $drives = [];
    foreach (KbChatDocument::TIERS as $tier) {
      $client = new GoogleDriveClient($this->httpClient, $settings['accounts'][$tier]);
      foreach ($client->sharedDrives() as $id => $name) {
        $drives[$tier][$name] = $this->driveExcluded($id, $name);
      }
      ksort($drives[$tier]);
    }
    return $drives;
  }

  /**
   * Files waiting to be read.
   */
  public function queued(): int {
    return $this->queueFactory->get(self::QUEUE)->numberOfItems();
  }

  /**
   * One run: list if due (or asked), then read a batch from the queue.
   *
   * @param int $time_limit
   *   Seconds to keep reading files.
   * @param int $max_items
   *   Most files to read this run.
   * @param string $mode
   *   'auto' (list when due, then read), 'list' (list only) or 'read' (read
   *   only).
   * @param callable|null $progress
   *   Called with a message per drive or file, for drush output.
   *
   * @return array
   *   Counts, or ['running' => TRUE] if another sync holds the lock.
   */
  public function sync(int $time_limit = 240, int $max_items = 100, string $mode = 'auto', ?callable $progress = NULL): array {
    $this->assertConfigured();
    // Listing a large drive set can take several minutes on its own.
    if (!$this->lock->acquire(self::LOCK, $time_limit + 1800)) {
      return ['running' => TRUE];
    }
    $progress ??= fn() => NULL;
    $counts = array_fill_keys(['listed', 'queued', 'relabeled', 'deleted', 'created', 'updated', 'skipped', 'failed'], 0);
    try {
      $interval = (int) ($this->settings()['list_interval'] ?? 6 * 3600);
      $due = time() - (int) $this->state->get(self::LISTED, 0) >= $interval;
      if ($mode === 'list' || ($mode === 'auto' && $due)) {
        $counts = $this->listAndQueue($progress) + $counts;
      }
      if ($mode !== 'list') {
        $counts = $this->readQueue($time_limit, $max_items, $progress) + $counts;
      }
      $counts['remaining'] = $this->queued();
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    return $counts;
  }

  /**
   * Lists every drive and queues new or changed files.
   */
  protected function listAndQueue(callable $progress): array {
    $settings = $this->settings();
    $queue = $this->queueFactory->get(self::QUEUE);
    // The listing decides what's outstanding, so start the queue afresh.
    $queue->deleteQueue();
    $queue->createQueue();
    $counts = ['listed' => 0, 'queued' => 0, 'relabeled' => 0, 'deleted' => 0];

    // The light fields of every existing document.
    $existing = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['id', 'source_id', 'tier', 'title', 'location', 'url', 'modified'])
      ->condition('source', self::SOURCE)
      ->execute()
      ->fetchAllAssoc('source_id', \PDO::FETCH_ASSOC);
    $storage = $this->entityTypeManager->getStorage('kb_chat_document');

    // File ID => TRUE for everything listed. The tier is the widest group
    // that can open a file: staff is listed first, and management only adds
    // files staff can't see.
    $seen = [];
    $tick = $this->keepAlive(...);
    foreach (KbChatDocument::TIERS as $tier) {
      $client = new GoogleDriveClient($this->httpClient, $settings['accounts'][$tier], $tick);
      $drives = $client->sharedDrives();
      $progress(sprintf('Listing %d shared drives as %s (%s)...', count($drives), $tier, $client->email()));
      foreach ($drives as $drive_id => $drive_name) {
        if ($this->driveExcluded($drive_id, $drive_name)) {
          $progress("  $drive_name: excluded");
          continue;
        }
        $added = 0;
        foreach ($client->filesInDrive($drive_id, $this->extractor->mimeTypes()) as $id => $file) {
          if (isset($seen[$id]) || !$this->wanted($file)) {
            continue;
          }
          $seen[$id] = TRUE;
          $counts['listed']++;
          $file['tier'] = $tier;
          $file['driveName'] = $drive_name;
          $row = $existing[$id] ?? NULL;

          if ($row && (int) $row['modified'] === strtotime($file['modifiedTime'])) {
            // Unchanged content: only the tier, title, drive or link may differ.
            $values = [
              'tier' => $tier,
              'title' => $this->title($file),
              'location' => $drive_name,
              'url' => $file['webViewLink'] ?? '',
            ];
            $changes = array_filter($values, fn($value, $field) => (string) $row[$field] !== (string) $value, ARRAY_FILTER_USE_BOTH);
            if ($changes) {
              $document = $storage->load($row['id']);
              foreach ($changes as $field => $value) {
                $document->set($field, $value);
              }
              $document->save();
              $storage->resetCache([$row['id']]);
              $counts['relabeled']++;
            }
            continue;
          }
          $queue->createItem(array_intersect_key($file, array_flip(['id', 'name', 'mimeType', 'modifiedTime', 'webViewLink', 'size', 'tier', 'driveName'])));
          $counts['queued']++;
          $added++;
        }
        $progress(sprintf('  %s: %d new or changed', $drive_name, $added));
      }
    }

    // Both listings completed, so anything not seen is gone or unshared (or
    // now excluded).
    $gone = array_column(array_diff_key($existing, $seen), 'id');
    foreach (array_chunk($gone, 50) as $ids) {
      $storage->delete($storage->loadMultiple($ids));
      $storage->resetCache();
    }
    $counts['deleted'] = count($gone);
    $this->state->set(self::LISTED, time());
    return $counts;
  }

  /**
   * Reads queued files until the time or item limit.
   */
  protected function readQueue(int $time_limit, int $max_items, callable $progress): array {
    $settings = $this->settings();
    $max_bytes = (int) ($settings['max_file_bytes'] ?? 25 * 1024 * 1024);
    // PDFs are unpacked in memory to read them, so big ones (usually print
    // proofs full of images) can exhaust the container.
    $max_pdf_bytes = (int) ($settings['max_pdf_bytes'] ?? 10 * 1024 * 1024);
    $max_chars = (int) ($settings['max_text_chars'] ?? 300000);
    $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

    $queue = $this->queueFactory->get(self::QUEUE);
    $storage = $this->entityTypeManager->getStorage('kb_chat_document');
    $clients = [];
    $end = microtime(TRUE) + $time_limit;
    $read = 0;

    while ($read < $max_items && microtime(TRUE) < $end && ($item = $queue->claimItem(600))) {
      $file = $item->data;
      $read++;
      $limit = $file['mimeType'] === 'application/pdf' ? min($max_bytes, $max_pdf_bytes) : $max_bytes;
      if (isset($file['size']) && (int) $file['size'] > $limit) {
        $counts['skipped']++;
        $queue->deleteItem($item);
        continue;
      }
      try {
        $clients[$file['tier']] ??= new GoogleDriveClient($this->httpClient, $settings['accounts'][$file['tier']], $this->keepAlive(...));
        $text = $this->extractor->extract($clients[$file['tier']], $file, $max_chars);
      }
      catch (\Throwable $e) {
        $counts['failed']++;
        $this->logger->warning('Could not read Drive file @name (@id): @message', [
          '@name' => $file['name'],
          '@id' => $file['id'],
          '@message' => $e->getMessage(),
        ]);
        // Not retried: most failures (protected or damaged files) won't
        // change. A changed file is queued again by the next listing.
        $queue->deleteItem($item);
        continue;
      }

      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('source', self::SOURCE)
        ->condition('source_id', $file['id'])
        ->execute();
      /** @var \Drupal\kb_chat\Entity\KbChatDocument $document */
      $document = $ids ? $storage->load(reset($ids)) : $storage->create(['source' => self::SOURCE, 'source_id' => $file['id']]);
      $counts[$document->isNew() ? 'created' : 'updated']++;
      $document->set('title', $this->title($file));
      $document->set('tier', $file['tier']);
      $document->set('url', $file['webViewLink'] ?? '');
      $document->set('location', $file['driveName']);
      $document->set('mime_type', $file['mimeType']);
      $document->set('text', $text);
      $document->set('modified', strtotime($file['modifiedTime']));
      $document->set('synced', time());
      $document->save();
      $storage->resetCache([$document->id()]);
      $queue->deleteItem($item);
      $progress(sprintf('%d: %s (%s)', $read, $file['name'], $file['tier']));
    }
    return $counts;
  }

  /**
   * Whether a listed file should be indexed.
   *
   * Leaves out unsupported types, Office lock files ("~$Report.docx") and
   * files whose names contain an entry of exclude_names (case-insensitive),
   * such as contact lists.
   */
  protected function wanted(array $file): bool {
    $name = mb_strtolower($file['name'] ?? '');
    if (!$this->extractor->supports($file['mimeType']) || str_starts_with($name, '~$')) {
      return FALSE;
    }
    foreach ($this->settings()['exclude_names'] ?? [] as $fragment) {
      if ($fragment !== '' && str_contains($name, mb_strtolower($fragment))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether a shared drive is in exclude_drives, by ID or name.
   */
  protected function driveExcluded(string $id, string $name): bool {
    $excluded = $this->settings()['exclude_drives'] ?? [];
    return in_array($id, $excluded, TRUE) || in_array($name, $excluded, TRUE);
  }

  /**
   * Keeps the database connection alive through a long Drive listing.
   *
   * A listing can make no database queries for many minutes, longer than
   * MySQL's idle timeout, after which Drupal's next query fails with "MySQL
   * server has gone away". A trivial query once a minute prevents that.
   */
  protected function keepAlive(): void {
    static $last = 0;
    if (time() - $last >= 60) {
      $last = time();
      $this->database->query('SELECT 1');
    }
  }

  /**
   * A document title: the file name, at most 255 characters.
   */
  protected function title(array $file): string {
    return mb_substr($file['name'] ?? 'Untitled', 0, 255);
  }

  /**
   * Throws if the service account keys are missing.
   */
  protected function assertConfigured(): void {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Set GOOGLE_DRIVE_SA_JSON and GOOGLE_DRIVE_MGMT_SA_JSON to sync Google Drive.');
    }
  }

  /**
   * $settings['kb_chat']['drive'].
   */
  protected function settings(): array {
    return Settings::get('kb_chat')['drive'] ?? [];
  }

}
