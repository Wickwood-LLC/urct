<?php

namespace Drupal\urct\Routing;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Routing\RouteProvider;

/**
 * A Route Provider front-end for all Drupal-stored routes.
 */
class UrctRouteProvider extends RouteProvider {

  /**
   * Same as RouteProvider::getRouteCollectionForRequest() but we don't do cache here
   */
  public function getRouteCollectionForRequest(Request $request) {
    // Just trim on the right side.
    $path = $request->getPathInfo();
    $path = $path === '/' ? $path : rtrim($request->getPathInfo(), '/');
    $path = $this->pathProcessor->processInbound($path, $request);
    $this->currentPath->setPath($path, $request);
    // Incoming path processors may also set query parameters.
    $query_parameters = $request->query->all();
    $routes = $this->getRoutesByPath(rtrim($path, '/'));
    return $routes;
  }

  /**
   * Same as RouteProvider::preLoadRoutes() but we don't do cache here
   */
  public function preLoadRoutes($names) {
    if (empty($names)) {
      throw new \InvalidArgumentException('You must specify the route names to load');
    }

    $routes_to_load = array_diff($names, array_keys($this->routes), array_keys($this->serializedRoutes));
    if ($routes_to_load) {

      try {
        $result = $this->connection->query('SELECT name, route FROM {' . $this->connection->escapeTable($this->tableName) . '} WHERE name IN ( :names[] )', [':names[]' => $routes_to_load]);
        $routes = $result->fetchAllKeyed();
      }
      catch (\Exception $e) {
        $routes = [];
      }

      $this->serializedRoutes += $routes;
    }
  }

}
