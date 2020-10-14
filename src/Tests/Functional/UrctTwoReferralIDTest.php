<?php

namespace Drupal\urct\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\user_referral\Traits\UserReferralTypeCreationTrait;
use Drupal\Tests\user_referral\Traits\ReferralIdFieldTrait;
use Drupal\urct\ReferralUrlHandler;

/**
 * Test basic functionality of Realname module.
 *
 * @group urct
 */
class UrctTwoReferralIDTest extends BrowserTestBase {

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
  }

  public function testReferralIDInUrl() {
    $this->drupalGet($this->consultant_referrer->get('field_referral_id')->first()->getValue()['value']);
    $referral_cookie = json_decode($this->getSession()->getCookie(ReferralUrlHandler::COOKIE_NAME));
    $this->assertEqual($this->consultant_referrer->id(), $referral_cookie->uid, t('Referral ID matches in cookied'));
    $this->assertEqual($this->consultant_referral_type->id(), $referral_cookie->type, t('Referral type matches to default referral type without it in the URL'));

    // Verify cookie re-assigning.
    $this->drupalGet($this->referral_partner_referrer->get($this->referral_id_field_2)->first()->getValue()['value'] . '/' . $this->referral_partner_referral_type->id());
    $referral_cookie = json_decode($this->getSession()->getCookie(ReferralUrlHandler::COOKIE_NAME));
    $this->assertEqual($this->referral_partner_referrer->id(), $referral_cookie->uid, t('Referral ID matches in cookied'));
    $this->assertEqual($this->referral_partner_referral_type->id(), $referral_cookie->type, t('Referral type matches to referral type in the URL'));

    $this->getSession()->reset(); // Or Drupal redirects to previous page on 404?

    // Access with non valid referral type for the referrer
    $this->drupalGet($this->consultant_referrer->get('field_referral_id')->first()->getValue()['value']. '/' . $this->referral_partner_referral_type->id());
    $this->assertSession()->statusCodeEquals(404);

    $this->getSession()->reset(); // Or Drupal redirects to previous page on 404?

    // Access with non valid referral type for the referrer
    $this->drupalGet($this->referral_partner_referrer->get($this->referral_id_field_2)->first()->getValue()['value']. '/' . $this->consultant_referral_type->id());
    $this->assertSession()->statusCodeEquals(404);
  }


}
