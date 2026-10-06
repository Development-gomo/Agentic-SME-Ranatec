<?php
/**
 * Router for PHP's built-in server that mimics the plugin's WordPress rewrite rules:
 *   php -S 127.0.0.1:8088 tests/php-router.php
 */
require __DIR__ . '/wp-stubs.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rules = [
    '#^/agent/v1/(products|categories|news)/([a-z0-9-]+)\.json$#' => fn($m) => ['ranatec_endpoint' => $m[1], 'ranatec_id' => $m[2]],
    '#^/agent/v1/([a-z-]+)\.json$#' => fn($m) => ['ranatec_endpoint' => $m[1]],
    '#^/agent/?$#' => fn($m) => ['ranatec_endpoint' => 'agent-page'],
    '#^/openapi\.json$#' => fn($m) => ['ranatec_endpoint' => 'openapi'],
    '#^/(llms\.txt|llms-full\.txt|ai\.txt|api-catalog\.json)$#' => fn($m) => ['ranatec_endpoint' => $m[1]],
    '#^/\.well-known/api-catalog/?$#' => fn($m) => ['ranatec_endpoint' => 'well-known-api-catalog'],
];
$GLOBALS['qv'] = [];
foreach ($rules as $re => $fn) {
    if (preg_match($re, $path, $m)) { $GLOBALS['qv'] = $fn($m); break; }
}
if (!$GLOBALS['qv']) { http_response_code(404); echo 'not routed'; return; }
require __DIR__ . '/../ranatec-api/ranatec-api.php';
Ranatec_Agent_API::route();
