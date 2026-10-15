<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Marks orders shipped (was ship-orders.php).
 */
#[ChargebeeOperation(
  id: 'ship_orders',
  label: new TranslatableMarkup('Mark orders shipped'),
  description: new TranslatableMarkup('Sets each order\'s status to shipped, with the time it is processed as the shipped date.'),
  id_type: 'order',
  writes: TRUE,
  weight: 40,
)]
class ShipOrders extends ChargebeeOperationBase {

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    $response = $this->client->post($site, ChargebeeClient::path('orders', $this->rowId($row)), [
      'status' => 'shipped',
      'shipped_at' => time(),
    ]);
    return $this->describe('order', $response['order'] ?? []);
  }

}
