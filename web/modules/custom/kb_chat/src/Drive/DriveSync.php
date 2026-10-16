<?php

namespace Drupal\kb_chat\Drive;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
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
 * Each run lists every file (fast), then reads the text of new and changed
 * files until the time limit, leaving the rest for the next run. Documents
 * for files that are gone or no longer shared are deleted, but only after a
 * complete listing from both accounts, so a failed listing never removes
 * anything.
 */
class DriveSync {

  const SOURCE = 'drive';

  const LOCK = 'kb_chat.drive_sync';

  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TextExtractor $extractor,
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
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Set GOOGLE_DRIVE_SA_JSON and GOOGLE_DRIVE_MGMT_SA_JSON to sync Google Drive.');
    }
    $settings = $this->settings();
    $excluded = $settings['exclude_drives'] ?? [];
    $drives = [];
    foreach (KbChatDocument::TIERS as $tier) {
      $client = new GoogleDriveClient($this->httpClient, $settings['accounts'][$tier]);
      foreach ($client->sharedDrives() as $id => $name) {
        $drives[$tier][$name] = in_array($id, $excluded, TRUE) || in_array($name, $excluded, TRUE);
      }
      ksort($drives[$tier]);
    }
    return $drives;
  }

  /**
   * Runs one sync.
   *
   * @param int $time_limit
   *   Seconds to keep reading files.
   * @param callable|null $progress
   *   Called with a message for each file read, for drush output.
   *
   * @return array
   *   Counts: listed, created, updated, retiered, deleted, skipped, failed,
   *   remaining. NULL values mean another sync was running.
   */
  public function sync(int $time_limit = 270, ?callable $progress = NULL): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Set GOOGLE_DRIVE_SA_JSON and GOOGLE_DRIVE_MGMT_SA_JSON to sync Google Drive.');
    }
    if (!$this->lock->acquire(self::LOCK, $time_limit + 300)) {
      return ['running' => TRUE];
    }
    try {
      return $this->doSync($time_limit, $progress ?? fn() => NULL);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * The sync itself; see sync().
   */
  protected function doSync(int $time_limit, callable $progress): array {
    $end = microtime(TRUE) + $time_limit;
    $settings = $this->settings();
    $max_bytes = (int) ($settings['max_file_bytes'] ?? 25 * 1024 * 1024);
    $max_chars = (int) ($settings['max_text_chars'] ?? 300000);
    $counts = array_fill_keys(['listed', 'created', 'updated', 'retiered', 'deleted', 'skipped', 'failed', 'remaining'], 0);

    // List everything both accounts can see. Any failure aborts the run
    // before anything is changed.
    $clients = [];
    $visible = [];
    $tick = $this->keepAlive(...);
    foreach (KbChatDocument::TIERS as $tier) {
      $clients[$tier] = new GoogleDriveClient($this->httpClient, $settings['accounts'][$tier], $tick);
      $visible[$tier] = $this->listFiles($clients[$tier], $tier, $progress);
    }

    // The tier is the widest group that can open the file.
    $files = [];
    foreach (array_reverse(KbChatDocument::TIERS) as $tier) {
      foreach ($visible[$tier] as $id => $file) {
        $files[$id] = $file + ['tier' => $tier];
      }
    }

    $storage = $this->entityTypeManager->getStorage('kb_chat_document');
    // The light fields only: loading every document's text would use a lot
    // of memory. A document is loaded when it needs changing.
    $existing = [];
    $query = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['id', 'source_id', 'tier', 'title', 'location', 'url', 'modified'])
      ->condition('source', self::SOURCE);
    foreach ($query->execute()->fetchAllAssoc('source_id', \PDO::FETCH_ASSOC) as $source_id => $row) {
      $existing[$source_id] = $row;
    }

    foreach ($files as $id => $file) {
      // Office lock files ("~$Report.docx") are left behind by open documents.
      if (!$this->extractor->supports($file['mimeType']) || str_starts_with($file['name'] ?? '', '~$')) {
        continue;
      }
      $counts['listed']++;
      $row = $existing[$id] ?? NULL;
      $modified = strtotime($file['modifiedTime']);

      // Unchanged content: only the tier, title, drive or link may change.
      if ($row && (int) $row['modified'] === $modified) {
        $values = [
          'tier' => $file['tier'],
          'title' => $this->title($file),
          'location' => $file['driveName'],
          'url' => $file['webViewLink'] ?? '',
        ];
        $changes = array_filter($values, fn($value, $field) => (string) $row[$field] !== (string) $value, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
          $document = $storage->load($row['id']);
          foreach ($changes as $field => $value) {
            $document->set($field, $value);
          }
          $document->save();
          $counts['retiered']++;
        }
        continue;
      }

      if (microtime(TRUE) >= $end) {
        $counts['remaining']++;
        continue;
      }
      if (isset($file['size']) && (int) $file['size'] > $max_bytes) {
        $counts['skipped']++;
        continue;
      }

      try {
        $text = $this->extractor->extract($clients[$file['tier']], $file, $max_chars);
      }
      catch (\Throwable $e) {
        $counts['failed']++;
        $this->logger->warning('Could not read Drive file @name (@id): @message', [
          '@name' => $file['name'],
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      /** @var \Drupal\kb_chat\Entity\KbChatDocument $document */
      $document = $row ? $storage->load($row['id']) : $storage->create(['source' => self::SOURCE, 'source_id' => $id]);
      $counts[$document->isNew() ? 'created' : 'updated']++;
      $document->set('title', $this->title($file));
      $document->set('tier', $file['tier']);
      $document->set('url', $file['webViewLink'] ?? '');
      $document->set('location', $file['driveName']);
      $document->set('mime_type', $file['mimeType']);
      $document->set('text', $text);
      $document->set('modified', $modified);
      $document->set('synced', time());
      $document->save();
      $progress(sprintf('%s: %s (%s)', $counts['created'] + $counts['updated'], $file['name'], $file['tier']));
    }

    // Both listings succeeded, so anything not listed (or no longer a
    // supported type) is gone or unshared.
    $delete = [];
    foreach ($existing as $id => $row) {
      if (!isset($files[$id]) || !$this->extractor->supports($files[$id]['mimeType']) || str_starts_with($files[$id]['name'] ?? '', '~$')) {
        $delete[] = $row['id'];
      }
    }
    foreach (array_chunk($delete, 50) as $ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
    $counts['deleted'] = count($delete);
    return $counts;
  }

  /**
   * Every file in the shared drives an account can see.
   *
   * @return array
   *   [file id => Drive file + driveName].
   */
  protected function listFiles(GoogleDriveClient $client, string $tier, callable $progress): array {
    $files = [];
    $excluded = $this->settings()['exclude_drives'] ?? [];
    $drives = $client->sharedDrives();
    $progress(sprintf('Listing %d shared drives as %s (%s)...', count($drives), $tier, $client->email()));
    foreach ($drives as $drive_id => $drive_name) {
      if (in_array($drive_id, $excluded, TRUE) || in_array($drive_name, $excluded, TRUE)) {
        $progress("  $drive_name: excluded");
        continue;
      }
      $drive_files = $client->filesInDrive($drive_id, $this->extractor->mimeTypes());
      foreach ($drive_files as $id => $file) {
        $files[$id] = $file + ['driveName' => $drive_name];
      }
      $progress(sprintf('  %s: %d files', $drive_name, count($drive_files)));
    }
    return $files;
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
   * $settings['kb_chat']['drive'].
   */
  protected function settings(): array {
    return Settings::get('kb_chat')['drive'] ?? [];
  }

}
