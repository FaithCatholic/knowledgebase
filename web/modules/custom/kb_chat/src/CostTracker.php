<?php

namespace Drupal\kb_chat;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Records AI usage and reports month-to-date costs.
 *
 * Two kinds of figure:
 * - Estimates: kb's own, from each call's token counts and the prices in
 *   $settings['kb_chat']['prices']. Live, per call.
 * - Billed: what each provider reports. Anthropic's cost report (refreshed
 *   by AnthropicCostReport) and Google's budget notifications (pushed to
 *   GoogleBillingController). Both are kept in State.
 *
 * Months are calendar months in UTC, the way both providers bill.
 */
class CostTracker {

  const TABLE = 'kb_chat_usage';

  /**
   * State keys for the billed figures, by provider.
   */
  const BILLED_STATE = [
    'anthropic' => 'kb_chat.billed.anthropic',
    'google' => 'kb_chat.billed.google',
  ];

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Saves one provider call with its estimated cost.
   *
   * @param array $usage
   *   Keys: provider, model, operation, thread, uid, input_tokens,
   *   output_tokens, cache_read_tokens, cache_write_tokens, tokens_estimated.
   */
  public function record(array $usage): void {
    $usage += [
      'input_tokens' => 0,
      'output_tokens' => 0,
      'cache_read_tokens' => 0,
      'cache_write_tokens' => 0,
      'tokens_estimated' => 0,
    ];
    $this->database->insert(self::TABLE)
      ->fields([
        'created' => $this->time->getCurrentTime(),
        'uid' => (int) $usage['uid'],
        'provider' => mb_substr($usage['provider'], 0, 64),
        'model' => mb_substr($usage['model'], 0, 128),
        'operation' => mb_substr($usage['operation'], 0, 64),
        'thread' => mb_substr($usage['thread'], 0, 128),
        'input_tokens' => $usage['input_tokens'],
        'output_tokens' => $usage['output_tokens'],
        'cache_read_tokens' => $usage['cache_read_tokens'],
        'cache_write_tokens' => $usage['cache_write_tokens'],
        'tokens_estimated' => (int) $usage['tokens_estimated'],
        'cost' => $this->estimate($usage),
      ])
      ->execute();
  }

  /**
   * Estimates a call's cost in USD, or NULL if the model has no price.
   *
   * Prices are USD per million tokens. Input tokens are the uncached ones;
   * cache reads and writes have their own rates where the model has them.
   */
  public function estimate(array $usage): ?float {
    $price = $this->price($usage['model']);
    if (!$price) {
      return NULL;
    }
    $cost = $usage['input_tokens'] * ($price['input'] ?? 0)
      + $usage['output_tokens'] * ($price['output'] ?? 0)
      + $usage['cache_read_tokens'] * ($price['cache_read'] ?? $price['input'] ?? 0)
      + $usage['cache_write_tokens'] * ($price['cache_write'] ?? $price['input'] ?? 0);
    return $cost / 1000000;
  }

  /**
   * The month-to-date estimates, by provider.
   *
   * @return array
   *   [provider => ['cost' => float, 'calls' => int, 'unpriced' => int,
   *   'estimated_tokens' => int]]. unpriced counts calls whose model has no
   *   price in settings, so their cost is missing from the total.
   */
  public function monthEstimates(): array {
    $query = $this->database->select(self::TABLE, 'u')
      ->fields('u', ['provider'])
      ->condition('created', $this->monthStart(), '>=')
      ->groupBy('provider');
    $query->addExpression('SUM([cost])', 'cost');
    $query->addExpression('COUNT(*)', 'calls');
    $query->addExpression('SUM(CASE WHEN [cost] IS NULL THEN 1 ELSE 0 END)', 'unpriced');
    $query->addExpression('SUM([tokens_estimated])', 'estimated_tokens');
    $estimates = [];
    foreach ($query->execute() as $row) {
      $estimates[$row->provider] = [
        'cost' => (float) $row->cost,
        'calls' => (int) $row->calls,
        'unpriced' => (int) $row->unpriced,
        'estimated_tokens' => (int) $row->estimated_tokens,
      ];
    }
    return $estimates;
  }

  /**
   * Month-to-date chat requests: the number of separate request threads.
   *
   * One question can make several model calls (for example a search query,
   * then the answer), so this counts threads rather than calls.
   */
  public function monthChatRequests(): int {
    $query = $this->database->select(self::TABLE, 'u')
      ->condition('created', $this->monthStart(), '>=')
      ->condition('operation', 'chat');
    $query->addExpression('COUNT(DISTINCT [thread])', 'threads');
    return (int) $query->execute()->fetchField();
  }

  /**
   * A provider's billed figure, if one has been received this month.
   *
   * @param string $provider
   *   anthropic or google.
   *
   * @return array|null
   *   ['amount' => USD float, 'as_of' => timestamp, ...], or NULL.
   */
  public function billed(string $provider): ?array {
    $billed = $this->state->get(self::BILLED_STATE[$provider]);
    if (!$billed || ($billed['month'] ?? '') !== $this->month()) {
      return NULL;
    }
    return $billed;
  }

  /**
   * Saves a provider's billed figure for the current month.
   */
  public function setBilled(string $provider, array $billed): void {
    $this->state->set(self::BILLED_STATE[$provider], $billed + ['month' => $this->month()]);
  }

  /**
   * The current month in UTC, as YYYY-MM.
   */
  public function month(): string {
    return gmdate('Y-m', $this->time->getCurrentTime());
  }

  /**
   * Unix time at the start of the current UTC month.
   */
  public function monthStart(): int {
    return (int) strtotime($this->month() . '-01T00:00:00Z');
  }

  /**
   * The price row for a model, matched exactly or by the longest prefix.
   *
   * A prefix lets one row such as 'gemini-embedding' cover its versions.
   */
  protected function price(string $model): ?array {
    $prices = Settings::get('kb_chat')['prices'] ?? [];
    if (isset($prices[$model])) {
      return $prices[$model];
    }
    $match = NULL;
    foreach ($prices as $prefix => $price) {
      if (str_starts_with($model, $prefix) && strlen($prefix) > strlen($match ?? '')) {
        $match = $prefix;
      }
    }
    return $match === NULL ? NULL : $prices[$match];
  }

}
