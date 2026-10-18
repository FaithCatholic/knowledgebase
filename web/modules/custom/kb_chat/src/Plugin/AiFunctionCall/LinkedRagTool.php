<?php

namespace Drupal\kb_chat\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_search\Plugin\AiFunctionCall\RagTool;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\kb_chat\Drive\DriveSync;
use Drupal\kb_chat\Entity\KbChatDocument;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Priority documents: Drive file ID => what it's for.
   */
  protected array $priority = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->priority = $container->get(DriveSync::class)->priorityDocuments();
    return $instance;
  }

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
      [$title, $url, $origin, $priority] = $this->source($result);
      $output .= 'Search result #' . ($i + 1) . "\n";
      if ($title !== '') {
        $output .= "Title: $title\n";
      }
      if ($origin !== '') {
        $output .= "From: $origin\n";
      }
      if ($url !== '') {
        $output .= "URL: $url\n";
      }
      if ($priority !== NULL) {
        $output .= 'Priority document' . ($priority !== '' ? " ($priority)" : '') . ": it takes precedence over other sources on the same subject. Use Read priority documents for its current, complete text.\n";
      }
      $output .= "```\n" . $result['content'] . "\n```\n\n";
    }
    $this->setOutput($output);
  }

  /**
   * A result's title, absolute URL, origin and priority note.
   *
   * The origin says where the text comes from: a kb page, a Google Drive
   * shared drive, or an outside website, so answers can say so. The priority
   * note is NULL unless the result is a priority document.
   */
  protected function source(array $result): array {
    if (empty($result['entity_type']) || empty($result['entity_id'])) {
      return ['', '', '', NULL];
    }
    try {
      $entity = $this->entityTypeManager->getStorage($result['entity_type'])->load($result['entity_id']);
      if (!$entity) {
        return ['', '', '', NULL];
      }
      if ($entity instanceof TranslatableInterface && $result['langcode'] && $entity->hasTranslation($result['langcode'])) {
        $entity = $entity->getTranslation($result['langcode']);
      }
      // Google Drive documents link to Drive; nodes to their kb page.
      $priority = NULL;
      if ($entity instanceof KbChatDocument) {
        if ($entity->get('source')->value === DriveSync::SOURCE) {
          $priority = $this->priority[$entity->get('source_id')->value] ?? NULL;
        }
        $url = $entity->getSourceUrl();
        $location = (string) $entity->get('location')->value;
        $origin = $entity->get('source')->value === 'web'
          ? $this->websiteOrigin($location)
          : "$location (Google Drive)";
      }
      else {
        $url = $entity->hasLinkTemplate('canonical') ? $entity->toUrl('canonical', ['absolute' => TRUE])->toString() : '';
        $origin = 'FAITH knowledge base page';
      }
      return [(string) $entity->label(), $url, $origin, $priority];
    }
    catch (\Exception) {
      return ['', '', '', NULL];
    }
  }

  /**
   * How a trusted website's pages are described, from its settings.
   */
  protected function websiteOrigin(string $label): string {
    static $origins = [];
    if (!isset($origins[$label])) {
      $sources = $this->entityTypeManager->getStorage('kb_chat_web_source')->loadByProperties(['label' => $label]);
      $source = reset($sources);
      $origins[$label] = $source ? $source->origin() : "$label (outside website)";
    }
    return $origins[$label];
  }

}
