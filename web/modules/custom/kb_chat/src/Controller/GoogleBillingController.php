<?php

namespace Drupal\kb_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\kb_chat\CostTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Google Cloud budget notifications from a Pub/Sub push subscription.
 *
 * The push endpoint is /kb-chat/billing/google?token=SECRET, where SECRET is
 * $settings['kb_chat']['google_billing_token'] (GOOGLE_BILLING_PUSH_TOKEN).
 * Google sends the budget's status several times a day. Its costAmount is the
 * project's cost since the start of the budget period (the month), estimated
 * and a few hours behind.
 */
class GoogleBillingController extends ControllerBase {

  public function __construct(
    protected CostTracker $costs,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Saves the cost from one notification.
   *
   * Responds 204 when saved, so Pub/Sub doesn't retry, and 403 for a wrong
   * token. A malformed message is acknowledged (204) and logged, since
   * retrying it would not help.
   */
  public function receive(Request $request): Response {
    $expected = (string) (Settings::get('kb_chat')['google_billing_token'] ?? '');
    $token = (string) $request->query->get('token', '');
    if ($expected === '' || !hash_equals($expected, $token)) {
      return new Response('', 403);
    }

    $envelope = json_decode($request->getContent(), TRUE);
    $notification = json_decode(base64_decode($envelope['message']['data'] ?? '', TRUE) ?: '', TRUE);
    if (!is_array($notification) || !isset($notification['costAmount'])) {
      $this->logger->warning('Ignored a Google billing push without a costAmount.');
      return new Response('', 204);
    }

    // Only this month's budget period counts. A late notification for last
    // month would otherwise replace the new month's figure.
    $period = isset($notification['costIntervalStart']) ? strtotime($notification['costIntervalStart']) : time();
    if (gmdate('Y-m', $period) !== $this->costs->month()) {
      return new Response('', 204);
    }

    $this->costs->setBilled('google', [
      'amount' => round((float) $notification['costAmount'], 2),
      'currency' => $notification['currencyCode'] ?? 'USD',
      'budget' => isset($notification['budgetAmount']) ? (float) $notification['budgetAmount'] : NULL,
      'as_of' => time(),
    ]);
    return new Response('', 204);
  }

}
