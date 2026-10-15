<?php

namespace Drupal\chargebee_tools;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for bulk Chargebee operations.
 *
 * A subclass only has to implement process(). By default the CSV has one
 * column, the ID named by the plugin's id_type, and check() looks that ID up.
 */
abstract class ChargebeeOperationBase extends PluginBase implements ChargebeeOperationInterface, ContainerFactoryPluginInterface {

  /**
   * Subscription statuses that check() flags as a warning.
   */
  const WARNING_STATUSES = ['cancelled', 'paused', 'non_renewing'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ChargebeeClient $client,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration + $this->defaultOptions(), $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ChargebeeClient::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * The default option values.
   */
  protected function defaultOptions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return (string) ($this->pluginDefinition['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function writes(): bool {
    return !empty($this->pluginDefinition['writes']);
  }

  /**
   * What the first CSV column holds: subscription, customer or order.
   */
  protected function idType(): string {
    return $this->pluginDefinition['id_type'] ?? 'subscription';
  }

  /**
   * {@inheritdoc}
   */
  public function columns(): array {
    return match ($this->idType()) {
      'customer' => ['customer_id' => $this->t('Customer ID')],
      'order' => ['order_id' => $this->t('Order ID')],
      default => ['subscription_id' => $this->t('Subscription ID')],
    };
  }

  /**
   * {@inheritdoc}
   *
   * These are the values describe() fills in for the ID type.
   */
  public function resultColumns(): array {
    return match ($this->idType()) {
      'customer' => [
        'name' => $this->t('Name'),
        'email' => $this->t('Email'),
      ],
      'order' => [
        'order_status' => $this->t('Order status'),
      ],
      default => [
        'subscription_status' => $this->t('Subscription status'),
        'next_billing_at' => $this->t('Next billing (UTC)'),
        'auto_collection' => $this->t('Auto collection'),
      ],
    };
  }

  /**
   * {@inheritdoc}
   */
  public function optionsForm(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptions(array &$options): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateRow(array $row): ?string {
    $id = $this->rowId($row);
    if ($id === '') {
      return (string) $this->t('No ID.');
    }
    // Chargebee IDs are at most 100 characters and can't be used in a URL
    // path if they contain spaces or slashes.
    if (!preg_match('@^[^\s/?#]{1,100}$@', $id)) {
      return (string) $this->t('"@id" is not a Chargebee ID.', ['@id' => $id]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function check(string $site, array $row): array {
    $type = $this->idType();
    $response = $this->client->get($site, ChargebeeClient::path($type . 's', $this->rowId($row)));
    return $this->describe($type, $response[$type] ?? []);
  }

  /**
   * The row's ID: the value of its first column.
   */
  protected function rowId(array $row): string {
    return (string) ($row[array_key_first($this->columns())] ?? '');
  }

  /**
   * Summarizes a Chargebee subscription, customer or order as a result.
   */
  protected function describe(string $type, array $entity): array {
    switch ($type) {
      case 'customer':
        $name = trim(($entity['first_name'] ?? '') . ' ' . ($entity['last_name'] ?? ''));
        $email = $entity['email'] ?? '';
        $who = implode(' ', array_filter([$name, $email ? "<$email>" : '']));
        return [
          'message' => (string) $this->t('Customer: @who', ['@who' => $who ?: $this->t('no name or email')]),
          'data' => ['name' => $name, 'email' => $email],
        ];

      case 'order':
        $status = $entity['status'] ?? '';
        return [
          'message' => (string) $this->t('Order status: @status', ['@status' => $status]),
          'data' => ['order_status' => $status],
        ];

      default:
        $status = $entity['status'] ?? '';
        $next = $this->formatTime($entity['next_billing_at'] ?? NULL);
        return [
          'message' => (string) $this->t('Status: @status. Next billing: @next. Auto collection: @auto.', [
            '@status' => $status,
            '@next' => $next ?: $this->t('none'),
            '@auto' => $entity['auto_collection'] ?? $this->t('customer default'),
          ]),
          'data' => [
            'subscription_status' => $status,
            'next_billing_at' => $next,
            'auto_collection' => $entity['auto_collection'] ?? '',
          ],
          'warning' => in_array($status, static::WARNING_STATUSES, TRUE),
        ];
    }
  }

  /**
   * Parses a Unix timestamp or a UTC date such as "2026-11-01 07:00".
   *
   * @return int|null
   *   The timestamp, or NULL if the value is empty or not a date.
   */
  protected function parseTime(string $value): ?int {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    if (ctype_digit($value)) {
      return (int) $value;
    }
    try {
      return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Formats a timestamp in UTC, the time zone dates are entered in.
   */
  protected function formatTime(?int $timestamp): string {
    return $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i T', 'UTC') : '';
  }

}
