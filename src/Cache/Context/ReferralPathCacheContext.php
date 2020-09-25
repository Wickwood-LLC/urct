<?php

namespace Drupal\urct\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\urct\ReferralManager;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\user\Entity\User;

/**
 * Defines the ReferralPathCacheContext service, for "per referrid in URL" caching.
 *
 * Cache context ID: 'user_referral'.
 */
class ReferralPathCacheContext implements CacheContextInterface {


  /**
   * ReferramManager service object
   * 
   * @var \Drupal\urct\ReferralManager
   */
  protected $referralManager;

  /**
   * Constructs a new UserCacheContext service.
   *
   * @param \Drupal\urct\ReferralManager $referralManager
   *   The current user.
   */
  public function __construct(ReferralManager $referral_manager) {
    $this->referralManager = $referral_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t("Referral ID in URL");
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    // return $this->permissionsHashGenerator->generate($this->user);
    $referral_item = $this->referralManager->getCurrentReferralItem();
    $referral_type = UserReferralType::load($referral_item->type);
    if ($referral_type) {
      $account = $referral_type->getReferralIDAccount($referral_item->refid);
      if ($account) {
        return $account->id();
      }
    }
    
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    $cacheable_metadata = new CacheableMetadata();

    $referral_item = $this->referralManager->getCurrentReferralItem();
    $referral_type = UserReferralType::load($referral_item->type);
    if ($referral_type) {
      $account = $referral_type->getReferralIDAccount($referral_item->refid);
      if ($account) {
        $tags = ['referrer:' . $referral_item->uid];
        $cacheable_metadata->setCacheTags($tags);
      }
    }

    return $cacheable_metadata;
  }

}
