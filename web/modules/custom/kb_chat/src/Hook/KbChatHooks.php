<?php

namespace Drupal\kb_chat\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\AnthropicCostReport;
use Drupal\kb_chat\Plugin\AiFunctionCall\LinkedRagTool;

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

  /**
   * Implements hook_ai_function_call_info_alter().
   *
   * Gives the RAG/Vector Search tool's results their page title and link, so
   * chat answers can link the pages they cite.
   */
  #[Hook('ai_function_call_info_alter')]
  public function linkRagResults(array &$definitions): void {
    if (isset($definitions['ai_search:rag_search'])) {
      $definitions['ai_search:rag_search']['class'] = LinkedRagTool::class;
    }
  }

  /**
   * Implements hook_search_api_datasource_info_alter().
   *
   * Search API describes every entity datasource as "Provides X entities for
   * indexing and searching"; say where these documents actually come from.
   */
  #[Hook('search_api_datasource_info_alter')]
  public function describeDocuments(array &$infos): void {
    if (isset($infos['entity:kb_chat_document'])) {
      $infos['entity:kb_chat_document']['description'] = new TranslatableMarkup('Documents from Google Drive shared drives and on-premises file locations (such as the Synology drive), synced for the kb chat.');
    }
  }

  /**
   * Implements hook_block_view_BASE_BLOCK_ID_alter() for ai_deepchat_block.
   *
   * The chatbot module only offers floating placements. When its DeepChat
   * block is placed in the Content region, show it inside the page instead,
   * always open.
   */
  #[Hook('block_view_ai_deepchat_block_alter')]
  public function inlineChat(array &$build, BlockPluginInterface $block): void {
    if (isset($build['#block']) && $build['#block']->getRegion() === 'content') {
      $build['#attributes']['class'][] = 'kb-chat-inline';
      $build['#attached']['library'][] = 'kb_chat/inline-chat';
    }
  }

}
