<?php

namespace Drupal\kb_chat;

use Drupal\ai\Event\AiProviderResponseBaseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Keeps each user's FAITH Search Agent conversations for "My searches".
 *
 * One row per answered question. Two steps, because no single place knows
 * everything: UsageSubscriber hands every chat response to capture(), which
 * notes the question and whether the response is a final answer (no tool
 * calls); the chat's deepchat_prepend_message hook then calls save() with the
 * conversation's thread ID and the answer as shown.
 */
class ConversationLog {

  const TABLE = 'kb_chat_conversation';

  /**
   * Days conversations are kept.
   */
  const KEEP_DAYS = 30;

  /**
   * The last chat response in this request: question and whether it's final.
   */
  protected ?array $last = NULL;

  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * Notes a chat response's question and whether it is a final answer.
   */
  public function capture(AiProviderResponseBaseEvent $event): void {
    $output = $event->getOutput();
    $input = $event->getInput();
    if (!$output instanceof ChatOutput || !$input instanceof ChatInput) {
      return;
    }
    $message = $output->getNormalized();
    if (!$message instanceof ChatMessage) {
      return;
    }
    // The question is the latest user message: tool results and earlier
    // turns come with it, but under other roles or before it.
    $question = '';
    foreach (array_reverse($input->getMessages()) as $candidate) {
      if ($candidate->getRole() === 'user') {
        $question = trim((string) $candidate->getText());
        break;
      }
    }
    $this->last = [
      'question' => $question,
      'final' => empty($message->getTools()),
    ];
  }

  /**
   * Saves the current answer, if the last response was a final answer.
   */
  public function save(string $thread, string $answer): void {
    $answer = trim($answer);
    if (!$this->last || !$this->last['final'] || $this->last['question'] === '' || $answer === '' || $thread === '' || $this->currentUser->isAnonymous()) {
      return;
    }
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => (int) $this->currentUser->id(),
        'thread' => mb_substr($thread, 0, 128),
        'question' => $this->last['question'],
        'answer' => $answer,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->last = NULL;
  }

  /**
   * A user's conversations, newest first.
   *
   * @return array
   *   Rows with thread, question (the first one), questions (count), started
   *   and updated.
   */
  public function conversations(int $uid): array {
    $query = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['thread'])
      ->condition('uid', $uid)
      ->groupBy('thread');
    $query->addExpression('MIN([id])', 'first_id');
    $query->addExpression('COUNT(*)', 'questions');
    $query->addExpression('MIN([created])', 'started');
    $query->addExpression('MAX([created])', 'updated');
    $query->orderBy('updated', 'DESC');
    $rows = $query->execute()->fetchAllAssoc('first_id', \PDO::FETCH_ASSOC);
    if (!$rows) {
      return [];
    }
    $first = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['id', 'question'])
      ->condition('id', array_keys($rows), 'IN')
      ->execute()
      ->fetchAllKeyed();
    foreach ($rows as $id => &$row) {
      $row['question'] = $first[$id] ?? '';
    }
    return array_values($rows);
  }

  /**
   * The questions and answers of one of a user's conversations, in order.
   */
  public function conversation(int $uid, string $thread): array {
    return $this->database->select(self::TABLE, 'c')
      ->fields('c', ['question', 'answer', 'created'])
      ->condition('uid', $uid)
      ->condition('thread', $thread)
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Deletes one of a user's conversations.
   */
  public function delete(int $uid, string $thread): void {
    $this->database->delete(self::TABLE)
      ->condition('uid', $uid)
      ->condition('thread', $thread)
      ->execute();
  }

  /**
   * Deletes conversations older than KEEP_DAYS.
   */
  public function purge(): void {
    $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getRequestTime() - self::KEEP_DAYS * 86400, '<')
      ->execute();
  }

}
