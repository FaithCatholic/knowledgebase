<?php

namespace Drupal\chargebee_tools;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal client for the Chargebee REST API (v2).
 *
 * Knows two Chargebee sites, "test" and "live", from
 * $settings['chargebee_tools']['sites'], which settings.php reads from
 * environment variables. Every call names the site it goes to.
 *
 * Parameters are sent form-encoded, the way Chargebee expects. Nested
 * parameters use Chargebee's own key format, for example
 * 'subscription_items[item_price_id][0]'.
 */
class ChargebeeClient {

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * The configured sites, keyed test and live.
   *
   * @return array
   *   Each site is ['site' => subdomain, 'api_key' => key].
   */
  public function sites(): array {
    return Settings::get('chargebee_tools')['sites'] ?? [];
  }

  /**
   * Whether a site has both a subdomain and an API key.
   */
  public function isConfigured(string $site): bool {
    $config = $this->sites()[$site] ?? [];
    return !empty($config['site']) && !empty($config['api_key']);
  }

  /**
   * The Chargebee subdomain of a site, such as faithcatholic-test.
   */
  public function subdomain(string $site): string {
    return (string) ($this->sites()[$site]['site'] ?? '');
  }

  /**
   * GETs an API path, such as /subscriptions/abc, and returns the JSON.
   *
   * @throws \Drupal\chargebee_tools\ChargebeeException
   */
  public function get(string $site, string $path, array $query = []): array {
    return $this->request($site, 'GET', $path, $query ? ['query' => $query] : []);
  }

  /**
   * POSTs form parameters to an API path and returns the JSON.
   *
   * @throws \Drupal\chargebee_tools\ChargebeeException
   */
  public function post(string $site, string $path, array $params = []): array {
    return $this->request($site, 'POST', $path, ['form_params' => $params]);
  }

  /**
   * Builds an API path from parts, encoding each one.
   *
   * ChargebeeClient::path('subscriptions', $id, 'change_term_end') returns
   * /subscriptions/{id}/change_term_end.
   */
  public static function path(string ...$parts): string {
    return '/' . implode('/', array_map('rawurlencode', $parts));
  }

  /**
   * Sends a request and decodes the response.
   */
  protected function request(string $site, string $method, string $path, array $options): array {
    if (!$this->isConfigured($site)) {
      throw new ChargebeeException("The Chargebee $site site is not configured.");
    }
    $config = $this->sites()[$site];
    $url = 'https://' . $config['site'] . '.chargebee.com/api/v2' . $path;
    $options += [
      // Chargebee uses the API key as the basic auth username, with no password.
      'auth' => [$config['api_key'], ''],
      'headers' => ['Accept' => 'application/json'],
      'timeout' => 60,
      'http_errors' => FALSE,
    ];
    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new ChargebeeException('Could not reach Chargebee: ' . $e->getMessage(), 0, $e);
    }
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($status >= 400 || !is_array($data)) {
      throw new ChargebeeException($data['message'] ?? "Chargebee returned HTTP $status.", $status);
    }
    return $data;
  }

}
