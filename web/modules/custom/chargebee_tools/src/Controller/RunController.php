<?php

namespace Drupal\chargebee_tools\Controller;

use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationInterface;
use Drupal\chargebee_tools\ChargebeeOperationManager;
use Drupal\chargebee_tools\Form\NewRunForm;
use Drupal\chargebee_tools\RunStorage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists operations and runs, shows a run's progress, and exports results.
 */
class RunController extends ControllerBase {

  /**
   * How many problem rows the run page lists. The CSV has them all.
   */
  const PROBLEM_ROWS = 200;

  /**
   * Seconds between reloads of the run page while it is active.
   */
  const RELOAD_SECONDS = 30;

  /**
   * Row statuses in display order, with labels.
   */
  const ROW_STATUSES = [
    'success' => 'OK',
    'warning' => 'Warning',
    'error' => 'Error',
    'skipped' => 'Skipped',
    'pending' => 'Pending',
    'processing' => 'Processing',
    'cancelled' => 'Cancelled',
  ];

  public function __construct(
    protected RunStorage $storage,
    protected ChargebeeOperationManager $operations,
    protected ChargebeeClient $client,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The operations to start a run with, and the recent runs.
   */
  public function overview(): array {
    $build['operations'] = [
      '#type' => 'table',
      '#caption' => $this->t('Start a run'),
      '#header' => [$this->t('Operation'), $this->t('What it does'), $this->t('CSV of'), ''],
      '#empty' => $this->t('No operations found.'),
    ];
    foreach ($this->operations->getSortedDefinitions() as $id => $definition) {
      $build['operations']['#rows'][] = [
        $definition['label'],
        $definition['description'] ?? '',
        $this->t('@type IDs', ['@type' => ucfirst($definition['id_type'] ?? 'subscription')]),
        Link::createFromRoute($this->t('New run'), 'chargebee_tools.new', ['operation' => $id]),
      ];
    }

    $build['runs'] = [
      '#type' => 'table',
      '#caption' => $this->t('Recent runs'),
      '#header' => [
        $this->t('Run'),
        $this->t('Operation'),
        $this->t('Site'),
        $this->t('Mode'),
        $this->t('Queued'),
        $this->t('By'),
        $this->t('Status'),
        $this->t('Progress'),
      ],
      '#empty' => $this->t('No runs yet.'),
    ];
    foreach ($this->storage->recent() as $rid => $run) {
      $build['runs']['#rows'][] = [
        Link::createFromRoute('#' . $rid, 'chargebee_tools.run', ['rid' => $rid]),
        $this->operationLabel($run['operation']),
        $run['site'],
        $run['mode'] === 'check' ? $this->t('Check only') : $this->t('Changes'),
        $this->dateFormatter->format($run['created'], 'short'),
        $this->userName($run['uid']),
        $run['status'],
        $this->progress($run),
      ];
    }

    foreach (['test', 'live'] as $site) {
      if (!$this->client->isConfigured($site)) {
        $this->messenger()->addWarning($this->t('The @site Chargebee site is not configured. See the chargebee_tools README.', ['@site' => $site]));
      }
    }
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * Title callback for a run page.
   */
  public function title(int $rid): string {
    $run = $this->loadRun($rid);
    return (string) $this->t('Run #@rid: @operation', [
      '@rid' => $rid,
      '@operation' => $this->operationLabel($run['operation']),
    ]);
  }

  /**
   * A run's settings, progress and problem rows.
   */
  public function view(int $rid): array {
    $run = $this->loadRun($rid);
    $plugin = $this->plugin($run);
    $active = in_array($run['status'], RunStorage::ACTIVE, TRUE);

    $summary = [
      (string) $this->t('Status') => $run['status'],
      (string) $this->t('Chargebee site') => $run['site'] . ' (' . $this->client->subdomain($run['site']) . ')',
      (string) $this->t('Mode') => $run['mode'] === 'check' ? $this->t('Check only (changes nothing)') : $this->t('Make the changes'),
    ];
    if ($plugin) {
      $summary += $plugin->optionsSummary();
    }
    $summary += [
      (string) $this->t('File') => $run['filename'],
      (string) $this->t('Queued') => $this->dateFormatter->format($run['created'], 'short') . ' ' . $this->t('by @name', ['@name' => $this->userName($run['uid'])]),
      (string) $this->t('Started') => $run['started'] ? $this->dateFormatter->format($run['started'], 'short') : '-',
      (string) $this->t('Finished') => $run['finished'] ? $this->dateFormatter->format($run['finished'], 'short') : '-',
    ];
    $build['progress'] = $this->progressDisplay($run);
    $build['summary'] = NewRunForm::summaryList($summary);

    $build['actions'] = ['#type' => 'actions'];
    $build['actions']['download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download results CSV'),
      '#url' => Url::fromRoute('chargebee_tools.download', ['rid' => $rid]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    if ($active) {
      $build['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel the rows not yet sent'),
        '#url' => Url::fromRoute('chargebee_tools.cancel', ['rid' => $rid]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }
    $build['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('All runs'),
      '#url' => Url::fromRoute('chargebee_tools.overview'),
      '#attributes' => ['class' => ['button']],
    ];

    $problems = iterator_to_array($this->storage->rows($rid, ['error', 'warning', 'skipped'], self::PROBLEM_ROWS));
    $build['problems'] = [
      '#type' => 'table',
      '#caption' => $this->t('Errors, warnings and skipped rows'),
      '#header' => [$this->t('Line'), $this->t('ID'), $this->t('Status'), $this->t('Message')],
      '#rows' => array_map(fn($row) => [
        $row['line'],
        (string) reset($row['input']),
        $this->t(self::ROW_STATUSES[$row['status']] ?? $row['status']),
        $row['message'],
      ], $problems),
      '#empty' => $this->t('None so far.'),
    ];
    if (count($problems) === self::PROBLEM_ROWS) {
      $build['more'] = [
        '#markup' => '<p>' . $this->t('Showing the first @count. The results CSV has every row.', ['@count' => self::PROBLEM_ROWS]) . '</p>',
      ];
    }

    if ($active) {
      $build['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => ['http-equiv' => 'refresh', 'content' => self::RELOAD_SECONDS],
        ],
        'chargebee_tools_refresh',
      ];
    }
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * Every row of a run, with its result, as a CSV download.
   */
  public function download(int $rid): Response {
    $run = $this->loadRun($rid);
    $plugin = $this->plugin($run);
    $columns = $plugin ? $plugin->columns() : [];
    $result_columns = $plugin ? $plugin->resultColumns() : [];

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array_merge(
      ['Line'],
      array_map('strval', array_values($columns)),
      ['Status', 'Message'],
      array_map('strval', array_values($result_columns)),
    ), ',', '"', '');
    foreach ($this->storage->rows($rid) as $row) {
      $values = [$row['line']];
      foreach (array_keys($columns) as $key) {
        $values[] = $row['input'][$key] ?? '';
      }
      $values[] = self::ROW_STATUSES[$row['status']] ?? $row['status'];
      $values[] = $row['message'];
      foreach (array_keys($result_columns) as $key) {
        $values[] = $row['data'][$key] ?? '';
      }
      fputcsv($handle, $values, ',', '"', '');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $filename = sprintf('chargebee-run-%d-%s-%s.csv', $rid, $run['operation'], $run['site']);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'private, no-store',
    ]);
  }

  /**
   * Cancels a run's unsent rows.
   */
  public function cancel(int $rid): RedirectResponse {
    $this->loadRun($rid);
    $this->storage->cancel($rid);
    $this->getLogger('chargebee_tools')->notice('Run @rid cancelled.', ['@rid' => $rid]);
    $this->messenger()->addStatus($this->t('Cancelled. Rows already sent to Chargebee keep their results.'));
    return $this->redirect('chargebee_tools.run', ['rid' => $rid]);
  }

  /**
   * Loads a run or responds 404.
   */
  protected function loadRun(int $rid): array {
    return $this->storage->load($rid) ?? throw new NotFoundHttpException();
  }

  /**
   * The run's operation plugin, or NULL if its code has since been removed.
   */
  protected function plugin(array $run): ?ChargebeeOperationInterface {
    if (!$this->operations->hasDefinition($run['operation'])) {
      return NULL;
    }
    return $this->operations->createInstance($run['operation'], $run['options']);
  }

  /**
   * An operation's label, or its ID if the plugin no longer exists.
   */
  protected function operationLabel(string $operation): string {
    return (string) ($this->operations->getDefinition($operation, FALSE)['label'] ?? $operation);
  }

  /**
   * A run's sent, succeeded and failed counts.
   *
   * Warnings count as succeeded: the call worked, but the row needs a look.
   *
   * @return array
   *   Keys: sendable, sent, succeeded, warnings, failed, skipped, cancelled,
   *   left.
   */
  protected function tally(array $run): array {
    $counts = $run['counts'];
    $warnings = $counts['warning'] ?? 0;
    $succeeded = ($counts['success'] ?? 0) + $warnings;
    $failed = $counts['error'] ?? 0;
    $skipped = $counts['skipped'] ?? 0;
    return [
      'sendable' => $run['total'] - $skipped,
      'sent' => $succeeded + $failed,
      'succeeded' => $succeeded,
      'warnings' => $warnings,
      'failed' => $failed,
      'skipped' => $skipped,
      'cancelled' => $counts['cancelled'] ?? 0,
      'left' => $run['left'],
    ];
  }

  /**
   * "1,200 / 3,250 sent · 6 failed" for the runs list.
   */
  protected function progress(array $run): string {
    $tally = $this->tally($run);
    $text = $this->t('@sent / @total sent', [
      '@sent' => number_format($tally['sent']),
      '@total' => number_format($tally['sendable']),
    ]);
    if ($tally['failed']) {
      $text .= ' · ' . $this->formatPlural($tally['failed'], '1 failed', '@count failed');
    }
    return (string) $text;
  }

  /**
   * The progress bar and succeeded/failed counters for the run page.
   *
   * The bar is split into succeeded (green), succeeded with a warning (amber)
   * and failed (red); the empty part is what is left to send.
   */
  protected function progressDisplay(array $run): array {
    $tally = $this->tally($run);
    $total = max($tally['sendable'], 1);
    $width = fn(int $count) => round($count / $total * 100, 2);

    $counters = [
      ['type' => 'ok', 'count' => $tally['succeeded'], 'label' => $this->t('Succeeded')],
      ['type' => 'error', 'count' => $tally['failed'], 'label' => $this->t('Failed')],
    ];
    if ($tally['warnings']) {
      $counters[] = ['type' => 'warning', 'count' => $tally['warnings'], 'label' => $this->t('Succeeded with a warning')];
    }
    if ($tally['left']) {
      $counters[] = ['type' => 'left', 'count' => $tally['left'], 'label' => $this->t('Waiting')];
    }
    if ($tally['cancelled']) {
      $counters[] = ['type' => 'left', 'count' => $tally['cancelled'], 'label' => $this->t('Cancelled')];
    }
    if ($tally['skipped']) {
      $counters[] = ['type' => 'left', 'count' => $tally['skipped'], 'label' => $this->t('Skipped (not sent)')];
    }
    foreach ($counters as &$counter) {
      $counter['count'] = number_format($counter['count']);
    }

    return [
      '#type' => 'inline_template',
      '#template' => <<<'TWIG'
<div class="cbt-run-progress">
  <div class="cbt-run-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ total }}" aria-valuenow="{{ sent }}" aria-label="{{ label }}">
    {% for segment in segments %}
      {% if segment.width > 0 %}<span class="cbt-run-progress__segment cbt-run-progress__segment--{{ segment.type }}" style="width: {{ segment.width }}%"></span>{% endif %}
    {% endfor %}
  </div>
  <p class="cbt-run-progress__label">{{ label }}</p>
  <ul class="cbt-run-progress__counters">
    {% for counter in counters %}
      <li class="cbt-run-progress__counter cbt-run-progress__counter--{{ counter.type }}">
        <span class="cbt-run-progress__count">{{ counter.count }}</span>
        <span class="cbt-run-progress__name">{{ counter.label }}</span>
      </li>
    {% endfor %}
  </ul>
</div>
TWIG,
      '#context' => [
        'total' => $tally['sendable'],
        'sent' => $tally['sent'],
        'label' => $this->t('@percent% · @sent of @total sent', [
          '@percent' => $tally['sendable'] ? floor($tally['sent'] / $tally['sendable'] * 100) : 100,
          '@sent' => number_format($tally['sent']),
          '@total' => number_format($tally['sendable']),
        ]),
        'segments' => [
          ['type' => 'ok', 'width' => $width($tally['succeeded'] - $tally['warnings'])],
          ['type' => 'warning', 'width' => $width($tally['warnings'])],
          ['type' => 'error', 'width' => $width($tally['failed'])],
        ],
        'counters' => $counters,
      ],
      '#attached' => ['library' => ['chargebee_tools/run']],
    ];
  }

  /**
   * A user's display name.
   */
  protected function userName(int|string $uid): string {
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    return $account ? $account->getDisplayName() : (string) $this->t('Unknown');
  }

}
