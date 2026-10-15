<?php

namespace Drupal\kb_chat\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kb_chat\KbChatDocumentAccessControlHandler;

/**
 * A document from outside Drupal, indexed for the kb chat.
 *
 * Google Drive files are synced into these by DriveSync; later sources (such
 * as the Synology drive) can add their own with a different source value. The
 * chat's search index includes them alongside nodes, and the access control
 * handler limits each one to the roles its tier allows.
 */
#[ContentEntityType(
  id: 'kb_chat_document',
  label: new TranslatableMarkup('Shared drive document'),
  label_collection: new TranslatableMarkup('Shared drive documents'),
  label_singular: new TranslatableMarkup('shared drive document'),
  label_plural: new TranslatableMarkup('shared drive documents'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'access' => KbChatDocumentAccessControlHandler::class,
    // Rendering lets the search index's "Rendered HTML output" field, the
    // chat's single Main content field, cover documents as well as nodes.
    'view_builder' => EntityViewBuilder::class,
  ],
  base_table: 'kb_chat_document',
  admin_permission: 'administer kb chat documents',
)]
class KbChatDocument extends ContentEntityBase {

  /**
   * The access tiers, from widest to narrowest.
   */
  const TIERS = ['staff', 'management'];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Where the document comes from, such as drive.'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);
    $fields['source_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source ID'))
      ->setDescription(t('The ID in the source, such as the Google Drive file ID.'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);
    $fields['tier'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Access tier'))
      ->setDescription(t('staff or management.'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);
    $fields['url'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('URL'))
      ->setDescription(t('Where to open the document, such as its Google Drive link.'));
    $fields['location'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Location'))
      ->setDescription(t('Where the document lives, such as the shared drive name.'))
      ->setSetting('max_length', 255);
    $fields['mime_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME type'))
      ->setSetting('max_length', 128);
    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'))
      ->setDescription(t('The document text, extracted for indexing.'))
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'basic_string',
        'weight' => 0,
      ]);
    $fields['modified'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Modified'))
      ->setDescription(t('When the document last changed in its source.'));
    $fields['synced'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Synced'))
      ->setDescription(t('When the text was last read from the source.'));
    return $fields;
  }

  /**
   * The access tier: staff or management.
   */
  public function getTier(): string {
    return (string) $this->get('tier')->value;
  }

  /**
   * The URL to open the document in its source.
   */
  public function getSourceUrl(): string {
    return (string) $this->get('url')->value;
  }

}
