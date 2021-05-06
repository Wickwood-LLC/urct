<?php

namespace Drupal\urct;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\system\PathBasedBreadcrumbBuilder;

/**
 * Class to define the menu_link breadcrumb builder for referral paths.
 */
class UrctPathBasedBreadcrumbBuilder extends PathBasedBreadcrumbBuilder {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    return \Drupal::currentUser()->isAnonymous() && ReferralUrlHandler::getReferralFromPath($this->context->getPathInfo());
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $referral_item = ReferralUrlHandler::getReferralFromPath($this->context->getPathInfo());

    $this->context->setPathInfo($referral_item->normal_path);

    return parent::build($route_match);
  }

}
