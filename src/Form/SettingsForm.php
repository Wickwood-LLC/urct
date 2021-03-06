<?php

namespace Drupal\urct\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Drupal\Core\Url;
use Drupal\user_referral\Entity\UserReferralType;

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

    $input = $form_state->getUserInput();
    if (!empty($input['default_fallback_referrer']['referrer']) && preg_match('/^.*\((\d+)\)$/', $input['default_fallback_referrer']['referrer'], $matces)) {
      $default_fallback_referrer['referrer'] = $matces[1];
      $default_fallback_referrer['type'] = $input['default_fallback_referrer']['type'] ?? NULL;
    }
    else {
      $default_fallback_referrer = $config->get('default_fallback_referrer');
    }

    $form['default_fallback_referrer'] = [
      '#type' => 'referrer',
      '#title' => $this->t('Default fallback referrer'),
      '#user_title' => t('Referrer user'),
      '#type_title' => t('Referral type'),
      '#default_value' => $default_fallback_referrer,
      '#description' => $this->t('Type a name to find required user and select. This user account will be used as referrer when system fails to get a referrer for a vistior through any of fallback methods configured below.'),
      '#required' => TRUE,
    ];

    $fallback_type_options = [
      'default_fallback_referrer' => $this->t('Default referrer user configured above'),
      // 'roles' => $this->t('One user among selected roles'),
      'referral_types' => $this->t('Referral types'),
      // 'view' => $this->t('One user among result of the <a href=":url">@name</a> view', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
    ];

    $form['fallback_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Fallback method'),
      '#options' => $fallback_type_options,
      '#default_value' => $config->get('fallback_type'),
      '#description' => $this->t('Select a fallback method to use when a referral cookie does not exist, which happens when a user visits the site directly or discovers the site through search engines.
            When this happens, a different user account is displayed each time the site is visited directly, rotating through selected users by incrementing the user ID based on Roles allowed for Referral Types selected below and with optional filtering and sorting with the <a href=":url">@name</a> View.', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
      '#required' => TRUE,
    ];

    $form['referral_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Referral types'),
      '#options' => UserReferralType::referralTypeOptions(),
      '#default_value' => $config->get('referral_types') ?? [],
      '#description' => $this->t('Select one or more referral types.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'referral_types'],
        ],
      ],
      '#prefix' => '<div class="indent-1">',
      '#suffix' => '</div>',
    ];

    $form['referral_types_filter_by_view'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Filter referrers by result of the view <a href=":url">@name</a>', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
      '#default_value' => $config->get('referral_types_filter_by_view') ?? FALSE,
      '#description' => $this->t('Only referrer users present in result of the view <a href=":url">@name</a> will be considered.', [':url' => Url::fromRoute('entity.view.edit_display_form', ['view' => 'urct_referral_fallbacks', 'display_id' => 'default'])->toString(), '@name' => 'Referral Fallbacks']),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'referral_types'],
        ],
      ],
      '#prefix' => '<div class="indent-2">',
      '#suffix' => '</div>',
    ];

    $form['referral_types_filter_by_view_negate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negate on the filtering by view result'),
      '#default_value' => $config->get('referral_types_filter_by_view_negate') ?? FALSE,
      '#description' => $this->t('Enabling this checkbox will cause the users from the view result to get excluded in rotation'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_type"]' => ['value' => 'referral_types'],
          ':input[name="referral_types_filter_by_view"]' => ['checked' => TRUE],
        ],
      ],
      '#prefix' => '<div class="indent-3">',
      '#suffix' => '</div>',
    ];

    $form['roll_up'] = [
      '#type' => 'radios',
      '#title' => $this->t('Roll-up method'),
      '#options' => [
        'enroller' => $this->t('Enroller (to use referrer of the referrer in case original referrer is inactive)'),
        'default_fallback_referrer' => $this->t('Default fallback referrer configured above'),
      ],
      '#default_value' => $config->get('roll_up') ?? 'enroller',
      '#description' => $this->t('Select a method to perform when a referrer is inactive. If you choose enroller, then referrer of the referrer will be used. Roll-Up will continue until an active referrer is found. If there is no active referrer available, then the default fallback referrer will be used.'),
    ];

    $form['bot_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Search engine bots'),
      '#default_value' => $config->get('bot_agents') ?? '',
      '#description' => $this->t('Enter part of string to match in User Agent name to detect search engine crawlers. Enter one item per line. On detection, Default Referrer User will be used.'),
    ];

    $form['debug_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug'),
      '#open' => $config->get('debug') ?? FALSE,
    ];

    $form['debug_details']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('Enabling this checkbox will disable cache on any page that use referral cookie tokens anywhere in its content.'),
      '#default_value' => $config->get('debug') ?? FALSE,
    ];

    // Submit button.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configurations'),
    ];

    $form['#attached']['library'][] = 'urct/urct.settings-form';

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $fallback_type = $form_state->getValue('fallback_type');

    $default_fallback_referrer = $form_state->getValue('default_fallback_referrer');
    if (!($fallback_referrer = User::load($default_fallback_referrer['referrer'])) || !$fallback_referrer->isActive() ) {
      $form_state->setErrorByName('default_fallback_referrer', t('Please select an active user account.'));
    }
    if ($fallback_type == 'referral_types' && empty(array_filter($form_state->getValue('referral_types'))) ) {
      $form_state->setErrorByName('referral_types', t('Select at least one referral type.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('urct.settings');
    $config->set('fallback_type', $form_state->getValue('fallback_type'));
    $config->set('default_fallback_referrer', $form_state->getValue('default_fallback_referrer'));
    $config->set('referral_types', $form_state->getValue('referral_types'));
    $config->set('referral_types_filter_by_view', $form_state->getValue('referral_types_filter_by_view'));
    $config->set('referral_types_filter_by_view_negate', $form_state->getValue('referral_types_filter_by_view_negate'));
    $config->set('roll_up', $form_state->getValue('roll_up'));
    $config->set('bot_agents', $form_state->getValue('bot_agents'));
    $config->set('debug', $form_state->getValue('debug'));
    $config->save();

    parent::submitForm($form, $form_state);
    drupal_flush_all_caches();
  }

}
