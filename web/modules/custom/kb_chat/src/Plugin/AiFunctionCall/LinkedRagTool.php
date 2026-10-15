<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai_search\Plugin\AiFunctionCall\RagTool;
use Drupal\Core\Entity\TranslatableInterface;

/**
 * The RAG/Vector Search tool, with each result's page title and link.
 *
 * AI Search's tool gives the model only the passage text, so answers can name
 * a page but not link to it. kb_chat_ai_function_call_info_alter() swaps this
 * class in for ai_search:rag_search. The search itself, including the
 * per-user access check and the score filter, is unchanged: this only
 * rewrites the text the model receives.
 */
class LinkedRagTool extends RagTool {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    parent::execute();
    if (!$this->results) {
      return;
    }

    $output = "Results from searching the knowledge base for: " . $this->searchString . ".\n"
      . "Each result has its page title and URL. Link the pages you use with Markdown links.\n\n";
    foreach ($this->results as $i => $result) {
      [$title, $url] = $this->source($result);
      $output .= 'Search result #' . ($i + 1) . "\n";
      if ($title !== '') {
        $output .= "Title: $title\n";
      }
      if ($url !== '') {
        $output .= "URL: $url\n";
      }
      $output .= "```\n" . $result['content'] . "\n```\n\n";
    }
    $this->setOutput($output);
  }

  /**
   * A result's page title and absolute URL, or empty strings.
   */
  protected function source(array $result): array {
    if (empty($result['entity_type']) || empty($result['entity_id'])) {
      return ['', ''];
    }
    try {
      $entity = $this->entityTypeManager->getStorage($result['entity_type'])->load($result['entity_id']);
      if (!$entity) {
        return ['', ''];
      }
      if ($entity instanceof TranslatableInterface && $result['langcode'] && $entity->hasTranslation($result['langcode'])) {
        $entity = $entity->getTranslation($result['langcode']);
      }
      $url = $entity->hasLinkTemplate('canonical') ? $entity->toUrl('canonical', ['absolute' => TRUE])->toString() : '';
      return [(string) $entity->label(), $url];
    }
    catch (\Exception) {
      return ['', ''];
    }
  }

}
