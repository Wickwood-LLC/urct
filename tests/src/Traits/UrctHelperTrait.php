<?php

namespace Drupal\Tests\urct\Traits;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\user_referral\Entity\UserReferralType;

use Drupal\user_referral\UserReferral;

/**
 * Provides methods to help testing.
 */
trait UrctHelperTrait {

  protected function assertReferralEntry($user, $referrer, $referral_type, $manually_entered, $auto) {
    if (is_object($referrer)) {
      $referrer = $referrer->id();
    }
    if (is_object($referral_type)) {
      $referral_type = $referral_type->id();
    }
    $referral_entry = UserReferral::getReferralEntry($user);
    $this->assertNotIdentical(FALSE, $referral_entry, t('Referral entry exists'));
    $this->assertEqual($referrer, $referral_entry->referrer_uid, t('Referrer in referral entry matches'));
    $this->assertEqual($referral_type, $referral_entry->type, t('Referral type matches in referral entry'));
    $this->assertEqual($manually_entered, $referral_entry->manually_entered, t('Referrer entry has manually_entered flag set  to %flag.', ['%flag' => $manually_entered]));
    $this->assertEqual($auto, $referral_entry->auto_referrer, t('Referrer entry has auto_referrer flag set  to %flag.', ['%flag' => $auto]));
  }

}
