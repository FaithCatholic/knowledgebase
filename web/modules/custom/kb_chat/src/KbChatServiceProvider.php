<?php

namespace Drupal\kb_chat;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\kb_chat\Markdown\GfmConverterFactory;

/**
 * Swaps in a Markdown converter factory that renders tables.
 */
class KbChatServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('ai.commonmark_converter_factory')) {
      $container->getDefinition('ai.commonmark_converter_factory')->setClass(GfmConverterFactory::class);
    }
  }

}
