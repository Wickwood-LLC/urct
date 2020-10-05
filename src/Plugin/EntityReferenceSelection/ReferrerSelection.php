<?php

namespace Drupal\urct\Plugin\EntityReferenceSelection;

use Drupal\user\Plugin\EntityReferenceSelection\UserSelection;
use Drupal\user_referral\Entity\UserReferralType;

/**
 * Provides specific access control for the referrer users.
 *
 * @EntityReferenceSelection(
 *   id = "urct:referrer_user_selection",
 *   label = @Translation("Referrer selection"),
 *   entity_types = {"user"},
 *   group = "urct",
 *   weight = 1
 * )
 */
class ReferrerSelection extends UserSelection {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'include_anonymous' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    $configuration = $this->getConfiguration();

    // Filter out the Anonymous user if the selection handler is configured to
    // exclude it.
    if (!$configuration['include_anonymous']) {
      $query->condition('uid', 0, '<>');
    }

    // The user entity doesn't have a label column.
    if (isset($match)) {
      $query->condition('name', $match, $match_operator);
    }

    $referral_enabled_roles = [];
    $referral_id_fields = [];
    foreach (UserReferralType::loadMultiple() as $referral_type_id => $referral_type) {
      $referral_enabled_roles = array_merge($referral_enabled_roles, $referral_type->getRoles());
      $referral_id_fields[] = $referral_type->getReferralField();
    }

    if (count($referral_id_fields) == 1) {
      $query->exists($referral_id_fields[0]);
    }
    else {
      $group = $query->orConditionGroup();
      foreach ($referral_id_fields as $referral_id_field) {
        $group->exists($referral_id_field);
      }
      $query->condition($group);
    }


    // Filter by role.
    if (!empty($referral_enabled_roles)) {
      $query->condition('roles', $referral_enabled_roles, 'IN');
    }

    // Adding the permission check is sadly insufficient for users: core
    // requires us to also know about the concept of 'blocked' and 'active'.
    if (!$this->currentUser->hasPermission('administer users')) {
      $query->condition('status', 1);
    }
    return $query;
  }

}
