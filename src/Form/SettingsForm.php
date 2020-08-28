<?php

namespace Drupal\urct\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Displays the captcha settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['urct.settings'];
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::getFormID().
   */
  public function getFormId() {
    return 'urct_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('urct.settings');

    $fallback_type_options = [
      'singel_user' => $this->t('Single user'),
      'roles' => $this->t('One user among selected roles'),
      'view' => $this->t('One user among result of a selected view'),
    ];

    $form['fallback_user_selection'] = [
      '#type' => 'radios',
      '#title' => $this->t('Fallback method'),
      '#options' => $fallback_type_options,
      '#default_value' => $config->get('fallback_user_selection'),
      '#description' => $this->t('Select a fallback method you like to set the referrer when referral cookie does not exist, which happens when a user vists the site directly or through search engines.'),
    ];

    $single_user_id = $config->get('single_user');

    if (!empty($single_user_id)) {
      $single_user = User::load($single_user_id);
    }

    $form['single_user'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('User'),
      '#default_value' => $single_user,
      '#description' => $this->t(''),
      '#states' => [
        'visible' => [
          ':input[name="fallback_user_selection"]' => ['value' => 'singel_user'],
        ],
      ],
    ];

    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => user_role_names(TRUE),
      '#default_value' => $config->get('roles'),
      '#description' => $this->t(''),
      '#states' => [
        'visible' => [
          ':input[name="fallback_user_selection"]' => ['value' => 'roles'],
        ],
      ],
    ];

    $views = \Drupal::entityTypeManager()->getStorage('view')
      ->loadByProperties(['base_table' => 'users_field_data']);

    $views_options = [];
    foreach ($views as $name => $view) {
      $views_options[$name] = $view->label();
    }

    $form['view'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('View'),
      '#options' => $views_options,
      '#default_value' => $config->get('view'),
      '#description' => $this->t(''),
      '#states' => [
        'visible' => [
          ':input[name="fallback_user_selection"]' => ['value' => 'view'],
        ],
      ],
    ];

    // // Submit button.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configurations'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('urct.settings');
    $config->set('fallback_user_selection', $form_state->getValue('fallback_user_selection'));
    $config->set('single_user', $form_state->getValue('single_user'));
    $config->set('roles', $form_state->getValue('roles'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
