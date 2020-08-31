<?php

/**
 * @file
 * Builds placeholder replacement tokens for user referral related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\user\Entity\User;
use Drupal\views\Views;

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
    \Drupal::service('page_cache_kill_switch')->trigger();
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

        if ($fallback_type == 'singel_user') {
          $single_user_id = $config->get('single_user');
          if (!empty($single_user_id)) {
            $uid = $single_user_id;
          }
        }
        else if ($fallback_type == 'roles') {
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
  $view_name = $config->get('view');

  $view = Views::getView($view_name);

  // Set which view display we want.
  $view->setDisplay('default');

  $query = $view->getQuery();
  // Always select only one row and just after already selected user.
  $query->addWhere(0, 'uid', $last_selected_uid, '>');
  $query->setLimit(1);
  $query->setOffset(0);

  // Execute the view.
  $view->execute();
  $view_result = $view->result;

  if (empty($view_result) && $times == 0) {
    $times++;
    $selected_uid = urct_get_user_from_view(0);
  }
  else {
    $row = reset($view_result);
    $selected_uid = $row->_entity->id();
    $config->set('last_selected_uid', $selected_uid);
    $config->save();
  }
  return $selected_uid;
}
