<?php

namespace Drupal\kb_chat\Controller;

use Drupal\ai_chatbot\Controller\DeepChatApi;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\kb_chat\ConversationLog;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "My searches": each user's past FAITH Search Agent conversations.
 *
 * Users only ever see their own conversations, kept for 30 days.
 */
class MySearchesController extends ControllerBase {

  public function __construct(
    protected ConversationLog $conversations,
    protected DateFormatterInterface $dateFormatter,
    protected CommonMarkConverter $converter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ConversationLog::class),
      $container->get('date.formatter'),
      $container->get('ai.commonmark_converter'),
    );
  }

  /**
   * The list of the user's conversations, by day.
   */
  public function list(): array {
    $build = [
      '#attached' => ['library' => ['kb_chat/my-searches']],
      '#cache' => ['contexts' => ['user'], 'max-age' => 0],
      'intro' => [
        '#markup' => '<p>' . $this->t('Your conversations with FAITH Search Agent from the last @days days. Only you can see them here.', ['@days' => ConversationLog::KEEP_DAYS]) . '</p>',
      ],
    ];
    $conversations = $this->conversations->conversations((int) $this->currentUser()->id());
    if (!$conversations) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No conversations yet. Questions you ask FAITH Search Agent will be listed here.') . '</p>'];
      return $build;
    }

    $days = [];
    foreach ($conversations as $conversation) {
      $day = $this->day((int) $conversation['updated']);
      $days[$day][] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['kb-my-searches__item']],
        'link' => Link::fromTextAndUrl(
          Unicode::truncate($conversation['question'], 140, TRUE, TRUE),
          Url::fromRoute('kb_chat.my_search', ['thread' => $conversation['thread']]),
        )->toRenderable(),
        'meta' => [
          '#markup' => '<div class="kb-my-searches__meta">' . $this->formatPlural((int) $conversation['questions'], '1 question', '@count questions')
            . ' · ' . $this->dateFormatter->format((int) $conversation['updated'], 'custom', 'g:ia') . '</div>',
        ],
      ];
    }
    foreach ($days as $day => $items) {
      $build[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['kb-my-searches__day']],
        'heading' => ['#markup' => '<h2>' . Html::escape($day) . '</h2>'],
        'items' => $items,
      ];
    }
    return $build;
  }

  /**
   * One conversation: each question and its answer, with the answer's links.
   */
  public function view(string $thread): array {
    $exchanges = $this->conversations->conversation((int) $this->currentUser()->id(), $thread);
    if (!$exchanges) {
      throw new NotFoundHttpException();
    }
    $build = [
      '#attached' => ['library' => ['kb_chat/my-searches']],
      '#cache' => ['contexts' => ['user'], 'max-age' => 0],
      'back' => [
        '#markup' => '<p>' . Link::createFromRoute($this->t('← My searches'), 'kb_chat.my_searches')->toString() . '</p>',
      ],
    ];
    foreach ($exchanges as $i => $exchange) {
      // The answer is model output: render its Markdown and sanitize it the
      // way the chat does.
      $answer = Xss::filter($this->converter->convert($exchange['answer'])->getContent(), DeepChatApi::ALLOWED_TAGS);
      $build['exchange_' . $i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['kb-my-searches__exchange']],
        'question' => [
          '#markup' => '<div class="kb-my-searches__question"><div class="kb-my-searches__meta">'
            . $this->t('You asked, @time', ['@time' => $this->dateFormatter->format((int) $exchange['created'], 'custom', 'M j, g:ia')])
            . '</div>' . nl2br(Html::escape($exchange['question'])) . '</div>',
        ],
        'answer' => [
          '#markup' => Markup::create('<div class="kb-my-searches__answer">' . $answer . '</div>'),
        ],
      ];
    }
    $build['delete'] = [
      '#markup' => '<p class="kb-my-searches__delete">' . Link::createFromRoute(
        $this->t('Delete this conversation'),
        'kb_chat.my_search_delete',
        ['thread' => $thread],
      )->toString() . '</p>',
    ];
    return $build;
  }

  /**
   * The conversation's title: its first question, shortened.
   */
  public function title(string $thread): string {
    $exchanges = $this->conversations->conversation((int) $this->currentUser()->id(), $thread);
    return $exchanges ? Unicode::truncate($exchanges[0]['question'], 80, TRUE, TRUE) : (string) $this->t('Conversation');
  }

  /**
   * Deletes one of the user's conversations.
   */
  public function delete(string $thread) {
    $this->conversations->delete((int) $this->currentUser()->id(), $thread);
    $this->messenger()->addStatus($this->t('Deleted the conversation.'));
    return $this->redirect('kb_chat.my_searches');
  }

  /**
   * "Today", "Yesterday", or the date.
   */
  protected function day(int $timestamp): string {
    $date = $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d');
    return match ($date) {
      $this->dateFormatter->format(time(), 'custom', 'Y-m-d') => (string) $this->t('Today'),
      $this->dateFormatter->format(time() - 86400, 'custom', 'Y-m-d') => (string) $this->t('Yesterday'),
      default => $this->dateFormatter->format($timestamp, 'custom', 'l, F j'),
    };
  }

}
