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
      'single_user' => $this->t('Single user'),
      'roles' => $this->t('One user among selected roles'),
      'view' => $this->t('One user among result of a selected view'),
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

    $single_user_id = $config->get('single_user');

    if (!empty($single_user_id)) {
      $single_user = User::load($single_user_id);
    }

    $form['single_user'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('User'),
      '#default_value' => $single_user,
      '#description' => $this->t('Type name to find required user and select.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'single_user'],
        ],
      ],
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

    $views = \Drupal::entityTypeManager()->getStorage('view')
      ->loadByProperties(['base_table' => 'users_field_data']);

    $views_options = [];
    foreach ($views as $name => $view) {
      $views_options[$name] = $view->label();
    }

    $form['view'] = [
      '#type' => 'radios',
      '#title' => $this->t('View'),
      '#options' => $views_options,
      '#default_value' => $config->get('view'),
      '#description' => $this->t('If not done already, you can create a view to list out the users matching your citeria and select it here. Please make sure you create the view having just the master display, the master display will be used to get the result. Please note the pager settings will be discarded and it will select the user account rotated each time by incrementing user ID within the view result.'),
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
    if ($fallback_type == 'single_user' && empty($form_state->getValue('single_user')) ) {
      $form_state->setErrorByName('single_user', t('Select a user account.'));
    }
    else if ($fallback_type == 'roles' && empty(array_filter($form_state->getValue('roles'))) ) {
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
    $config->set('view', $form_state->getValue('view'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
