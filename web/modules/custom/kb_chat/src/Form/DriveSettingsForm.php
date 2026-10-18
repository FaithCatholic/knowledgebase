<?php

namespace Drupal\kb_chat\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\kb_chat\Drive\DriveSync;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for the Google Drive sync: what to leave out, and limits.
 *
 * The service account keys stay in settings.php; this edits the kb_chat.drive
 * config. Changes apply at the next listing of the drives, which the "Re-list
 * the drives" button brings forward to the next cron run.
 */
class DriveSettingsForm extends ConfigFormBase {

  /**
   * Choices for how often the drives are re-listed, in seconds.
   */
  const INTERVALS = [
    3600 => 'Every hour',
    10800 => 'Every 3 hours',
    21600 => 'Every 6 hours',
    43200 => 'Every 12 hours',
    86400 => 'Once a day',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected StateInterface $state,
    protected QueueFactory $queueFactory,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
      $container->get('queue'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kb_chat_drive_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['kb_chat.drive'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kb_chat.drive');

    $listed = $this->state->get(DriveSync::LISTED);
    $crashed = $this->state->get(DriveSync::CRASHED, []);
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Drives last listed: @time', ['@time' => $listed ? $this->dateFormatter->format($listed, 'short') : $this->t('never')]),
          $this->t('Files waiting to be read: @count', ['@count' => number_format($this->queueFactory->get(DriveSync::QUEUE)->numberOfItems())]),
          $this->t('Files skipped because they crashed the sync: @count', ['@count' => count($crashed)]),
        ],
      ],
      'note' => [
        '#markup' => '<p>' . $this->t('The drives are listed on a schedule (below); new and changed files are then read in small batches every 5 minutes. Changes on this page apply at the next listing.') . '</p>',
      ],
      'relist' => [
        '#type' => 'submit',
        '#value' => $this->t('Re-list the drives on the next run'),
        '#submit' => ['::relist'],
        '#limit_validation_errors' => [],
      ],
    ];
    if ($crashed) {
      $form['status']['crashed'] = [
        '#type' => 'details',
        '#title' => $this->t('Files that crashed the sync'),
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_values(array_map(fn($name, $id) => "$name ($id)", $crashed, array_keys($crashed))),
        ],
        'retry' => [
          '#type' => 'submit',
          '#value' => $this->t('Try these files again'),
          '#submit' => ['::retryCrashed'],
          '#limit_validation_errors' => [],
        ],
      ];
    }

    $form['exclude_drives'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Shared drives to leave out'),
      '#description' => $this->t('One per line: the drive name exactly as in Google Drive, or its ID.'),
      '#default_value' => implode("\n", $config->get('exclude_drives') ?? []),
      '#rows' => 4,
    ];
    $form['exclude_folders'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Folders to leave out'),
      '#description' => $this->t('One per line, with everything inside them: a path starting with the drive name, like <em>Custom Publishing/Production/2024</em> (capitals don\'t matter), or the folder ID from the end of its Drive URL. To see which folders hold the most files: <code>drush kb-drive --folders="Custom Publishing"</code>'),
      '#default_value' => implode("\n", $config->get('exclude_folders') ?? []),
      '#rows' => 6,
    ];
    $form['exclude_names'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Skip files whose names contain'),
      '#description' => $this->t('One per line, capitals don\'t matter. For files that shouldn\'t be easy to query in the chat, such as contact or donor lists.'),
      '#default_value' => implode("\n", $config->get('exclude_names') ?? []),
      '#rows' => 4,
    ];
    $form['exclude_files'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Files to leave out'),
      '#description' => $this->t('One per line: the file ID from its Drive URL, optionally followed by its name as a note. The easiest way to add files is <a href=":url">Indexed files</a>, which removes them from the chat at once and adds them here. Delete a line to let the file back in at the next listing.', [
        ':url' => Url::fromRoute('kb_chat.drive_files')->toString(),
      ]),
      '#default_value' => implode("\n", $config->get('exclude_files') ?? []),
      '#rows' => 6,
    ];
    $form['list_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('List the drives for changes'),
      '#options' => array_map(fn($label) => $this->t($label), self::INTERVALS),
      '#default_value' => $config->get('list_interval') ?? 21600,
    ];
    $form['max_file_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest file to read (MB)'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => (int) round(($config->get('max_file_bytes') ?? 26214400) / 1048576),
    ];
    $form['max_pdf_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest PDF to read (MB)'),
      '#description' => $this->t('PDFs are unpacked in memory to read; big ones, usually print proofs, can stop the sync.'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => (int) round(($config->get('max_pdf_bytes') ?? 10485760) / 1048576),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('kb_chat.drive')
      ->set('exclude_drives', $this->lines($form_state->getValue('exclude_drives')))
      ->set('exclude_folders', array_map(fn($line) => trim($line, " /"), $this->lines($form_state->getValue('exclude_folders'))))
      ->set('exclude_names', $this->lines($form_state->getValue('exclude_names')))
      ->set('exclude_files', $this->lines($form_state->getValue('exclude_files')))
      ->set('list_interval', (int) $form_state->getValue('list_interval'))
      ->set('max_file_bytes', (int) $form_state->getValue('max_file_mb') * 1048576)
      ->set('max_pdf_bytes', (int) $form_state->getValue('max_pdf_mb') * 1048576)
      ->save();
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Changes apply at the next listing of the drives. Use "Re-list the drives on the next run" to apply them within 5 minutes.'));
  }

  /**
   * Makes the next cron run list the drives.
   */
  public function relist(array &$form, FormStateInterface $form_state): void {
    $this->state->set(DriveSync::LISTED, 0);
    $this->messenger()->addStatus($this->t('The drives will be listed again within 5 minutes.'));
  }

  /**
   * Forgets the crashed files, so the next listing queues them again.
   */
  public function retryCrashed(array &$form, FormStateInterface $form_state): void {
    $this->state->delete(DriveSync::CRASHED);
    $this->state->set(DriveSync::LISTED, 0);
    $this->messenger()->addStatus($this->t('Those files will be queued again at the next listing, within 5 minutes.'));
  }

  /**
   * Non-empty trimmed lines of a textarea value.
   */
  protected function lines(?string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value))));
  }

}
