<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\Trello\TrelloClient;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches FAITH's Trello boards, live, for the kb chat.
 *
 * Only boards on the allowlist in $settings['kb_chat']['trello']['boards']
 * are searched: the "staff" list for everyone, plus the "management" list for
 * users with the management documents permission.
 */
#[FunctionCall(
  id: 'kb_chat:trello_search',
  function_name: 'kb_chat_trello_search',
  name: 'Search Trello',
  description: 'Searches FAITH\'s Trello boards live: project and task cards with their board, list (the status column), due date, members, labels, description and recent comments. Use it for questions about project status, deadlines, who is working on what, and work in progress. Give keywords in query; Trello search operators work, e.g. due:week, due:overdue, list:"In Progress", label:urgent, @name. To see everything on one board grouped by list, leave query empty and give the board name. Call with no query and no board to get the list of boards.',
  group: 'information_tools',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Query'),
      description: new TranslatableMarkup('Keywords and Trello search operators. Leave empty to list a board or the boards.'),
      required: FALSE,
    ),
    'board' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Board'),
      description: new TranslatableMarkup('Optional. A board name (or part of one) to search or list.'),
      required: FALSE,
    ),
  ],
)]
class TrelloSearchTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Cards returned by a search, and by a board listing.
   */
  const SEARCH_LIMIT = 15;

  const BOARD_LIMIT = 100;

  /**
   * How many top search results get their latest comments.
   */
  const COMMENTED = 5;

  protected ClientInterface $httpClient;

  protected AccountProxyInterface $currentUser;

  protected LoggerInterface $logger;

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
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.channel.kb_chat');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $settings = Settings::get('kb_chat')['trello'] ?? [];
    if (empty($settings['key']) || empty($settings['token'])) {
      $this->setOutput('Trello is not configured.');
      return;
    }
    $boards = $settings['boards']['staff'] ?? [];
    if ($this->currentUser->hasPermission('view kb chat management documents')) {
      $boards = array_merge($boards, $settings['boards']['management'] ?? []);
    }
    elseif (!$this->currentUser->hasPermission('view kb chat staff documents')) {
      $this->setOutput('You do not have access to Trello.');
      return;
    }
    $client = new TrelloClient($this->httpClient, $settings['key'], $settings['token'], $boards);

    $query = trim((string) ($this->getContextValue('query') ?? ''));
    $board = trim((string) ($this->getContextValue('board') ?? ''));
    try {
      if ($query === '' && $board === '') {
        $this->setOutput("Trello boards you can search:\n- " . implode("\n- ", $client->boards()));
        return;
      }
      $cards = $query === ''
        ? $client->boardCards($board, self::BOARD_LIMIT)
        : $client->searchCards($query, $board ?: NULL, self::SEARCH_LIMIT);
      $this->setOutput($this->format($client, $cards, $query, $board));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Trello search failed: @message', ['@message' => $e->getMessage()]);
      $this->setOutput('Trello could not be searched: ' . $e->getMessage());
    }
  }

  /**
   * Formats cards for the model, with comments on the top search results.
   */
  protected function format(TrelloClient $client, array $cards, string $query, string $board): string {
    $scope = $board !== '' ? " on boards matching \"$board\"" : '';
    if (!$cards) {
      return "No open Trello cards found for \"$query\"$scope. Try other keywords, fewer operators, or a different board.";
    }
    $listing = $query === '';
    $output = ($listing ? "Open cards$scope, grouped by list:" : "Trello cards for \"$query\"$scope:")
      . "\nCite cards as Markdown links to their URL. Today is " . date('Y-m-d') . ".\n\n";
    foreach ($cards as $i => $card) {
      $details = array_filter([
        'Board' => $card['board'],
        'List' => $card['list'],
        'Due' => $card['due'] ? $card['due'] . ($card['due_complete'] ? ' (done)' : '') : '',
        'Start' => $card['start'],
        'Members' => implode(', ', $card['members']),
        'Labels' => implode(', ', $card['labels']),
        'Last activity' => $card['last_activity'],
        'URL' => $card['url'],
      ]);
      $output .= "Card: {$card['name']}\n";
      foreach ($details as $label => $value) {
        $output .= "$label: $value\n";
      }
      if (!$listing && $card['description'] !== '') {
        $output .= "Description: " . mb_strimwidth(preg_replace('/\s+/', ' ', $card['description']), 0, 600, '…') . "\n";
      }
      if (!$listing && $i < self::COMMENTED) {
        foreach ($client->comments($card['id']) as $comment) {
          $output .= sprintf("Comment (%s, %s): %s\n", $comment['date'], $comment['author'], mb_strimwidth(preg_replace('/\s+/', ' ', $comment['text']), 0, 300, '…'));
        }
      }
      $output .= "\n";
    }
    return $output;
  }

}
