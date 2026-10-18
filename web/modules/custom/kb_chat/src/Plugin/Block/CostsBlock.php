<?php

namespace Drupal\kb_chat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\CostTracker;
use Drupal\upsun_dashboard\DashboardCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows kb's month-to-date costs: AI and Upsun hosting, and their total.
 *
 * AI: for each provider, kb's live estimate and the provider's billed figure.
 * Hosting: the open invoice of the FAITH Flex billing profile
 * ($settings['kb_chat']['upsun_billing_profile']), which holds only kb, as
 * collected hourly by the upsun_dashboard module.
 *
 * The plugin ID is from when the block only showed AI costs; it's kept so the
 * placed block keeps working.
 */
#[Block(
  id: 'kb_chat_ai_costs',
  admin_label: new TranslatableMarkup('KB costs this month'),
  category: new TranslatableMarkup('KB Chat'),
)]
class CostsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Providers in display order: AI provider plugin ID => [label, billing key].
   */
  const PROVIDERS = [
    'anthropic' => ['Claude (Anthropic)', 'anthropic'],
    'gemini' => ['Gemini (Google)', 'google'],
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CostTracker $costs,
    protected DateFormatterInterface $dateFormatter,
    protected ?DashboardCollector $upsun = NULL,
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
      $container->get(CostTracker::class),
      $container->get('date.formatter'),
      $container->has(DashboardCollector::class) ? $container->get(DashboardCollector::class) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'view ai costs');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $estimates = $this->costs->monthEstimates();
    $rows = [];
    $total_estimate = 0.0;
    $total_billed = 0.0;
    // For the grand total: billed where the provider has reported, otherwise
    // kb's estimate.
    $ai_total = 0.0;
    $notes = [];

    foreach (self::PROVIDERS as $provider => [$label, $billing]) {
      $estimate = $estimates[$provider] ?? ['cost' => 0.0, 'calls' => 0, 'unpriced' => 0];
      $billed = $this->costs->billed($billing);
      $total_estimate += $estimate['cost'];
      $total_billed += $billed['amount'] ?? 0.0;
      $ai_total += $billed['amount'] ?? $estimate['cost'];

      $estimate_cell = $this->money($estimate['cost']);
      if ($estimate['unpriced']) {
        $estimate_cell .= ' *';
        $notes[] = $this->t('* @count @label calls used a model with no price in settings, so they are missing from the estimate.', [
          '@count' => $estimate['unpriced'],
          '@label' => $label,
        ]);
      }
      $rows[] = [
        $label,
        $estimate_cell,
        $this->billedCell($billed),
      ];
    }
    $rows[] = [
      ['data' => $this->t('AI total'), 'header' => TRUE],
      ['data' => $this->money($total_estimate), 'header' => TRUE],
      ['data' => $this->money($total_billed), 'header' => TRUE],
    ];

    $requests = $this->costs->monthChatRequests();
    $chat_cost = $estimates['anthropic']['cost'] ?? 0.0;
    $build['month'] = [
      '#markup' => '<p>' . $this->t('@month (UTC)', ['@month' => $this->dateFormatter->format($this->costs->monthStart(), 'custom', 'F Y', 'UTC')]) . '</p>',
    ];
    $build['ai_heading'] = ['#markup' => '<h3>' . $this->t('AI') . '</h3>'];
    $build['table'] = [
      '#type' => 'table',
      '#header' => ['', $this->t('Live estimate'), $this->t('Billed by provider')],
      '#rows' => $rows,
    ];
    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Chat requests this month: @count · Average Claude cost per request: @average', [
        '@count' => number_format($requests),
        '@average' => $requests ? $this->money($chat_cost / $requests, 3) : '-',
      ]) . '</p>',
    ];

    $build['hosting_heading'] = ['#markup' => '<h3>' . $this->t('Upsun hosting') . '</h3>'];
    $hosting = $this->hosting();
    $build['hosting'] = $hosting['build'];

    $build['total'] = [
      '#markup' => '<p class="kb-costs__total"><strong>' . $this->t('Total this month: @total', [
        '@total' => $this->money($ai_total + ($hosting['amount'] ?? 0.0)),
      ]) . '</strong><br>' . ($hosting['amount'] === NULL
        ? $this->t('AI @ai; hosting not available yet.', ['@ai' => $this->money($ai_total)])
        : $this->t('AI @ai + hosting @hosting', ['@ai' => $this->money($ai_total), '@hosting' => $this->money($hosting['amount'])])) . '</p>',
    ];
    $notes[] = $this->t('AI in the total uses each provider\'s billed figure where it has reported one, otherwise kb\'s live estimate.');
    foreach ($notes as $index => $note) {
      $build['note_' . $index] = ['#markup' => '<p><small>' . $note . '</small></p>'];
    }
    $build['#cache'] = [
      // Estimates change with every call; a short cache keeps the block cheap.
      'max-age' => 300,
      'contexts' => ['user.permissions'],
      // Hosting figures change when the Upsun dashboard refreshes.
      'tags' => ['upsun_dashboard'],
    ];
    return $build;
  }

  /**
   * The FAITH Flex billing profile's open and last invoices.
   *
   * kb is the only project on that profile, so all of it counts: the project's
   * charges, and the organization's support, user licenses and discounts.
   *
   * @return array
   *   'build': a render array; 'amount': this month's total in USD, or NULL
   *   if there is none.
   */
  protected function hosting(): array {
    $profile_id = Settings::get('kb_chat')['upsun_billing_profile'] ?? '';
    if (!$this->upsun || !$profile_id) {
      return [
        'build' => ['#markup' => '<p>' . $this->t('Not set up: needs the Upsun Dashboard module and the billing profile in settings.php.') . '</p>'],
        'amount' => NULL,
      ];
    }
    $snapshot = $this->upsun->getSnapshot();
    $profile = $snapshot['billing']['profiles'][$profile_id] ?? NULL;
    $current = $profile['current'] ?? NULL;
    // Only this month's invoice counts toward this month.
    if ($current && substr($current['start'], 0, 7) !== $this->costs->month()) {
      $current = NULL;
    }
    $previous = $profile['previous'] ?? NULL;

    $cell = fn(?array $invoice, string $part) => $invoice ? match ($part) {
      'projects' => $this->money(($invoice['total'] - $invoice['unassigned']) / 100),
      'unassigned' => $this->money($invoice['unassigned'] / 100),
      'total' => $this->money($invoice['total'] / 100),
    } : '-';
    $last_month = $previous
      ? $this->dateFormatter->format(strtotime($previous['start']), 'custom', 'F', 'UTC')
      : $this->t('Last month');
    $build['table'] = [
      '#type' => 'table',
      '#header' => ['', $this->t('This month'), $last_month],
      '#rows' => [
        [$this->t('KB project'), $cell($current, 'projects'), $cell($previous, 'projects')],
        [$this->t('Support, user licenses and discounts'), $cell($current, 'unassigned'), $cell($previous, 'unassigned')],
        [
          ['data' => $this->t('Hosting total'), 'header' => TRUE],
          ['data' => $cell($current, 'total'), 'header' => TRUE],
          ['data' => $cell($previous, 'total'), 'header' => TRUE],
        ],
      ],
    ];
    $note = $snapshot['updated']
      ? $this->t('FAITH Flex organization, from the open invoice as of @time. Resources are priced for the whole month; usage such as requests and bandwidth grows until month end.', [
        '@time' => $this->dateFormatter->format($snapshot['updated'], 'custom', 'M j, g:ia'),
      ])
      : $this->t('The Upsun dashboard hasn\'t collected billing yet.');
    if (!empty($profile['error'])) {
      $note .= ' ' . $this->t('Last refresh failed: @error', ['@error' => $profile['error']]);
    }
    elseif ($snapshot['updated'] && !$profile) {
      $note .= ' ' . $this->t('The Upsun token can\'t see this billing profile.');
    }
    $build['note'] = ['#markup' => '<p><small>' . $note . '</small></p>'];
    return [
      'build' => $build,
      'amount' => $current ? $current['total'] / 100 : NULL,
    ];
  }

  /**
   * The billed figure with when it was reported, or why there is none.
   */
  protected function billedCell(?array $billed): string {
    if (!$billed || !isset($billed['amount'])) {
      return isset($billed['error'])
        ? (string) $this->t('Error: @error', ['@error' => $billed['error']])
        : (string) $this->t('Not reported yet');
    }
    $cell = $this->money($billed['amount']) . ' ' . $this->t('(as of @time)', [
      '@time' => $this->dateFormatter->format($billed['as_of'], 'custom', 'M j, g:ia'),
    ]);
    if (!empty($billed['budget'])) {
      $cell .= ' · ' . $this->t('budget @budget', ['@budget' => $this->money($billed['budget'])]);
    }
    if (!empty($billed['error'])) {
      $cell .= ' · ' . $this->t('last refresh failed');
    }
    return $cell;
  }

  /**
   * Formats US dollars.
   */
  protected function money(float $amount, int $decimals = 2): string {
    return '$' . number_format($amount, $decimals);
  }

}
