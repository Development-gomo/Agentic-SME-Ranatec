<?php
/**
 * CLI harness: php tests/wp-stub-harness.php <endpoint> [id] [METHOD] [querystring] < body.json
 * Prints the response body followed by "STATUS <code>". Mail is captured to $TMPDIR/ranatec-mail.txt.
 */
require __DIR__ . '/wp-stubs.php';
$GLOBALS['qv'] = ['ranatec_endpoint' => $argv[1] ?? '', 'ranatec_id' => $argv[2] ?? ''];
$_SERVER['REQUEST_METHOD'] = $argv[3] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = getenv('TEST_IP') ?: '203.0.113.7';
if (getenv('HTTP_X_RANATEC_MCP_KEY') !== false) { $_SERVER['HTTP_X_RANATEC_MCP_KEY'] = getenv('HTTP_X_RANATEC_MCP_KEY'); }
parse_str($argv[4] ?? '', $_GET);
define('RANATEC_TEST_INPUT', stream_get_contents(STDIN));
register_shutdown_function(function () { echo "\nSTATUS " . $GLOBALS['status'] . "\n"; });
require __DIR__ . '/../ranatec-api/ranatec-api.php';
Ranatec_Agent_API::route();
