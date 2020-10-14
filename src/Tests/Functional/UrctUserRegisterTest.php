<?php

namespace Drupal\urct\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\user_referral\Traits\UserReferralTypeCreationTrait;
use Drupal\Tests\user_referral\Traits\ReferralIdFieldTrait;
use Drupal\user\Entity\User;
use Drupal\user_referral\UserReferral;
use Drupal\user_referral\Entity\UserReferralType;

/**
 * Test user registrations.
 *
 * @group urct
 */
class UrctUserRegisterTest extends BrowserTestBase {

  use UserReferralTypeCreationTrait {
    createUserReferralType as drupalCreateUserReferralType;
  }

  use ReferralIdFieldTrait {
    createReferralIDField as drupalCreateReferralIDField;
  }

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

    $config = \Drupal::configFactory()->getEditable('urct.settings');

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
      'default_fallback_referrer' => 'referrer1 (' . $this->consultant_referrer->id() . ')',
      // 'default_fallback_referrer_referral_type' => 'consultant',
      'fallback_type' => 'default_fallback_referrer',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();
    // Close the prior connection and remove the collected state.
    $this->getSession()->reset();

    $this->drupalGet('');
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $edit = [];
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertSession()->statusCodeEquals(200);
    $uids = \Drupal::entityQuery('user')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $last_user_uid = reset($uids);
    $last_user = User::load($last_user_uid);
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertEqual($referral_entry->referrer_uid, $referral_cookie->uid, t('Referrer UID matche in cookie and referral entry'));
    $this->assertEqual($referral_entry->type, $referral_cookie->type, t('Referrer UID matche in cookie and referral entry'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag set indicating auto assigned'));
    $this->assertEqual(1, $referral_entry->auto_referrer, t('Referral entry has auto flag set indicating auto assigned'));

    $this->getSession()->reset(); // Reset browser session cookie, so re-assining logic wont affect.

    // Test referral ID of default referral type at end of the URL.
    $this->drupalGet($this->consultant_referrer->get('field_referral_id')->first()->getValue()['value']);
    $edit = [];
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual($referral_cookie->uid, $this->consultant_referrer->id(), t('Referrer UID matches in cookied'));
    $this->assertEqual($referral_cookie->type, $this->consultant_referral_type->id(), t('Referral type matches in cookie'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag indicating auto assigned'));
    $uids = \Drupal::entityQuery('user')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $last_user_uid = reset($uids);
    $last_user = User::load($last_user_uid);
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertNotIdentical(FALSE, $referral_entry, t('Referral entry exists'));
    $this->assertEqual($this->consultant_referrer->id(), $referral_entry->referrer_uid, t('Referrer got recorded'));
    $this->assertEqual(1, $referral_entry->auto_referrer, t('Referrer entyr has auto_referrer flag set indicating auto assinged referrer'));

    $this->getSession()->reset(); // Reset browser session cookie, so re-assining logic wont affect.

    // Test referral id / referral type at end of the URL.
    $this->drupalGet($this->referral_partner_referrer->get($this->referral_id_field_2)->first()->getValue()['value'] . '/' . $this->referral_partner_referral_type->id());
    $edit = [];
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual($referral_cookie->uid, $this->referral_partner_referrer->id(), t('Referrer UID matches in cookied'));
    $this->assertEqual($referral_cookie->type, $this->referral_partner_referral_type->id(), t('Referral type matches in cookie'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie has auto flag indicating auto assigned'));
    $uids = \Drupal::entityQuery('user')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $last_user_uid = reset($uids);
    $last_user = User::load($last_user_uid);
    // $referrer = UserReferral::getReferrer($last_user);
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertNotIdentical(FALSE, $referral_entry, t('Referral entry exists'));
    $this->assertEqual($this->referral_partner_referrer->id(), $referral_entry->referrer_uid, t('Referrer got recorded'));
    $this->assertEqual(1, $referral_entry->auto_referrer, t('Referrer as auto assinged referrer'));


    // Ensure accessing referral entry does not set auto flag in cookie or referral entry
    // Also ensure non-auto referral cookie is set by accessing referral link without clearing browser cookies.
    $this->drupalGet('user/' . $this->consultant_referrer->id() . '/user-referral/' . $this->consultant_referral_type->id());
    $edit = [];
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual($referral_cookie->uid, $this->consultant_referrer->id(), t('Referrer UID matches in cookied'));
    $this->assertEqual($referral_cookie->type, $this->consultant_referral_type->id(), t('Referral type matches in cookie'));
    $this->assertEqual(TRUE, empty($referral_cookie->auto), t('Referral cookie has no auto flag indicating auto assigned'));
    $uids = \Drupal::entityQuery('user')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $last_user_uid = reset($uids);
    $last_user = User::load($last_user_uid);
    $referrer = UserReferral::getReferrer($last_user);
    $this->assertEqual($this->consultant_referrer->id(), $referrer->id(), t('Referrer got recorded'));
    $referral_entry = UserReferral::getReferralEntry($last_user);
    $this->assertNotIdentical(FALSE, $referral_entry, t('Referral entry exists'));
    $this->assertEqual($this->consultant_referrer->id(), $referral_entry->referrer_uid, t('Referrer got recorded'));
    $this->assertEqual($this->consultant_referral_type->id(), $referral_entry->type, t('Referral type matches in referral entry'));
    $this->assertEqual(0, $referral_entry->auto_referrer, t('Referrer as auto assinged referrer is not set.'));
  }
}
