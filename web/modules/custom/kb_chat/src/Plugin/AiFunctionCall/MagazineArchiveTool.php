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
 * Searches the magazine archive (catholicmagazines.org) for the kb chat.
 *
 * Calls the archive site's /api/archive-search endpoint (archive_search_api
 * module), which runs the query through that site's Solr index of every
 * issue's PDF text. Configured by $settings['kb_chat']['magazine_archive'].
 */
#[FunctionCall(
  id: 'kb_chat:magazine_archive_search',
  function_name: 'kb_chat_magazine_archive_search',
  name: 'Search the magazine archive',
  description: 'Full-text search of every magazine issue FAITH Catholic has published for its clients (the PDF text, plus issue, client and publication names). Use it for questions about past issues, articles, or what was published for a client. It is a keyword search: use distinctive words or names, and try other wordings if the first search finds nothing.',
  group: 'information_tools',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Keywords'),
      description: new TranslatableMarkup('Keywords to search for. Put a phrase in double quotes to match it exactly.'),
      required: TRUE,
    ),
    'client' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Client'),
      description: new TranslatableMarkup('Optional. Part of a client name (a diocese or parish) to limit the search to.'),
      required: FALSE,
    ),
    'from_year' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('From year'),
      description: new TranslatableMarkup('Optional. The earliest publication year.'),
      required: FALSE,
    ),
    'to_year' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('To year'),
      description: new TranslatableMarkup('Optional. The latest publication year.'),
      required: FALSE,
    ),
  ],
)]
class MagazineArchiveTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

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
    $settings = Settings::get('kb_chat')['magazine_archive'] ?? [];
    if (empty($settings['url']) || empty($settings['token'])) {
      $this->setOutput('The magazine archive search is not configured.');
      return;
    }
    $query = array_filter([
      'q' => (string) $this->getContextValue('query'),
      'client' => (string) ($this->getContextValue('client') ?? ''),
      'from' => (int) ($this->getContextValue('from_year') ?? 0),
      'to' => (int) ($this->getContextValue('to_year') ?? 0),
      'limit' => (int) ($settings['limit'] ?? 8),
    ]);

    try {
      $response = $this->httpClient->request('GET', rtrim($settings['url'], '/') . '/api/archive-search', [
        'query' => $query,
        'headers' => ['X-Archive-Token' => $settings['token'], 'Accept' => 'application/json'],
        'timeout' => 20,
        'http_errors' => FALSE,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->setOutput('The magazine archive could not be reached: ' . $e->getMessage());
      return;
    }
    if ($response->getStatusCode() !== 200 || !is_array($data)) {
      $this->setOutput('The magazine archive search failed: ' . ($data['error'] ?? 'HTTP ' . $response->getStatusCode()));
      return;
    }

    $results = $data['results'] ?? [];
    $header = sprintf('Magazine archive results for "%s"%s: %d found, showing %d.',
      $data['query'] ?? $query['q'],
      !empty($data['notes']) ? ' (' . implode('; ', $data['notes']) . ')' : '',
      $data['total'] ?? count($results),
      count($results));
    if (!$results) {
      $this->setOutput($header . "\nNo issues matched. Try other keywords, a shorter query, or no client or year filter.");
      return;
    }
    $output = $header . "\nCite issues as Markdown links to their URL, and mention the PDF link when useful.\n\n";
    foreach ($results as $i => $result) {
      $output .= 'Result #' . ($i + 1) . "\n";
      foreach ([
        'Title' => $result['title'] ?? '',
        'Issue' => $result['issue'] ?? '',
        'Client' => $result['client'] ?? '',
        'Publication' => $result['publication'] ?? '',
        'Special issue' => $result['special_issue'] ?? '',
        'Date' => $result['date'] ?? '',
        'URL' => $result['url'] ?? '',
        'PDF' => $result['pdf_url'] ?? '',
      ] as $label => $value) {
        if ($value !== '') {
          $output .= "$label: $value\n";
        }
      }
      if (!empty($result['excerpt'])) {
        $output .= "Excerpt:\n```\n" . $result['excerpt'] . "\n```\n";
      }
      $output .= "\n";
    }
    $this->setOutput($output);
  }

}
