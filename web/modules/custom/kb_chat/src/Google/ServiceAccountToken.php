<?php

namespace Drupal\kb_chat\Google;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Access tokens for a Google service account, from its JSON key.
 *
 * Signs a JWT with the account's private key and exchanges it for an access
 * token for one OAuth scope, reusing the token until shortly before it
 * expires. Used by the Drive and BigQuery clients.
 */
class ServiceAccountToken {

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
   * @param string $scope
   *   The OAuth scope, such as https://www.googleapis.com/auth/drive.readonly.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    string $keyJson,
    protected string $scope,
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
   * A valid access token, exchanging a signed JWT for a new one as needed.
   */
  public function token(): string {
    if ($this->token && time() < $this->expires - 60) {
      return $this->token;
    }
    $now = time();
    $token_uri = $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $encode = fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
      . '.' . $encode(json_encode([
        'iss' => $this->key['client_email'],
        'scope' => $this->scope,
        'aud' => $token_uri,
        'iat' => $now,
        'exp' => $now + 3600,
      ]));
    if (!openssl_sign($unsigned, $signature, $this->key['private_key'], OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Could not sign the Google service account token. Is the private key valid?');
    }
    try {
      $response = $this->httpClient->request('POST', $token_uri, [
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
