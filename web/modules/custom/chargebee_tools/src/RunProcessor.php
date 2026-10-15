<?php

namespace Drupal\chargebee_tools;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Works through pending rows, one Chargebee call at a time.
 *
 * Runs from `drush chargebee:process`, which Upsun cron starts every five
 * minutes. A lock keeps two processors from running at once.
 */
class RunProcessor {

  const LOCK = 'chargebee_tools.process';

  public function __construct(
    protected RunStorage $storage,
    protected ChargebeeOperationManager $operations,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.chargebee_tools')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Processes pending rows until there are none or time runs out.
   *
   * @param int $time_limit
   *   Seconds to keep starting new rows.
   *
   * @return int|null
   *   The number of rows processed, or NULL if another processor is running.
   */
  public function process(int $time_limit): ?int {
    // Allow for the last call running past the limit (60 second timeout).
    if (!$this->lock->acquire(self::LOCK, $time_limit + 120)) {
      return NULL;
    }
    try {
      if ($interrupted = $this->storage->failInterrupted()) {
        $this->logger->warning('Marked @count interrupted rows as errors.', ['@count' => $interrupted]);
      }
      // Seconds between calls, to stay under Chargebee's rate limit.
      $delay = (float) (Settings::get('chargebee_tools')['delay'] ?? 1);
      $end = microtime(TRUE) + $time_limit;
      $count = 0;
      while (microtime(TRUE) < $end && ($row = $this->storage->nextPending())) {
        if ($count) {
          usleep((int) ($delay * 1000000));
        }
        if (!$this->processRow($row)) {
          break;
        }
        $count++;
      }
      return $count;
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Sends one row to Chargebee and saves the result.
   *
   * @return bool
   *   FALSE if Chargebee's rate limit was hit and processing should pause.
   */
  protected function processRow(array $row): bool {
    $rid = $row['rid'];
    $line = $row['line'];
    $run = $this->storage->load($rid);
    $this->storage->start($rid);
    // Marked before the call, so a processor killed mid-call leaves a trace
    // instead of a row that would be sent again.
    $this->storage->updateRow($rid, $line, 'processing');
    try {
      /** @var \Drupal\chargebee_tools\ChargebeeOperationInterface $operation */
      $operation = $this->operations->createInstance($run['operation'], $run['options']);
      $result = $run['mode'] === 'check'
        ? $operation->check($run['site'], $row['input'])
        : $operation->process($run['site'], $row['input']);
      $status = empty($result['warning']) ? 'success' : 'warning';
      $this->storage->updateRow($rid, $line, $status, $result['message'] ?? '', $result['data'] ?? []);
    }
    catch (ChargebeeException $e) {
      if ($e->isRateLimited()) {
        $this->storage->updateRow($rid, $line, 'pending');
        $this->logger->notice('Chargebee rate limit reached; pausing until the next run.');
        return FALSE;
      }
      $this->storage->updateRow($rid, $line, 'error', $e->getMessage());
    }
    catch (\Throwable $e) {
      $this->storage->updateRow($rid, $line, 'error', $e->getMessage());
      $this->logger->error('Run @rid line @line: @message', [
        '@rid' => $rid,
        '@line' => $line,
        '@message' => $e->getMessage(),
      ]);
    }
    $this->storage->finishIfDone($rid);
    return TRUE;
  }

}
