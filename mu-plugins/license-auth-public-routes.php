<?php
/**
 * Plugin Name: License Auth Public REST Routes
 * Description: Allows anonymous access to explicitly public license-auth API endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('rest_authentication_errors', static function ($result) {
    if (
        !is_wp_error($result)
        || $result->get_error_code() !== 'rest_not_logged_in'
    ) {
        return $result;
    }

    // Определяем маршрут только для запросов к REST API.
    $route = '';

    if (isset($_GET['rest_route'])) {
        $route = (string) wp_unslash($_GET['rest_route']);
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $request_uri = (string) $_SERVER['REQUEST_URI'];
        $path = wp_parse_url($request_uri, PHP_URL_PATH);

        if (is_string($path) && strpos($path, '/wp-json/') === 0) {
            $route = substr($path, strlen('/wp-json'));
        } elseif ($path === '/wp-json') {
            $route = '/';
        }
    }

    $route = '/' . trim($route, '/');

    $public_routes = [
        '/license-auth/v1/token',
        '/license-auth/v1/refresh',
        '/license-auth/v1/revoke',
        '/license-auth/v1/jwks',
        '/license-auth/v1/openid-configuration',
        '/license-auth/v1/service/jwks',
        '/license-auth/v1/service/openid-configuration',
    ];

    if (in_array($route, $public_routes, true)) {
        return null;
    }

    return $result;
}, PHP_INT_MAX);
