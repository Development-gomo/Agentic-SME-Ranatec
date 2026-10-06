<?php
/** Minimal WordPress function stubs for exercising the plugin outside WordPress (tests only). */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['status'] = 200;
function plugin_dir_path($f) { return dirname($f) . '/'; }
function add_action(...$a) {} function add_filter(...$a) {} function add_rewrite_rule(...$a) {}
function register_activation_hook(...$a) {} function register_deactivation_hook(...$a) {}
function get_query_var($k) { return $GLOBALS['qv'][$k] ?? ''; }
function status_header($c) { $GLOBALS['status'] = $c; if (PHP_SAPI !== 'cli') http_response_code($c); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return $s; }
function sanitize_email($e) { return filter_var(trim((string) $e), FILTER_SANITIZE_EMAIL); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function esc_url_raw($u) { return filter_var($u, FILTER_VALIDATE_URL) ? $u : ''; }
function esc_url($u) { return $u; }
function sanitize_title($s) { return strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $s)); }
function get_transient($k) { $f = sys_get_temp_dir() . "/rl-$k"; return is_file($f) ? (int) file_get_contents($f) : false; }
function set_transient($k, $v, $t) { file_put_contents(sys_get_temp_dir() . "/rl-$k", $v); }
function apply_filters($h, $v) { return $v; }
function do_action(...$a) {}
function get_option($k, $d = false) { return $d; }
function wp_mail($to, $s, $m, $h) { file_put_contents(sys_get_temp_dir() . '/ranatec-mail.txt', "TO: $to\nSUBJECT: $s\n" . implode("\n", $h) . "\n\n$m"); return getenv('MAIL_FAIL') ? false : true; }
