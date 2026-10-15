<?php

namespace Drupal\chargebee_tools\Form;

use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationInterface;
use Drupal\chargebee_tools\ChargebeeOperationManager;
use Drupal\chargebee_tools\RunStorage;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Queues a run: upload a CSV, preview it, then confirm.
 *
 * Step one takes the site, mode, options and CSV. Step two shows which rows
 * will be sent and which skipped, and on the live site asks the user to type
 * LIVE before anything that changes Chargebee is queued.
 */
class NewRunForm extends FormBase {

  use AutowireTrait;

  /**
   * The most CSV rows one run accepts.
   */
  const MAX_ROWS = 20000;

  /**
   * How many rows the preview lists.
   */
  const PREVIEW_ROWS = 10;

  public function __construct(
    protected ChargebeeOperationManager $operations,
    protected ChargebeeClient $client,
    protected RunStorage $storage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'chargebee_tools_new_run';
  }

  /**
   * Title callback: "New run: {operation}".
   */
  public function title(string $operation): string {
    $definition = $this->operations->getDefinition($operation, FALSE);
    if (!$definition) {
      throw new NotFoundHttpException();
    }
    return (string) $this->t('New run: @operation', ['@operation' => $definition['label']]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $operation = ''): array {
    if (!$this->operations->hasDefinition($operation)) {
      throw new NotFoundHttpException();
    }
    $form_state->set('operation', $operation);
    $plugin = $this->plugin($form_state);
    return $form_state->get('step') === 'confirm'
      ? $this->buildConfirm($form, $form_state, $plugin)
      : $this->buildUpload($form, $form_state, $plugin);
  }

  /**
   * Step one: site, mode, options and the CSV.
   */
  protected function buildUpload(array $form, FormStateInterface $form_state, ChargebeeOperationInterface $plugin): array {
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['description'] = [
      '#markup' => '<p>' . $plugin->description() . '</p>',
    ];

    $sites = [];
    foreach (['test', 'live'] as $site) {
      if ($this->client->isConfigured($site)) {
        $sites[$site] = $this->siteLabel($site);
      }
    }
    if (!$sites) {
      $form['not_configured'] = [
        '#markup' => '<p>' . $this->t('No Chargebee site is configured. See the chargebee_tools README.') . '</p>',
      ];
      return $form;
    }
    $values = $form_state->get('values') ?? [];
    $form['site'] = [
      '#type' => 'radios',
      '#title' => $this->t('Chargebee site'),
      '#options' => $sites,
      '#default_value' => $values['site'] ?? array_key_first($sites),
      '#required' => TRUE,
    ];
    if ($plugin->writes()) {
      $form['mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Mode'),
        '#options' => [
          'check' => $this->t('Check only: look up each row in Chargebee and change nothing'),
          'run' => $this->t('Make the changes'),
        ],
        '#default_value' => $values['mode'] ?? 'check',
        '#required' => TRUE,
      ];
    }

    $options = $plugin->optionsForm([], $form_state);
    if ($options) {
      $form['options'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Options'),
        '#tree' => TRUE,
      ] + $options;
    }

    $columns = array_map('strval', $plugin->columns());
    $csv = $form_state->get('csv');
    $form['csv'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t('Columns: @columns. Extra columns are ignored.', ['@columns' => implode(', ', $columns)])
        . ($csv ? ' ' . $this->t('Leave empty to reuse @file.', ['@file' => $csv['filename']]) : ''),
      '#attributes' => ['accept' => '.csv,text/csv'],
    ];
    $form['header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('The first row is a header'),
      '#default_value' => $values['header'] ?? FALSE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#button_type' => 'primary',
      '#validate' => ['::validateUpload'],
      '#submit' => ['::submitUpload'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('chargebee_tools.overview'),
    ];
    return $form;
  }

  /**
   * Step two: what will happen, and the live confirmation.
   */
  protected function buildConfirm(array $form, FormStateInterface $form_state, ChargebeeOperationInterface $plugin): array {
    $values = $form_state->get('values');
    $rows = $form_state->get('rows');
    $ready = array_filter($rows, fn($row) => $row['error'] === NULL);
    $skipped = array_filter($rows, fn($row) => $row['error'] !== NULL);
    $mode = $plugin->writes() ? $values['mode'] : 'run';

    $summary = [
      (string) $this->t('Chargebee site') => $this->siteLabel($values['site']),
      (string) $this->t('Mode') => $mode === 'check' ? $this->t('Check only (changes nothing)') : $this->t('Make the changes'),
    ] + $plugin->optionsSummary() + [
      (string) $this->t('File') => $form_state->get('csv')['filename'],
      (string) $this->t('Rows to send') => count($ready),
      (string) $this->t('Rows skipped') => count($skipped),
    ];
    $form['summary'] = $this->summaryList($summary);

    $columns = $plugin->columns();
    if ($ready) {
      $form['ready'] = [
        '#type' => 'table',
        '#caption' => count($ready) > self::PREVIEW_ROWS
          ? $this->t('First @count of @total rows to send', ['@count' => self::PREVIEW_ROWS, '@total' => count($ready)])
          : $this->t('Rows to send'),
        '#header' => array_merge([$this->t('Line')], array_values($columns)),
        '#rows' => array_map(
          fn($row) => array_merge([$row['line']], array_values($row['input'])),
          array_slice($ready, 0, self::PREVIEW_ROWS),
        ),
      ];
    }
    if ($skipped) {
      $form['skipped'] = [
        '#type' => 'table',
        '#caption' => $this->t('Skipped rows (not sent to Chargebee)'),
        '#header' => [$this->t('Line'), $columns[array_key_first($columns)], $this->t('Reason')],
        '#rows' => array_map(
          fn($row) => [$row['line'], reset($row['input']), $row['error']],
          array_slice($skipped, 0, 100),
        ),
      ];
    }

    if ($this->needsLiveConfirmation($plugin, $values) && $ready) {
      $form['confirm'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Type LIVE to change @count subscriptions, customers or orders on the live Chargebee site', ['@count' => count($ready)]),
        '#size' => 10,
        '#required' => TRUE,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($ready) {
      $form['actions']['queue'] = [
        '#type' => 'submit',
        '#value' => $this->t('Queue run'),
        '#button_type' => 'primary',
      ];
    }
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitBack'],
    ];
    return $form;
  }

  /**
   * Validates step one and reads the CSV.
   */
  public function validateUpload(array &$form, FormStateInterface $form_state): void {
    $options = $form_state->getValue('options') ?? [];
    $plugin = $this->operations->createInstance($form_state->get('operation'));
    foreach ($plugin->validateOptions($options) as $name => $message) {
      $form_state->setErrorByName("options][$name", $message);
    }
    $form_state->set('options', $options);

    $file = $this->getRequest()->files->get('files', [])['csv'] ?? NULL;
    if ($file instanceof UploadedFile) {
      if (!$file->isValid()) {
        $form_state->setErrorByName('csv', $this->t('The upload failed: @error', ['@error' => $file->getErrorMessage()]));
        return;
      }
      $lines = $this->readCsv($file->getRealPath());
      if (count($lines) > self::MAX_ROWS) {
        $form_state->setErrorByName('csv', $this->t('The CSV has more than @max rows. Split it into smaller files.', ['@max' => self::MAX_ROWS]));
        return;
      }
      $form_state->set('csv', ['filename' => $file->getClientOriginalName(), 'lines' => $lines]);
    }
    if (empty($form_state->get('csv')['lines'])) {
      $form_state->setErrorByName('csv', $this->t('Upload a CSV with at least one row.'));
    }
  }

  /**
   * Parses the CSV into rows and moves to the preview.
   */
  public function submitUpload(array &$form, FormStateInterface $form_state): void {
    $values = [
      'site' => $form_state->getValue('site'),
      'mode' => $form_state->getValue('mode') ?? 'run',
      'header' => (bool) $form_state->getValue('header'),
    ];
    $form_state->set('values', $values);
    $form_state->set('rows', $this->parseRows($this->plugin($form_state), $form_state->get('csv')['lines'], $values['header']));
    $form_state->set('step', 'confirm');
    $form_state->setRebuild();
  }

  /**
   * Returns to step one, keeping what was entered.
   */
  public function submitBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'upload');
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') !== 'confirm' || !$form_state->hasValue('confirm')) {
      return;
    }
    if (strtoupper(trim($form_state->getValue('confirm'))) !== 'LIVE') {
      $form_state->setErrorByName('confirm', $this->t('Type LIVE to confirm, or go back and choose the test site or check only.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Queues the run.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $plugin = $this->plugin($form_state);
    $values = $form_state->get('values');
    $rid = $this->storage->create([
      'operation' => $form_state->get('operation'),
      'site' => $values['site'],
      'mode' => $plugin->writes() ? $values['mode'] : 'run',
      'options' => $form_state->get('options') ?? [],
      'filename' => $form_state->get('csv')['filename'],
      'uid' => $this->currentUser()->id(),
    ], $form_state->get('rows'));
    $this->logger('chargebee_tools')->notice('Queued run @rid: @operation on @site (@mode).', [
      '@rid' => $rid,
      '@operation' => $plugin->label(),
      '@site' => $values['site'],
      '@mode' => $values['mode'],
    ]);
    $this->messenger()->addStatus($this->t('Run queued. It starts within five minutes, and this page shows its progress.'));
    $form_state->setRedirect('chargebee_tools.run', ['rid' => $rid]);
  }

  /**
   * The operation plugin, configured with the submitted options.
   */
  protected function plugin(FormStateInterface $form_state): ChargebeeOperationInterface {
    return $this->operations->createInstance($form_state->get('operation'), $form_state->get('options') ?? []);
  }

  /**
   * Whether queuing needs LIVE typed: changes on the live site.
   */
  protected function needsLiveConfirmation(ChargebeeOperationInterface $plugin, array $values): bool {
    return $values['site'] === 'live' && $plugin->writes() && $values['mode'] === 'run';
  }

  /**
   * "Live (faithcatholic)" and "Test (faithcatholic-test)".
   */
  protected function siteLabel(string $site): string {
    $label = $site === 'live' ? $this->t('Live') : $this->t('Test');
    return $label . ' (' . $this->client->subdomain($site) . ')';
  }

  /**
   * Renders label => value pairs as a list of "Label: value" lines.
   */
  public static function summaryList(array $summary): array {
    $items = [];
    foreach ($summary as $label => $value) {
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ label }}:</strong> {{ value }}',
        '#context' => ['label' => $label, 'value' => $value],
      ];
    }
    return ['#theme' => 'item_list', '#items' => $items];
  }

  /**
   * Reads a CSV into trimmed cells keyed by line number, skipping blank lines.
   */
  protected function readCsv(string $path): array {
    $lines = [];
    $handle = fopen($path, 'r');
    $number = 0;
    while (($cells = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      $number++;
      $cells = array_map(fn($cell) => trim((string) $cell), $cells);
      if ($number === 1) {
        // Excel adds a byte order mark to UTF-8 CSVs.
        $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
      }
      if (implode('', $cells) !== '') {
        $lines[$number] = $cells;
      }
    }
    fclose($handle);
    return $lines;
  }

  /**
   * Maps CSV lines to the operation's columns and validates them.
   *
   * @return array
   *   Each row is ['line' => int, 'input' => array, 'error' => ?string].
   *   A repeated ID is skipped, so nothing is changed twice.
   */
  protected function parseRows(ChargebeeOperationInterface $plugin, array $lines, bool $header): array {
    if ($header) {
      unset($lines[array_key_first($lines)]);
    }
    $columns = array_keys($plugin->columns());
    $seen = [];
    $rows = [];
    foreach ($lines as $line => $cells) {
      $input = [];
      foreach ($columns as $index => $name) {
        $input[$name] = $cells[$index] ?? '';
      }
      $id = $input[$columns[0]];
      $error = $plugin->validateRow($input);
      if ($error === NULL && isset($seen[$id])) {
        $error = (string) $this->t('Same ID as line @line.', ['@line' => $seen[$id]]);
      }
      $seen[$id] ??= $line;
      $rows[] = ['line' => $line, 'input' => $input, 'error' => $error];
    }
    return $rows;
  }

}
