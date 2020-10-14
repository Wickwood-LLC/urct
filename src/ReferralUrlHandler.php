<?php

namespace Drupal\urct;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user_referral\Entity\UserReferralType;

class ReferralUrlHandler implements InboundPathProcessorInterface {

  // const COOKIE_NAME = 'urct_referral';

  /**
   * Referrer user id and referral type.
   *
   * @var \stdClass;
   */
  protected $referralItem;

  protected $processed = FALSE;

  public static $setting_path_cookie = FALSE;

  public static function getReferralFromPath($path) {
    $path_parts = array_filter(explode('/', $path));
    $path_part_count = count($path_parts);
    $original_path_parts = $path_parts;
    $num_items_to_remove = 0;
    if ($path_part_count > 0) {
      $account = NULL;
      $referral_type = NULL;
      $refid = NULL;
      $last_item = array_pop($path_parts);
      if ( $path_part_count > 1) {
        // Path requires 2 or more components to have referral type name in its last part.
        $referral_type = UserReferralType::load($last_item);
        if ($referral_type) {
          $previous_to_last_item = array_pop($path_parts);
          $account = $referral_type->getReferralIDAccount($previous_to_last_item);
          if ($account) {
            $refid = $previous_to_last_item;
            $num_items_to_remove = 2;
          }
        }
      }
      if (!$account || !$referral_type) {
        $referral_types = UserReferralType::loadMultiple();
        $referral_type = reset($referral_types);
        if ($referral_type) {
          $account = $referral_type->getReferralIDAccount($last_item);
          $refid = $last_item;
          $num_items_to_remove = 1;
        }
      }
      if ($account && $referral_type) {
        $referral_item = new \stdClass();
        $referral_item->refid = $refid;
        $referral_item->uid = $account->id();
        $referral_item->type = $referral_type->id();
        $referral_item->normal_path = '/' . implode('/', array_slice($original_path_parts, 0, $path_part_count - $num_items_to_remove));
        $referral_item->refid_only = $num_items_to_remove == 1;
        return $referral_item;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (\Drupal::service('urct.path_validator')->getUrlIfValidWithoutAccessCheck($path)) {
      return $path;
    }
    // if (strpos($path, '/user/') === 0) {
    //   return $path;
    // }
    $result = static::getReferralFromPath($path);
    if ($result) {
      $path = $result->normal_path;
      $this->referralItem = $result;

      $request->attributes->add(['_disable_route_normalizer' => TRUE]);
      $this->setPathReferralCookie($this->referralItem, TRUE);
      \Drupal::service('urct.referral_manager')->setCurrentReferralItem($result);

      $this->processed = TRUE;
    }
    return $path;
  }

  public static function setPathReferralCookie($referral_item, $overwrite = FALSE) {
    static $set_cookie = FALSE;
    if (!$set_cookie) {
      $referral_type = UserReferralType::load($referral_item->type);
      if ($referral_type) {
        $account = $referral_type->getReferralIDAccount($referral_item->refid);
        if ($account) {
          $existing_cookie = isset($_COOKIE[UserReferralType::COOKIE_NAME]) ? json_decode($_COOKIE[UserReferralType::COOKIE_NAME]) : NULL;
          if ($existing_cookie) {
            if (empty($existing_cookie->auto)) {
              // Not to process cookie set with regular referral link or aliases.
              return;
            }
            else if ($overwrite) {
              // This cookie is with auto assigned referrer which is supposed to overwirte.
              // So remove it from $_COOKIE array, other wise UserReferralType::setCookie() will apply reassign logic.
              unset($_COOKIE[UserReferralType::COOKIE_NAME]);
            }
          }
          if ($overwrite || !$existing_cookie) {
            self::$setting_path_cookie = TRUE;
            // $cookie = new \stdClass();
            // $cookie->uid = $account->id();
            // $cookie->type = $referral_item->type;
            // setcookie(self::COOKIE_NAME, json_encode($cookie), time() + 7 * 24 * 60 * 60, '/');
            $referral_type->setCookie($account, \Drupal::request());
            $set_cookie = TRUE;
            self::$setting_path_cookie = FALSE;
          }
        }
      }
    }
  }

  public function isProcessed() {
    return $this->processed;
  }

  public function setProcessed($processed) {
    return $this->processed = $processed;
  }
}