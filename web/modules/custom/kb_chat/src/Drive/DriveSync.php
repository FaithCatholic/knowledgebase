<?php

namespace Drupal\kb_chat\Drive;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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

  /**
   * State key: the file being read, cleared when done. Still set at the next
   * run means reading it crashed the process (usually out of memory).
   */
  const READING = 'kb_chat.drive_reading';

  /**
   * State key: IDs of files that crashed a run, skipped from then on.
   */
  const CRASHED = 'kb_chat.drive_crashed';

  /**
   * Exclusions and limits used before the kb_chat.drive config exists.
   */
  const FALLBACK = [
    'exclude_drives' => ['Accounts Payable', 'Magazines'],
    'exclude_folders' => [],
    'exclude_names' => ['CONTACTS DATABASE', 'Christmas card list'],
    'exclude_files' => [],
    'priority_documents' => [],
    'list_interval' => 21600,
    'max_file_bytes' => 26214400,
    'max_pdf_bytes' => 10485760,
  ];

  /**
   * Memory ceiling for a sync run. The container is shared with the website,
   * so a runaway file must stop the sync, not get the container killed.
   */
  const MEMORY_LIMIT = '384M';

  /**
   * exclude_files as file ID => TRUE, parsed once per listing.
   */
  protected ?array $excludedFiles = NULL;

  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TextExtractor $extractor,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Seconds a live read of a priority document is reused.
   */
  const LIVE_CACHE = 600;

  /**
   * The priority documents: file ID => what it's for.
   *
   * Entries of priority_documents are a file ID or Drive link, then
   * optionally "|" and a note on what the document is for.
   */
  public function priorityDocuments(): array {
    $documents = [];
    foreach ($this->settings()['priority_documents'] ?? [] as $entry) {
      $parts = array_map('trim', explode('|', (string) $entry, 2));
      if ($id = self::fileId($parts[0])) {
        $documents[$id] = $parts[1] ?? '';
      }
    }
    return $documents;
  }

  /**
   * The file ID in a Drive link, or the ID itself; NULL if neither.
   */
  public static function fileId(string $value): ?string {
    $value = trim($value);
    if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $value, $match) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $value, $match)) {
      return $match[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{10,}$/', $value) ? $value : NULL;
  }

  /**
   * A document's current text, read from Drive and reused for 10 minutes.
   *
   * For priority documents such as production schedules, which change more
   * often than the drives are listed. Falls back to the synced text if Drive
   * can't be read.
   *
   * @return array
   *   ['text' => string, 'as_of' => Unix time, 'live' => bool].
   */
  public function currentText(KbChatDocument $document): array {
    $file_id = (string) $document->get('source_id')->value;
    $cid = 'kb_chat:live:' . $file_id;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    try {
      $this->assertConfigured();
      $settings = $this->settings();
      $client = new GoogleDriveClient($this->httpClient, $settings['accounts'][$document->getTier()]);
      $text = $this->extractor->extract($client, [
        'id' => $file_id,
        'mimeType' => (string) $document->get('mime_type')->value,
      ], (int) ($settings['max_text_chars'] ?? 300000));
      $result = ['text' => $text, 'as_of' => time(), 'live' => TRUE];
      $this->cache->set($cid, $result, time() + self::LIVE_CACHE);
      return $result;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not read @title from Drive, using the synced copy: @message', [
        '@title' => $document->label(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'text' => (string) $document->get('text')->value,
        'as_of' => (int) $document->get('synced')->value,
        'live' => FALSE,
      ];
    }
  }

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
    $limit = ini_get('memory_limit');
    if ($limit === '-1' || $this->bytes($limit) > $this->bytes(self::MEMORY_LIMIT)) {
      ini_set('memory_limit', self::MEMORY_LIMIT);
    }
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
    $this->excludedFiles = NULL;
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
        $excluded_folders = $this->excludedFolders($client, $drive_id, $drive_name);
        foreach ($client->filesInDrive($drive_id, $this->extractor->mimeTypes()) as $id => $file) {
          if (isset($seen[$id]) || !$this->wanted($file) || array_intersect($file['parents'] ?? [], $excluded_folders)) {
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
              // The tier isn't in the search index: access is checked against
              // the document when searching. So a sharing change alone
              // doesn't need the file re-embedded.
              if (array_keys($changes) === ['tier']) {
                $document->search_api_skip_tracking = TRUE;
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
    $this->excludedFiles = NULL;

    $queue = $this->queueFactory->get(self::QUEUE);
    $storage = $this->entityTypeManager->getStorage('kb_chat_document');
    $clients = [];
    $end = microtime(TRUE) + $time_limit;
    $read = 0;

    // A file still marked as being read crashed the last run: never retry it.
    $crashed = $this->state->get(self::CRASHED, []);
    if ($reading = $this->state->get(self::READING)) {
      $crashed[$reading['id']] = $reading['name'];
      $this->state->set(self::CRASHED, $crashed);
      $this->state->delete(self::READING);
      $this->logger->error('Drive file @name (@id) crashed the sync, probably out of memory, and will be skipped.', [
        '@name' => $reading['name'],
        '@id' => $reading['id'],
      ]);
    }

    while ($read < $max_items && microtime(TRUE) < $end && ($item = $queue->claimItem(600))) {
      $file = $item->data;
      $read++;
      // Crashed before, or excluded since it was queued.
      if (isset($crashed[$file['id']]) || !$this->wanted($file)) {
        $counts['skipped']++;
        $queue->deleteItem($item);
        continue;
      }
      $limit = $file['mimeType'] === 'application/pdf' ? min($max_bytes, $max_pdf_bytes) : $max_bytes;
      if (isset($file['size']) && (int) $file['size'] > $limit) {
        $counts['skipped']++;
        $queue->deleteItem($item);
        continue;
      }
      try {
        $clients[$file['tier']] ??= new GoogleDriveClient($this->httpClient, $settings['accounts'][$file['tier']], $this->keepAlive(...));
        $this->state->set(self::READING, ['id' => $file['id'], 'name' => $file['name']]);
        $text = $this->extractor->extract($clients[$file['tier']], $file, $max_chars);
        $this->state->delete(self::READING);
        gc_collect_cycles();
      }
      catch (\Throwable $e) {
        $this->state->delete(self::READING);
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
   * Leaves out unsupported types, Office lock files ("~$Report.docx"),
   * files whose names contain an entry of exclude_names (case-insensitive),
   * such as contact lists, and files listed in exclude_files.
   */
  protected function wanted(array $file): bool {
    $name = mb_strtolower($file['name'] ?? '');
    if (!$this->extractor->supports($file['mimeType']) || str_starts_with($name, '~$')) {
      return FALSE;
    }
    $this->excludedFiles ??= array_flip(self::fileIds($this->settings()['exclude_files'] ?? []));
    if (isset($this->excludedFiles[$file['id'] ?? ''])) {
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
   * The file IDs in exclude_files entries ("ID  title": the title is a note).
   */
  public static function fileIds(array $entries): array {
    return array_values(array_filter(array_map(fn($entry) => strtok(trim((string) $entry), " \t") ?: '', $entries)));
  }

  /**
   * IDs of a drive's excluded folders and every folder inside them.
   *
   * Entries of exclude_folders are a path from the drive, such as
   * "Custom Publishing/Production/2024" (case-insensitive), or a folder ID
   * (the end of the folder's Drive URL).
   */
  protected function excludedFolders(GoogleDriveClient $client, string $drive_id, string $drive_name): array {
    $entries = $this->settings()['exclude_folders'] ?? [];
    if (!$entries) {
      return [];
    }
    $folders = $client->foldersInDrive($drive_id);
    $paths = $this->folderPaths($folders, $drive_id, $drive_name);
    $by_path = array_flip(array_map('mb_strtolower', $paths));
    $roots = [];
    foreach ($entries as $entry) {
      $entry = trim($entry, " /");
      if (isset($folders[$entry])) {
        $roots[] = $entry;
      }
      elseif (isset($by_path[mb_strtolower($entry)])) {
        $roots[] = $by_path[mb_strtolower($entry)];
      }
    }
    if (!$roots) {
      return [];
    }
    // Everything inside the excluded folders.
    $children = [];
    foreach ($folders as $id => $folder) {
      $children[$folder['parent']][] = $id;
    }
    $excluded = [];
    while ($roots) {
      $id = array_pop($roots);
      if (!isset($excluded[$id])) {
        $excluded[$id] = $id;
        array_push($roots, ...($children[$id] ?? []));
      }
    }
    return array_values($excluded);
  }

  /**
   * Each folder's path from the drive: [folder id => "Drive/Folder/Sub"].
   */
  protected function folderPaths(array $folders, string $drive_id, string $drive_name): array {
    $paths = [];
    $path = function (string $id, int $depth = 0) use (&$path, &$paths, $folders, $drive_id, $drive_name): string {
      if ($id === $drive_id || !isset($folders[$id]) || $depth > 50) {
        return $drive_name;
      }
      return $paths[$id] ??= $path($folders[$id]['parent'], $depth + 1) . '/' . $folders[$id]['name'];
    };
    foreach (array_keys($folders) as $id) {
      $path($id);
    }
    return $paths;
  }

  /**
   * Indexable files per folder of a drive, two levels deep, for choosing
   * folders to exclude.
   *
   * @return array
   *   [folder path => file count], largest first, with excluded folders
   *   marked. Files deeper down count towards their level-two folder.
   */
  public function folderSummary(string $drive_name): array {
    $this->assertConfigured();
    $settings = $this->settings();
    foreach (KbChatDocument::TIERS as $tier) {
      $client = new GoogleDriveClient($this->httpClient, $settings['accounts'][$tier]);
      $drive_id = array_search(mb_strtolower($drive_name), array_map('mb_strtolower', $client->sharedDrives()), TRUE);
      if ($drive_id !== FALSE) {
        break;
      }
    }
    if (empty($drive_id)) {
      throw new \RuntimeException("Neither account can see a shared drive named \"$drive_name\".");
    }
    $folders = $client->foldersInDrive($drive_id);
    $paths = $this->folderPaths($folders, $drive_id, $drive_name);
    $excluded = array_flip($this->excludedFolders($client, $drive_id, $drive_name));
    $counts = [];
    foreach ($client->filesInDrive($drive_id, $this->extractor->mimeTypes()) as $file) {
      if (!$this->wanted($file)) {
        continue;
      }
      $parent = $file['parents'][0] ?? $drive_id;
      $path = $paths[$parent] ?? $drive_name;
      // Group by the first two folder levels below the drive.
      $key = implode('/', array_slice(explode('/', $path), 0, 3));
      if (isset($excluded[$parent])) {
        $key .= '  (excluded)';
      }
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
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
   * A php.ini size such as "384M" in bytes.
   */
  protected function bytes(string $size): int {
    $value = (int) $size;
    return match (strtoupper(substr(trim($size), -1))) {
      'G' => $value * 1024 ** 3,
      'M' => $value * 1024 ** 2,
      'K' => $value * 1024,
      default => $value,
    };
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
   * The sync's settings.
   *
   * The service account keys come from $settings['kb_chat']['drive'] in
   * settings.php (secrets stay out of config). Exclusions and limits come
   * from the kb_chat.drive config, edited at Configuration > Search > KB chat
   * Google Drive.
   */
  protected function settings(): array {
    $config = $this->configFactory->get('kb_chat.drive');
    $settings = Settings::get('kb_chat')['drive'] ?? [];
    // Until kb_chat_update_11002 has created the config, keep the exclusions
    // that used to be in settings.php, so nothing private slips in.
    if ($config->isNew()) {
      return $settings + self::FALLBACK;
    }
    foreach (array_keys(self::FALLBACK) as $key) {
      if ($config->get($key) !== NULL) {
        $settings[$key] = $config->get($key);
      }
    }
    return $settings;
  }

}
