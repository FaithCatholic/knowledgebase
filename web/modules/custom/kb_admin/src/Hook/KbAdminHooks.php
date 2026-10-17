<?php

namespace Drupal\kb_admin\Hook;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for KB admin.
 */
class KbAdminHooks {

  /**
   * Google's "G" mark, for the Google sign-in button.
   */
  const GOOGLE_ICON = '<svg class="kb-google-icon" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected RouteProviderInterface $routeProvider,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for user_login_form.
   *
   * Runs after Gin Login and Microsoft 365 SSO have added their parts, then:
   * - adds a "Sign in with Google" button (Social Auth Google), returning to
   *   the page the user was going to;
   * - orders the form as Log in, Microsoft 365, Google, then Gin Login's
   *   "Forgot your password?" link. Gin Login's links and the Microsoft 365
   *   buttons both had weight 99, so they overlapped.
   */
  #[Hook('form_user_login_form_alter', order: Order::Last)]
  public function userLoginFormAlter(array &$form, FormStateInterface $form_state): void {
    $form['actions']['#weight'] = 90;
    if (isset($form['o365_actions'])) {
      $form['o365_actions']['#weight'] = 91;
    }

    if ($this->routeExists('social_auth.network.redirect') && $this->routeExists('social_auth_google.settings_form')) {
      $destination = $this->requestStack->getCurrentRequest()?->query->get('destination');
      $form['kb_google_login'] = [
        '#type' => 'actions',
        '#attributes' => ['class' => ['kb-social-login']],
        '#weight' => 92,
        'google' => [
          '#type' => 'link',
          '#title' => Markup::create(self::GOOGLE_ICON . '<span>' . t('Sign in with Google') . '</span>'),
          '#url' => Url::fromRoute('social_auth.network.redirect', ['network' => 'google'], $destination ? ['query' => ['destination' => $destination]] : []),
          '#attributes' => ['class' => ['button', 'kb-google-login']],
        ],
      ];
    }

    if (isset($form['more-links'])) {
      $form['more-links']['#weight'] = 100;
    }
  }

  /**
   * Whether a route exists (the module providing it is enabled).
   */
  protected function routeExists(string $name): bool {
    return (bool) $this->routeProvider->getRoutesByNames([$name]);
  }

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
