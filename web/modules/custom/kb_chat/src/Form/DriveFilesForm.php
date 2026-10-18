<?php

namespace Drupal\kb_chat\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\kb_chat\Drive\DriveSync;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the Google Drive files in the chat, to find ones to leave out.
 *
 * Removing files deletes their documents, which takes them out of the search
 * index at once, and adds them to exclude_files so the next listing doesn't
 * bring them back.
 */
class DriveFilesForm extends FormBase {

  const PER_PAGE = 50;

  /**
   * File types: MIME type => label.
   */
  const TYPES = [
    'application/vnd.google-apps.document' => 'Google Doc',
    'application/vnd.google-apps.spreadsheet' => 'Google Sheet',
    'application/vnd.google-apps.presentation' => 'Google Slides',
    'application/pdf' => 'PDF',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel',
    'text/plain' => 'Text',
    'text/csv' => 'CSV',
    'text/markdown' => 'Markdown',
    'text/x-markdown' => 'Markdown',
  ];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kb_chat_drive_files';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $filters = $this->filters();

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    $form['filters']['title'] = [
      '#type' => 'search',
      '#title' => $this->t('Name contains'),
      '#default_value' => $filters['title'],
      '#size' => 30,
    ];
    $form['filters']['drive'] = [
      '#type' => 'select',
      '#title' => $this->t('Shared drive'),
      '#options' => ['' => $this->t('- Any -')] + $this->drives(),
      '#default_value' => $filters['drive'],
    ];
    $form['filters']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => ['' => $this->t('- Any -')] + array_unique(self::TYPES),
      '#default_value' => $filters['type'],
    ];
    $form['filters']['tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Visible to'),
      '#options' => ['' => $this->t('- Any -'), 'staff' => $this->t('All staff'), 'management' => $this->t('Management only')],
      '#default_value' => $filters['tier'],
    ];
    $form['filters']['actions'] = ['#type' => 'actions'];
    $form['filters']['actions']['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#submit' => ['::filter'],
    ];
    if (array_filter($filters)) {
      $form['filters']['actions']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#submit' => ['::reset'],
      ];
    }

    $header = [
      'title' => ['data' => $this->t('Name'), 'field' => 'title', 'sort' => 'asc'],
      'drive' => ['data' => $this->t('Shared drive'), 'field' => 'location'],
      'type' => ['data' => $this->t('Type'), 'field' => 'mime_type'],
      'tier' => ['data' => $this->t('Visible to'), 'field' => 'tier'],
      'modified' => ['data' => $this->t('Modified'), 'field' => 'modified'],
      'chars' => ['data' => $this->t('Text'), 'field' => 'chars'],
    ];

    $query = $this->query($filters);
    $total = (int) (clone $query)->countQuery()->execute()->fetchField();
    $query->fields('d', ['id', 'title', 'url', 'location', 'mime_type', 'tier', 'modified']);
    $query->addExpression('LENGTH([text])', 'chars');
    $rows = $query
      ->extend(TableSortExtender::class)->orderByHeader($header)
      ->extend(PagerSelectExtender::class)->limit(self::PER_PAGE)
      ->execute();

    $options = [];
    foreach ($rows as $row) {
      $options[$row->id] = [
        'title' => $row->url
          ? ['data' => Link::fromTextAndUrl($row->title, Url::fromUri($row->url, ['attributes' => ['target' => '_blank']]))->toRenderable()]
          : $row->title,
        'drive' => $row->location,
        'type' => self::TYPES[$row->mime_type] ?? $row->mime_type,
        'tier' => $row->tier === 'management' ? $this->t('Management') : $this->t('All staff'),
        'modified' => $row->modified ? $this->dateFormatter->format($row->modified, 'custom', 'M j, Y') : '',
        'chars' => $this->size((int) $row->chars),
      ];
    }

    $form['summary'] = [
      '#markup' => '<p>' . $this->formatPlural($total, '1 file in the chat matches.', '@count files in the chat match.') . ' '
        . $this->t('Removing files takes them out of the chat now and adds them to "Files to leave out" in the <a href=":settings">Google Drive settings</a>, so the sync doesn\'t bring them back. To leave out a whole folder, add it to "Folders to leave out" there instead.', [
          ':settings' => Url::fromRoute('kb_chat.drive_settings')->toString(),
        ]) . '</p>',
    ];
    $form['files'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('No files match.'),
      '#js_select' => TRUE,
    ];
    $form['pager'] = ['#type' => 'pager'];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected files from the chat'),
      '#button_type' => 'danger',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getTriggeringElement()['#parents'] === ['remove'] && !array_filter($form_state->getValue('files') ?? [])) {
      $form_state->setErrorByName('files', $this->t('Select the files to remove.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Removes the selected files: excludes them, then deletes their documents.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = array_keys(array_filter($form_state->getValue('files')));
    $storage = $this->entityTypeManager->getStorage('kb_chat_document');
    /** @var \Drupal\kb_chat\Entity\KbChatDocument[] $documents */
    $documents = $storage->loadMultiple($ids);

    // Exclude first: if deleting fails part way, the next listing still
    // removes the rest.
    $config = $this->configFactory()->getEditable('kb_chat.drive');
    $entries = $config->get('exclude_files') ?? [];
    $known = array_flip(DriveSync::fileIds($entries));
    foreach ($documents as $document) {
      $file_id = (string) $document->get('source_id')->value;
      if ($document->get('source')->value === DriveSync::SOURCE && !isset($known[$file_id])) {
        $entries[] = $file_id . '  ' . str_replace(["\r", "\n"], ' ', $document->label());
        $known[$file_id] = TRUE;
      }
    }
    $config->set('exclude_files', $entries)->save();
    $storage->delete($documents);

    $this->messenger()->addStatus($this->formatPlural(count($documents),
      'Removed 1 file from the chat. To let it back in, delete its line from "Files to leave out" in the <a href=":settings">Google Drive settings</a>.',
      'Removed @count files from the chat. To let one back in, delete its line from "Files to leave out" in the <a href=":settings">Google Drive settings</a>.',
      [':settings' => Url::fromRoute('kb_chat.drive_settings')->toString()],
    ));
    // Stay on the same filters and page.
    $form_state->setRedirect('kb_chat.drive_files', [], ['query' => $this->getRequest()->query->all()]);
  }

  /**
   * Applies the filters, by reloading the page with them in the URL.
   */
  public function filter(array &$form, FormStateInterface $form_state): void {
    $query = array_filter([
      'title' => trim((string) $form_state->getValue('title')),
      'drive' => $form_state->getValue('drive'),
      'type' => $form_state->getValue('type'),
      'tier' => $form_state->getValue('tier'),
    ]);
    $form_state->setRedirect('kb_chat.drive_files', [], ['query' => $query]);
  }

  /**
   * Clears the filters.
   */
  public function reset(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('kb_chat.drive_files');
  }

  /**
   * The filters from the URL.
   */
  protected function filters(): array {
    $query = $this->getRequest()->query;
    return [
      'title' => trim((string) $query->get('title', '')),
      'drive' => (string) $query->get('drive', ''),
      'type' => (string) $query->get('type', ''),
      'tier' => (string) $query->get('tier', ''),
    ];
  }

  /**
   * The Drive documents matching the filters.
   */
  protected function query(array $filters) {
    $query = $this->database->select('kb_chat_document', 'd')
      ->condition('d.source', DriveSync::SOURCE);
    if ($filters['title'] !== '') {
      $query->condition('d.title', '%' . $this->database->escapeLike($filters['title']) . '%', 'LIKE');
    }
    if ($filters['drive'] !== '') {
      $query->condition('d.location', $filters['drive']);
    }
    if ($filters['type'] !== '') {
      // Every MIME type with the chosen type's label (Markdown has two).
      $label = self::TYPES[$filters['type']] ?? NULL;
      $query->condition('d.mime_type', $label ? array_keys(self::TYPES, $label) : [$filters['type']], 'IN');
    }
    if ($filters['tier'] !== '') {
      $query->condition('d.tier', $filters['tier']);
    }
    return $query;
  }

  /**
   * The shared drives that have documents, as options.
   */
  protected function drives(): array {
    $drives = $this->database->select('kb_chat_document', 'd')
      ->fields('d', ['location'])
      ->condition('d.source', DriveSync::SOURCE)
      ->distinct()
      ->orderBy('d.location')
      ->execute()
      ->fetchCol();
    return array_combine($drives, $drives);
  }

  /**
   * A text length, roughly: "12 K chars".
   */
  protected function size(int $chars): string {
    if ($chars >= 1000000) {
      return (string) $this->t('@count M chars', ['@count' => round($chars / 1000000, 1)]);
    }
    if ($chars >= 1000) {
      return (string) $this->t('@count K chars', ['@count' => round($chars / 1000)]);
    }
    return (string) $this->t('@count chars', ['@count' => $chars]);
  }

}
