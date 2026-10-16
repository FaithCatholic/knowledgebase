<?php

namespace Drupal\kb_chat\Web;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\kb_chat\Drive\TextExtractor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Indexes sections of trusted outside websites for the kb chat.
 *
 * Each trusted website (a kb_chat_web_source config entity, managed at
 * Configuration > Search > KB chat trusted websites) lists start URLs and the
 * URL prefixes the crawl may follow. Pages (and PDF or Office documents)
 * under those prefixes become KB chat documents with source "web", so the
 * chat can search and cite them, linking to the original site rather than
 * copying it into kb. Pages that disappear from a complete crawl are removed.
 */
class WebSync {

  const SOURCE = 'web';

  const LOCK = 'kb_chat.web_sync';

  const USER_AGENT = 'FAITH-KB-Chat/1.0 (+https://kb.faithcatholic.com)';

  /**
   * Content types fetched: HTML pages, plus documents TextExtractor reads.
   */
  const DOCUMENT_TYPES = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ];

  public function __construct(
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TextExtractor $extractor,
    protected StateInterface $state,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * The trusted websites: [id => entity].
   *
   * @return \Drupal\kb_chat\Entity\KbChatWebSource[]
   */
  public function sources(): array {
    return $this->entityTypeManager->getStorage('kb_chat_web_source')->loadMultiple();
  }

  /**
   * Crawls trusted websites.
   *
   * @param string|null $only
   *   A website ID, or NULL for all.
   * @param bool $due_only
   *   Only enabled websites whose interval has passed since the last crawl
   *   (or that were queued with "Crawl now"). What the hourly cron uses.
   * @param callable|null $progress
   *   Called with a message per page, for drush output.
   *
   * @return array
   *   Counts per website: pages, created, updated, unchanged, deleted,
   *   failed. ['running' => TRUE] if another crawl holds the lock.
   */
  public function sync(?string $only = NULL, bool $due_only = FALSE, ?callable $progress = NULL): array {
    if (!$this->lock->acquire(self::LOCK, 3600)) {
      return ['running' => TRUE];
    }
    $progress ??= fn() => NULL;
    $results = [];
    try {
      foreach ($this->sources() as $id => $source) {
        if ($only !== NULL && $only !== $id) {
          continue;
        }
        $last = (int) $this->state->get('kb_chat.web_crawled.' . $id, 0);
        if ($due_only && (!$source->status() || time() - $last < $source->interval())) {
          continue;
        }
        $progress(sprintf('Crawling %s...', $source->label()));
        $results[$id] = $this->syncSource($id, $source->toSource(), $progress);
        $this->state->set('kb_chat.web_crawled.' . $id, time());
      }
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    return $results;
  }

  /**
   * Crawls one source and updates its documents.
   */
  protected function syncSource(string $key, array $source, callable $progress): array {
    $counts = array_fill_keys(['pages', 'created', 'updated', 'unchanged', 'deleted', 'failed'], 0);
    $label = $source['label'] ?? $key;
    $prefixes = $source['include'] ?? $source['start'];
    $max_pages = (int) ($source['max_pages'] ?? 200);
    $max_chars = (int) ($source['max_text_chars'] ?? 100000);
    $delay = (float) ($source['delay'] ?? 1);
    $disallowed = $this->robotsDisallowed($source['start'][0]);

    $storage = $this->entityTypeManager->getStorage('kb_chat_document');
    $existing = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['id', 'source_id'])
      ->condition('source', self::SOURCE)
      ->condition('location', $label)
      ->execute()
      ->fetchAllKeyed(1, 0);

    $queue = array_map([$this, 'normalize'], $source['start']);
    $seen = array_fill_keys($queue, TRUE);
    $found = [];
    $complete = TRUE;

    while ($queue && $counts['pages'] < $max_pages) {
      $url = array_shift($queue);
      if ($counts['pages']) {
        usleep((int) ($delay * 1000000));
      }
      try {
        [$type, $body, $final] = $this->fetch($url);
      }
      catch (\Throwable $e) {
        $counts['failed']++;
        // A start page that fails means the crawl can't be trusted to be
        // complete, so nothing will be deleted this run.
        if (in_array($url, array_map([$this, 'normalize'], $source['start']), TRUE)) {
          $complete = FALSE;
        }
        $this->logger->warning('Could not fetch @url: @message', ['@url' => $url, '@message' => $e->getMessage()]);
        continue;
      }
      if ($type === NULL) {
        continue;
      }
      $counts['pages']++;

      if ($type === 'text/html') {
        [$title, $text, $links] = $this->parseHtml($body, $final);
        foreach ($links as $link) {
          $link = $this->normalize($link);
          if (!isset($seen[$link]) && $this->allowed($link, $prefixes, $disallowed, !empty($source['documents']))) {
            $seen[$link] = TRUE;
            $queue[] = $link;
          }
        }
      }
      else {
        $title = urldecode(basename(parse_url($final, PHP_URL_PATH)));
        try {
          $text = $this->extractor->fromContent($type, $body, $max_chars);
        }
        catch (\Throwable $e) {
          $counts['failed']++;
          $this->logger->warning('Could not read @url: @message', ['@url' => $url, '@message' => $e->getMessage()]);
          continue;
        }
      }
      $text = mb_substr(trim($text), 0, $max_chars);
      if (mb_strlen($text) < 50) {
        continue;
      }
      $found[$url] = TRUE;

      $id = $existing[$url] ?? NULL;
      $document = $id ? $storage->load($id) : $storage->create(['source' => self::SOURCE, 'source_id' => $url]);
      if ($id && $document->get('text')->value === $text && $document->get('title')->value === $title) {
        $counts['unchanged']++;
        continue;
      }
      $document->set('title', mb_substr($title ?: $url, 0, 255));
      $document->set('url', $url);
      $document->set('location', $label);
      $document->set('tier', $source['tier'] ?? 'staff');
      $document->set('mime_type', $type);
      $document->set('text', $text);
      $document->set('modified', time());
      $document->set('synced', time());
      $counts[$id ? 'updated' : 'created']++;
      $document->save();
      $progress(sprintf('%s: %s', $id ? 'updated' : 'new', $url));
    }

    // Remove pages gone from a complete crawl (not cut short by max_pages).
    if ($complete && !$queue) {
      $gone = array_diff_key($existing, $found);
      foreach (array_chunk(array_values($gone), 50) as $ids) {
        $storage->delete($storage->loadMultiple($ids));
      }
      $counts['deleted'] = count($gone);
    }
    return $counts;
  }

  /**
   * Fetches a URL.
   *
   * @return array
   *   [content type or NULL if not wanted, body, final URL].
   */
  protected function fetch(string $url): array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/pdf;q=0.9,*/*;q=0.5'],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => ['max' => 5, 'track_redirects' => TRUE],
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
    if ($response->getStatusCode() >= 400) {
      throw new \RuntimeException('HTTP ' . $response->getStatusCode());
    }
    $redirects = $response->getHeader('X-Guzzle-Redirect-History');
    $final = $redirects ? end($redirects) : $url;
    $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
    if ($type !== 'text/html' && !in_array($type, self::DOCUMENT_TYPES, TRUE)) {
      return [NULL, '', $final];
    }
    return [$type, (string) $response->getBody(), $final];
  }

  /**
   * A page's title, main text, and links (absolute).
   */
  protected function parseHtml(string $html, string $base): array {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new \DOMXPath($dom);

    $links = [];
    foreach ($xpath->query('//a[@href]') as $a) {
      if ($absolute = $this->absolute($a->getAttribute('href'), $base)) {
        $links[] = $absolute;
      }
    }

    // Drop page furniture, then prefer the main content area.
    foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//iframe|//svg') as $node) {
      $node->parentNode?->removeChild($node);
    }
    $main = $xpath->query('//main|//*[@role="main"]|//article')->item(0) ?? $xpath->query('//body')->item(0);
    $h1 = $xpath->query('//h1')->item(0);
    $title = trim($h1?->textContent ?: ($xpath->query('//title')->item(0)?->textContent ?? ''));

    $text = '';
    if ($main) {
      // Keep headings, paragraphs and list items on their own lines.
      foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//td|.//th|.//dt|.//dd', $main) as $node) {
        $line = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if ($line !== '') {
          $text .= (preg_match('/^h[1-4]$/', $node->nodeName) ? "\n## " : '') . $line . "\n";
        }
      }
      if (trim($text) === '') {
        $text = trim(preg_replace('/\s+/u', ' ', $main->textContent));
      }
    }
    return [$title, trim($text), array_unique($links)];
  }

  /**
   * Whether a link should be crawled.
   */
  protected function allowed(string $url, array $prefixes, array $disallowed, bool $documents): bool {
    $path = parse_url($url, PHP_URL_PATH) ?? '/';
    foreach ($disallowed as $rule) {
      if ($rule !== '' && str_starts_with($path, $rule)) {
        return FALSE;
      }
    }
    foreach ($prefixes as $prefix) {
      if (str_starts_with($url, $this->normalize($prefix))) {
        return TRUE;
      }
    }
    // Documents linked from allowed pages may live elsewhere on the same site
    // (e.g. /wp-content/uploads/...).
    if ($documents && preg_match('/\.(pdf|docx|xlsx)$/i', $path)) {
      return parse_url($url, PHP_URL_HOST) === parse_url($prefixes[0], PHP_URL_HOST);
    }
    return FALSE;
  }

  /**
   * The paths robots.txt disallows for all user agents.
   */
  protected function robotsDisallowed(string $start): array {
    $parts = parse_url($start);
    try {
      $response = $this->httpClient->request('GET', $parts['scheme'] . '://' . $parts['host'] . '/robots.txt', [
        'headers' => ['User-Agent' => self::USER_AGENT],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException) {
      return [];
    }
    if ($response->getStatusCode() !== 200) {
      return [];
    }
    $rules = [];
    $applies = FALSE;
    foreach (preg_split('/\R/', (string) $response->getBody()) as $line) {
      $line = trim(preg_replace('/#.*/', '', $line));
      if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
        $applies = trim($m[1]) === '*';
      }
      elseif ($applies && preg_match('/^disallow:\s*(.*)$/i', $line, $m)) {
        $rules[] = trim($m[1]);
      }
    }
    return $rules;
  }

  /**
   * An absolute http(s) URL for a link, or NULL.
   */
  protected function absolute(string $href, string $base): ?string {
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
      return NULL;
    }
    if (preg_match('#^https?://#i', $href)) {
      return $href;
    }
    $b = parse_url($base);
    $origin = $b['scheme'] . '://' . $b['host'];
    if (str_starts_with($href, '//')) {
      return $b['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
      return $origin . $href;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $origin . $dir . $href;
  }

  /**
   * A URL without fragment or query, with "www." and http removed so one page
   * has one key.
   */
  protected function normalize(string $url): string {
    $parts = parse_url($url);
    $host = preg_replace('/^www\./', '', strtolower($parts['host'] ?? ''));
    $path = $parts['path'] ?? '/';
    return 'https://' . $host . $path;
  }

}
