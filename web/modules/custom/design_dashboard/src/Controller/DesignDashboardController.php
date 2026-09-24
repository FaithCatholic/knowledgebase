<?php

namespace Drupal\design_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the static design dashboard HTML to users with the Management role.
 */
class DesignDashboardController extends ControllerBase {

  /**
   * Returns the dashboard HTML, cloned outside the docroot during the build.
   */
  public function page(): Response {
    // DRUPAL_ROOT is web/, so go up one level to the repo root.
    $file = dirname(DRUPAL_ROOT) . '/design_dashboard/index.html';

    if (!is_readable($file)) {
      throw new NotFoundHttpException();
    }

    return new Response(file_get_contents($file), 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      // Keep shared caches and CDNs from serving it to anonymous users.
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
