<?php

namespace Drupal\kb_chat\Plugin\Block;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\kb_chat\Drive\DriveSync;
use Drupal\kb_chat\JobLog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the health of what feeds the kb chat, on one screen.
 *
 * The content index, the Google Drive sync, the trusted websites and the
 * Upsun cron jobs that run them, with a summary of anything that needs a look.
 */
#[Block(
  id: 'kb_chat_status',
  admin_label: new TranslatableMarkup('KB chat status'),
  category: new TranslatableMarkup('KB Chat'),
)]
class KbChatStatusBlock extends BlockBase implements ContainerFactoryPluginInterface {

  const INDEX = 'kb_chat_content';

  /**
   * Content index datasources => labels.
   */
  const DATASOURCES = [
    'entity:node' => 'kb pages',
    'entity:kb_chat_document' => 'Drive files and website pages',
  ];

  /**
   * Trusted website change frequencies in seconds.
   */
  const FREQUENCIES = [
    'daily' => 86400,
    'weekly' => 604800,
    'monthly' => 2592000,
  ];

  /**
   * Problems found while building: [level, message].
   */
  protected array $issues = [];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected StateInterface $state,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected DateFormatterInterface $dateFormatter,
    protected JobLog $jobLog,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('state'),
      $container->get('queue'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get(JobLog::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'view kb chat status');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $this->issues = [];
    $sections = [
      'index' => $this->indexSection(),
      'drive' => $this->driveSection(),
      'web' => $this->webSection(),
      'jobs' => $this->jobsSection(),
    ];

    $build = [
      '#attached' => ['library' => ['kb_chat/status-block']],
      '#attributes' => ['class' => ['kb-status']],
    ];
    if ($this->issues) {
      usort($this->issues, fn($a, $b) => ($a[0] === 'error' ? 0 : 1) <=> ($b[0] === 'error' ? 0 : 1));
      $build['summary'] = [
        '#theme' => 'item_list',
        '#items' => array_map(fn($issue) => [
          '#markup' => $this->state($issue[0], $issue[1]),
        ], $this->issues),
        '#attributes' => ['class' => ['kb-status__issues']],
      ];
    }
    else {
      $build['summary'] = [
        '#markup' => '<p class="kb-status__issues">' . $this->state('ok', $this->t('Everything is running normally.')) . '</p>',
      ];
    }
    $build += $sections;
    $build['#cache'] = [
      // Counts change with every cron run; a short cache keeps the block cheap.
      'max-age' => 60,
      'contexts' => ['user.permissions'],
    ];
    return $build;
  }

  /**
   * The content index: what's indexed and waiting, per source.
   */
  protected function indexSection(): array {
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load(self::INDEX);
    if (!$index) {
      $this->issue('error', $this->t('The content index is missing.'));
      return [];
    }
    if (!$index->status()) {
      $this->issue('error', $this->t('The content index is disabled, so the chat finds nothing new.'));
    }
    $server = $index->getServerInstanceIfAvailable();
    if (!$server || !$server->isAvailable()) {
      $this->issue('error', $this->t('The vector database server is unavailable, so the chat cannot search.'));
    }
    if ($index->getOption('index_directly')) {
      $this->issue('warning', $this->t('"Index items immediately" is on: saving content waits on embedding, and a slow save can time out. Untick it on the index\'s edit page.'));
    }

    $tracker = $index->getTrackerInstanceIfAvailable();
    $rows = [];
    if ($tracker) {
      foreach (self::DATASOURCES as $id => $label) {
        if ($index->isValidDatasource($id)) {
          $rows[] = [
            $label,
            number_format($tracker->getIndexedItemsCount($id)),
            number_format($tracker->getRemainingItemsCount($id)),
          ];
        }
      }
      $indexed = $tracker->getIndexedItemsCount();
      $total = $tracker->getTotalItemsCount();
      $rows[] = [
        ['data' => $this->t('Total'), 'header' => TRUE],
        ['data' => $this->t('@indexed of @total (@percent%)', [
          '@indexed' => number_format($indexed),
          '@total' => number_format($total),
          '@percent' => $total ? floor($indexed / $total * 100) : 100,
        ]), 'header' => TRUE],
        ['data' => number_format($tracker->getRemainingItemsCount()), 'header' => TRUE],
      ];
    }
    return $this->section(
      $this->t('Content index'),
      Url::fromRoute('entity.search_api_index.canonical', ['search_api_index' => self::INDEX]),
      [
        '#type' => 'table',
        '#header' => ['', $this->t('Indexed'), $this->t('Waiting')],
        '#rows' => $rows,
      ],
    );
  }

