<?php
/**
 * Product JSON-LD and the homepage alt-text scan, against real inputs: the Yoast graph captured from the live
 * ranatec.com RI 268 page (tests/fixtures/yoast-graph-ri-268.json) and, when given, a saved copy of the live homepage.
 * Usage: php tests/schema-test.php [homepage.html]. Prints PASS/FAIL lines.
 */
require __DIR__ . '/wp-stubs.php';
$GLOBALS['singular'] = true; $GLOBALS['slug'] = 'tunable-band-reject-filter-ri-268';
function is_singular($t = '') { return $GLOBALS['singular'] && $t === 'product'; }
function get_queried_object() { return (object) ['post_name' => $GLOBALS['slug']]; }
function get_queried_object_id() { return 42; }
function attachment_url_to_postid($u) { return preg_match('/logo/i', $u) ? 0 : abs(crc32($u)) % 100000 + 1; }
function get_post_meta($id, $k, $s = false) { return ''; }
function get_the_title($id) { return ''; }
function get_attached_file($id) { return false; }
require dirname(__DIR__) . '/ranatec-api/ranatec-api.php';
$ok = function ($n, $c, $i = '') { echo ($c ? 'PASS ' : 'FAIL ') . $n . ($i !== '' ? " ($i)" : '') . "\n"; };

// ---- Yoast: one graph, Product added, WebPage → mainEntity
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/yoast-graph-ri-268.json'), true);
$graph = Ranatec_Agent_Product_Schema::yoast_graph($fixture['@graph']);
$products = array_values(array_filter($graph, fn($p) => in_array('Product', (array) ($p['@type'] ?? []), true)));
$p = $products[0] ?? [];
$web = array_values(array_filter($graph, fn($x) => in_array('WebPage', (array) ($x['@type'] ?? []), true)))[0] ?? [];
$ok('Yoast graph: exactly one Product added, nothing else changed in count', count($products) === 1 && count($graph) === count($fixture['@graph']) + 1);
$ok('Product links into the Yoast graph (Organization, primary image, WebPage)', ($p['manufacturer']['@id'] ?? '') === 'https://ranatec.com/#organization' && str_ends_with($p['image']['@id'] ?? '', '#primaryimage') && ($p['mainEntityOfPage']['@id'] ?? '') === $web['@id'] && ($web['mainEntity']['@id'] ?? '') === $p['@id'], json_encode([$p['manufacturer'] ?? null, $p['image'] ?? null]));
$ok('Product fields: name, sku (WooCommerce), mpn, brand Ranatec, category, description, url', $p['name'] === 'Tunable Band Reject Filter RI 268' && $p['sku'] === 'SKU-42' && $p['mpn'] === 'RI 268' && $p['brand'] === ['@type' => 'Brand', 'name' => 'Ranatec'] && $p['category'] === 'Tunable Band Reject Filters' && $p['description'] !== '' && $p['url'] === 'https://ranatec.com/product/tunable-band-reject-filter-ri-268/');
$ok('datasheet as subjectOf DigitalDocument (PDF)', ($p['subjectOf'][0]['url'] ?? '') === 'https://ranatec.com/wp-content/uploads/2026/07/RI-268-Datasheet-rev.-A.pdf' && $p['subjectOf'][0]['encodingFormat'] === 'application/pdf');
$o = $p['offers'] ?? [];
$ok('Offer: WooCommerce stock status → availability, "price on request", no price value', $o['@type'] === 'Offer' && $o['availability'] === 'https://schema.org/BackOrder' && stripos($o['priceSpecification']['description'] ?? '', 'price on request') === 0 && !isset($o['price']) && !isset($o['priceSpecification']['price']));
$again = Ranatec_Agent_Product_Schema::yoast_graph($graph);
$ok('a graph that already has a Product is left alone (no duplicate)', count($again) === count($graph));
$GLOBALS['singular'] = false;
$ok('non-product pages: Yoast graph untouched', Ranatec_Agent_Product_Schema::yoast_graph($fixture['@graph']) === $fixture['@graph']);
$GLOBALS['singular'] = true; $GLOBALS['slug'] = 'not-in-the-catalogue';
$ok('a product page missing from the data: graph untouched', Ranatec_Agent_Product_Schema::yoast_graph($fixture['@graph']) === $fixture['@graph']);
$GLOBALS['slug'] = 'tunable-band-reject-filter-ri-268';
$ok('WooCommerce\'s own Product markup is dropped on catalogue product pages', Ranatec_Agent_Product_Schema::drop_woocommerce_product(['@type' => 'Product']) === []);
define('WPSEO_VERSION', 'test'); ob_start(); Ranatec_Agent_Product_Schema::standalone(); $out = ob_get_clean();
$ok('with Yoast active no second JSON-LD block is printed', $out === '');

// ---- alt text proposals
$P = fn($t, $f) => Ranatec_Agent_Alt_Text::proposal($t, $f);
$ok('alt proposal from the Media Library title', $P('Ranatec RI 268 Tunable Band Reject Filter', 'x.jpg') === 'Ranatec RI 268 Tunable Band Reject Filter');
$ok('alt proposal from a file name, size suffix removed', $P('', 'shield-box_RI-181-300x200.webp') === 'shield box RI 181');
$ok('decorative file names get no proposal (alt="")', $P('Background', 'hero-bg-dark.jpg') === '' && $P('', 'divider-line.png') === '' && $P('', 'IMG_2041.jpg') === '');
if (!empty($argv[1]) && is_readable($argv[1])) {
    $found = Ranatec_Agent_Alt_Text::scan_html(file_get_contents($argv[1]));
    $props = array_filter($found, fn($f) => $f['proposal'] !== '');
    $ok('homepage sample: linked images named after their product or category, decorative ones left empty', count($found) === 44 && count(array_filter($found, fn($f) => $f['source'] === 'linked product or category')) === 43 && count($found) - count($props) === 1, count($found) . ' unique images, ' . count($props) . ' with a proposal, ' . (count($found) - count($props)) . ' decorative/no name');
}
