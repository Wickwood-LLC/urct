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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user_referral\Event\UserReferralReferralEntryEvent;

class ReferralManager implements OutboundPathProcessorInterface, EventSubscriberInterface {

  /**
   * Referrer user id and referral type.
   *
   * @var \stdClass;
   */
  protected $referralItem;

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Variable indicating if current page is admin page.
   *
   * @var boolean
   */
  protected $isAdminPage;

  /**
   * Constructs a BookManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $killSwitch
   *   The kill switch.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   */
  public function __construct(ConfigFactoryInterface $config_factory, KillSwitch $killSwitch, AccountProxyInterface $account) {
    $this->configFactory = $config_factory;
    $this->killSwitch = $killSwitch;
    $this->crawler = NULL;
    $this->currentUser = $account;
  }

  public function setCurrentReferralItem($referral_item) {
    $this->referralItem = $referral_item;
  }


  public function getCurrentReferralItem() {
    if (!isset($this->referralItem)) {
      $cookie_exists = FALSE;
      $referral_item = NULL;
      if ($this->currentUser->isAuthenticated()) {
        $referral_types = UserReferralType::loadMultiple();
        $referral_type = reset($referral_types);
        if ($referral_type) {
          $account = User::load($this->currentUser->id());
          $referral_id = $referral_type->getAccountReferralID($account);
          if ($referral_id) {
            $referral_item = new \stdClass();
            $referral_item->refid = $referral_id;
            $referral_item->uid = $this->currentUser->id();
            $referral_item->type = $referral_type->id();
          }
        }
      }
      else {
        $config = $this->configFactory->getEditable('urct.settings');
        if ($config->get('debug')) {
          $this->killSwitch->trigger();
        }
        if (isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
          // Retrieve referral info from the cookie set by referral link
          $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);
          if (!empty($cookie) && isset($cookie->uid)) {
            $referral_item = new \stdClass();
            $referral_item->uid = $cookie->uid;
            $referral_item->type = $cookie->type;
            $cookie_exists = TRUE;
          }
        }
        // else if (isset($_COOKIE[UserReferralType::COOKIE_NAME])) {
        //   // Retrieve referral info from the cookie set by referral path item
        //   $cookie = json_decode($_COOKIE[UserReferralType::COOKIE_NAME]);
        //   if (!empty($cookie) && isset($cookie->uid)) {
        //     $referral_item = new \stdClass();
        //     $referral_item->uid = $cookie->uid;
        //     $referral_item->type = $cookie->type;
        //     $cookie_exists = TRUE;
        //   }
        // }
        if (empty($referral_item)) {
          if ($this->isCrawler()) {
            $default_fallback_referrer = $config->get('default_fallback_referrer');
            if (!empty($default_fallback_referrer) && !empty($default_fallback_referrer['referrer'])) {
              $referral_item = new \stdClass();
              $referral_item->uid = $default_fallback_referrer['referrer'];
              $referral_item->type = $default_fallback_referrer['type'];
            }
          }
        }
        if (empty($referral_item)) {
          // No referrer found from cookie. Fallback configured type.

          $fallback_type = $config->get('fallback_type');
          if (!empty($fallback_type)) {
            $last_selected = new \stdClass();
            $last_selected->uid  = \Drupal::state()->get('urct.last_selected_uid') ?? 0;
            $last_selected->type  = \Drupal::state()->get('urct.last_selected_referral_type') ?? NULL;

            if ($fallback_type == 'referral_types') {
              $referral_item = $this->getUserFromReferralTypes($last_selected);
            }
          }
          if (!empty($referral_item->uid) && $config->get('roll_up') == 'enroller') {
            $referrer_account = User::load($referral_item->uid);
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
        }
      }

      if (empty($referral_item->uid)) {
        $default_fallback_referrer = $config->get('default_fallback_referrer');
        if (!empty($default_fallback_referrer) && !empty($default_fallback_referrer['referrer'])) {
          $referral_item = new \stdClass();
          $referral_item->uid = $default_fallback_referrer['referrer'];
          $referral_item->type = $default_fallback_referrer['type'];
        }
      }
      if ($referral_item) {
        $referral_type = UserReferralType::load($referral_item->type);
        $account = User::load($referral_item->uid);
        if ($referral_type && $account && empty($referral_item->refid)) {
          $referral_item->refid = $referral_type->getAccountReferralID($account);
        }

        if (!$cookie_exists && !$this->isCrawler()) {
          ReferralUrlHandler::setPathReferralCookie($referral_item);
        }
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
  public function isCrawler() {
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

  public function getUserFromReferralTypes($last_selected) {
    $config = $this->configFactory->getEditable('urct.settings');
    $referral_types = array_filter($config->get('referral_types'));

    // $query = \Drupal::entityQuery('node');
    $connection = \Drupal::service('database');
    $count = 0;
    $queries = [];
    foreach ($referral_types as $referral_type_id) {
      $referral_type = UserReferralType::load($referral_type_id);
      $referral_id_field_name = $referral_type->getReferralField();
      $roles = $referral_type->getRoles();
      $query = $connection->select('users', 'u');
      $query->join('user__roles', 'ur', "u.uid = ur.entity_id AND ur.deleted = 0 AND ur.bundle = 'user'");
      $query->join('user__' . $referral_id_field_name, 'rf', "u.uid = rf.entity_id AND rf.deleted = 0 AND rf.bundle = 'user' AND rf." . $referral_id_field_name . '_value IS NOT NULL');
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
      \Drupal::state()->set('urct.last_selected_uid', $next_item->uid);
      \Drupal::state()->set('urct.last_selected_referral_type', $next_item->type);
      $config->save();
    }

    return $next_item;
  }

  public function appendPathReferralToPath($path, $referral_item) {
    if (isset($referral_item->refid_only) && $referral_item->refid_only) {
      $new_path = rtrim($path, '/') . '/' . $referral_item->refid;
    }
    else {
      $new_path = rtrim($path, '/') . '/' . $referral_item->refid . '/' . $referral_item->type;
    }
    if (\Drupal::service('urct.path_validator')->getUrlIfValidWithoutAccessCheck($new_path)) {
      $prefix = 'direct/';
      if (isset($referral_item->refid_only) && $referral_item->refid_only) {
        $new_path = rtrim($path, '/') . '/' . $prefix . $referral_item->refid;
      }
      else {
        $new_path = rtrim($path, '/') . '/' . $prefix . $referral_item->refid . '/' . $referral_item->type;
      }
    }
    return $new_path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    if (\Drupal::currentUser()->isAnonymous() && $this->referralItem && ( empty($options['route']) || !\Drupal::service('router.admin_context')->isAdminRoute($options['route']) ) && !$this->isCrawler()) {
      $path = $this->appendPathReferralToPath($path, $this->referralItem);
      $bubbleable_metadata = $bubbleable_metadata ?: new BubbleableMetadata();
      $bubbleable_metadata->addCacheContexts(['user_referral']);
    }
    return $path;
  }

  public function onKernelResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if (\Drupal::currentUser()->isAnonymous() && $response instanceof RedirectResponse && !\Drupal::service('router.admin_context')->isAdminRoute()) {
      $target_url = $response->getTargetUrl();
      if ($this->referralItem && !ReferralUrlHandler::getReferralFromPath($target_url )) {
        $target_url = $this->appendPathReferralToPath($target_url, $this->referralItem);
        $response->setTargetUrl($target_url);
      }
    }
  }

  public function onReferralCookieBeingSet(UserReferralCookieEvent $event) {
    // Get referrer from cookie being set.
    $cookie = $event->getCookie();
    $account = User::load($cookie->uid);
    $referral_type = UserReferralType::load($cookie->type);
    if ($account && $referral_type) {
      $referral_id = $referral_type->getAccountReferralID($account);
      if ($referral_id) {
        $this->referralItem = new \stdClass();
        $this->referralItem->refid = $referral_id;
        $this->referralItem->type = $cookie->type;
      }
    }
    if (ReferralUrlHandler::$setting_path_cookie) {
      // Setting path cookie is with auto-assinged referrer.
      $cookie->auto = 1;
      $event->setCookie($cookie);
    }
  }

  /**
   *
   * @param \Drupal\user_referral\Event\UserReferralReferralEntryEvent $event
   *  The Event to process.
   */
  public function onReferralEntryBeingCreated(UserReferralReferralEntryEvent $event) {
    $cookie = $event->getCookie();
    if (!empty($cookie->auto)) {
      $referral_entry = $event->getEntry();
      $referral_entry['auto_referrer'] = 1;
      $event->setEntry($referral_entry);
    }
  }

  public function onReferralCookieSettingLogic(UserReferralCookieEvent $event) {
    $existing_cookie = isset($_COOKIE[UserReferralType::COOKIE_NAME]) ? json_decode($_COOKIE[UserReferralType::COOKIE_NAME]) : NULL;
    if (!ReferralUrlHandler::$setting_path_cookie && $existing_cookie && empty($existing_cookie->auto)) {
      // Setting not path cookie, but a buth cookie already exists.
      // So, unset it as referral link cookie has priority.
      unset($_COOKIE[UserReferralType::COOKIE_NAME]);
    }
  }

  /**
   * Performs a redirect if the URL not end with Referral ID.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function onKernelRequestRedirect(GetResponseEvent $event) {
    if (\Drupal::currentUser()->isAuthenticated()) {
      // Skip for logged in users.
      return;
    }
    $request = $event->getRequest();
    // Get the requested path minus the base path.
    $path = $request->getPathInfo();

    $url_handler = \Drupal::service('urct.referral_url_handler');
    if (!ReferralUrlHandler::getReferralFromPath($path) && !$this->isCrawler() && $referral_item = $this->getCurrentReferralItem()) {
      $path = $this->appendPathReferralToPath($path, $referral_item);
      $qs = $request->getQueryString();
      if ($qs) {
        $qs = '?' . $qs;
      }
      ReferralUrlHandler::setPathReferralCookie($referral_item);
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
    $events[KernelEvents::REQUEST][] = ['onKernelRequestRedirect', 30];
    $events[UserReferralReferralEntryEvent::ENTRY_CREATE][] = ['onReferralEntryBeingCreated'];
    $events[UserReferralCookieEvent::COOKIE_PRE_ASSIGN_LOGIC][] = ['onReferralCookieSettingLogic'];
    return $events;
  }

}