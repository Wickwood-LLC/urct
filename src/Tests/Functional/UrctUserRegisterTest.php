<?php

namespace Drupal\urct\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\user_referral\Traits\UserReferralTypeTestTrait;
use Drupal\Tests\user_referral\Traits\ReferralIdFieldTrait;
use Drupal\Tests\user_referral\Traits\UserRegistrationTrait;
use Drupal\user\Entity\User;
use Drupal\user_referral\UserReferral;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\Tests\urct\Traits\UrctHelperTrait;

/**
 * Test user registrations.
 *
 * @group urct
 */
class UrctUserRegisterTest extends BrowserTestBase {

  use UserReferralTypeTestTrait {
    createUserReferralType as drupalCreateUserReferralType;
  }

  use ReferralIdFieldTrait {
    createReferralIDField as drupalCreateReferralIDField;
  }

  use UserRegistrationTrait;
  use UrctHelperTrait;

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'urct',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $permissions = [
      'access administration pages',
      'administer modules',
      'administer site configuration',
      'administer users',
      'administer user referral types',
    ];

    $this->consultant_role = $this->drupalCreateRole([], 'consultant');
    $this->referral_partner_role = $this->drupalCreateRole([], 'referral partner');

    $this->referral_id_field_2 = $this->drupalCreateReferralIDField();

    $this->consultant_referral_type = $this->drupalCreateUserReferralType([
      'type' => 'consultant',
      'name' => 'Consultant',
      'referral_field' => 'field_referral_id',
      'roles' => ['consultant'],
      'weight' => 0,
    ]);

    $this->referral_partner_referral_type = $this->drupalCreateUserReferralType([
      'type' => 'referralpartner',
      'name' => 'Referral Partner',
      'referral_field' => $this->referral_id_field_2,
      'roles' => ['referral partner'],
      'weight' => 1,
    ]);

    $this->consultant_referrer = $this->drupalCreateUser([], 'referrer1', FALSE, ['field_referral_id' => 'referrer1', 'roles' => ['consultant']]);
    $this->referral_partner_referrer = $this->drupalCreateUser([], 'referrer2', FALSE, [$this->referral_id_field_2 => 'referrer2', 'roles' => ['referral partner']]);
    
    // Ensure referral link aliases are created.
    $this->consultant_referral_type->getReferralLink($this->consultant_referrer);
    $this->referral_partner_referral_type->getReferralLink($this->referral_partner_referrer);

    // User to set up realname.
    $this->admin_user = $this->drupalCreateUser($permissions);

    // $config = \Drupal::configFactory()->getEditable('urct.settings');

    // NOT Working: why?
    // $config->set('default_fallback_referrer', $this->consultant_referrer->id());
    // $config->set('default_fallback_referrer_referral_type', $this->consultant_referral_type->id());
    // $config->save();
  }

  public function testUserRegistrations() {

    // Login as admin
    $this->drupalLogin($this->admin_user);
    // Take configuration form
    $this->drupalGet('admin/config/people/user-referral/tokens');

    $edit = [
      'default_fallback_referrer[referrer]' => 'referrer1 (' . $this->consultant_referrer->id() . ')',
      // 'default_fallback_referrer_referral_type' => 'consultant',
      'fallback_type' => 'default_fallback_referrer',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();
    // Close the prior connection and remove the collected state.
    $this->getSession()->reset();

    $this->drupalGet('');
    $referral_cookie = $this->getReferralCookie();
    $last_user = $this->registerUser();
    $this->assertSession()->statusCodeEquals(200);
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertReferralCookie($referral_entry->referrer_uid, $referral_entry->type);
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag set indicating auto assigned'));
    $this->assertEqual(1, $referral_entry->auto_referrer, t('Referral entry has auto flag set indicating auto assigned'));

    $this->getSession()->reset(); // Reset browser session cookie, so re-assining logic wont affect.

    // Test referral ID of default referral type at end of the URL.
    $this->drupalGet($this->consultant_referrer->get('field_referral_id')->first()->getValue()['value']);
    $last_user = $this->registerUser();
    $this->assertSession()->statusCodeEquals(200);
    $referral_cookie = $this->getReferralCookie();
    $this->assertReferralCookie($this->consultant_referrer->id(), $this->consultant_referral_type->id());
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag indicating auto assigned'));
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertReferralEntry($last_user, $this->consultant_referrer, $this->consultant_referral_type, 0, 1);

    $this->getSession()->reset(); // Reset browser session cookie, so re-assining logic wont affect.

    // Test referral id / referral type at end of the URL.
    $this->drupalGet($this->referral_partner_referrer->get($this->referral_id_field_2)->first()->getValue()['value'] . '/' . $this->referral_partner_referral_type->id());
    $last_user = $this->registerUser();
    $this->assertSession()->statusCodeEquals(200);
    $referral_cookie = $this->getReferralCookie();
    $this->assertReferralCookie($this->referral_partner_referrer->id(), $this->referral_partner_referral_type->id());
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag indicating auto assigned'));
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertReferralEntry($last_user, $this->referral_partner_referrer, $this->referral_partner_referral_type, 0, 1);


    // Ensure accessing referral entry does not set auto flag in cookie or referral entry
    // Also ensure non-auto referral cookie is set by accessing referral link without clearing browser cookies.
    $this->drupalGet('user/' . $this->consultant_referrer->id() . '/user-referral/' . $this->consultant_referral_type->id());
    $last_user = $this->registerUser();
    $this->assertSession()->statusCodeEquals(200);
    $referral_cookie = $this->getReferralCookie();
    $this->assertReferralCookie($this->consultant_referrer->id(), $this->consultant_referral_type->id());
    $this->assertEqual(TRUE, empty($referral_cookie->auto), t('Referral cookie has no auto flag indicating auto assigned'));
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertReferralEntry($last_user, $this->consultant_referrer, $this->consultant_referral_type, 0, 0);
  }
}
