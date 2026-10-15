<?php

namespace Drupal\chargebee_tools;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Finds the bulk Chargebee operations in src/Plugin/ChargebeeOperation.
 */
class ChargebeeOperationManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ChargebeeOperation', $namespaces, $module_handler, ChargebeeOperationInterface::class, ChargebeeOperation::class);
    $this->alterInfo('chargebee_operation_info');
    $this->setCacheBackend($cache_backend, 'chargebee_operation_plugins');
  }

  /**
   * The operation definitions, sorted by weight and then label.
   */
  public function getSortedDefinitions(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, fn($a, $b) => [$a['weight'] ?? 0, (string) $a['label']] <=> [$b['weight'] ?? 0, (string) $b['label']]);
    return $definitions;
  }

}
