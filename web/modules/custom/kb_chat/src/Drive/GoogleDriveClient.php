<?php

namespace Drupal\kb_chat\Drive;

use GuzzleHttp\ClientInterface;
use Drupal\kb_chat\Google\ServiceAccountToken;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal read-only Google Drive client for one service account.
 *
 * Signs in as the service account (ServiceAccountToken, scope drive.readonly)
 * and lists and reads files. One instance
 * per account; DriveSync creates them from $settings['kb_chat']['drive'].
 */
class GoogleDriveClient {

  const API = 'https://www.googleapis.com/drive/v3';

  const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

  /**
   * Retries for temporary errors before giving up. With exponential backoff
   * the last wait is 64 seconds, long enough for a rate limit to reset.
   */
  const RETRIES = 7;

  /**
   * Drive's 403 reasons that mean "slow down" rather than "no access".
   */
  const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded'];

  /**
   * Access tokens for the service account.
   */
  protected ServiceAccountToken $auth;

  /**
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param string $keyJson
   *   The service account's JSON key file contents.
   * @param \Closure|null $tick
   *   Called after every request and during waits, so the caller can keep its
   *   database connection alive through a long listing.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    string $keyJson,
    protected ?\Closure $tick = NULL,
  ) {
    $this->auth = new ServiceAccountToken($httpClient, $keyJson, self::SCOPE);
  }

  /**
   * The service account's email address.
   */
  public function email(): string {
    return $this->auth->email();
  }

  /**
   * The shared drives this account can see: [id => name].
   */
  public function sharedDrives(): array {
    $drives = [];
    $query = ['pageSize' => 100, 'fields' => 'nextPageToken,drives(id,name)'];
    do {
      $data = $this->getJson('/drives', $query);
      foreach ($data['drives'] ?? [] as $drive) {
        $drives[$drive['id']] = $drive['name'];
      }
      $query['pageToken'] = $data['nextPageToken'] ?? NULL;
    } while ($query['pageToken']);
    return $drives;
  }

  /**
   * The files in a shared drive (not trashed), optionally of some types only.
   *
   * @param string $drive_id
   *   The shared drive ID.
   * @param string[] $mime_types
   *   Only list these MIME types; empty for every file except folders.
   *   Filtering here, not afterwards, keeps big drives full of images and
   *   design files quick to list.
   *
   * @return array
   *   [file id => [id, name, mimeType, modifiedTime, webViewLink, size,
   *   parents]].
   */
  public function filesInDrive(string $drive_id, array $mime_types = []): array {
    $files = [];
    $types = $mime_types
      ? '(' . implode(' or ', array_map(fn($type) => "mimeType = '" . str_replace("'", "\\'", $type) . "'", $mime_types)) . ')'
      : "mimeType != 'application/vnd.google-apps.folder'";
    $query = [
      'corpora' => 'drive',
      'driveId' => $drive_id,
      'includeItemsFromAllDrives' => 'true',
      'supportsAllDrives' => 'true',
      'q' => "trashed = false and $types",
      'pageSize' => 200,
      'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,webViewLink,size,parents)',
    ];
    do {
      $data = $this->getJson('/files', $query);
      foreach ($data['files'] ?? [] as $file) {
        $files[$file['id']] = $file;
      }
      $query['pageToken'] = $data['nextPageToken'] ?? NULL;
    } while ($query['pageToken']);
    return $files;
  }

  /**
   * Every folder in a shared drive (not trashed).
   *
   * @return array
   *   [folder id => ['name' => name, 'parent' => parent folder or drive ID]].
   */
  public function foldersInDrive(string $drive_id): array {
    $folders = [];
    $query = [
      'corpora' => 'drive',
      'driveId' => $drive_id,
      'includeItemsFromAllDrives' => 'true',
      'supportsAllDrives' => 'true',
      'q' => "trashed = false and mimeType = 'application/vnd.google-apps.folder'",
      'pageSize' => 1000,
      'fields' => 'nextPageToken,files(id,name,parents)',
    ];
    do {
      $data = $this->getJson('/files', $query);
      foreach ($data['files'] ?? [] as $folder) {
        $folders[$folder['id']] = ['name' => $folder['name'], 'parent' => $folder['parents'][0] ?? $drive_id];
      }
      $query['pageToken'] = $data['nextPageToken'] ?? NULL;
    } while ($query['pageToken']);
    return $folders;
  }

  /**
   * Downloads a file's content (for files stored in Drive, such as PDFs).
   */
  public function download(string $file_id): string {
    return $this->request('/files/' . rawurlencode($file_id), ['alt' => 'media', 'supportsAllDrives' => 'true']);
  }

  /**
   * Exports a Google Docs, Sheets or Slides file to another format.
   */
  public function export(string $file_id, string $mime_type): string {
    return $this->request('/files/' . rawurlencode($file_id) . '/export', ['mimeType' => $mime_type]);
  }

  /**
   * GETs an API path and decodes the JSON.
   */
  protected function getJson(string $path, array $query): array {
    $data = json_decode($this->request($path, $query), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException("Google Drive returned invalid JSON for $path.");
    }
    return $data;
  }

  /**
   * GETs an API path and returns the body.
   */
  protected function request(string $path, array $query): string {
    // Google asks for 5xx and 429 responses to be retried with exponential
    // backoff: 1, 2, 4, 8 and 16 seconds, plus up to a second of jitter.
    for ($attempt = 0; ; $attempt++) {
      try {
        $response = $this->httpClient->request('GET', self::API . $path, [
          'query' => $query,
          'headers' => ['Authorization' => 'Bearer ' . $this->auth->token()],
          'timeout' => 120,
          'http_errors' => FALSE,
        ]);
        $status = $response->getStatusCode();
      }
      catch (GuzzleException $e) {
        if ($attempt >= self::RETRIES) {
          throw new \RuntimeException('Could not reach Google Drive: ' . $e->getMessage(), 0, $e);
        }
        $status = 0;
        $response = NULL;
      }
      $this->tick();
      if ($attempt < self::RETRIES && $this->isTemporary($status, $response)) {
        $this->wait((2 ** $attempt) + random_int(0, 1000) / 1000);
        continue;
      }
      break;
    }
    $body = (string) $response->getBody();
    if ($response->getStatusCode() >= 400) {
      $error = json_decode($body, TRUE);
      throw new \RuntimeException(sprintf('Google Drive returned HTTP %d for %s: %s',
        $response->getStatusCode(), $path, $error['error']['message'] ?? substr($body, 0, 200)), $response->getStatusCode());
    }
    return $body;
  }

  /**
   * Whether an error is worth retrying: network, 5xx, or a rate limit.
   *
   * Drive reports rate limits as 429, or as 403 with a rate-limit reason.
   */
  protected function isTemporary(int $status, $response): bool {
    if ($status === 0 || $status === 429 || $status >= 500) {
      return TRUE;
    }
    if ($status === 403 && $response) {
      $error = json_decode((string) $response->getBody(), TRUE);
      $response->getBody()->rewind();
      $reason = $error['error']['errors'][0]['reason'] ?? '';
      return in_array($reason, self::RATE_LIMIT_REASONS, TRUE);
    }
    return FALSE;
  }

  /**
   * Sleeps, calling the tick every few seconds.
   */
  protected function wait(float $seconds): void {
    $end = microtime(TRUE) + $seconds;
    while (($left = $end - microtime(TRUE)) > 0) {
      usleep((int) (min($left, 5) * 1000000));
      $this->tick();
    }
  }

  /**
   * Calls the tick callback, if any.
   */
  protected function tick(): void {
    if ($this->tick) {
      ($this->tick)();
    }
  }

}
