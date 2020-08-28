<?php

/**
 * @file
 * Builds placeholder replacement tokens for user referral related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\user\Entity\User;

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
  if (!isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
    return;
  }

  // Retrieve referral info from the cookie
  $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);

  if (empty($cookie)) {
    // Nothing to do ...
    return;
  }
  return User::load($cookie->uid);
}