<?php

namespace Drupal\urct\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Drupal\Core\Url;

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

    $single_user_id = $config->get('single_user');

    if (!empty($single_user_id)) {
      $single_user = User::load($single_user_id);
    }

    $form['single_user'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Default referrer user'),
      '#default_value' => $single_user,
      '#description' => $this->t('Type a name to find required user and select. This user account will be used as referrer when system fails to get a referrer for a vistior through any of fallback methods configured below.'),
      '#required' => TRUE,
    ];

    $fallback_type_options = [
      'single_user' => $this->t('Default referrer user configured above'),
      'roles' => $this->t('One user among selected roles'),
      'view' => $this->t('One user among result of the <a href=":url">@name</a> view', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
    ];

    $form['fallback_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Fallback method'),
      '#options' => $fallback_type_options,
      '#default_value' => $config->get('fallback_type'),
      '#description' => $this->t('Select a fallback method you like to set the referrer when referral cookie does not exist, which happens when a user vists the site directly or through search engines. 
      For roles and view options, it select a single user account each time, by rotating among the result by incrementing the user ID.'),
      '#required' => TRUE,
    ];

    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#options' => user_role_names(TRUE),
      '#default_value' => $config->get('roles'),
      '#description' => $this->t('Select one or more roles.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'roles'],
        ],
      ],
    ];

    $roles_condition_options = [
      'or' => $this->t('User having any of selected roles.'),
      'and' => $this->t('User having all of selected roles.'),
    ];
    $form['roles_condition'] = [
      '#type' => 'radios',
      '#title' => $this->t('Roles matching condition'),
      '#options' => $roles_condition_options,
      '#default_value' => $config->get('roles_condition') ?? 'or',
      '#description' => $this->t('Select one or more roles.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'roles'],
        ],
      ],
    ];

    $form['view'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Fallback referral account will be selected from the reult of view <a href=":url">@name</a>. You may edit the view to change its filter and sorting to set the conditions and sorting for the rotation.', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'view'],
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $fallback_type = $form_state->getValue('fallback_type');

    if (!($fallback_referrer = User::load($form_state->getValue('single_user'))) || !$fallback_referrer->isActive() ) {
      $form_state->setErrorByName('single_user', t('Please select an active user account.'));
    }
    if ($fallback_type == 'roles' && empty(array_filter($form_state->getValue('roles'))) ) {
      $form_state->setErrorByName('roles', t('Select at least one role.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('urct.settings');
    $config->set('fallback_type', $form_state->getValue('fallback_type'));
    $config->set('single_user', $form_state->getValue('single_user'));
    $config->set('roles', $form_state->getValue('roles'));
    $config->set('roles_condition', $form_state->getValue('roles_condition'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
