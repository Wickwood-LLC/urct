<?php

namespace Drupal\urct;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\user\Entity\User;
use Drupal\user_referral\Entity\UserReferralType;
use Drupal\views\Views;
use Drupal\user_referral\UserReferral;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Drupal\user_referral\Event\UserReferralCookieEvent;

class ReferralManager implements InboundPathProcessorInterface, OutboundPathProcessorInterface, EventSubscriberInterface {

  /**
   * Referrer user object.
   *
   * @var \Drupal\user\Entity\User;
   */
  protected $referrer;

  protected $referrerInPath;

  /**
   * Config Factory Service Object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * Constructs a BookManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $killSwitch
   *   The kill switch.
   */
  public function __construct(ConfigFactoryInterface $config_factory, KillSwitch $killSwitch) {
    $this->configFactory = $config_factory;
    $this->killSwitch = $killSwitch;
  }


  public function getCurrentReferrer() {
    if (!isset($this->referrer)) {
      $config = $this->configFactory->getEditable('urct.settings');
      if ($config->get('debug')) {
        $this->killSwitch->trigger();
      }
      $uid = NULL;
      if (isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
        // Retrieve referral info from the cookie
        $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);
        if (!empty($cookie) && isset($cookie->uid)) {
          $uid = $cookie->uid;
        }
      }
      if (empty($uid)) {
        $bot_agents = $config->get('bot_agents');
        $list = explode("\n", $bot_agents);
        $list = array_map('trim', $list);
        $list = array_map('strtolower', $list);
        $list = array_filter($list, 'strlen');

        $crawler = FALSE;
        $request = \Drupal::request();
        $userAgent = $request->headers->get('User-Agent');
        foreach ($list as $position => $crawler_name_part) {
          // Check for an explicit key.
          $matches = [];
          if (preg_match('/' . preg_quote($crawler_name_part, '/') . '/i', $userAgent, $matches)) {
            $crawler = TRUE;
            break;
          }
        }
        if ($crawler) {
          $default_fallback_referrer_id = $config->get('default_fallback_referrer');
          if (!empty($default_fallback_referrer_id)) {
            $uid = $default_fallback_referrer_id;
          }
        }
      }
      if (empty($uid)) {
        // No referrer found from cookie. Fallback configured type.

        $fallback_type = $config->get('fallback_type');
        if (!empty($fallback_type)) {
          $last_selected_uid = $config->get('last_selected_uid');
          $last_selected_uid = $last_selected_uid ?? 0;

          if ($fallback_type == 'roles') {
            $roles_condition = $config->get('roles_condition');
            if ($roles_condition == 'and') {
              $uid = $this->getUserHavingAllRoles($last_selected_uid);
            }
            else {
              $uid = $this->getUserHavingAnyRoles($last_selected_uid);
            }
          }
          else if ($fallback_type == 'view') {
            $uid = $this->getUserFromView($last_selected_uid);
          }
        }
        if (!empty($uid) && $config->get('roll_up') == 'enroller') {
          $referrer_account = User::load($uid);
          do {
            if ($referrer_account && $referrer_account->isActive()) {
              $uid = $referrer_account->id();
              break;
            }
            else if ($referrer_account) {
              // Referrer account exists but not active.
              // Then find enroller of this account.
              $referrer_account = UserReferral::getReferrer($referrer_account);
            }
            else {
              break;
            }
          } while($referrer_account);
        }
        if (empty($uid)) {
          $default_fallback_referrer_id = $config->get('default_fallback_referrer');
          if (!empty($default_fallback_referrer_id)) {
            $uid = $default_fallback_referrer_id;
          }
        }
      }
      $this->referrer = User::load($uid);
    }
    return $this->referrer;
  }


  protected function getUserHavingAnyRoles($last_selected_uid) {
    static $times = 0;
    $selected_uid = NULL;
    $config = $this->configFactory->getEditable('urct.settings');
    $roles = array_values(array_filter($config->get('roles')));

    $query = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('uid', $last_selected_uid, '>');
      $query->condition('roles', $roles, 'IN');
    $ids = $query->range(0, 1)->execute();
    if (empty($ids) && $times == 0) {
      $times++;
      $selected_uid = $this->getUserHavingAnyRoles(0);
    }
    else {
      $selected_uid = reset($ids);
      $config->set('last_selected_uid', $selected_uid);
      $config->save();
    }
    return $selected_uid;
  }

  protected function getUserHavingAllRoles($last_selected_uid) {
    static $times = 0;
    $selected_uid = NULL;
    $config = $this->configFactory->getEditable('urct.settings');
    $roles = array_values(array_filter($config->get('roles')));

    $database = \Drupal::database();
    $query = $database->select('users_field_data', 'u');
    foreach ($roles as $index => $role) {
      $alias = 'ur_' . $index;
      $query->join('user__roles', $alias, "u.uid = $alias.entity_id AND $alias.deleted = 0 AND $alias.roles_target_id = '$role'");
    }

    $query->fields('u', array('uid'));
    $query->condition('uid', $last_selected_uid, '>');

    $id = $query->range(0, 1)->execute()->fetchField();
    if (empty($id) && $times == 0) {
      $times++;
      $selected_uid = $this->getUserHavingAllRoles(0);
    }
    else {
      $selected_uid = $id;
      $config->set('last_selected_uid', $selected_uid);
      $config->save();
    }
    return $selected_uid;
  }

  protected function getUserFromView($last_selected_uid) {
    static $times = 0;
    $selected_uid = NULL;
    $config = $this->configFactory->getEditable('urct.settings');
    $view_name = 'urct_referral_fallbacks';

    $view = Views::getView($view_name);

    // Set which view display we want.
    $view->setDisplay('default');
    // To initialize the query.
    $view->build();
    // Get underlaying SQL select query.
    // We will execute the select query directly without executing the whole view.
    // Executing the whole view will cause to load the user objects will increase the memory usage, which we want never to happen here.
    $query = $view->getQuery()->query();
    $fields = &$query->getFields();
    // Ensure uid is always as first column, so we can take it easily from the result.
    unset($fields['uid']);
    $fields = [
      'uid' => [
        'field' => 'uid',
        'table' => 'users_field_data',
        'alias' => 'uid',
      ],
    ] + $fields;

    $results = $query->execute()->fetchCol();

    if (!empty($results)) {
      if (!empty($last_selected_uid)) {
        $index_of_last_selected_uid = array_search($last_selected_uid, $results);
        if ($index_of_last_selected_uid === FALSE || $index_of_last_selected_uid == (count($results) - 1)) {
          // Could not find or last selected item is las position in result.
          // Select the first item from the result.
          $selected_uid = reset($results);
        }
        else {
          $selected_uid = $results[$index_of_last_selected_uid + 1];
        }
      }
      else {
        // No information about last selected uid.
        // Start with first item in the result.
        $selected_uid = reset($results);
      }
    }
    else {
      $selected_uid = NULL;
    }

    $config->set('last_selected_uid', $selected_uid);
    $config->save();

    return $selected_uid;
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (preg_match('~refid(\d+)$~', $path, $matches)) {
      $parts = array_filter(explode('/', $path));
      array_pop($parts);
      $path = '/' . implode('/', $parts);
      $request->attributes->add(['_disable_route_normalizer' => TRUE]);
      $this->referrerInPath = User::load($matches[1]);
      if (!$this->referrer) {
        $this->referrer = $this->referrerInPath;
      }
    }
    // if (!$this->referrer && $request->query->has('refid')) {
    //   $this->referrer = User::load($request->query->get('refid'));
    // }
    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    if ($this->referrerInPath && ( empty($options['route']) || !\Drupal::service('router.admin_context')->isAdminRoute($options['route']) ) ) {
      // $options['query']['refid'] = $this->referrer->id();
      $path .= '/refid' . $this->referrerInPath->id();
      $bubbleable_metadata = $bubbleable_metadata ?: new BubbleableMetadata();
      $bubbleable_metadata->addCacheContexts(['user_referral']);
      // $bubbleable_metadata->addCacheableDependency($this->referrer);
    }
    return $path;
  }

  public function onKernelResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if ($this->referrer && $response instanceof RedirectResponse) {
      $target_url = $response->getTargetUrl();
      $response->setTargetUrl($target_url . '/refid' . $this->referrer->id());
    }
  }

  public function onReferralCookieBeingSet(UserReferralCookieEvent $event) {
    // Get referrer from cookie being set.
    $cookie = $event->getCookie();
    $this->referrer = User::load($cookie->uid);
  }

  /**
   * {@inheritdoc}
   */
  static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onKernelResponse'];
    $events[UserReferralCookieEvent::COOKIE_SET][] = ['onReferralCookieBeingSet'];
    return $events;
  }

}