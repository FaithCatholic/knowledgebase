<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Moves the renewal date (was change_renewal_date.php).
 */
#[ChargebeeOperation(
  id: 'change_term_end',
  label: new TranslatableMarkup('Change renewal date'),
  description: new TranslatableMarkup('Moves the end of the current term, and so the next billing date. The date can be set for all rows below, or per row in a second CSV column.'),
  id_type: 'subscription',
  writes: TRUE,
  weight: 10,
)]
class ChangeTermEnd extends ChargebeeOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultOptions(): array {
    return [
      'term_end' => '',
      'prorate' => FALSE,
      'invoice_immediately' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function columns(): array {
    return parent::columns() + ['term_end' => $this->t('New renewal date (optional)')];
  }

  /**
   * {@inheritdoc}
   */
  public function resultColumns(): array {
    return ['new_term_end' => $this->t('Requested renewal (UTC)')] + parent::resultColumns();
  }

  /**
   * {@inheritdoc}
   */
  public function optionsForm(array $form, FormStateInterface $form_state): array {
    $form['term_end'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New renewal date for every row'),
      '#description' => $this->t('UTC, such as 2026-11-01 07:00, or a Unix timestamp. A date in the CSV\'s second column overrides this for that row. Leave empty if every row has its own date.'),
      '#default_value' => $this->configuration['term_end'],
      '#size' => 24,
    ];
    $form['prorate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prorate'),
      '#description' => $this->t('Credit or charge for the change in term length.'),
      '#default_value' => $this->configuration['prorate'],
    ];
    $form['invoice_immediately'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invoice prorated charges immediately'),
      '#default_value' => $this->configuration['invoice_immediately'],
      '#states' => ['visible' => [':input[name="options[prorate]"]' => ['checked' => TRUE]]],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptions(array &$options): array {
    $options['term_end'] = trim($options['term_end']);
    $options['prorate'] = !empty($options['prorate']);
    $options['invoice_immediately'] = $options['prorate'] && !empty($options['invoice_immediately']);
    if ($options['term_end'] !== '' && $this->parseTime($options['term_end']) === NULL) {
      return ['term_end' => $this->t('Enter a date such as 2026-11-01 07:00, or a Unix timestamp.')];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(): array {
    $default = $this->parseTime($this->configuration['term_end']);
    return [
      (string) $this->t('New renewal date') => $default ? $this->formatTime($default) : $this->t('From the CSV'),
      (string) $this->t('Prorate') => $this->configuration['prorate'] ? $this->t('Yes') : $this->t('No'),
      (string) $this->t('Invoice immediately') => $this->configuration['invoice_immediately'] ? $this->t('Yes') : $this->t('No'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateRow(array $row): ?string {
    if ($error = parent::validateRow($row)) {
      return $error;
    }
    $value = ($row['term_end'] ?? '') !== '' ? $row['term_end'] : $this->configuration['term_end'];
    if ($value === '') {
      return (string) $this->t('No renewal date: add one in the second column or set one for every row.');
    }
    $term_end = $this->parseTime($value);
    if ($term_end === NULL) {
      return (string) $this->t('"@value" is not a date or timestamp.', ['@value' => $value]);
    }
    if ($term_end <= time()) {
      return (string) $this->t('@date is in the past.', ['@date' => $this->formatTime($term_end)]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function check(string $site, array $row): array {
    $result = parent::check($site, $row);
    $term_end = $this->termEnd($row);
    $result['data']['new_term_end'] = $this->formatTime($term_end);
    $result['message'] .= ' ' . $this->t('Would change to @date.', ['@date' => $this->formatTime($term_end)]);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    $term_end = $this->termEnd($row);
    $response = $this->client->post($site, ChargebeeClient::path('subscriptions', $this->rowId($row), 'change_term_end'), [
      'term_end' => $term_end,
      'prorate' => $this->configuration['prorate'] ? 'true' : 'false',
      'invoice_immediately' => $this->configuration['invoice_immediately'] ? 'true' : 'false',
    ]);
    $result = $this->describe('subscription', $response['subscription'] ?? []);
    $result['data']['new_term_end'] = $this->formatTime($term_end);
    return $result;
  }

  /**
   * The row's new term end: its own date, or the one for every row.
   */
  protected function termEnd(array $row): int {
    $value = ($row['term_end'] ?? '') !== '' ? $row['term_end'] : $this->configuration['term_end'];
    return (int) $this->parseTime($value);
  }

}
