<?php
/**
 * Runs the daily sync (Ranatec_Agent_Sync) against a COPY of the plugin (it rewrites data/ and, in the last checks,
 * public/), with WooCommerce's real WC_DateTime class and products dated the way WooCommerce dates them (stored
 * as UTC, then given the shop's time zone or UTC offset). Prints one "PASS|FAIL <check>" line per check.
 * Usage: php tests/sync-test.php <copy-of-ranatec-api-dir>
 */
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/vendor/woocommerce/class-wc-datetime.php';
$plugin = rtrim($argv[1], '/');

// get_posts: the news query (has_password => false) returns no public posts; the "any protected posts?" query
// returns $GLOBALS['protected_posts'].
function get_posts($args) {
    if (($args['has_password'] ?? null) === true) return $GLOBALS['protected_posts'] ?? [];
    $GLOBALS['get_posts_args'] = $args;
    return [];
}
function wp_get_post_terms($id, $tax, $a = []) { if (isset($GLOBALS['during_terms'])) ($GLOBALS['during_terms'])(); return ['tunable-band-reject-filter']; }
function get_permalink($id) { return 'https://ranatec.com/product/' . $GLOBALS['slugs'][$id] . '/'; }
function wp_get_attachment_image_url($id, $size) { return false; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wc_get_products($args) { if (isset($GLOBALS['during_wc_get_products'])) ($GLOBALS['during_wc_get_products'])(); return $GLOBALS['products']; }
function is_admin() { return !empty($GLOBALS['is_admin']); }
function wp_doing_ajax() { return false; }
function wp_schedule_single_event($ts, $hook, $args = []) { $GLOBALS['scheduled'][] = [$hook, $args]; return true; }

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
$product = fn($id, $slug, $pw, $mode = 'timezone') => new Fake_Product($id, $slug, $pw, wc_date($GLOBALS['created'], $mode), wc_date($GLOBALS['modified'], $mode));

require $plugin . '/ranatec-api.php';
$ok = function ($name, $cond, $info = '') { echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($info !== '' ? " ($info)" : '') . "\n"; };
$read = fn($f) => json_decode(file_get_contents("$plugin/data/$f.json"), true);
$released = $read('products');   // the products the static files (llms.txt, llms-full.txt, /agent/) were built from
const LOCK = 'ranatec_api_sync_lock';

// ---------------------------------------------------------------- UTC dates, protected products, lock release
foreach (['timezone', 'offset'] as $mode) {
    $GLOBALS['products'] = [$product(1, 'sync-test-new-product', '', $mode), $product(2, 'sync-test-protected-product', 'secret', $mode)];
    $GLOBALS['protected_posts'] = [];
    // The test is only meaningful if WooCommerce's own formatting really prints local time labelled +00:00.
    $ok("WC_DateTime::date('c') mislabels local time ($mode) — the bug the sync must avoid", wc_date($modified, $mode)->date('c') === '2026-09-11T07:34:54+00:00', wc_date($modified, $mode)->date('c'));
    $report = Ranatec_Agent_Sync::run('test');
    $data = $read('products');
    $p = array_values(array_filter($data['products'], fn($x) => $x['id'] === 'sync-test-new-product'))[0] ?? [];
    $ok("sync writes real UTC from WooCommerce dates ($mode)", ($p['date_modified'] ?? '') === '2026-09-11T05:34:54+00:00' && ($p['date_published'] ?? '') === '2026-03-02T09:15:00+00:00', ($p['date_published'] ?? '-') . ' / ' . ($p['date_modified'] ?? '-'));
    $ok("sync leaves out password-protected products ($mode)", !in_array('sync-test-protected-product', array_column($data['products'], 'id'), true));
}
$ok('news sync asks WordPress for posts without a password', ($GLOBALS['get_posts_args']['has_password'] ?? null) === false);
$ok('sync releases its lock when done', !array_key_exists(LOCK, $GLOBALS['wp_options']));
$ok('no temporary files left in data/', !glob($plugin . '/data/*.tmp'));

// ---------------------------------------------------------------- lock: atomic take, takeover, owner-only release
$t1 = Ranatec_Agent_Sync::acquire_lock();
$t2 = Ranatec_Agent_Sync::acquire_lock();
$ok('lock: a second run cannot take a held lock', is_string($t1) && $t2 === null);
Ranatec_Agent_Sync::release_lock('someone-else');
$ok('lock: only the owner can release it', ($GLOBALS['wp_options'][LOCK] ?? null) === $t1);
$r = Ranatec_Agent_Sync::run('test');
$ok('lock: a sync started while it is held is skipped and leaves the lock alone', !empty($r['skipped']) && ($GLOBALS['wp_options'][LOCK] ?? null) === $t1);
Ranatec_Agent_Sync::release_lock($t1);
$ok('lock: the owner releases it', !array_key_exists(LOCK, $GLOBALS['wp_options']));
$GLOBALS['wp_options'][LOCK] = (time() - 700) . ':crashed-run';   // left by a run that died 11+ minutes ago
$a = Ranatec_Agent_Sync::acquire_lock();
$b = Ranatec_Agent_Sync::acquire_lock();
$ok('lock: an expired lock is taken over by exactly one run', is_string($a) && $b === null && $GLOBALS['wp_options'][LOCK] === $a);
Ranatec_Agent_Sync::release_lock('' . (time() - 700) . ':crashed-run');
$ok('lock: the crashed run cannot release the new owner\'s lock', ($GLOBALS['wp_options'][LOCK] ?? null) === $a);
Ranatec_Agent_Sync::release_lock($a);
$GLOBALS['wp_options'][LOCK] = (string) (time() - 700);         // legacy 1.3.4 lock value (a bare timestamp)
$c = Ranatec_Agent_Sync::acquire_lock();
$ok('lock: an expired 1.3.4-style lock is taken over too', is_string($c));
Ranatec_Agent_Sync::release_lock($c);

// ---------------------------------------------------------------- every item protected → empty list, not stale JSON
$GLOBALS['products'] = [$product(3, 'sync-test-protected-a', 'pw'), $product(4, 'sync-test-protected-b', 'pw')];
$GLOBALS['protected_posts'] = [101];
$r = Ranatec_Agent_Sync::run('test');
$p = $read('products'); $n = $read('news'); $c = $read('categories');
$ok('all products protected → products.json is written empty', $p['products'] === [] && $p['meta']['count'] === 0, json_encode($r['errors']));
$ok('all products protected → no category lists any product', array_sum(array_map(fn($x) => count($x['product_ids']) + count($x['additional_listing_ids']), $c['categories'])) === 0);
$ok('all posts protected → news.json is written empty', $n['news'] === [] && $n['meta']['count'] === 0);
// nothing published at all is still treated as a site problem: the last good file is kept
copy("$plugin/data/products.json", "$plugin/data/products.before");
file_put_contents("$plugin/data/products.json", json_encode(['meta' => ['count' => 1], 'products' => [['id' => 'kept', 'categories' => [], 'listing' => 'catalogue']]]));
$GLOBALS['products'] = []; $GLOBALS['protected_posts'] = [];
$r = Ranatec_Agent_Sync::run('test');
$ok('nothing published at all → error, last good files kept', $read('products')['products'][0]['id'] === 'kept' && count(preg_grep('/No published (posts|products)/', $r['errors'])) === 2, json_encode($r['errors']));

// ---------------------------------------------------------------- items no longer public but still in the static files
$GLOBALS['wp_options']['ranatec_api_unpublished_in_static'] = [];
file_put_contents("$plugin/data/products.json", json_encode($released));       // back to the released data
$keep = 'tunable-band-reject-filter-ri-268';
$GLOBALS['products'] = [$product(5, $keep, ''), $product(6, 'shield-box-ri-181', 'pw')];
Ranatec_Agent_Sync::run('test');
$stale = Ranatec_Agent_Sync::stale_static();
$ok('unpublished item still in the static files is reported, with the files', isset($stale['shield-box-ri-181']) && in_array('/llms-full.txt', $stale['shield-box-ri-181']['files'], true) && in_array('/agent/', $stale['shield-box-ri-181']['files'], true), json_encode($stale['shield-box-ri-181'] ?? null));
$ok('public items are not reported', !isset($stale[$keep]));
ob_start(); Ranatec_Agent_Sync::stale_static_notice(); $notice = ob_get_clean();
$ok('administrators get an admin notice naming it', strpos($notice, 'notice-warning') !== false && strpos($notice, 'shield-box-ri-181') !== false);
putenv('TEST_ADMIN=0'); ob_start(); Ranatec_Agent_Sync::stale_static_notice(); $hidden = ob_get_clean(); putenv('TEST_ADMIN');
$ok('the notice is hidden from non-administrators', $hidden === '');
$GLOBALS['products'][] = $product(7, 'shield-box-ri-181-again', '');   // unrelated; ri-181 itself made public again:
$GLOBALS['products'][1] = $product(6, 'shield-box-ri-181', '');
Ranatec_Agent_Sync::run('test');
$ok('an item made public again is no longer reported', !isset(Ranatec_Agent_Sync::stale_static()['shield-box-ri-181']));
$GLOBALS['products'][1] = $product(6, 'shield-box-ri-181', 'pw');
Ranatec_Agent_Sync::run('test');
foreach (['llms.txt', 'llms-full.txt', 'agent-page.html'] as $f) file_put_contents("$plugin/public/$f", "rebuilt without it\n");   // a rebuilt plugin
$ok('the report clears once the static files no longer contain it (rebuilt plugin)', Ranatec_Agent_Sync::stale_static() === []);

// ---------------------------------------------------------------- long runs: the lock is renewed; a lost lock stops all writes
$GLOBALS['products'] = [$product(8, 'sync-test-heartbeat', '')];
$GLOBALS['protected_posts'] = [101];
$seen = $later = null;
$GLOBALS['during_wc_get_products'] = function () use (&$seen) { $seen = $GLOBALS['wp_options'][LOCK] ?? null; };   // before the renewal
$GLOBALS['during_terms'] = function () use (&$later) { $later = $GLOBALS['wp_options'][LOCK] ?? null; };            // after it
Ranatec_Agent_Sync::run('test');
$ok('lock: a running sync renews its lock as it works (new token after progress)', is_string($seen) && is_string($later) && $later !== $seen, "$seen → $later");
$ok('lock: the renewed lock is still released at the end', !array_key_exists(LOCK, $GLOBALS['wp_options']));
unset($GLOBALS['during_terms']);
// another run takes the lock over while this one is busy (as if this one had stalled past LOCK_TTL)
file_put_contents("$plugin/data/products.json", json_encode($released));
$before = md5_file("$plugin/data/products.json");
$GLOBALS['during_wc_get_products'] = function () { $GLOBALS['wp_options'][LOCK] = time() . ':newer-run'; };
$r = Ranatec_Agent_Sync::run('test');
$ok('lock: a run whose lock was taken over stops without writing', md5_file("$plugin/data/products.json") === $before && count(preg_grep('/took over the lock/', $r['errors'])) === 1, json_encode($r['errors']));
$ok('lock: it leaves the newer run\'s lock in place', str_ends_with((string) ($GLOBALS['wp_options'][LOCK] ?? ''), ':newer-run'));
$ok('lock: no temporary file left behind by the stopped run', !glob($plugin . '/data/*.tmp'));
unset($GLOBALS['during_wc_get_products'], $GLOBALS['wp_options'][LOCK]);

// ---------------------------------------------------------------- plugin upgrade: sync at once, so release data never lingers
$GLOBALS['wp_options']['ranatec_api_rewrite_version'] = '0.0.1';
$GLOBALS['is_admin'] = false; $GLOBALS['scheduled'] = [];
Ranatec_Agent_API::maybe_flush_rewrites();
$ok('upgrade seen on a front-end request → sync handed to WP-Cron at once', $GLOBALS['scheduled'] === [['ranatec_api_daily_sync', ['upgrade']]], json_encode($GLOBALS['scheduled']));
$GLOBALS['wp_options']['ranatec_api_rewrite_version'] = '0.0.1';
$GLOBALS['is_admin'] = true; $GLOBALS['scheduled'] = []; unset($GLOBALS['wp_options']['ranatec_api_last_sync']);
$GLOBALS['products'] = [$product(9, 'sync-test-after-upgrade', '')];
Ranatec_Agent_API::maybe_flush_rewrites();
$ok('upgrade seen on an admin request → synced right away', in_array('sync-test-after-upgrade', array_column($read('products')['products'], 'id'), true) && ($GLOBALS['wp_options']['ranatec_api_last_sync']['trigger'] ?? '') === 'upgrade');
$GLOBALS['scheduled'] = [];
Ranatec_Agent_API::maybe_flush_rewrites();
$ok('…and only once per version', $GLOBALS['scheduled'] === [] && $GLOBALS['wp_options']['ranatec_api_rewrite_version'] === RANATEC_API_VERSION);