  /**
   * The Google Drive sync: documents, listing and the read queue.
   */
  protected function driveSection(): array {
    $tiers = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['tier'])
      ->condition('source', DriveSync::SOURCE);
    $tiers->addExpression('COUNT(*)', 'count');
    $tiers = $tiers->groupBy('tier')->execute()->fetchAllKeyed();

    $interval = (int) ($this->configFactory->get('kb_chat.drive')->get('list_interval') ?? 21600);
    $listed = (int) $this->state->get(DriveSync::LISTED, 0);
    $waiting = $this->queueFactory->get(DriveSync::QUEUE)->numberOfItems();
    $crashed = count($this->state->get(DriveSync::CRASHED, []));
    $reading = $this->state->get(DriveSync::READING);

    if (!$listed) {
      $this->issue('warning', $this->t('Google Drive has never been listed.'));
    }
    elseif (time() - $listed > 2 * $interval + 3600) {
      $this->issue('warning', $this->t('Google Drive was last listed @ago; it should be every @every.', [
        '@ago' => $this->ago($listed),
        '@every' => $this->dateFormatter->formatInterval($interval),
      ]));
    }
    if ($crashed) {
      $this->issue('warning', $this->formatPlural($crashed, '1 Drive file crashed the sync and is skipped.', '@count Drive files crashed the sync and are skipped.'));
    }

