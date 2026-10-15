<?php

namespace Drupal\chargebee_tools;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores runs and their rows in the chargebee_tools_* tables.
 *
 * Rows with status "pending" are the work queue: RunProcessor takes them in
 * run and line order.
 */
class RunStorage {

  const RUN_TABLE = 'chargebee_tools_run';

  const ROW_TABLE = 'chargebee_tools_row';

  /**
   * Run statuses that still have work to do.
   */
  const ACTIVE = ['queued', 'running'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Saves a new queued run and its rows.
   *
   * @param array $run
   *   The operation, site, mode, options, filename and uid.
   * @param array $rows
   *   Each row is ['line' => int, 'input' => array, 'error' => ?string].
   *   Rows with an error are saved as skipped and never sent to Chargebee.
   *
   * @return int
   *   The new run ID.
   */
  public function create(array $run, array $rows): int {
    $transaction = $this->database->startTransaction();
    $rid = (int) $this->database->insert(self::RUN_TABLE)
      ->fields([
        'operation' => $run['operation'],
        'site' => $run['site'],
        'mode' => $run['mode'],
        'options' => json_encode($run['options']),
        'filename' => mb_substr($run['filename'], 0, 255),
        'uid' => $run['uid'],
        'status' => 'queued',
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    foreach (array_chunk($rows, 500) as $chunk) {
      $insert = $this->database->insert(self::ROW_TABLE)
        ->fields(['rid', 'line', 'input', 'status', 'message']);
      foreach ($chunk as $row) {
        $insert->values([
          'rid' => $rid,
          'line' => $row['line'],
          'input' => json_encode($row['input']),
          'status' => $row['error'] === NULL ? 'pending' : 'skipped',
          'message' => $row['error'],
        ]);
      }
      $insert->execute();
    }
    // Nothing to send: every row was skipped.
    $this->finishIfDone($rid);
    return $rid;
  }

  /**
   * Loads a run, with its options decoded and its row counts.
   */
  public function load(int $rid): ?array {
    $run = $this->database->select(self::RUN_TABLE, 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchAssoc();
    if (!$run) {
      return NULL;
    }
    return $this->decodeRun($run, $this->counts([$rid])[$rid] ?? []);
  }

  /**
   * The most recent runs, newest first, with their row counts.
   */
  public function recent(int $limit = 50): array {
    $runs = $this->database->select(self::RUN_TABLE, 'r')
      ->fields('r')
      ->orderBy('rid', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('rid', \PDO::FETCH_ASSOC);
    $counts = $this->counts(array_keys($runs));
    foreach ($runs as $rid => $run) {
      $runs[$rid] = $this->decodeRun($run, $counts[$rid] ?? []);
    }
    return $runs;
  }

  /**
   * Row counts by status for each run: [rid => [status => count]].
   */
  public function counts(array $rids): array {
    if (!$rids) {
      return [];
    }
    $query = $this->database->select(self::ROW_TABLE, 'w')
      ->fields('w', ['rid', 'status'])
      ->condition('rid', $rids, 'IN')
      ->groupBy('rid')
      ->groupBy('status');
    $query->addExpression('COUNT(*)', 'total');
    $counts = [];
    foreach ($query->execute() as $record) {
      $counts[$record->rid][$record->status] = (int) $record->total;
    }
    return $counts;
  }

  /**
   * A run's rows in line order, optionally only some statuses.
   *
   * @return \Generator
   *   Rows with input and data decoded.
   */
  public function rows(int $rid, array $statuses = [], int $limit = 0): \Generator {
    $query = $this->database->select(self::ROW_TABLE, 'w')
      ->fields('w')
      ->condition('rid', $rid)
      ->orderBy('line');
    if ($statuses) {
      $query->condition('status', $statuses, 'IN');
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      yield $this->decodeRow($row);
    }
  }

  /**
   * The next row to process, from the oldest active run.
   */
  public function nextPending(): ?array {
    $query = $this->database->select(self::ROW_TABLE, 'w');
    $query->join(self::RUN_TABLE, 'r', '[r].[rid] = [w].[rid]');
    $row = $query->fields('w')
      ->condition('w.status', 'pending')
      ->condition('r.status', self::ACTIVE, 'IN')
      ->orderBy('w.rid')
      ->orderBy('w.line')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ? $this->decodeRow($row) : NULL;
  }

  /**
   * Updates one row.
   */
  public function updateRow(int $rid, int $line, string $status, ?string $message = NULL, array $data = []): void {
    $this->database->update(self::ROW_TABLE)
      ->fields([
        'status' => $status,
        'message' => $message,
        'data' => $data ? json_encode($data) : NULL,
        'processed' => $status === 'pending' ? 0 : $this->time->getCurrentTime(),
      ])
      ->condition('rid', $rid)
      ->condition('line', $line)
      ->execute();
  }

  /**
   * Marks a queued run as running.
   */
  public function start(int $rid): void {
    $this->database->update(self::RUN_TABLE)
      ->fields(['status' => 'running', 'started' => $this->time->getCurrentTime()])
      ->condition('rid', $rid)
      ->condition('status', 'queued')
      ->execute();
  }

  /**
   * Marks an active run done if it has no rows left to process.
   */
  public function finishIfDone(int $rid): void {
    $left = $this->database->select(self::ROW_TABLE, 'w')
      ->condition('rid', $rid)
      ->condition('status', ['pending', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    if (!$left) {
      $this->database->update(self::RUN_TABLE)
        ->fields(['status' => 'done', 'finished' => $this->time->getCurrentTime()])
        ->condition('rid', $rid)
        ->condition('status', self::ACTIVE, 'IN')
        ->execute();
    }
  }

  /**
   * Cancels an active run. Rows already sent keep their results.
   */
  public function cancel(int $rid): void {
    $this->database->update(self::RUN_TABLE)
      ->fields(['status' => 'cancelled', 'finished' => $this->time->getCurrentTime()])
      ->condition('rid', $rid)
      ->condition('status', self::ACTIVE, 'IN')
      ->execute();
    $this->database->update(self::ROW_TABLE)
      ->fields(['status' => 'cancelled'])
      ->condition('rid', $rid)
      ->condition('status', 'pending')
      ->execute();
  }

  /**
   * Fails rows left "processing" by a processor that was killed mid-call.
   *
   * Chargebee may or may not have applied those calls, so they are not
   * retried. Only call this while holding the processing lock.
   *
   * @return int
   *   The number of rows marked.
   */
  public function failInterrupted(): int {
    return $this->database->update(self::ROW_TABLE)
      ->fields([
        'status' => 'error',
        'message' => 'Interrupted before Chargebee answered, so it may or may not have been applied. Check this one in Chargebee.',
        'processed' => $this->time->getCurrentTime(),
      ])
      ->condition('status', 'processing')
      ->execute();
  }

  /**
   * Decodes a run record and adds its counts and progress.
   */
  protected function decodeRun(array $run, array $counts): array {
    $run['options'] = json_decode($run['options'] ?? '', TRUE) ?: [];
    $run['counts'] = $counts;
    $run['total'] = array_sum($counts);
    $run['left'] = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0);
    return $run;
  }

  /**
   * Decodes a row record.
   */
  protected function decodeRow(array $row): array {
    $row['rid'] = (int) $row['rid'];
    $row['line'] = (int) $row['line'];
    $row['input'] = json_decode($row['input'], TRUE) ?: [];
    $row['data'] = json_decode($row['data'] ?? '', TRUE) ?: [];
    return $row;
  }

}
