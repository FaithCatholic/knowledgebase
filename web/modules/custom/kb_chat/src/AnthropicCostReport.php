<?php

namespace Drupal\kb_chat;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the month-to-date cost from Anthropic's cost report.
 *
 * Uses the Admin API key in $settings['kb_chat']['anthropic_admin_key']
 * (ANTHROPIC_ADMIN_API_KEY), which is a different key type from the chat key.
 * If $settings['kb_chat']['anthropic_workspace_id'] is set, only that
 * workspace's costs count, so other use of the organization is left out.
 *
 * Anthropic reports costs within about five minutes, in daily buckets, as
 * decimal strings in cents.
 */
class AnthropicCostReport {

  const URL = 'https://api.anthropic.com/v1/organizations/cost_report';

  public function __construct(
    protected ClientInterface $httpClient,
    protected CostTracker $costs,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether an Admin API key is configured.
   */
  public function isConfigured(): bool {
    return (bool) (Settings::get('kb_chat')['anthropic_admin_key'] ?? '');
  }

  /**
   * Fetches this month's total and saves it as Anthropic's billed figure.
   *
   * @return float|null
   *   The month-to-date cost in USD, or NULL on failure (which is logged and
   *   saved with the figure, so the block can show it).
   */
  public function refresh(): ?float {
    if (!$this->isConfigured()) {
      return NULL;
    }
    $settings = Settings::get('kb_chat');
    $workspace = $settings['anthropic_workspace_id'] ?? '';
    $now = time();
    $query = [
      'starting_at' => gmdate('Y-m-d\T00:00:00\Z', $this->costs->monthStart()),
      // Buckets ending before tomorrow, so today's partial day is included.
      'ending_at' => gmdate('Y-m-d\T00:00:00\Z', $now + 86400),
      'bucket_width' => '1d',
      'limit' => 31,
    ];
    if ($workspace) {
      $query['group_by'] = ['workspace_id'];
    }

    $cents = 0.0;
    try {
      do {
        $data = $this->get($query, $settings['anthropic_admin_key']);
        foreach ($data['data'] ?? [] as $bucket) {
          foreach ($bucket['results'] ?? [] as $result) {
            if ($workspace && ($result['workspace_id'] ?? NULL) !== $workspace) {
              continue;
            }
            $cents += (float) ($result['amount'] ?? 0);
          }
        }
        $query['page'] = $data['next_page'] ?? NULL;
      } while (!empty($data['has_more']) && $query['page']);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not read the Anthropic cost report: @message', ['@message' => $e->getMessage()]);
      $previous = $this->costs->billed('anthropic') ?? [];
      $this->costs->setBilled('anthropic', ['error' => $e->getMessage(), 'checked' => $now] + $previous);
      return NULL;
    }

    $amount = round($cents / 100, 2);
    $this->costs->setBilled('anthropic', [
      'amount' => $amount,
      'as_of' => $now,
      'checked' => $now,
    ]);
    return $amount;
  }

  /**
   * GETs one page of the cost report.
   */
  protected function get(array $query, string $key): array {
    // group_by[]=workspace_id, the array form Anthropic expects.
    $query_string = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query));
    try {
      $response = $this->httpClient->request('GET', self::URL . '?' . $query_string, [
        'headers' => [
          'x-api-key' => $key,
          'anthropic-version' => '2023-06-01',
          'Accept' => 'application/json',
          'User-Agent' => 'kb_chat (Drupal)',
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Could not reach Anthropic: ' . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() >= 400 || !is_array($data)) {
      throw new \RuntimeException($data['error']['message'] ?? 'Anthropic returned HTTP ' . $response->getStatusCode());
    }
    return $data;
  }

}
