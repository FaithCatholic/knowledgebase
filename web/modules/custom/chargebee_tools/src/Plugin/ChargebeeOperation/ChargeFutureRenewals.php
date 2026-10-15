<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Invoices upcoming renewals now (was future_renewal.php).
 */
#[ChargebeeOperation(
  id: 'charge_future_renewals',
  label: new TranslatableMarkup('Charge future renewals'),
  description: new TranslatableMarkup('Creates the advance invoice for upcoming renewal terms now, ahead of the renewal date. Used for the quarterly renewal runs.'),
  id_type: 'subscription',
  writes: TRUE,
  weight: 0,
)]
class ChargeFutureRenewals extends ChargebeeOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultOptions(): array {
    return ['terms_to_charge' => 1];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsForm(array $form, FormStateInterface $form_state): array {
    $form['terms_to_charge'] = [
      '#type' => 'number',
      '#title' => $this->t('Terms to charge'),
      '#min' => 1,
      '#max' => 12,
      '#default_value' => $this->configuration['terms_to_charge'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptions(array &$options): array {
    $options['terms_to_charge'] = (int) $options['terms_to_charge'];
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(): array {
    return [(string) $this->t('Terms to charge') => $this->configuration['terms_to_charge']];
  }

  /**
   * {@inheritdoc}
   */
  public function resultColumns(): array {
    return ['invoice_id' => $this->t('Invoice ID')] + parent::resultColumns();
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    $response = $this->client->post($site, ChargebeeClient::path('subscriptions', $this->rowId($row), 'charge_future_renewals'), [
      'terms_to_charge' => $this->configuration['terms_to_charge'],
    ]);
    $result = $this->describe('subscription', $response['subscription'] ?? []);
    $invoice = $response['invoice']['id'] ?? '';
    $result['data']['invoice_id'] = $invoice;
    $result['message'] = $invoice
      ? (string) $this->t('Invoice @invoice created.', ['@invoice' => $invoice])
      : (string) $this->t('Charged, but Chargebee returned no invoice.');
    $result['warning'] = !$invoice;
    return $result;
  }

}
