<?php

namespace Drupal\kb_chat\Drush\Commands;

use Drupal\kb_chat\AnthropicCostReport;
use Drupal\kb_chat\CostTracker;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for KB Chat.
 */
final class KbChatCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly CostTracker $costs,
    private readonly AnthropicCostReport $anthropicCosts,
  ) {
    parent::__construct();
  }

  /**
   * Refreshes Anthropic's billed figure and prints this month's AI costs.
   */
  #[CLI\Command(name: 'kb-chat:costs', aliases: ['kb-costs'])]
  #[CLI\Usage(name: 'drush kb-costs', description: 'Show month-to-date estimates and billed totals.')]
  public function costs(): void {
    if ($this->anthropicCosts->isConfigured()) {
      $this->anthropicCosts->refresh();
    }
    else {
      $this->logger()->warning(dt('ANTHROPIC_ADMIN_API_KEY is not set, so there is no billed Claude figure.'));
    }
    $estimates = $this->costs->monthEstimates();
    foreach (['anthropic' => 'anthropic', 'gemini' => 'google'] as $provider => $billing) {
      $billed = $this->costs->billed($billing);
      $this->output()->writeln(sprintf(
        '%-10s estimate $%.2f (%d calls, %d unpriced)   billed %s',
        $provider,
        $estimates[$provider]['cost'] ?? 0,
        $estimates[$provider]['calls'] ?? 0,
        $estimates[$provider]['unpriced'] ?? 0,
        isset($billed['amount']) ? sprintf('$%.2f as of %s', $billed['amount'], date('Y-m-d H:i', $billed['as_of'])) : ($billed['error'] ?? 'not reported yet'),
      ));
    }
  }

}
