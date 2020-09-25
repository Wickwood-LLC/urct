<?php

namespace Drupal\urct;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user_referral\Entity\UserReferralType;

class ReferralUrlHandler implements InboundPathProcessorInterface {

  const COOKIE_NAME = 'urct_referral';

  /**
   * Referrer user id and referral type.
   *
   * @var \stdClass;
   */
  protected $referralItem;

  protected $processed = FALSE;

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (preg_match('~/([a-zA-Z]+)-refid-(\w+)$~', $path, $matches)) {
      $parts = array_filter(explode('/', $path));
      array_pop($parts);
      $path = '/' . implode('/', $parts);

      $this->referralItem = new \stdClass();
      $this->referralItem->refid = $matches[1];
      $this->referralItem->type = str_replace('-', '_', $matches[2]);

      $request->attributes->add(['_disable_route_normalizer' => TRUE]);
      $this->setPathReferralCookie($this->referralItem);

      $this->processed = TRUE;
    }
    return $path;
  }

  public static function setPathReferralCookie($referral_item) {
    $existing_cookie = isset($_COOKIE[self::COOKIE_NAME]) ? json_decode($_COOKIE[self::COOKIE_NAME]) : NULL;
    $referral_type = UserReferralType::load($referral_item->type);
    if ($referral_type) {
      $account = $referral_type->getReferralIDAccount($referral_item->refid);
      if ($account) {
        if (!$existing_cookie || $existing_cookie->uid != $account->id() || $existing_cookie->type != $referral_item->type) {
          $cookie = new \stdClass();
          $cookie->uid = $account->id();
          $cookie->type = $referral_item->type;
          setcookie(self::COOKIE_NAME, json_encode($cookie), time() + 7 * 24 * 60 * 60, '/');
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