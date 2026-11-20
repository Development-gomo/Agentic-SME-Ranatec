<?php
/**
 * Runs the daily sync (Ranatec_Agent_Sync::run) against a copy of the plugin, with WooCommerce's real
 * WC_DateTime class and products dated the way WooCommerce dates them (stored as UTC, then given the shop's
 * time zone or UTC offset). Prints one "PASS|FAIL <check>" line per check.
 * Usage: php tests/sync-test.php <copy-of-ranatec-api-dir>
 */
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/vendor/woocommerce/class-wc-datetime.php';
$plugin = rtrim($argv[1], '/');

$GLOBALS['opts'] = [];
function add_option($k, $v = '', $d = '', $a = 'yes') { if (getenv('WP_OPTION_' . $k) !== false || isset($GLOBALS['opts'][$k])) return false; $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function get_posts($args) { $GLOBALS['get_posts_args'] = $args; return []; }
function wp_get_post_terms($id, $tax, $a = []) { return ['tunable-band-reject-filter']; }
function get_permalink($id) { return 'https://ranatec.com/product/' . $GLOBALS['slugs'][$id] . '/'; }
function wp_get_attachment_image_url($id, $size) { return false; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }

class Fake_Product {
    public function __construct(public int $id, public string $slug, public string $password, public WC_DateTime $created, public WC_DateTime $modified) { $GLOBALS['slugs'][$id] = $slug; }
    public function get_id() { return $this->id; }
    public function get_slug() { return $this->slug; }
    public function get_post_password() { return $this->password; }
    public function get_name() { return 'Product ' . $this->slug; }
    public function get_short_description() { return 'Short description.'; }
    public function get_description() { return '<p>Description.</p>'; }
    public function get_image_id() { return 0; }
    public function get_date_created() { return $this->created; }
    public function get_date_modified() { return $this->modified; }
}
// WC_Data::set_date_prop(): a UTC timestamp, then the shop's time zone (timezone_string) or a bare UTC offset.
function wc_date($ts, $mode) {
    $d = new WC_DateTime("@{$ts}", new DateTimeZone('UTC'));
    if ($mode === 'timezone') $d->setTimezone(new DateTimeZone('Europe/Stockholm')); else $d->set_utc_offset(7200);
    return $d;
}
$created = gmmktime(9, 15, 0, 3, 2, 2026);     // 2026-03-02T09:15:00Z
$modified = gmmktime(5, 34, 54, 9, 11, 2026);  // 2026-09-11T05:34:54Z (07:34:54 in Stockholm)
function wc_get_products($args) { return $GLOBALS['products']; }

require $plugin . '/ranatec-api.php';
$ok = function ($name, $cond, $info = '') { echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($info !== '' ? " ($info)" : '') . "\n"; };

foreach (['timezone', 'offset'] as $mode) {
    $GLOBALS['products'] = [
        new Fake_Product(1, 'sync-test-new-product', '', wc_date($created, $mode), wc_date($modified, $mode)),
        new Fake_Product(2, 'sync-test-protected-product', 'secret', wc_date($created, $mode), wc_date($modified, $mode)),
    ];
    // The test is only meaningful if WooCommerce's own formatting really prints local time labelled +00:00.
    $ok("WC_DateTime::date('c') mislabels local time ($mode) — the bug the sync must avoid", wc_date($modified, $mode)->date('c') === '2026-09-11T07:34:54+00:00', wc_date($modified, $mode)->date('c'));
    $report = Ranatec_Agent_Sync::run('test');
    if ($report['errors'] !== ['news: No published posts found — refusing to write an empty news.json.']) echo 'INFO sync report: ' . json_encode($report['errors']) . "\n";
    $data = json_decode(file_get_contents($plugin . '/data/products.json'), true);
    $p = array_values(array_filter($data['products'], fn($x) => $x['id'] === 'sync-test-new-product'))[0] ?? [];
    $ok("sync writes real UTC from WooCommerce dates ($mode)", ($p['date_modified'] ?? '') === '2026-09-11T05:34:54+00:00' && ($p['date_published'] ?? '') === '2026-03-02T09:15:00+00:00', ($p['date_published'] ?? '-') . ' / ' . ($p['date_modified'] ?? '-'));
    $ok("sync leaves out password-protected products ($mode)", !in_array('sync-test-protected-product', array_column($data['products'], 'id'), true));
}
$ok('news sync asks WordPress for posts without a password', ($GLOBALS['get_posts_args']['has_password'] ?? null) === false);
$ok('sync releases its lock when done', !isset($GLOBALS['opts']['ranatec_api_sync_lock']));
$ok('no temporary files left in data/', !glob($plugin . '/data/*.tmp'));
