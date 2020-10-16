<?php

namespace Drupal\urct\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\Tests\user_referral\Traits\UserReferralTypeCreationTrait;
use Drupal\user_referral\Entity\UserReferralType;

/**
 * Test basic functionality of Realname module.
 *
 * @group urct
 */
class UrctBasicTest extends BrowserTestBase {

  use UserReferralTypeCreationTrait {
    createUserReferralType as drupalCreateUserReferralType;
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

    $this->consultant_referral_type = $this->drupalCreateUserReferralType([
      'type' => 'consultant',
      'name' => 'Consultant',
      'referral_field' => 'field_referral_id',
      'roles' => ['consultant'],
      'url_alias_pattern' => '/[user_referral_type:referral_field_value]'
    ]);

    $this->referral_partner_referral_type = $this->drupalCreateUserReferralType([
      'type' => 'referralpartner',
      'name' => 'Referral Partner',
      'referral_field' => 'field_referral_id',
      'roles' => ['referral partner'],
      'url_alias_pattern' => '/[user_referral_type:referral_field_value]/[user_referral_type:type]',
    ]);

    $this->consultant_referrer = $this->drupalCreateUser([], 'referrer1', FALSE, ['field_referral_id' => 'referrer1', 'roles' => ['consultant']]);
    $this->referral_partner_referrer = $this->drupalCreateUser([], 'referrer2', FALSE, ['field_referral_id' => 'referrer2', 'roles' => ['referral partner']]);
    
    // Ensure referral link aliases are created.
    $this->consultant_referral_type->getReferralLink($this->consultant_referrer);
    $this->referral_partner_referral_type->getReferralLink($this->referral_partner_referrer);

    // User to set up realname.
    $this->admin_user = $this->drupalCreateUser($permissions);
  }

  /**
   * Test configuration form.
   */
  public function testConfigurationForm() {
    // Login as admin
    $this->drupalLogin($this->admin_user);
    // Take configuration form
    $this->drupalGet('admin/config/people/user-referral/tokens');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('fallback_type');
    $this->assertSession()->checkboxChecked('fallback_type');
    // $this->assertSession()->optionExists('fallback_type', 'referral_types');


    $this->assertSession()->fieldExists('referral_types[' . $this->consultant_referral_type->id() . ']');
    $this->assertSession()->fieldExists('referral_types[' . $this->referral_partner_referral_type->id() . ']');

    $this->assertSession()->fieldExists('referral_types_filter_by_view');

    $this->assertSession()->fieldExists('referral_types_filter_by_view_negate');

    $this->assertSession()->fieldExists('roll_up');

    $this->assertSession()->checkboxNotChecked('debug');

    $this->assertSession()->buttonExists('Save configuration');
  }

  /**
   * Test setting default fallback user.
   */
  public function testDefaultFallBackUser() {
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
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual($referral_cookie->uid, $this->consultant_referrer->id());
    $this->assertEqual($referral_cookie->type, $this->consultant_referral_type->id());

    $this->drupalLogin($this->admin_user);
    $this->drupalGet('admin/config/people/user-referral/tokens');
    $edit = [
      'default_fallback_referrer[referrer]' => 'referrer2 (' . $this->referral_partner_referrer->id() . ')',
      // 'default_fallback_referrer_referral_type' => 'referralpartner',
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
    $this->assertEqual($referral_cookie->uid, $this->referral_partner_referrer->id());
    $this->assertEqual($referral_cookie->type, $this->referral_partner_referral_type->id());
  }

  /**
   * Test fallback rotation.
   */
  public function testFallbackRotation() {
    // Login as admin
    $this->drupalLogin($this->admin_user);
    // Take configuration form
    $this->drupalGet('admin/config/people/user-referral/tokens');

    $edit = [
      'default_fallback_referrer[referrer]' => 'referrer1 (' . $this->consultant_referrer->id() . ')',
      // 'default_fallback_referrer_referral_type' => 'consultant',
      'fallback_type' => 'referral_types',
      'referral_types[consultant]' => 'consultant',
      'referral_types[referralpartner]' => 'referralpartner',
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();
    // Close the prior connection and remove the collected state.
    $this->getSession()->reset();

    $this->drupalGet('');
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual(isset($referral_cookie->auto), TRUE, t('Referral cookie auto property exists'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie should flagged as auto referral assgined'));
    $first_referrer_uid = $referral_cookie->uid;

    // To ensure referral cookie is retained when accessed again.
    $referral_cookie_old = clone $referral_cookie;
    $this->drupalGet('');
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $first_referrer_uid = $referral_cookie->uid;
    $this->assertEqual($referral_cookie_old->uid, $referral_cookie->uid, t('Referrer UID in cookie retained'));
    $this->assertEqual($referral_cookie_old->type, $referral_cookie->type, t('Referrer UID in cookie retained'));
    $this->assertEqual(isset($referral_cookie->auto), TRUE, t('Referral cookie auto property exists'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie should flagged as auto referral assgined'));
    
    // Close the prior connection and remove the collected state.
    $this->getSession()->reset();
    
    $this->drupalGet('');
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual(isset($referral_cookie->auto), TRUE, t('Referral cookie auto property exists'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie should flagged as auto referral assgined'));
    $second_referrer_uid = $referral_cookie->uid;

    $this->assertNotEqual($first_referrer_uid, $second_referrer_uid, t('Referrer rotation wroks'));

    // Close the prior connection and remove the collected state.
    $this->getSession()->reset();

    $this->drupalGet('');
    $this->assertSession()->cookieExists(UserReferralType::COOKIE_NAME);
    $referral_cookie = json_decode($this->getSession()->getCookie(UserReferralType::COOKIE_NAME));
    $this->assertEqual(isset($referral_cookie->auto), TRUE, t('Referral cookie auto property exists'));
    $this->assertEqual(1, $referral_cookie->auto, t('Referral cookie should flagged as auto referral assgined'));
    $third_referrer_uid = $referral_cookie->uid;

    $this->assertNotEqual($second_referrer_uid, $third_referrer_uid, t('Referrer rotation wroks'));

    $this->assertEqual($first_referrer_uid, $third_referrer_uid, t('Referrer rotation completes'));

  }

}
