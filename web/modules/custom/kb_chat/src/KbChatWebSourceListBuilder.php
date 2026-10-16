<?php

namespace Drupal\kb_chat;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Lists the trusted websites with their indexed pages and last crawl.
 */
class KbChatWebSourceListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Website'),
      'url' => $this->t('Starts at'),
      'frequency' => $this->t('Changes'),
      'tier' => $this->t('Visible to'),
      'pages' => $this->t('Pages indexed'),
      'crawled' => $this->t('Last crawled'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\kb_chat\Entity\KbChatWebSource $entity */
    $pages = \Drupal::database()->select('kb_chat_document', 'd')
      ->condition('source', 'web')
      ->condition('location', $entity->label())
      ->countQuery()->execute()->fetchField();
    $crawled = \Drupal::state()->get('kb_chat.web_crawled.' . $entity->id());
    $start = $entity->get('start_urls')[0] ?? '';
    return [
      'label' => $entity->label(),
      'url' => $start,
      'frequency' => ucfirst($entity->get('frequency')),
      'tier' => $entity->get('tier') === 'management' ? $this->t('Management') : $this->t('All staff'),
      'pages' => $pages,
      'crawled' => $crawled ? \Drupal::service('date.formatter')->format($crawled, 'short') : $this->t('Not yet'),
      'status' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $operations['crawl'] = [
      'title' => $this->t('Crawl now'),
      'weight' => 20,
      'url' => Url::fromRoute('kb_chat.web_source_crawl', ['kb_chat_web_source' => $entity->id()]),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['description'] = [
      '#markup' => '<p>' . $this->t('Outside websites the kb chat searches and cites. Pages are indexed, not copied into kb: answers link to the website. Websites are re-crawled by how often they change; "Crawl now" queues one for the next hourly run.') . '</p>',
    ];
    return $build + parent::render();
  }

}
