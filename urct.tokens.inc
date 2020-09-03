<?php

/**
 * @file
 * Builds placeholder replacement tokens for user referral related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Drupal\user_referral\UserReferral;

/**
 * Implements hook_token_info().
 */
function urct_token_info() {
  $types = [
    'referrer_from_cookie' => [
      'name' => t('Referrer from the cookie'),
      'description' => t('Tokens related to the referrer recordeed in the cookie.'),
      'type' => 'user',
    ],
  ];

  return [
    'types' => $types,
    'tokens' => [],
  ];
}

/**
 * Implements hook_tokens().
 */
function urct_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $token_service = \Drupal::token();
  $replacements = [];

  if ($type == 'referrer_from_cookie') {
    $account = urct_cookie_referrer();
    $bubbleable_metadata->addCacheContexts(['user']);
    $replacements += $token_service->generate('user', $tokens, ['user' => $account], $options, $bubbleable_metadata);
  }
  return $replacements;
}

function urct_cookie_referrer() {
  $account = &drupal_static(__FUNCTION__);
  if (!isset($account)) {
    $uid = NULL;
    if (isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
      // Retrieve referral info from the cookie
      $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);
      if (!empty($cookie) && isset($cookie->uid)) {
        $uid = $cookie->uid;
      }
    }
    if (empty($uid)) {
      // No referrer found from cookie. Fallback configured type.

      // $config = \Drupal::config('urct.settings');
      $config = \Drupal::service('config.factory')->getEditable('urct.settings');
      $fallback_type = $config->get('fallback_type');
      if (!empty($fallback_type)) {
        $last_selected_uid = $config->get('last_selected_uid');
        $last_selected_uid = $last_selected_uid ?? 0;

        if ($fallback_type == 'roles') {
          $roles_condition = $config->get('roles_condition');
          if ($roles_condition == 'and') {
            $uid = urct_get_user_having_alls_roles($last_selected_uid);
          }
          else {
            $uid = urct_get_user_having_any_roles($last_selected_uid);
          }
        }
        else if ($fallback_type == 'view') {
          $uid = urct_get_user_from_view($last_selected_uid);
        }
      }
      if (!empty($uid) && $config->get('roll_up') == 'enroller') {
        $referrer_account = User::load($uid);
        do {
          if ($referrer_account && $referrer_account->isActive()) {
            $uid = $referrer_account->id();
            break;
          }
          else if ($referrer_account) {
            // Referrer account exists but not active.
            // Then find enroller of this account.
            $referrer_account = UserReferral::getReferrer($referrer_account);
          }
          else {
            break;
          }
        } while($referrer_account);
      }
      if (empty($uid)) {
        $default_fallback_referrer_id = $config->get('default_fallback_referrer');
        if (!empty($default_fallback_referrer_id)) {
          $uid = $default_fallback_referrer_id;
        }
      }
    }
    $account = User::load($uid);
  }
  return $account;
}

function urct_get_user_having_any_roles($last_selected_uid) {
  static $times = 0;
  $selected_uid = NULL;
  $config = \Drupal::service('config.factory')->getEditable('urct.settings');
  $roles = array_values(array_filter($config->get('roles')));

  $query = \Drupal::entityQuery('user')
    ->condition('status', 1)
    ->condition('uid', $last_selected_uid, '>');
    $query->condition('roles', $roles, 'IN');
  $ids = $query->range(0, 1)->execute();
  if (empty($ids) && $times == 0) {
    $times++;
    $selected_uid = urct_get_user_having_any_roles(0);
  }
  else {
    $selected_uid = reset($ids);
    $config->set('last_selected_uid', $selected_uid);
    $config->save();
  }
  return $selected_uid;
}

function urct_get_user_having_alls_roles($last_selected_uid) {
  static $times = 0;
  $selected_uid = NULL;
  $config = \Drupal::service('config.factory')->getEditable('urct.settings');
  $roles = array_values(array_filter($config->get('roles')));

  $database = \Drupal::database();
  $query = $database->select('users_field_data', 'u');
  foreach ($roles as $index => $role) {
    $alias = 'ur_' . $index;
    $query->join('user__roles', $alias, "u.uid = $alias.entity_id AND $alias.deleted = 0 AND $alias.roles_target_id = '$role'");
  }

  $query->fields('u', array('uid'));
  $query->condition('uid', $last_selected_uid, '>');

  $id = $query->range(0, 1)->execute()->fetchField();
  if (empty($id) && $times == 0) {
    $times++;
    $selected_uid = urct_get_user_having_alls_roles(0);
  }
  else {
    $selected_uid = $id;
    $config->set('last_selected_uid', $selected_uid);
    $config->save();
  }
  return $selected_uid;
}

function urct_get_user_from_view($last_selected_uid) {
  static $times = 0;
  $selected_uid = NULL;
  $config = \Drupal::service('config.factory')->getEditable('urct.settings');
  $view_name = 'urct_referral_fallbacks';

  $view = Views::getView($view_name);

  // Set which view display we want.
  $view->setDisplay('default');
  // To initialize the query.
  $view->build();
  // Get underlaying SQL select query.
  // We will execute the select query directly without executing the whole view.
  // Executing the whole view will cause to load the user objects will increase the memory usage, which we want never to happen here.
  $query = $view->getQuery()->query();
  $fields = &$query->getFields();
  // Ensure uid is always as first column, so we can take it easily from the result.
  unset($fields['uid']);
  $fields = [
    'uid' => [
      'field' => 'uid',
      'table' => 'users_field_data',
      'alias' => 'uid',
    ],
  ] + $fields;

  $results = $query->execute()->fetchCol();

  if (!empty($results)) {
    if (!empty($last_selected_uid)) {
      $index_of_last_selected_uid = array_search($last_selected_uid, $results);
      if ($index_of_last_selected_uid === FALSE || $index_of_last_selected_uid == (count($results) - 1)) {
        // Could not find or last selected item is las position in result.
        // Select the first item from the result.
        $selected_uid = reset($results);
      }
      else {
        $selected_uid = $results[$index_of_last_selected_uid + 1];
      }
    }
    else {
      // No information about last selected uid.
      // Start with first item in the result.
      $selected_uid = reset($results);
    }
  }
  else {
    $selected_uid = NULL;
  }

  $config->set('last_selected_uid', $selected_uid);
  $config->save();

  return $selected_uid;
}
