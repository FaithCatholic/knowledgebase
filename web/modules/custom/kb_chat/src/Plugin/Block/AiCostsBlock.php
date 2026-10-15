<?php

namespace Drupal\kb_chat\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\CostTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the kb chat's month-to-date AI costs.
 *
 * For each provider: kb's live estimate and the provider's billed figure.
 */
#[Block(
  id: 'kb_chat_ai_costs',
  admin_label: new TranslatableMarkup('AI costs this month'),
  category: new TranslatableMarkup('KB Chat'),
)]
class AiCostsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $notes = [];

    foreach (self::PROVIDERS as $provider => [$label, $billing]) {
      $estimate = $estimates[$provider] ?? ['cost' => 0.0, 'calls' => 0, 'unpriced' => 0];
      $billed = $this->costs->billed($billing);
      $total_estimate += $estimate['cost'];
      $total_billed += $billed['amount'] ?? 0.0;

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
      ['data' => $this->t('Total'), 'header' => TRUE],
      ['data' => $this->money($total_estimate), 'header' => TRUE],
      ['data' => $this->money($total_billed), 'header' => TRUE],
    ];

    $requests = $this->costs->monthChatRequests();
    $chat_cost = $estimates['anthropic']['cost'] ?? 0.0;
    $build['table'] = [
      '#type' => 'table',
      '#caption' => $this->t('@month (UTC)', ['@month' => $this->dateFormatter->format($this->costs->monthStart(), 'custom', 'F Y', 'UTC')]),
      '#header' => ['', $this->t('Live estimate'), $this->t('Billed by provider')],
      '#rows' => $rows,
    ];
    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Chat requests this month: @count · Average Claude cost per request: @average', [
        '@count' => number_format($requests),
        '@average' => $requests ? $this->money($chat_cost / $requests, 3) : '-',
      ]) . '</p>',
    ];
    foreach ($notes as $index => $note) {
      $build['note_' . $index] = ['#markup' => '<p><small>' . $note . '</small></p>'];
    }
    $build['#cache'] = [
      // Estimates change with every call; a short cache keeps the block cheap.
      'max-age' => 300,
      'contexts' => ['user.permissions'],
    ];
    return $build;
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
