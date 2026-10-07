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
function get_option($k, $d = false) { $e = getenv('WP_OPTION_' . $k); return $e !== false ? $e : $d; }
function current_time($t) { return gmdate('Y-m-d H:i:s'); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
/** Fake $wpdb: Advanced CF7 DB tables "exist" when TEST_ACF7DB=1; inserts are appended to $TMPDIR/ranatec-acf7db.jsonl */
class Fake_WPDB {
    public $prefix = 'wp_'; public $insert_id = 0;
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($q, ...$a) { return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $q), $a); }
    public function get_var($q) {
        if (!getenv('TEST_ACF7DB')) return null;
        return preg_match("/LIKE '(.+)'/", $q, $m) ? stripslashes($m[1]) : null;
    }
    public function insert($table, $data, $format = null) {
        static $n = 100;
        if ($table === 'wp_cf7_vdata') $this->insert_id = ++$n;
        file_put_contents(sys_get_temp_dir() . '/ranatec-acf7db.jsonl', json_encode(['table' => $table, 'data' => $data]) . "\n", FILE_APPEND);
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
function wp_mail($to, $s, $m, $h) { file_put_contents(sys_get_temp_dir() . '/ranatec-mail.txt', "TO: $to\nSUBJECT: $s\n" . implode("\n", $h) . "\n\n$m"); return getenv('MAIL_FAIL') ? false : true; }

/**
 * Minimal WooCommerce stand-in for the quote flow (disabled with TEST_WC=0). Orders are appended to
 * $TMPDIR/ranatec-wc-orders.jsonl. Countries/locale mirror WooCommerce for the countries used in tests.
 */
if (getenv('TEST_WC') !== '0') {
    if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
    class Fake_WC_Countries {
        public function get_allowed_countries() { return ['SE' => 'Sweden', 'DE' => 'Germany', 'US' => 'United States (US)', 'GB' => 'United Kingdom (UK)', 'CA' => 'Canada', 'AE' => 'United Arab Emirates']; }
        public function get_states($cc) { $s = ['US' => ['CA' => 'California', 'NY' => 'New York', 'TX' => 'Texas'], 'CA' => ['ON' => 'Ontario', 'QC' => 'Quebec'], 'SE' => [], 'DE' => ['BY' => 'Bavaria (Bayern)', 'BE' => 'Berlin'], 'GB' => [], 'AE' => []]; return $s[$cc] ?? false; }
        public function get_country_locale() { return ['SE' => ['state' => ['required' => false, 'hidden' => true]], 'DE' => ['state' => ['required' => false]], 'GB' => ['state' => ['required' => false]], 'AE' => ['postcode' => ['required' => false, 'hidden' => true], 'state' => ['required' => false]]]; }
    }
    class Fake_WC_Gateway { public function get_title() { return 'YITH Request a Quote'; } }
    class Fake_WC_Gateways { public function payment_gateways() { return ['yith-request-a-quote' => new Fake_WC_Gateway()]; } }
    class Fake_WC { public $countries; public function __construct() { $this->countries = new Fake_WC_Countries(); } public function payment_gateways() { return new Fake_WC_Gateways(); } }
    function WC() { static $wc; return $wc ?: ($wc = new Fake_WC()); }
    class Fake_WC_Product { public $slug; public function __construct($s) { $this->slug = $s; } public function get_name() { return 'Product ' . $this->slug; } }
    function get_page_by_path($slug, $o = null, $t = null) { return in_array($slug, explode(',', (string) getenv('TEST_WC_MISSING')), true) ? null : (object) ['ID' => $slug]; }
    function wc_get_product($id) { return new Fake_WC_Product($id); }
    function wc_get_order_statuses() { return ['wc-pending' => 'Pending payment', 'wc-ywraq-new' => 'New Quote Request']; }
    function is_wp_error($x) { return false; }
    function wc_add_order_item_meta($id, $k, $v) { $GLOBALS['fake_order']->d['item_meta'][$id][$k] = $v; }
    class Fake_WC_Order {
        public $d = ['items' => [], 'meta' => [], 'notes' => []];
        public function __construct($args) { $this->d['args'] = $args; }
        public function __call($m, $a) { if (preg_match('/^set_(.+)$/', $m, $x)) { $this->d[$x[1]] = $a[0]; } }
        public function add_product($p, $q) { $this->d['items'][] = [$p->slug, $q]; return count($this->d['items']); }
        public function update_meta_data($k, $v) { $this->d['meta'][$k] = $v; }
        public function add_order_note($n) { $this->d['notes'][] = $n; }
        public function calculate_totals($t) {}
        public function get_id() { return 4242; }
        public function get_order_number() { return '4242'; }
        public function get_status() { return $this->d['args']['status']; }
        public function save() { $this->d['saved'] = true; }
        public function __destruct() { if (!empty($this->d['saved'])) file_put_contents(sys_get_temp_dir() . '/ranatec-wc-orders.jsonl', json_encode($this->d) . "\n", FILE_APPEND); }
    }
    function wc_create_order($args) { return $GLOBALS['fake_order'] = new Fake_WC_Order($args); }
}
