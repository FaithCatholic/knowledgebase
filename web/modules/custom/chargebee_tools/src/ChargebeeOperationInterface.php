<?php

namespace Drupal\chargebee_tools;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * A bulk operation applied to each row of an uploaded CSV.
 *
 * The plugin's configuration holds the options chosen on the run form, such
 * as the new renewal date.
 *
 * check() and process() return a result array:
 * - message: a short line for the run page and the results CSV.
 * - data: values for the resultColumns() columns of the results CSV.
 * - warning: TRUE to flag the row for attention even though the call worked.
 * Both throw \Drupal\chargebee_tools\ChargebeeException when the call fails.
 */
interface ChargebeeOperationInterface extends PluginInspectionInterface {

  /**
   * The operation's name.
   */
  public function label(): string;

  /**
   * What the operation does in Chargebee.
   */
  public function description(): string;

  /**
   * Whether the operation changes Chargebee.
   */
  public function writes(): bool;

  /**
   * The CSV columns, in order: machine name => label.
   *
   * The first column is always the ID the operation acts on.
   */
  public function columns(): array;

  /**
   * Extra columns in the results CSV: data key => label.
   */
  public function resultColumns(): array;

  /**
   * Builds the form elements for the operation's options.
   */
  public function optionsForm(array $form, FormStateInterface $form_state): array;

  /**
   * Validates and normalizes submitted options.
   *
   * @param array $options
   *   The submitted values of the options form, changed in place.
   *
   * @return array
   *   Error messages keyed by option name. Empty if the options are valid.
   */
  public function validateOptions(array &$options): array;

  /**
   * The chosen options, for display: label => value.
   */
  public function optionsSummary(): array;

  /**
   * Checks one CSV row before anything is sent to Chargebee.
   *
   * @param array $row
   *   The row, keyed by the columns() machine names.
   *
   * @return string|null
   *   Why the row will be skipped, or NULL if it is fine.
   */
  public function validateRow(array $row): ?string;

  /**
   * Looks up one row in Chargebee without changing anything.
   */
  public function check(string $site, array $row): array;

  /**
   * Applies the operation to one row.
   */
  public function process(string $site, array $row): array;

}
