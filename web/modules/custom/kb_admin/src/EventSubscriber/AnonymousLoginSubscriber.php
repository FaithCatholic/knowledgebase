<?php

namespace Drupal\kb_admin\EventSubscriber;

use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the login page the start page for anonymous visitors.
 *
 * kb is staff-only: anonymous users can't view content. So instead of the
 * front page or an "Access denied" page, they get the (Gin) login page, with
 * a destination so they land where they were going after signing in.
 */
class AnonymousLoginSubscriber implements EventSubscriberInterface {

  /**
   * Routes never redirected: the pages anonymous users need, and files.
   */
  const OPEN_ROUTES = [
    'user.login',
    'user.register',
    'user.pass',
    'user.reset',
    'user.reset.form',
    'user.reset.login',
    'user.login.http',
    // File and image requests keep their 403; they aren't page views.
    'system.files',
    'system.private_file_download',
    'image.style_private',
  ];

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected PathMatcherInterface $pathMatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing (priority 32), so the route is known.
      KernelEvents::REQUEST => ['onRequest', 30],
      // Before the HTML 403 page is built (priority -50 and below), after
      // the exception is logged (50).
      KernelEvents::EXCEPTION => ['onException', 40],
    ];
  }

  /**
   * Sends anonymous visitors of the front page to the login page.
   */
  public function onRequest(RequestEvent $event): void {
    if ($event->isMainRequest() && $this->applies($event->getRequest()) && $this->pathMatcher->isFrontPage()) {
      $event->setResponse($this->loginRedirect());
    }
  }

  /**
   * Sends anonymous visitors of a denied page to the login page.
   */
  public function onException(ExceptionEvent $event): void {
    if ($event->getThrowable() instanceof AccessDeniedHttpException && $event->isMainRequest() && $this->applies($event->getRequest())) {
      $event->setResponse($this->loginRedirect($event->getRequest()->getRequestUri()));
    }
  }

  /**
   * Whether this is an anonymous page view that may be redirected.
   */
  protected function applies(Request $request): bool {
    return $this->currentUser->isAnonymous()
      && $request->isMethod('GET')
      && !$request->isXmlHttpRequest()
      && $request->getRequestFormat() === 'html'
      && !in_array($request->attributes->get('_route'), self::OPEN_ROUTES, TRUE);
  }

  /**
   * A redirect to the login page, returning to $destination afterwards.
   */
  protected function loginRedirect(?string $destination = NULL): RedirectResponse {
    $options = $destination && $destination !== '/' ? ['query' => ['destination' => $destination]] : [];
    $response = new RedirectResponse(Url::fromRoute('user.login', [], $options)->toString(), 302);
    // Per visitor: never let a cache serve this to someone signed in.
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

}
