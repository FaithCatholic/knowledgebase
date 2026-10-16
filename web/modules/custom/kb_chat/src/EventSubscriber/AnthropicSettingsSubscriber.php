<?php

namespace Drupal\kb_chat\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes sampling settings that current Claude models reject.
 *
 * Claude Opus 5.5 and the other 5.x models refuse temperature, top_p and top_k
 * ("`temperature` is deprecated for this model"), and the assistant form saves
 * temperature: 0 whenever it is re-saved, which broke the chat. Stripping them
 * here keeps every Anthropic request valid whatever the forms store.
 */
class AnthropicSettingsSubscriber implements EventSubscriberInterface {

  /**
   * Settings Claude 5.x rejects.
   */
  const REJECTED = ['temperature', 'top_p', 'top_k'];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreGenerateResponseEvent::EVENT_NAME => 'removeSamplingSettings'];
  }

  /**
   * Strips the rejected settings from Anthropic requests.
   */
  public function removeSamplingSettings(PreGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'anthropic') {
      return;
    }
    $configuration = $event->getConfiguration();
    $cleaned = array_diff_key($configuration, array_flip(self::REJECTED));
    if ($cleaned !== $configuration) {
      $event->setConfiguration($cleaned);
    }
  }

}
