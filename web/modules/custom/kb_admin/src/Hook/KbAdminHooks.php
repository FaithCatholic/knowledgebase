<?php

namespace Drupal\kb_admin\Hook;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for KB admin.
 */
class KbAdminHooks {

  public function __construct(
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * Implements hook_library_info_alter().
   *
   * Adds our overrides to Gin Login's login library, so they load only on the
   * Gin Login pages and after Gin's own styles.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'gin_login' && isset($libraries['login'])) {
      $path = '/' . $this->moduleList->getPath('kb_admin') . '/css/gin-login.css';
      $libraries['login']['css']['theme'][$path] = ['weight' => 100];
    }
  }

}
