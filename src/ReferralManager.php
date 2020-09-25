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
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Drupal\Core\Routing\LocalRedirectResponse;

class ReferralManager implements InboundPathProcessorInterface, OutboundPathProcessorInterface, EventSubscriberInterface {

  const COOKIE_NAME = 'urct_referral';
  /**
   * Referrer user id and referral type.
   *
   * @var \stdClass;
   */
  protected $referralItem;

  /**
   * @var \stdClass;
   */
  protected $referralItemInPath;

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
   * Indicates if current request is from a bot/crawler.
   */
  protected $crawler;

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
    $this->crawler = NULL;
  }


  public function getCurrentReferralItem() {
    if (!isset($this->referralItem)) {
      $config = $this->configFactory->getEditable('urct.settings');
      if ($config->get('debug')) {
        $this->killSwitch->trigger();
      }
      $referral_item = NULL;
      if (isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
        // Retrieve referral info from the cookie set by referral link
        $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);
        if (!empty($cookie) && isset($cookie->uid)) {
          $referral_item = new \stdClass();
          $referral_item->uid = $cookie->uid;
          $referral_item->type = $cookie->type;
        }
      }
      else if (isset($_COOKIE[self::COOKIE_NAME])) {
        // Retrieve referral info from the cookie set by referral path item
        $cookie = json_decode($_COOKIE[self::COOKIE_NAME]);
        if (!empty($cookie) && isset($cookie->uid)) {
          $referral_item = new \stdClass();
          $referral_item->uid = $cookie->uid;
          $referral_item->type = $cookie->type;
        }
      }
      if (empty($referral_item)) {
        if ($this->isBotAgent()) {
          $default_fallback_referrer_id = $config->get('default_fallback_referrer');
          if (!empty($default_fallback_referrer_id)) {
            $referral_item = new \stdClass();
            $referral_item->uid = $default_fallback_referrer_id;
            $referral_item->type = $config->get('default_fallback_referrer_referral_type');
          }
        }
      }
      if (empty($referral_item)) {
        // No referrer found from cookie. Fallback configured type.

        $fallback_type = $config->get('fallback_type');
        if (!empty($fallback_type)) {
          $last_selected = new \stdClass();
          $last_selected->uid = $config->get('last_selected_uid') ?? 0;
          $last_selected->type = $config->get('last_selected_referral_type') ?? NULL;

          // if ($fallback_type == 'roles') {
          //   $roles_condition = $config->get('roles_condition');
          //   if ($roles_condition == 'and') {
          //     $uid = $this->getUserHavingAllRoles($last_selected_uid);
          //   }
          //   else {
          //     $uid = $this->getUserHavingAnyRoles($last_selected_uid);
          //   }
          // }
          if ($fallback_type == 'referral_types') {
            $referral_item = $this->getUserFromReferralTypes($last_selected);
          }
          // else if ($fallback_type == 'view') {
          //   $uid = $this->getUserFromView($last_selected_uid);
          // }
        }
        if (!empty($referral_item->uid) && $config->get('roll_up') == 'enroller') {
          $referrer_account = User::load($next_item->uid);
          do {
            if ($referrer_account && $referrer_account->isActive()) {
              $referral_item->uid = $referrer_account->id();
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
        if (empty($referral_item->uid)) {
          $default_fallback_referrer_id = $config->get('default_fallback_referrer');
          if (!empty($default_fallback_referrer_id)) {
            $referral_item = new \stdClass();
            $referral_item->uid = $default_fallback_referrer_id;
            $referral_item->type = $config->get('default_fallback_referrer_referral_type');
          }
        }

        $this->setPathReferralCookie($referral_item);
      }
      $this->referralItem = $referral_item;
    }
    return $this->referralItem;
  }

  /**
   * Check if current request is from bot agent.
   *
   * @return boolean
   */
  public function isBotAgent() {
    if (!isset($this->crawler)) {
      $config = $this->configFactory->get('urct.settings');
      $bot_agents = $config->get('bot_agents');
      $list = explode("\n", $bot_agents);
      $list = array_map('trim', $list);
      $list = array_map('strtolower', $list);
      $list = array_filter($list, 'strlen');

      $this->crawler = FALSE;
      $request = \Drupal::request();
      $userAgent = $request->headers->get('User-Agent');
      foreach ($list as $position => $crawler_name_part) {
        // Check for an explicit key.
        $matches = [];
        if (preg_match('/' . preg_quote($crawler_name_part, '/') . '/i', $userAgent, $matches)) {
          $this->crawler = TRUE;
          break;
        }
      }
    }
    return $this->crawler;
  }


  // protected function getUserHavingAnyRoles($last_selected_uid) {
  //   static $times = 0;
  //   $selected_uid = NULL;
  //   $config = $this->configFactory->getEditable('urct.settings');
  //   $roles = array_values(array_filter($config->get('roles')));

  //   $query = \Drupal::entityQuery('user')
  //     ->condition('status', 1)
  //     ->condition('uid', $last_selected_uid, '>');
  //     $query->condition('roles', $roles, 'IN');
  //   $ids = $query->range(0, 1)->execute();
  //   if (empty($ids) && $times == 0) {
  //     $times++;
  //     $selected_uid = $this->getUserHavingAnyRoles(0);
  //   }
  //   else {
  //     $selected_uid = reset($ids);
  //     $config->set('last_selected_uid', $selected_uid);
  //     $config->save();
  //   }
  //   return $selected_uid;
  // }

  public function getUserFromReferralTypes($last_selected) {
    $config = $this->configFactory->getEditable('urct.settings');
    $referral_types = $config->get('referral_types');

    $query = \Drupal::entityQuery('node');
    $connection = \Drupal::service('database');
    $count = 0;
    $queries = [];
    foreach ($referral_types as $referral_type_id) {
      $referral_type = UserReferralType::load($referral_type_id);
      $roles = $referral_type->getRoles();
      $query = $connection->select('users', 'u');
      $query->join('user__roles', 'ur', "u.uid = ur.entity_id AND ur.deleted = 0 AND ur.bundle = 'user'");
      $query->condition('ur.roles_target_id', $roles, 'IN');
      $query->addField('u', 'uid');
      $query->addExpression("'$referral_type_id'", 'type');
      $queries[] = $query;
      $count++;
    }

    $main_query = array_shift($queries);
    while ($query = array_shift($queries)) {
      $main_query->union($query, 'UNION ALL');
    }
    $result = $main_query->execute()->fetchAll();

    $referral_types_filter_by_view = $config->get('referral_types_filter_by_view') ?? FALSE;

    if ($referral_types_filter_by_view) {

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

      $uids_to_filter_out = $query->execute()->fetchCol();
      $referral_types_filter_by_view_negate = $config->get('referral_types_filter_by_view_negate') ?? FALSE;

      $result = array_values(array_filter($result, function($item) use($uids_to_filter_out, $referral_types_filter_by_view_negate) {
        if (!$referral_types_filter_by_view_negate) {
          return in_array($item->uid, $uids_to_filter_out);
        }
        else {
          return !in_array($item->uid, $uids_to_filter_out);
        }
      }));
    }
    $position = -1;
    foreach ($result as $key => $item) {
      if ($item->uid == $last_selected->uid && $item->type == $last_selected->type) {
        $position = $key;
        break;
      }
    }
    if ($position > -1 && $position != (count($result) - 1)) {
      $next_item = $result[$position + 1];
    }
    else {
      $next_item = reset($result);
    }
    if (!empty($next_item->uid)) {
      $config->set('last_selected_uid', $next_item->uid);
      $config->set('last_selected_referral_type', $next_item->type);
      $config->save();
    }

    return $next_item;
  }

  // protected function getUserHavingAllRoles($last_selected_uid) {
  //   static $times = 0;
  //   $selected_uid = NULL;
  //   $config = $this->configFactory->getEditable('urct.settings');
  //   $roles = array_values(array_filter($config->get('roles')));

  //   $database = \Drupal::database();
  //   $query = $database->select('users_field_data', 'u');
  //   foreach ($roles as $index => $role) {
  //     $alias = 'ur_' . $index;
  //     $query->join('user__roles', $alias, "u.uid = $alias.entity_id AND $alias.deleted = 0 AND $alias.roles_target_id = '$role'");
  //   }

  //   $query->fields('u', array('uid'));
  //   $query->condition('uid', $last_selected_uid, '>');

  //   $id = $query->range(0, 1)->execute()->fetchField();
  //   if (empty($id) && $times == 0) {
  //     $times++;
  //     $selected_uid = $this->getUserHavingAllRoles(0);
  //   }
  //   else {
  //     $selected_uid = $id;
  //     $config->set('last_selected_uid', $selected_uid);
  //     $config->save();
  //   }
  //   return $selected_uid;
  // }

  // protected function getUserFromView($last_selected_uid) {
  //   static $times = 0;
  //   $selected_uid = NULL;
  //   $config = $this->configFactory->getEditable('urct.settings');
  //   $view_name = 'urct_referral_fallbacks';

  //   $view = Views::getView($view_name);

  //   // Set which view display we want.
  //   $view->setDisplay('default');
  //   // To initialize the query.
  //   $view->build();
  //   // Get underlaying SQL select query.
  //   // We will execute the select query directly without executing the whole view.
  //   // Executing the whole view will cause to load the user objects will increase the memory usage, which we want never to happen here.
  //   $query = $view->getQuery()->query();
  //   $fields = &$query->getFields();
  //   // Ensure uid is always as first column, so we can take it easily from the result.
  //   unset($fields['uid']);
  //   $fields = [
  //     'uid' => [
  //       'field' => 'uid',
  //       'table' => 'users_field_data',
  //       'alias' => 'uid',
  //     ],
  //   ] + $fields;

  //   $results = $query->execute()->fetchCol();

  //   if (!empty($results)) {
  //     if (!empty($last_selected_uid)) {
  //       $index_of_last_selected_uid = array_search($last_selected_uid, $results);
  //       if ($index_of_last_selected_uid === FALSE || $index_of_last_selected_uid == (count($results) - 1)) {
  //         // Could not find or last selected item is las position in result.
  //         // Select the first item from the result.
  //         $selected_uid = reset($results);
  //       }
  //       else {
  //         $selected_uid = $results[$index_of_last_selected_uid + 1];
  //       }
  //     }
  //     else {
  //       // No information about last selected uid.
  //       // Start with first item in the result.
  //       $selected_uid = reset($results);
  //     }
  //   }
  //   else {
  //     $selected_uid = NULL;
  //   }

  //   $config->set('last_selected_uid', $selected_uid);
  //   $config->save();

  //   return $selected_uid;
  // }

  public function checkPathReferral($path) {
    if (preg_match('~/refid(\d+)-(.+)$~', $path, $matches)) {
      $parts = array_filter(explode('/', $path));
      array_pop($parts);
      $path = '/' . implode('/', $parts);
      $referral_item = new \stdClass();
      $referral_item->uid = $matches[1];
      $referral_item->type = $matches[2];
      return $referral_item;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if ($referral_item = $this->checkPathReferral($path)) {
      $request->attributes->add(['_disable_route_normalizer' => TRUE]);
      $this->referralItemInPath = $referral_item;
      $this->setPathReferralCookie($this->referralItemInPath);
      if (!$this->referralItem) {
        $this->referralItem = $this->referralItemInPath;
      }

      $parts = array_filter(explode('/', $path));
      array_pop($parts);
      $path = '/' . implode('/', $parts);
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
    if ($this->referralItem && ( empty($options['route']) || !\Drupal::service('router.admin_context')->isAdminRoute($options['route']) ) ) {
      // $options['query']['refid'] = $this->referrer->id();
      $path .= '/refid' . $this->referralItem->uid . '-' . $this->referralItem->type;
      $bubbleable_metadata = $bubbleable_metadata ?: new BubbleableMetadata();
      $bubbleable_metadata->addCacheContexts(['user_referral']);
      // $bubbleable_metadata->addCacheableDependency($this->referrer);
    }
    return $path;
  }

  public function onKernelResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if ($response instanceof RedirectResponse) {
      $target_url = $response->getTargetUrl();
      if ($this->referralItem && !$this->checkPathReferral($target_url)) {
        $target_url = rtrim($target_url, '/') . '/refid' . $this->referralItem->uid . '-' . $this->referralItem->type;
        $response->setTargetUrl($target_url);
      }
    }
  }

  public function onReferralCookieBeingSet(UserReferralCookieEvent $event) {
    // Get referrer from cookie being set.
    $cookie = $event->getCookie();
    $this->referralItem = new \stdClass();
    $this->referralItem->uid = $cookie->uid;
    $this->referralItem->type = $cookie->type;
  }

  /**
   * Performs a redirect if the URL not end with Referral ID.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function onKernelRequestRedirect(GetResponseEvent $event) {
    $request = $event->getRequest();
    // Get the requested path minus the base path.
    $path = $request->getPathInfo();

    if (!$this->checkPathReferral($path)) {
      $referral_item = $this->getCurrentReferralItem();
      $path = rtrim($path, '/') . '/refid' . $referral_item->uid . '-' . $referral_item->type;
      $qs = $request->getQueryString();
      if ($qs) {
        $qs = '?' . $qs;
      }
      $response = new LocalRedirectResponse($request->getUriForPath($path) . $qs);
      $response->getCacheableMetadata()->setCacheMaxAge(0);
      $this->killSwitch->trigger();
      $event->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onKernelResponse'];
    $events[UserReferralCookieEvent::COOKIE_SET][] = ['onReferralCookieBeingSet'];
    $events[KernelEvents::REQUEST][] = ['onKernelRequestRedirect', 100];
    return $events;
  }

  public function setPathReferralCookie($referral_item) {
    $existing_cookie = isset($_COOKIE[self::COOKIE_NAME]) ? json_decode($_COOKIE[self::COOKIE_NAME]) : NULL;
    if (!$existing_cookie || $existing_cookie->uid != $referral_item->uid || $existing_cookie->type != $referral_item->type) {
      $cookie = new \stdClass();
      $cookie->uid = $referral_item->uid;
      $cookie->type = $referral_item->type;
      setcookie(self::COOKIE_NAME, json_encode($cookie), time() + 7 * 24 * 60 * 60, '/');
    }
  }

}