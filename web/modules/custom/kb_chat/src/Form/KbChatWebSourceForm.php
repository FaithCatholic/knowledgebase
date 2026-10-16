<?php

namespace Drupal\kb_chat\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\kb_chat\Entity\KbChatWebSource;

/**
 * Adds or edits a trusted website.
 */
class KbChatWebSourceForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\kb_chat\Entity\KbChatWebSource $source */
    $source = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('Who maintains it and what it covers, e.g. "Michigan Catholic Conference: Employee Benefits". Answers name the source with this.'),
      '#default_value' => $source->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $source->id(),
      '#machine_name' => ['exists' => [KbChatWebSource::class, 'load']],
      '#disabled' => !$source->isNew(),
    ];
    $form['start_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Start URLs'),
      '#description' => $this->t('One per line. The crawl starts here and follows links.'),
      '#default_value' => implode("\n", $source->get('start_urls') ?? []),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $form['include_prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only follow links under'),
      '#description' => $this->t('One URL prefix per line, e.g. https://micatholic.org/benefits/. Leave empty to use the start URLs. Keeps the crawl to the section you trust instead of the whole site.'),
      '#default_value' => implode("\n", $source->get('include_prefixes') ?? []),
      '#rows' => 3,
    ];
    $form['documents'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include linked PDF, Word and Excel files on the same site'),
      '#default_value' => $source->get('documents') ?? TRUE,
    ];
    $form['frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('How often the information changes'),
      '#description' => $this->t('How often it is re-crawled to pick up changes.'),
      '#options' => [
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
      ],
      '#default_value' => $source->get('frequency') ?? 'weekly',
    ];
    $form['tier'] = [
      '#type' => 'radios',
      '#title' => $this->t('Who can see it in the chat'),
      '#options' => [
        'staff' => $this->t('All staff'),
        'management' => $this->t('Management only'),
      ],
      '#default_value' => $source->get('tier') ?? 'staff',
    ];
    $form['max_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Most pages to index'),
      '#min' => 1,
      '#max' => 2000,
      '#default_value' => $source->get('max_pages') ?? 200,
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('Disabled websites are not re-crawled; pages already indexed stay searchable.'),
      '#default_value' => $source->status(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (['start_urls', 'include_prefixes'] as $field) {
      foreach ($this->lines($form_state->getValue($field)) as $url) {
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
          $form_state->setErrorByName($field, $this->t('"@url" is not a full web address (https://...).', ['@url' => $url]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    $entity->set('start_urls', $this->lines($form_state->getValue('start_urls')));
    $entity->set('include_prefixes', $this->lines($form_state->getValue('include_prefixes')));
    $entity->set('documents', (bool) $form_state->getValue('documents'));
    $entity->set('max_pages', (int) $form_state->getValue('max_pages'));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Added %label. It will be crawled within the hour.', ['%label' => $this->entity->label()])
      : $this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

  /**
   * Non-empty trimmed lines of a textarea value.
   */
  protected function lines(?string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value))));
  }

}
