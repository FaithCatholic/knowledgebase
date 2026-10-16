<?php

namespace Drupal\kb_chat\Trello;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Minimal read-only Trello client, limited to an allowlist of boards.
 *
 * Authenticates with an API key and token for one Trello account. Every
 * search and listing is restricted to the allowed boards, and cards from any
 * other board are dropped, so the chat only sees boards on the list even if
 * the account belongs to more.
 */
class TrelloClient {

  const API = 'https://api.trello.com/1';

  /**
   * Card fields returned to the chat.
   */
  const CARD_FIELDS = 'name,desc,due,dueComplete,start,idList,idBoard,idMembers,labels,shortUrl,dateLastActivity,closed';

  /**
   * The allowed boards: [board id => name], resolved on first use.
   */
  protected ?array $boards = NULL;

  /**
   * Lists and members by ID, filled as boards are read.
   */
  protected array $lists = [];

  protected array $members = [];

  /**
   * @param string[] $boardNames
   *   Names (or IDs) of the boards the chat may read.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected string $key,
    protected string $token,
    protected array $boardNames,
  ) {}

  /**
   * The allowed boards the account can see: [id => name].
   *
   * @return array
   *   Boards matched by name (case-insensitive) or ID. Names with no match
   *   are left out; see missingBoards().
   */
  public function boards(): array {
    if ($this->boards === NULL) {
      $wanted = array_map(fn($name) => mb_strtolower(trim($name)), $this->boardNames);
      $this->boards = [];
      foreach ($this->get('/members/me/boards', ['fields' => 'name,closed', 'filter' => 'open']) as $board) {
        if (in_array(mb_strtolower($board['name']), $wanted, TRUE) || in_array(mb_strtolower($board['id']), $wanted, TRUE)) {
          $this->boards[$board['id']] = $board['name'];
        }
      }
    }
    return $this->boards;
  }

  /**
   * Configured board names the account can't see (not a member, or renamed).
   */
  public function missingBoards(): array {
    $found = array_map('mb_strtolower', array_merge(array_keys($this->boards()), array_values($this->boards())));
    return array_values(array_filter($this->boardNames, fn($name) => !in_array(mb_strtolower(trim($name)), $found, TRUE)));
  }

  /**
   * Searches open cards on the allowed boards.
   *
   * @param string $query
   *   Trello search text; supports operators such as due:week, due:overdue,
   *   list:"In Progress", label:urgent and @member.
   * @param string|null $board
   *   Limit to one allowed board, by name.
   * @param int $limit
   *   Most cards to return.
   */
  public function searchCards(string $query, ?string $board, int $limit): array {
    $ids = $this->boardIds($board);
    if (!$ids) {
      return [];
    }
    $data = $this->get('/search', [
      'query' => $query,
      'modelTypes' => 'cards',
      'idBoards' => implode(',', $ids),
      'cards_limit' => $limit,
      'card_fields' => self::CARD_FIELDS,
      'card_list' => 'true',
      'card_members' => 'true',
      'partial' => 'true',
    ]);
    return $this->describeCards($data['cards'] ?? []);
  }

  /**
   * The open cards on one allowed board, in list order.
   */
  public function boardCards(string $board, int $limit): array {
    $ids = $this->boardIds($board);
    if (!$ids) {
      return [];
    }
    $id = reset($ids);
    foreach ($this->get("/boards/$id/lists", ['filter' => 'open', 'fields' => 'name,pos']) as $list) {
      $this->lists[$list['id']] = $list['name'];
    }
    $cards = $this->get("/boards/$id/cards", ['filter' => 'open', 'fields' => self::CARD_FIELDS, 'members' => 'true', 'member_fields' => 'fullName']);
    $order = array_flip(array_keys($this->lists));
    usort($cards, fn($a, $b) => ($order[$a['idList']] ?? 999) <=> ($order[$b['idList']] ?? 999));
    return $this->describeCards(array_slice($cards, 0, $limit));
  }

  /**
   * The latest comments on a card, newest first.
   */
  public function comments(string $card_id, int $limit = 3): array {
    $comments = [];
    foreach ($this->get('/cards/' . rawurlencode($card_id) . '/actions', ['filter' => 'commentCard', 'limit' => $limit]) as $action) {
      $comments[] = [
        'date' => substr($action['date'] ?? '', 0, 10),
        'author' => $action['memberCreator']['fullName'] ?? '',
        'text' => $action['data']['text'] ?? '',
      ];
    }
    return $comments;
  }

  /**
   * Allowed board IDs, optionally just the one named.
   */
  protected function boardIds(?string $board): array {
    $boards = $this->boards();
    if ($board === NULL || trim($board) === '') {
      return array_keys($boards);
    }
    $wanted = mb_strtolower(trim($board));
    // Exact name first, then a name containing the text.
    foreach ([fn($name) => mb_strtolower($name) === $wanted, fn($name) => str_contains(mb_strtolower($name), $wanted)] as $match) {
      $ids = array_keys(array_filter($boards, $match));
      if ($ids) {
        return $ids;
      }
    }
    return [];
  }

  /**
   * Turns API cards into plain arrays, dropping any from other boards.
   */
  protected function describeCards(array $cards): array {
    $boards = $this->boards();
    $result = [];
    foreach ($cards as $card) {
      if (!isset($boards[$card['idBoard'] ?? '']) || !empty($card['closed'])) {
        continue;
      }
      if (isset($card['list']['id'])) {
        $this->lists[$card['list']['id']] = $card['list']['name'];
      }
      foreach ($card['members'] ?? [] as $member) {
        $this->members[$member['id']] = $member['fullName'] ?? $member['username'] ?? '';
      }
      $result[] = [
        'id' => $card['id'],
        'name' => $card['name'],
        'board' => $boards[$card['idBoard']],
        'list' => $this->lists[$card['idList']] ?? '',
        'due' => $card['due'] ? substr($card['due'], 0, 10) : '',
        'due_complete' => !empty($card['dueComplete']),
        'start' => !empty($card['start']) ? substr($card['start'], 0, 10) : '',
        'members' => array_values(array_filter(array_map(fn($id) => $this->members[$id] ?? NULL, $card['idMembers'] ?? []))),
        'labels' => array_values(array_filter(array_map(fn($label) => $label['name'] ?: ($label['color'] ?? ''), $card['labels'] ?? []))),
        'description' => $card['desc'] ?? '',
        'last_activity' => substr($card['dateLastActivity'] ?? '', 0, 10),
        'url' => $card['shortUrl'] ?? '',
      ];
    }
    return $result;
  }

  /**
   * GETs an API path and decodes the JSON.
   */
  protected function get(string $path, array $query = []): array {
    try {
      $response = $this->httpClient->request('GET', self::API . $path, [
        'query' => $query,
        'headers' => [
          'Authorization' => sprintf('OAuth oauth_consumer_key="%s", oauth_token="%s"', $this->key, $this->token),
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Could not reach Trello: ' . $e->getMessage(), 0, $e);
    }
    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if ($response->getStatusCode() >= 400 || !is_array($data)) {
      throw new \RuntimeException(sprintf('Trello returned HTTP %d: %s', $response->getStatusCode(), substr($body, 0, 200)));
    }
    return $data;
  }

}
