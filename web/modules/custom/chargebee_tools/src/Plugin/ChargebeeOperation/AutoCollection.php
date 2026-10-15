<?php

namespace Drupal\chargebee_tools\Plugin\ChargebeeOperation;

use Drupal\chargebee_tools\Attribute\ChargebeeOperation;
use Drupal\chargebee_tools\ChargebeeClient;
use Drupal\chargebee_tools\ChargebeeOperationBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Turns automatic payment collection on or off (was auto_collect_on/off.php).
 */
#[ChargebeeOperation(
  id: 'auto_collection',
  label: new TranslatableMarkup('Set auto collection'),
  description: new TranslatableMarkup('Turns automatic payment collection on or off for each subscription.'),
  id_type: 'subscription',
  writes: TRUE,
  weight: 20,
)]
class AutoCollection extends ChargebeeOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function defaultOptions(): array {
    return ['auto_collection' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsForm(array $form, FormStateInterface $form_state): array {
    $form['auto_collection'] = [
      '#type' => 'radios',
      '#title' => $this->t('Auto collection'),
      '#options' => [
        'on' => $this->t('On: charge the card on file automatically'),
        'off' => $this->t('Off: invoices wait for manual payment'),
      ],
      '#default_value' => $this->configuration['auto_collection'] ?: NULL,
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptions(array &$options): array {
    if (!in_array($options['auto_collection'] ?? '', ['on', 'off'], TRUE)) {
      return ['auto_collection' => $this->t('Choose on or off.')];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(): array {
    return [(string) $this->t('Auto collection') => $this->configuration['auto_collection']];
  }

  /**
   * {@inheritdoc}
   */
  public function process(string $site, array $row): array {
    $response = $this->client->post($site, ChargebeeClient::path('subscriptions', $this->rowId($row), 'update_for_items'), [
      'auto_collection' => $this->configuration['auto_collection'],
    ]);
    return $this->describe('subscription', $response['subscription'] ?? []);
  }

}
