<?php

namespace Drupal\kb_chat\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\Form\KbChatWebSourceForm;
use Drupal\kb_chat\KbChatWebSourceListBuilder;

/**
 * A trusted outside website the kb chat indexes and cites.
 *
 * WebSync crawls its start URLs, following links under its URL prefixes, and
 * stores each page as a KB chat document (source "web", location = this
 * website's name). Answers link to the website, so kb never copies it.
 */
#[ConfigEntityType(
  id: 'kb_chat_web_source',
  label: new TranslatableMarkup('Trusted website'),
  label_collection: new TranslatableMarkup('KB chat trusted websites'),
  label_singular: new TranslatableMarkup('trusted website'),
  label_plural: new TranslatableMarkup('trusted websites'),
  config_prefix: 'web_source',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => KbChatWebSourceListBuilder::class,
    'form' => [
      'add' => KbChatWebSourceForm::class,
      'edit' => KbChatWebSourceForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/search/kb-chat-websites',
    'add-form' => '/admin/config/search/kb-chat-websites/add',
    'edit-form' => '/admin/config/search/kb-chat-websites/{kb_chat_web_source}',
    'delete-form' => '/admin/config/search/kb-chat-websites/{kb_chat_web_source}/delete',
  ],
  admin_permission: 'administer kb chat websites',
  config_export: [
    'id',
    'label',
    'start_urls',
    'include_prefixes',
    'documents',
    'frequency',
    'tier',
    'max_pages',
  ],
)]
class KbChatWebSource extends ConfigEntityBase {

  /**
   * How often a website is re-crawled, in seconds, by frequency.
   */
  const FREQUENCIES = [
    'daily' => 86400,
    'weekly' => 604800,
    'monthly' => 2592000,
  ];

  protected string $id = '';

  protected string $label = '';

  protected array $start_urls = [];

  protected array $include_prefixes = [];

  protected bool $documents = TRUE;

  protected string $frequency = 'weekly';

  protected string $tier = 'staff';

  protected int $max_pages = 200;

  /**
   * The crawl settings WebSync uses.
   */
  public function toSource(): array {
    return [
      'label' => $this->label(),
      'start' => $this->start_urls,
      'include' => $this->include_prefixes ?: $this->start_urls,
      'documents' => $this->documents,
      'tier' => $this->tier,
      'max_pages' => $this->max_pages,
    ];
  }

  /**
   * Seconds between crawls.
   */
  public function interval(): int {
    return self::FREQUENCIES[$this->frequency] ?? self::FREQUENCIES['weekly'];
  }

  /**
   * {@inheritdoc}
   *
   * Keeps indexed pages attached when the website is renamed: they are
   * grouped by name.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    if ($update && isset($this->original) && $this->original->label() !== $this->label()) {
      \Drupal::database()->update('kb_chat_document')
        ->fields(['location' => $this->label()])
        ->condition('source', 'web')
        ->condition('location', $this->original->label())
        ->execute();
      // The search index keeps the old name in its text until re-indexed.
      self::reindexDocuments($this->label());
    }
  }

  /**
   * {@inheritdoc}
   *
   * Removes the website's indexed pages along with it.
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);
    $documents = \Drupal::entityTypeManager()->getStorage('kb_chat_document');
    foreach ($entities as $entity) {
      $ids = $documents->getQuery()->accessCheck(FALSE)
        ->condition('source', 'web')
        ->condition('location', $entity->label())
        ->execute();
      foreach (array_chunk($ids, 50) as $chunk) {
        $documents->delete($documents->loadMultiple($chunk));
      }
      \Drupal::state()->delete('kb_chat.web_crawled.' . $entity->id());
    }
  }

  /**
   * Re-saves a website's documents so search re-indexes them.
   */
  protected static function reindexDocuments(string $label): void {
    $documents = \Drupal::entityTypeManager()->getStorage('kb_chat_document');
    $ids = $documents->getQuery()->accessCheck(FALSE)
      ->condition('source', 'web')
      ->condition('location', $label)
      ->execute();
    // The location was just changed in the database; don't save stale copies.
    $documents->resetCache($ids);
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($documents->loadMultiple($chunk) as $document) {
        $document->save();
      }
    }
  }

}
