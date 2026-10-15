<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Reports each order's status (was check-order.php).
 */
#[ChargebeeOperation(
  id: 'order_status',
  label: new TranslatableMarkup('Look up order status'),
  description: new TranslatableMarkup('Reports the current status of each order. Changes nothing.'),
  id_type: 'order',
  weight: 50,
)]
class OrderStatus extends ChargebeeOperationBase {

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    return $this->check($site, $row);
  }

}
