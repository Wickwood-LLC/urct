<?php

namespace Drupal\urct\PathProcessor;

use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\PathProcessor\PathProcessorManager;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;

use Drupal\urct\ReferralUrlHandler;

/**
 * Path processor manager for URCT module purpose.
 *
 * Copy of PathProcessorManager with minor modifications.
 */
class UrctPathProcessorManager extends PathProcessorManager {

  /**
   * Adds an inbound processor object to the $inboundProcessors property.
   *
   * @param \Drupal\Core\PathProcessor\InboundPathProcessorInterface $processor
   *   The processor object to add.
   * @param int $priority
   *   The priority of the processor being added.
   */
  public function addInbound(InboundPathProcessorInterface $processor, $priority = 0) {
    if ($processor instanceof ReferralUrlHandler) {
      // Skip ReferralUrlHandler to avoid the loop.
      return;
    }
    $this->inboundProcessors[$priority][] = $processor;
    $this->sortedInbound = [];
  }

}
