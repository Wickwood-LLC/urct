<?php

namespace Drupal\urct\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\urct\ReferralManager;

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
    $referrer = $this->referralManager->getCurrentReferrer();
    if ($referrer) {
      return $referrer->id();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    $cacheable_metadata = new CacheableMetadata();

    $referrer = $this->referralManager->getCurrentReferrer();
    if ($referrer) {
      $tags = ['referrer:' . $referrer->id()];
      $cacheable_metadata->setCacheTags($tags);
    }

    return $cacheable_metadata;
  }

}
