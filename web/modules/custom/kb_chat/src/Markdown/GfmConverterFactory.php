<?php

namespace Drupal\kb_chat\Markdown;

use Drupal\ai\Service\CommonMarkConverterFactory;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Adds GitHub-style tables, strikethrough and bare-URL links to AI Markdown.
 *
 * Replaces the AI module's converter factory (KbChatServiceProvider), so chat
 * answers and chat history render the tables Claude writes instead of showing
 * raw pipes. DeepChat's tag filter is patched to keep the table tags
 * (patches/ai_chatbot-allow-table-tags.patch). The converter class is final,
 * but its environment takes extensions until the first conversion.
 */
class GfmConverterFactory extends CommonMarkConverterFactory {

  /**
   * {@inheritdoc}
   */
  public function fromOptions(array $options = []): CommonMarkConverter {
    $converter = parent::fromOptions($options);
    $converter->getEnvironment()
      ->addExtension(new TableExtension())
      ->addExtension(new StrikethroughExtension())
      ->addExtension(new AutolinkExtension());
    return $converter;
  }

}
