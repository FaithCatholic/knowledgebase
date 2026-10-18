<?php

namespace Drupal\kb_chat;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Records each run of the kb chat's Upsun cron jobs, for the status block.
 *
 * Kept in state as kb_chat.job.<job>. A run that is started but never
 * finished was stopped from outside: Upsun's timeout or the out-of-memory
 * killer. A PHP fatal error is caught at shutdown and recorded as a failure.
 */
class JobLog {

  const PREFIX = 'kb_chat.job.';

  /**
   * The jobs: key => Upsun cron name, schedule, timeout, and how long without
   * a successful run before the status block warns, in seconds.
   */
  const JOBS = [
    'index' => ['cron' => 'kbindex', 'label' => 'Content indexing', 'every' => 300, 'timeout' => 300, 'stale' => 1800],
    'drive' => ['cron' => 'kbdrive', 'label' => 'Google Drive sync', 'every' => 300, 'timeout' => 1200, 'stale' => 2400],
    'web' => ['cron' => 'kbweb', 'label' => 'Trusted website crawl', 'every' => 600, 'timeout' => 600, 'stale' => 3600],
  ];

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * The log of a job: started, finished, ok, summary, counts, last_success,
   * last_error, error_time, skipped.
   */
  public function get(string $job): array {
    return $this->state->get(self::PREFIX . $job, []);
  }

  /**
   * Records the start of a run.
   */
  public function start(string $job): void {
    $log = $this->get($job);
    // Kept so a run that turns out to overlap another can be undone.
    $log['previous'] = ['started' => $log['started'] ?? NULL, 'finished' => $log['finished'] ?? NULL];
    $log['started'] = $this->time->getCurrentTime();
    $log['finished'] = NULL;
    $this->state->set(self::PREFIX . $job, $log);
    $started = $log['started'];
    register_shutdown_function(function () use ($job, $started) {
      // Only this run: not one this process skipped for.
      $log = $this->get($job);
      if (($log['started'] ?? NULL) === $started && empty($log['finished'])) {
        $error = error_get_last();
        $this->finish($job, $error ? 'Stopped by a PHP error: ' . $error['message'] : 'Stopped before finishing.', [], FALSE);
      }
    });
  }

  /**
   * Records the end of a run.
   */
  public function finish(string $job, string $summary, array $counts = [], bool $ok = TRUE): void {
    $log = $this->get($job);
    $now = $this->time->getCurrentTime();
    $log['finished'] = $now;
    $log['ok'] = $ok;
    $log['summary'] = $summary;
    $log['counts'] = $counts;
    if ($ok) {
      $log['last_success'] = $now;
    }
    else {
      $log['last_error'] = $summary;
      $log['error_time'] = $now;
    }
    $this->state->set(self::PREFIX . $job, $log);
  }

  /**
   * Undoes start(): the run didn't happen because the previous one still runs.
   */
  public function skipped(string $job): void {
    $log = $this->get($job);
    $log = ($log['previous'] ?? []) + $log;
    $log['skipped'] = $this->time->getCurrentTime();
    $this->state->set(self::PREFIX . $job, $log);
  }

}
