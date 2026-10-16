<?php

namespace Drupal\kb_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\kb_chat\Entity\KbChatWebSource;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Queues a trusted website for crawling on the next hourly run.
 */
class WebSourceController extends ControllerBase {

  /**
   * Marks the website as due and returns to the list.
   *
   * Crawling a site takes minutes, too long for a page request, so the
   * hourly `drush kb-web --due` run picks it up.
   */
  public function crawl(KbChatWebSource $kb_chat_web_source): RedirectResponse {
    $this->state()->set('kb_chat.web_crawled.' . $kb_chat_web_source->id(), 0);
    $this->messenger()->addStatus($this->t('%label will be crawled within the hour.', ['%label' => $kb_chat_web_source->label()]));
    return $this->redirect('entity.kb_chat_web_source.collection');
  }

}