    $items = [
      $this->t('Documents: @staff staff, @management management', [
        '@staff' => number_format($tiers['staff'] ?? 0),
        '@management' => number_format($tiers['management'] ?? 0),
      ]),
      $this->t('Last listed: @time (every @every)', [
        '@time' => $listed ? $this->ago($listed) : $this->t('never'),
        '@every' => $this->dateFormatter->formatInterval($interval),
      ]),
      $this->t('Waiting to be read: @count@eta', [
        '@count' => number_format($waiting),
        '@eta' => $this->driveEta($waiting),
      ]),
    ];
    if ($crashed) {
      $items[] = $this->t('Skipped after crashing the sync: @count', ['@count' => $crashed]);
    }
    if ($reading) {
      $items[] = $this->t('Reading now: @name', ['@name' => $reading['name'] ?? $reading['id'] ?? '']);
    }
    return $this->section(
      $this->t('Google Drive'),
      Url::fromRoute('kb_chat.drive_settings'),
      ['#theme' => 'item_list', '#items' => $items],
    );
  }

  /**
   * Roughly how long the Drive queue takes to read at the last run's pace.
   */
  protected function driveEta(int $waiting): string {
    $counts = $this->jobLog->get('drive')['counts'] ?? [];
    $per_run = ($counts['created'] ?? 0) + ($counts['updated'] ?? 0) + ($counts['skipped'] ?? 0) + ($counts['failed'] ?? 0);
    if (!$waiting || !$per_run) {
      return '';
    }
    $seconds = (int) ceil($waiting / $per_run) * JobLog::JOBS['drive']['every'];
    return ' ' . $this->t('(about @time at @count a run)', [
      '@time' => $this->dateFormatter->formatInterval($seconds, 2),
      '@count' => $per_run,
    ]);
  }

  /**
   * The trusted websites: pages indexed and when each was last crawled.
   */
  protected function webSection(): array {
    $query = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['location'])
      ->condition('source', 'web');
    $query->addExpression('COUNT(*)', 'count');
    $pages = $query->groupBy('location')->execute()->fetchAllKeyed();

    $rows = [];
    $total = 0;
    /** @var \Drupal\kb_chat\Entity\KbChatWebSource[] $sources */
    $sources = $this->entityTypeManager->getStorage('kb_chat_web_source')->loadMultiple();
    uasort($sources, fn($a, $b) => strnatcasecmp($a->label(), $b->label()));
    foreach ($sources as $source) {
      $count = (int) ($pages[$source->label()] ?? 0);
      $total += $count;
      $crawled = (int) $this->state->get('kb_chat.web_crawled.' . $source->id(), 0);
      $level = 'ok';
      if (!$source->status()) {
        $level = 'info';
      }
      elseif ($crawled && !$count) {
        $level = 'warning';
        $this->issue('warning', $this->t('@site was crawled but has no pages indexed.', ['@site' => $source->label()]));
      }
      elseif ($crawled && time() - $crawled > 2 * (self::FREQUENCIES[$source->get('frequency')] ?? 604800)) {
        $level = 'warning';
        $this->issue('warning', $this->t('@site is overdue for a crawl (last @ago).', ['@site' => $source->label(), '@ago' => $this->ago($crawled)]));
      }
      $rows[] = [
        ['data' => ['#markup' => $this->state($level, $source->label())]],
        number_format($count),
        $crawled ? $this->ago($crawled) : $this->t('Not yet'),
        $source->status() ? ucfirst($source->get('frequency')) : $this->t('Disabled'),
      ];
    }
    $rows[] = [
      ['data' => $this->t('Total'), 'header' => TRUE],
      ['data' => number_format($total), 'header' => TRUE],
      ['data' => '', 'header' => TRUE],
      ['data' => '', 'header' => TRUE],
    ];
    return $this->section(
      $this->t('Trusted websites'),
      Url::fromRoute('entity.kb_chat_web_source.collection'),
      [
        '#type' => 'table',
        '#header' => [$this->t('Website'), $this->t('Pages'), $this->t('Last crawled'), $this->t('Re-crawled')],
        '#rows' => $rows,
        '#empty' => $this->t('No trusted websites yet.'),
      ],
    );
  }

  /**
   * The Upsun cron jobs: each one's last run and last success.
   */
  protected function jobsSection(): array {
    $rows = [];
    foreach (JobLog::JOBS as $key => $job) {
      $log = $this->jobLog->get($key);
      [$level, $state] = $this->jobState($log, $job);
      if (in_array($level, ['warning', 'error'], TRUE)) {
        $this->issue($level, $this->t('@job (@cron): @state', ['@job' => $job['label'], '@cron' => $job['cron'], '@state' => $state]));
      }
      $took = !empty($log['finished']) && !empty($log['started'])
        ? $this->dateFormatter->formatDiff($log['started'], $log['finished'], ['granularity' => 2])
        : '';
      $last_success = !empty($log['last_success']) ? $this->ago($log['last_success']) : $this->t('never');
      $rows[] = [
        ['data' => ['#markup' => $this->state($level, $this->t('@job<br><small>@cron, every @every</small>', [
          '@job' => $job['label'],
          '@cron' => $job['cron'],
          '@every' => $this->dateFormatter->formatInterval($job['every']),
        ]))]],
        !empty($log['started']) ? $this->ago($log['started']) : $this->t('No runs recorded yet'),
        $took,
        $state,
        $last_success,
      ];
    }
    return $this->section(
      $this->t('Cron jobs'),
      NULL,
      [
        '#type' => 'table',
        '#header' => [$this->t('Job'), $this->t('Last started'), $this->t('Took'), $this->t('Result'), $this->t('Last success')],
        '#rows' => $rows,
      ],
    );
  }

  /**
   * A job's state: [level, text].
   */
  protected function jobState(array $log, array $job): array {
    $now = time();
    if (empty($log['started'])) {
      return ['info', $this->t('Waiting for its first recorded run.')];
    }
    if (empty($log['finished'])) {
      $running = $now - $log['started'];
      return $running > $job['timeout'] + 60
        ? ['error', $this->t('Started @ago and never finished: probably stopped by the Upsun timeout or out of memory.', ['@ago' => $this->ago($log['started'])])]
        : ['info', $this->t('Running for @time.', ['@time' => $this->dateFormatter->formatInterval(max(1, $running))])];
    }
    if (empty($log['ok'])) {
      return ['error', $this->t('Failed: @error', ['@error' => $log['summary'] ?? ''])];
    }
    if ($now - ($log['last_success'] ?? 0) > $job['stale']) {
      return ['warning', $this->t('No successful run since @ago.', ['@ago' => $this->ago($log['last_success'])])];
    }
    if (!empty($log['error_time']) && $now - $log['error_time'] < 86400) {
      return ['ok', $this->t('@summary (failed @ago: @error)', [
        '@summary' => $log['summary'] ?? '',
        '@ago' => $this->ago($log['error_time']),
        '@error' => $log['last_error'],
      ])];
    }
    return ['ok', $log['summary'] ?? ''];
  }

  /**
   * A section: heading with a link to its admin page, and its content.
   */
  protected function section(TranslatableMarkup $title, ?Url $url, array $content): array {
    $heading = $url && $url->access()
      ? Link::fromTextAndUrl($title, $url)->toString()
      : $title;
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['kb-status__section']],
      'heading' => ['#markup' => '<h3 class="kb-status__heading">' . $heading . '</h3>'],
      'content' => $content,
    ];
  }

  /**
   * Text with a coloured state marker.
   */
  protected function state(string $level, $text): string {
    $text = $text instanceof MarkupInterface ? (string) $text : Html::escape((string) $text);
    return '<span class="kb-status__state kb-status__state--' . $level . '">' . $text . '</span>';
  }

  /**
   * Records a problem for the summary.
   */
  protected function issue(string $level, $message): void {
    $this->issues[] = [$level, $message];
  }

  /**
   * "5 min ago".
   */
  protected function ago(int $timestamp): string {
    return (string) $this->t('@time ago', ['@time' => $this->dateFormatter->formatTimeDiffSince($timestamp, ['granularity' => 1])]);
  }

}
