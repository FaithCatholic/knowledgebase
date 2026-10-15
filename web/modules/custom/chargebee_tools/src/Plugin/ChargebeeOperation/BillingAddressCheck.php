<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Flags customers with an incomplete billing address (was check_billing.php).
 */
#[ChargebeeOperation(
  id: 'billing_address_check',
  label: new TranslatableMarkup('Check billing addresses'),
  description: new TranslatableMarkup('Flags customers whose billing address is missing a name, street, city, state or ZIP. Changes nothing.'),
  id_type: 'customer',
  weight: 60,
)]
class BillingAddressCheck extends ChargebeeOperationBase {

  /**
   * The billing address fields that must be filled in.
   */
  const FIELDS = [
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'line1' => 'Line 1',
    'city' => 'City',
    'state' => 'State',
    'zip' => 'ZIP',
  ];

  /**
   * {@inheritdoc}
   */
  public function resultColumns(): array {
    $columns = [];
    foreach (static::FIELDS as $key => $label) {
      $columns[$key] = $label;
    }
    return $columns + ['missing' => $this->t('Missing')];
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    $response = $this->client->get($site, ChargebeeClient::path('customers', $this->rowId($row)));
    $address = $response['customer']['billing_address'] ?? [];
    $data = [];
    $missing = [];
    foreach (static::FIELDS as $key => $label) {
      $data[$key] = trim((string) ($address[$key] ?? ''));
      if ($data[$key] === '') {
        $missing[] = $label;
      }
    }
    $data['missing'] = implode(', ', $missing);
    return [
      'message' => $missing
        ? (string) $this->t('Missing: @fields', ['@fields' => $data['missing']])
        : (string) $this->t('Billing address complete.'),
      'data' => $data,
      'warning' => (bool) $missing,
    ];
  }

}
