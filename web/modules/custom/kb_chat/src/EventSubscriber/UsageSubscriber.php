<?php

namespace Drupal\kb_chat\EventSubscriber;

use Drupal\ai\Event\AiProviderResponseBaseEvent;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\kb_chat\CostTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the tokens and estimated cost of every AI provider call.
 *
 * A normal call fires PostGenerateResponseEvent with its token counts. A
 * streamed chat fires it at the start, before any tokens exist, and fires
 * PostStreamingResponseEvent with the counts when the stream ends, so the
 * streamed case is only recorded on the second event.
 *
 * Embedding responses carry no token counts, so they are estimated at four
 * characters per token. That is close enough for Gemini embeddings, which
 * cost little; Google's billed figure is the real one.
 */
class UsageSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected CostTracker $costs,
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'logger.channel.kb_chat')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => 'onResponse',
      PostStreamingResponseEvent::EVENT_NAME => 'onResponse',
    ];
  }

  /**
   * Records one response.
   */
  public function onResponse(AiProviderResponseBaseEvent $event): void {
    // Cost tracking must never break a chat.
    try {
      $usage = $this->usage($event);
      if ($usage) {
        $this->costs->record($usage);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not record AI usage: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * The token counts of a response, or NULL if it shouldn't be recorded.
   */
  protected function usage(AiProviderResponseBaseEvent $event): ?array {
    $usage = [
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'operation' => $event->getOperationType(),
      'thread' => $event->getRequestThreadId(),
      'uid' => (int) $this->currentUser->id(),
    ];
    $output = $event->getOutput();

    if ($output instanceof ChatOutput) {
      // The start of a stream: the counts come with PostStreamingResponseEvent.
      if ($output->getNormalized() instanceof StreamedChatMessageIteratorInterface) {
        return NULL;
      }
      $tokens = $output->getTokenUsage();
      if ($tokens->input === NULL && $tokens->output === NULL) {
        return NULL;
      }
      $cache_read = (int) $tokens->cached;
      $cache_write = (int) $tokens->cachedWrite;
      // Providers differ on whether input includes cached tokens. Count the
      // uncached remainder as input, never less than zero.
      $input = max(0, (int) $tokens->input - $cache_read - $cache_write);
      return $usage + [
        'input_tokens' => $input,
        // Reasoning (thinking) tokens are billed as output.
        'output_tokens' => (int) $tokens->output + (int) $tokens->reasoning,
        'cache_read_tokens' => $cache_read,
        'cache_write_tokens' => $cache_write,
      ];
    }

    $input = $event->getInput();
    if ($usage['operation'] === 'embeddings' && $input instanceof EmbeddingsInput) {
      return $usage + [
        'input_tokens' => (int) ceil(mb_strlen($input->getPrompt()) / 4),
        'tokens_estimated' => 1,
      ];
    }
    return NULL;
  }

}
