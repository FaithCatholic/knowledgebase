<?php

namespace Drupal\kb_chat\Drive;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal read-only Google Drive client for one service account.
 *
 * Signs in with the service account's JSON key (a signed JWT exchanged for an
 * access token, scope drive.readonly) and lists and reads files. One instance
 * per account; DriveSync creates them from $settings['kb_chat']['drive'].
 */
class GoogleDriveClient {

  const API = 'https://www.googleapis.com/drive/v3';

  const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

  /**
   * The current access token and when it expires.
   */
  protected ?string $token = NULL;

  protected int $expires = 0;

  /**
   * The decoded service account key.
   */
  protected array $key;

  /**
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param string $keyJson
   *   The service account's JSON key file contents.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    string $keyJson,
  ) {
    $key = json_decode($keyJson, TRUE);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
      throw new \InvalidArgumentException('The Google service account key is missing or not valid JSON.');
    }
    $this->key = $key;
  }

  /**
   * The service account's email address.
   */
  public function email(): string {
    return $this->key['client_email'];
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
   * Every file in a shared drive (not trashed, not folders).
   *
   * @return array
   *   [file id => [id, name, mimeType, modifiedTime, webViewLink, size]].
   */
  public function filesInDrive(string $drive_id): array {
    $files = [];
    $query = [
      'corpora' => 'drive',
      'driveId' => $drive_id,
      'includeItemsFromAllDrives' => 'true',
      'supportsAllDrives' => 'true',
      'q' => "trashed = false and mimeType != 'application/vnd.google-apps.folder'",
      'pageSize' => 1000,
      'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,webViewLink,size)',
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
    try {
      $response = $this->httpClient->request('GET', self::API . $path, [
        'query' => $query,
        'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
        'timeout' => 120,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Could not reach Google Drive: ' . $e->getMessage(), 0, $e);
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
   * A valid access token, exchanging a signed JWT for a new one as needed.
   */
  protected function accessToken(): string {
    if ($this->token && time() < $this->expires - 60) {
      return $this->token;
    }
    $now = time();
    $encode = fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
      . '.' . $encode(json_encode([
        'iss' => $this->key['client_email'],
        'scope' => self::SCOPE,
        'aud' => $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
      ]));
    if (!openssl_sign($unsigned, $signature, $this->key['private_key'], OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Could not sign the Google service account token. Is the private key valid?');
    }
    try {
      $response = $this->httpClient->request('POST', $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion' => $unsigned . '.' . $encode($signature),
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Could not reach Google sign-in: ' . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if (empty($data['access_token'])) {
      throw new \RuntimeException('Google sign-in failed for ' . $this->key['client_email'] . ': ' . ($data['error_description'] ?? $data['error'] ?? 'no token returned'));
    }
    $this->token = $data['access_token'];
    $this->expires = $now + (int) ($data['expires_in'] ?? 3600);
    return $this->token;
  }

}
