<?php

namespace Drupal\kb_chat\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\kb_chat\AnthropicCostReport;

/**
 * Hook implementations for KB Chat.
 */
class KbChatHooks {

  public function __construct(
    protected AnthropicCostReport $anthropicCosts,
    protected StateInterface $state,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Refreshes Anthropic's billed figure once it is older than the refresh
   * interval (default one hour). kb's cron runs every 19 minutes.
   */
  #[Hook('cron')]
  public function cron(): void {
    $interval = (int) (Settings::get('kb_chat')['refresh_interval'] ?? 3600);
    $last = (int) $this->state->get('kb_chat.anthropic_checked', 0);
    if ($this->anthropicCosts->isConfigured() && time() - $last >= $interval) {
      $this->state->set('kb_chat.anthropic_checked', time());
      $this->anthropicCosts->refresh();
    }
  }

}
