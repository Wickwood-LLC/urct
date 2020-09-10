<?php

/**
 * @file
 * Builds placeholder replacement tokens for user referral related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
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
    $account = \Drupal::service('urct.referral_manager')->getCurrentReferrer();
    $bubbleable_metadata->addCacheContexts(['user_referral']);
    // $bubbleable_metadata->addCacheableDependency($account);
    $replacements += $token_service->generate('user', $tokens, ['user' => $account], $options, $bubbleable_metadata);
  }
  return $replacements;
}


