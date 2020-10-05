<?php

/**
 * @file
 * Builds placeholder replacement tokens for user referral related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Drupal\user_referral\Entity\UserReferralType;

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

  $node['url-non-referral'] = [
    'name' => t("Non referral canonical URL"),
    'description' => t("The non-referral canonical URL of the node."),
  ];

  $site['url-non-referral'] = [
    'name' => t("Non referral URL"),
    'description' => t("The non-referral of the site's front page."),
  ];

  $url['non-referral'] = [
    'name' => t('Non referral URL'),
    'description' => t('The non-referral URL.'),
  ];

  return [
    'types' => $types,
    'tokens' => ['node' => $node, 'url' => $url, 'site' => $site],
  ];
}

/**
 * Implements hook_tokens().
 */
function urct_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $token_service = \Drupal::token();
  $replacements = [];

  $url_options = ['absolute' => TRUE];
  if (isset($options['langcode'])) {
    $url_options['language'] = \Drupal::languageManager()->getLanguage($options['langcode']);
  }

  if ($type == 'referrer_from_cookie') {
    $referral_item = \Drupal::service('urct.referral_manager')->getCurrentReferralItem();
    $referral_type = UserReferralType::load($referral_item->type);
    if ($referral_type) {
      $account = $referral_type->getReferralIDAccount($referral_item->refid);
      $bubbleable_metadata->addCacheContexts(['user_referral']);
      // $bubbleable_metadata->addCacheableDependency($account);
      $replacements += $token_service->generate('user', $tokens, ['user' => $account], $options, $bubbleable_metadata);
    }
  }
  else if ($type == 'node' && !empty($data['node'])) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $data['node'];

    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'url-non-referral':
          $url = $node->toUrl('canonical', $url_options)->toString();
          $url_parts = parse_url($url);
          if ($result = \Drupal::service('urct.referral_url_handler')->getReferralFromPath($url_parts['path'])) {
            $url_parts['path'] = $result->normal_path;
            $url = unparse_url($url_parts);
          }
          $replacements[$original] = $url;
          break;
      }
    }
  }
  else if ($type == 'url' && !empty($data['url'])) {
    /** @var \Drupal\Core\Url $url */
    $url = $data['url'];

    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'non-referral':
          $unaliased = clone $url;

          $url_string = $url->toString();
          $url_parts = parse_url($url_string);
          if ($result = \Drupal::service('urct.referral_url_handler')->getReferralFromPath($url_parts['path'])) {
            $url_parts['path'] = $result->normal_path;
            $url = unparse_url($url_parts);
          }
          $replacements[$original] = $url;
          break;
      }
    }
  }
  else if ($type == 'site') {
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'url-non-referral':
          /** @var \Drupal\Core\GeneratedUrl $result */
          $result = Url::fromRoute('<front>', [], $url_options)->toString(TRUE);
          $bubbleable_metadata->addCacheableDependency($result);
          $url = $result->getGeneratedUrl();
          $url_parts = parse_url($url);
          if ($result = \Drupal::service('urct.referral_url_handler')->getReferralFromPath($url_parts['path'])) {
            $url_parts['path'] = $result->normal_path;
            $url = unparse_url($url_parts);
          }
          $replacements[$original] = $url;
          break;
      }
    }
  }
  return $replacements;
}

function unparse_url($parsed_url) {
  $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
  $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
  $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
  $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
  $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
  $pass     = ($user || $pass) ? "$pass@" : '';
  $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
  $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
  $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
  return "$scheme$user$pass$host$port$path$query$fragment";
}
