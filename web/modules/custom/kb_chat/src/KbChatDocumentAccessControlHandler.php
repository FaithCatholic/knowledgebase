<?php

namespace Drupal\kb_chat;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Limits each shared drive document to the roles its tier allows.
 *
 * AI Search checks this for every search result, so a management document
 * never reaches the model in a staff member's chat. Documents are only ever
 * created and changed by the sync, so only viewing is granted here.
 */
class KbChatDocumentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * The permission needed to view each tier.
   */
  const TIER_PERMISSIONS = [
    'staff' => 'view kb chat staff documents',
    'management' => 'view kb chat management documents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\kb_chat\Entity\KbChatDocument $entity */
    if ($operation !== 'view') {
      return AccessResult::neutral();
    }
    $permission = self::TIER_PERMISSIONS[$entity->getTier()] ?? NULL;
    // An unknown tier is never viewable: fail closed.
    if (!$permission) {
      return AccessResult::forbidden('Unknown document tier.')->addCacheableDependency($entity);
    }
    return AccessResult::allowedIfHasPermission($account, $permission)->addCacheableDependency($entity);
  }

}
