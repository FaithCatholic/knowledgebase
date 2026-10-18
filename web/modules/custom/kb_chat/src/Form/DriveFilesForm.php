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
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\kb_chat\Drive\DriveSync;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the Google Drive files in the chat, to find ones to leave out.
 *
 * Ignoring files deletes their documents, which takes them out of the search
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
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('state'),
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
        . $this->t('Ignoring files takes them out of the chat now, and the sync won\'t bring them back; they move to "Ignored files" below. To leave out a whole folder, add it to "Folders to leave out" in the <a href=":settings">Google Drive settings</a> instead.', [
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
      '#value' => $this->t('Ignore selected files'),
      '#button_type' => 'danger',
    ];

    // Ignored files have no document any more: only their exclude_files line.
    $ignored = [];
    foreach ($this->configFactory()->get('kb_chat.drive')->get('exclude_files') ?? [] as $entry) {
      $file_id = DriveSync::fileIds([$entry])[0] ?? NULL;
      if ($file_id) {
        $name = trim(mb_substr(trim($entry), strlen($file_id)));
        $ignored[$file_id] = [
          'name' => $name !== '' ? $name : $file_id,
          'link' => ['data' => Link::fromTextAndUrl($this->t('Open in Drive'), Url::fromUri('https://drive.google.com/open', [
            'query' => ['id' => $file_id],
            'attributes' => ['target' => '_blank'],
          ]))->toRenderable()],
        ];
      }
    }
    uasort($ignored, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $form['ignored'] = [
      '#type' => 'details',
      '#title' => $this->t('Ignored files (@count)', ['@count' => count($ignored)]),
      '#open' => (bool) $ignored,
    ];
    $form['ignored']['ignored_files'] = [
      '#type' => 'tableselect',
      '#header' => ['name' => $this->t('Name'), 'link' => ''],
      '#options' => $ignored,
      '#empty' => $this->t('No files are ignored.'),
      '#js_select' => TRUE,
    ];
    if ($ignored) {
      $form['ignored']['unignore'] = [
        '#type' => 'submit',
        '#value' => $this->t('Stop ignoring selected files'),
        '#submit' => ['::unignore'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement()['#parents'];
    if ($button === ['remove'] && !array_filter($form_state->getValue('files') ?? [])) {
      $form_state->setErrorByName('files', $this->t('Select the files to ignore.'));
    }
    if ($button === ['unignore'] && !array_filter($form_state->getValue('ignored_files') ?? [])) {
      $form_state->setErrorByName('ignored_files', $this->t('Select the ignored files to bring back.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Ignores the selected files: excludes them, then deletes their documents.
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
      'Ignored 1 file: it is out of the chat. To bring it back, use "Ignored files" below.',
      'Ignored @count files: they are out of the chat. To bring one back, use "Ignored files" below.',
    ));
    // Stay on the same filters and page.
    $form_state->setRedirect('kb_chat.drive_files', [], ['query' => $this->getRequest()->query->all()]);
  }

  /**
   * Stops ignoring the selected files, and re-lists the drives to add them.
   */
  public function unignore(array &$form, FormStateInterface $form_state): void {
    $selected = array_flip(array_keys(array_filter($form_state->getValue('ignored_files'))));
    $config = $this->configFactory()->getEditable('kb_chat.drive');
    $entries = $config->get('exclude_files') ?? [];
    $kept = array_values(array_filter($entries, fn($entry) => !isset($selected[DriveSync::fileIds([$entry])[0] ?? ''])));
    $config->set('exclude_files', $kept)->save();
    $this->state->set(DriveSync::LISTED, 0);

    $this->messenger()->addStatus($this->formatPlural(count($entries) - count($kept),
      'Stopped ignoring 1 file. The drives will be listed again within 5 minutes, and it will be back in the chat once it has been read.',
      'Stopped ignoring @count files. The drives will be listed again within 5 minutes, and they will be back in the chat once they have been read.',
    ));
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
