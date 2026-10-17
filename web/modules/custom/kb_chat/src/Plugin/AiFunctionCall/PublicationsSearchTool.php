<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches the FAITH publications on the mothership for the kb chat.
 *
 * Calls the mothership's /api/kb-search endpoint (kb_search_api module in the
 * faithmagazine repo), which runs the query through that site's Solr index of
 * every published article on every domain: Faith Syndicated, Content
 * Evangelist, FAITH Magazine and the diocesan publications. Configured by
 * $settings['kb_chat']['mothership'].
 */
#[FunctionCall(
  id: 'kb_chat:publications_search',
  function_name: 'kb_chat_publications_search',
  name: 'Search FAITH publications',
  description: 'Full-text search of the articles published on the FAITH publication websites run from one platform: Faith Syndicated (faithsyndicated.org, the syndicated content service), Content Evangelist (contentevangelist.com), FAITH Magazine (faithmag.com) and the diocesan publications FAITH produces for clients. Use it for questions about published articles, what has been written on a topic, or what a publication has run. It is a keyword search: use distinctive words or names, and try other wordings if the first search finds nothing.',
  group: 'information_tools',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Keywords'),
      description: new TranslatableMarkup('Keywords to search for. Put a phrase in double quotes to match it exactly.'),
      required: TRUE,
    ),
    'publications' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Publications'),
      description: new TranslatableMarkup('Optional. Comma-separated publication names or web addresses to limit the search to, e.g. "Faith Syndicated" or "contentevangelist.com". Leave empty to search them all.'),
      required: FALSE,
    ),
    'from_year' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('From year'),
      description: new TranslatableMarkup('Optional. The earliest year published.'),
      required: FALSE,
    ),
    'to_year' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('To year'),
      description: new TranslatableMarkup('Optional. The latest year published.'),
      required: FALSE,
    ),
  ],
)]
class PublicationsSearchTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $settings = Settings::get('kb_chat')['mothership'] ?? [];
    if (empty($settings['url']) || empty($settings['token'])) {
      $this->setOutput('The FAITH publications search is not configured.');
      return;
    }
    $query = array_filter([
      'q' => (string) $this->getContextValue('query'),
      'publications' => (string) ($this->getContextValue('publications') ?? ''),
      'from' => (int) ($this->getContextValue('from_year') ?? 0),
      'to' => (int) ($this->getContextValue('to_year') ?? 0),
      'limit' => (int) ($settings['limit'] ?? 8),
    ]);

    try {
      $response = $this->httpClient->request('GET', rtrim($settings['url'], '/') . '/api/kb-search', [
        'query' => $query,
        'headers' => ['X-KB-Search-Token' => $settings['token'], 'Accept' => 'application/json'],
        'timeout' => 20,
        'http_errors' => FALSE,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->setOutput('The FAITH publications search could not be reached: ' . $e->getMessage());
      return;
    }
    if ($response->getStatusCode() !== 200 || !is_array($data)) {
      $this->setOutput('The FAITH publications search failed: ' . ($data['error'] ?? 'HTTP ' . $response->getStatusCode()));
      return;
    }

    $results = $data['results'] ?? [];
    $header = sprintf('FAITH publications results for "%s"%s: %d found, showing %d.',
      $data['query'] ?? $query['q'],
      !empty($data['notes']) ? ' (' . implode('; ', $data['notes']) . ')' : '',
      $data['total'] ?? count($results),
      count($results));
    if (!$results) {
      $this->setOutput($header . "\nNo articles matched. Try other keywords, a shorter query, or no publication or year filter.");
      return;
    }
    $output = $header . "\nThese are FAITH's own published articles. Cite them as Markdown links to their URL.\n\n";
    foreach ($results as $i => $result) {
      $output .= 'Result #' . ($i + 1) . "\n";
      foreach ([
        'Title' => $result['title'] ?? '',
        'Published on' => implode(', ', $result['publications'] ?? []),
        'Type' => $result['type'] ?? '',
        'Date' => $result['date'] ?? '',
        'Author' => $result['author'] ?? '',
        'URL' => $result['url'] ?? '',
      ] as $label => $value) {
        if ($value !== '') {
          $output .= "$label: $value\n";
        }
      }
      if (!empty($result['snippet'])) {
        $output .= "Excerpt:\n```\n" . $result['snippet'] . "\n```\n";
      }
      $output .= "\n";
    }
    $this->setOutput($output);
  }

}
