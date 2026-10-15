<?php

namespace Drupal\chargebee_tools\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a bulk Chargebee operation plugin.
 *
 * Plugins live in src/Plugin/ChargebeeOperation and implement
 * \Drupal\chargebee_tools\ChargebeeOperationInterface, usually by extending
 * \Drupal\chargebee_tools\ChargebeeOperationBase.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ChargebeeOperation extends Plugin {

  /**
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The operation's name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   What the operation does in Chargebee.
   * @param string $id_type
   *   What the CSV's first column holds: subscription, customer or order.
   * @param bool $writes
   *   TRUE if the operation changes Chargebee. These get a check-only mode
   *   and need confirming on the live site.
   * @param int $weight
   *   Sort order on the overview page.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly string $id_type = 'subscription',
    public readonly bool $writes = FALSE,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
