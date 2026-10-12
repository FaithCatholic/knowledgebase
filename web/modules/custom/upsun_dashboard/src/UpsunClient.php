<?php

namespace Drupal\upsun_dashboard;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

/**
 * Minimal read-only client for the Upsun REST API.
 *
 * Authenticates with the API token in $settings['upsun_dashboard']['api_token'],
 * which settings.php reads from the UPSUN_API_TOKEN environment variable.
 */
class UpsunClient {

  const API_URL = 'https://api.upsun.com';

  const AUTH_URL = 'https://auth.upsun.com/oauth2/token';

  /**
   * The short-lived OAuth access token, reused until it expires.
   */
  protected ?string $accessToken = NULL;

  /**
   * Unix time the access token expires.
   */
  protected int $expires = 0;

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Whether an API token has been configured.
   */
  public function isConfigured(): bool {
    return (bool) $this->apiToken();
  }

  /**
   * GETs an API path and returns the decoded JSON.
   *
   * @param string $path
   *   A path such as /projects/abc123, or a full https://api.upsun.com URL.
   * @param array $query
   *   Query parameters. Arrays are sent as types[0]=cpu&types[1]=disk.
   */
  public function get(string $path, array $query = []): array {
    $url = str_starts_with($path, 'https://') ? $path : self::API_URL . $path;
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken(),
        'Accept' => 'application/json',
      ],
      'timeout' => 60,
    ];
    // Guzzle replaces the URL's own query string whenever this option is set,
    // even to an empty array, which would break paging and signed links.
    if ($query) {
      $options['query'] = $query;
    }
    $response = $this->httpClient->request('GET', $url, $options);
    return json_decode((string) $response->getBody(), TRUE) ?? [];
  }

  /**
   * GETs a paginated collection and returns the items from every page.
   */
  public function getAll(string $path): array {
    $items = [];
    $next = $path;
    while ($next) {
      $page = $this->get($next);
      $items = array_merge($items, $page['items'] ?? []);
      $next = $page['_links']['next']['href'] ?? NULL;
    }
    return $items;
  }

  /**
   * Exchanges the API token for an access token, or reuses the current one.
   */
  protected function accessToken(): string {
    if ($this->accessToken && time() < $this->expires - 60) {
      return $this->accessToken;
    }

    $api_token = $this->apiToken();
    if (!$api_token) {
      throw new \RuntimeException('The UPSUN_API_TOKEN environment variable is not set.');
    }

    $response = $this->httpClient->request('POST', self::AUTH_URL, [
      'auth' => ['platform-api-user', ''],
      'form_params' => [
        'grant_type' => 'api_token',
        'api_token' => $api_token,
      ],
      'timeout' => 30,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE) ?? [];
    if (empty($data['access_token'])) {
      throw new \RuntimeException('Upsun did not return an access token.');
    }

    $this->accessToken = $data['access_token'];
    $this->expires = time() + (int) ($data['expires_in'] ?? 900);
    return $this->accessToken;
  }

  /**
   * Returns the configured API token, if any.
   */
  protected function apiToken(): string {
    return (string) (Settings::get('upsun_dashboard')['api_token'] ?? '');
  }

}
