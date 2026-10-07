<?php
/**
 * Plugin Name:       Ranatec Agent API
 * Plugin URI:        https://ranatec.com/agent/
 * Description:       Agentic Web package for ranatec.com — serves the machine-readable agent page (/agent/), clean JSON endpoints (/agent/v1/*.json), the OpenAPI spec (/openapi.json), llms.txt, ai.txt and the API catalog, plus an agent-safe RFQ / contact endpoint.
 * Version:           1.0.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GO MO Group for Ranatec AB
 * License:           Proprietary
 * Text Domain:       ranatec-api
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RANATEC_API_VERSION', '1.0.9');
define('RANATEC_API_DIR', plugin_dir_path(__FILE__));
define('RANATEC_API_DATA', RANATEC_API_DIR . 'data/');
define('RANATEC_API_PUBLIC', RANATEC_API_DIR . 'public/');
define('RANATEC_API_SITE', 'https://ranatec.com');
define('RANATEC_API_BASE', RANATEC_API_SITE . '/agent/v1');
// MCP server (hosted on Render). tools/build_static.py reads this value, so change it only here.
define('RANATEC_MCP_URL', 'https://agentic-mcp-sme-ranatec.onrender.com/mcp');

require_once RANATEC_API_DIR . 'includes/class-contact.php';
require_once RANATEC_API_DIR . 'includes/class-sync.php';

final class Ranatec_Agent_API
{
    /** Collection endpoints backed by a data file of the same name. */
    const COLLECTIONS = [
        'company'    => null,
        'products'   => 'products',
        'categories' => 'categories',
        'solutions'  => 'solutions',
        'news'       => 'news',
        'pages'      => 'pages',
        'faq'        => 'faq',
    ];

    /** Single-item endpoints: /agent/v1/{collection}/{id}.json */
    const ITEMS = ['products', 'categories', 'news'];

    /** Root-level discovery files served from /public when no physical file exists in the web root. */
    const STATIC_FILES = [
        'llms.txt'        => ['llms.txt', 'text/plain; charset=utf-8'],
        'llms-full.txt'   => ['llms-full.txt', 'text/plain; charset=utf-8'],
        'ai.txt'          => ['ai.txt', 'text/plain; charset=utf-8'],
        'api-catalog.json' => ['api-catalog.json', 'application/json; charset=utf-8'],
        'well-known-api-catalog' => ['well-known-api-catalog.json', 'application/linkset+json; charset=utf-8'],
        'openapi'         => [null, 'application/json; charset=utf-8'],
        'agent-page'      => ['agent-page.html', 'text/html; charset=utf-8'],
    ];

    public static function init()
    {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'route'], 0);
        add_filter('redirect_canonical', [__CLASS__, 'no_trailing_slash_redirect'], 10, 2);
        add_action('wp_head', [__CLASS__, 'head_links'], 2);
        Ranatec_Agent_Sync::init();
    }

    public static function add_rewrite_rules()
    {
        // The dots MUST be escaped, and the rules MUST be registered at 'top'.
        add_rewrite_rule('^agent/v1/([a-z-]+)\.json$', 'index.php?ranatec_endpoint=$matches[1]', 'top');
        add_rewrite_rule('^agent/v1/(products|categories|news)/([a-z0-9-]+)\.json$', 'index.php?ranatec_endpoint=$matches[1]&ranatec_id=$matches[2]', 'top');
        add_rewrite_rule('^agent/?$', 'index.php?ranatec_endpoint=agent-page', 'top');
        add_rewrite_rule('^openapi\.json$', 'index.php?ranatec_endpoint=openapi', 'top');
        add_rewrite_rule('^llms\.txt$', 'index.php?ranatec_endpoint=llms.txt', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?ranatec_endpoint=llms-full.txt', 'top');
        add_rewrite_rule('^ai\.txt$', 'index.php?ranatec_endpoint=ai.txt', 'top');
        add_rewrite_rule('^api-catalog\.json$', 'index.php?ranatec_endpoint=api-catalog.json', 'top');
        add_rewrite_rule('^\.well-known/api-catalog/?$', 'index.php?ranatec_endpoint=well-known-api-catalog', 'top');
    }

    public static function query_vars($vars)
    {
        $vars[] = 'ranatec_endpoint';
        $vars[] = 'ranatec_id';
        return $vars;
    }

    /** Stop WordPress from 301-ing /llms.txt → /llms.txt/ etc. */
    public static function no_trailing_slash_redirect($redirect, $requested)
    {
        return get_query_var('ranatec_endpoint') ? false : $redirect;
    }

    public static function head_links()
    {
        echo '<link rel="alternate" type="text/html" title="Ranatec — machine-readable agent page" href="' . esc_url(RANATEC_API_SITE . '/agent/') . "\" />\n";
        echo '<link rel="alternate" type="application/json" title="Ranatec Agent API" href="' . esc_url(RANATEC_API_BASE . '/index.json') . "\" />\n";
        echo '<link rel="service-desc" type="application/json" href="' . esc_url(RANATEC_API_SITE . '/openapi.json') . "\" />\n";
        echo '<link rel="api-catalog" href="' . esc_url(RANATEC_API_SITE . '/.well-known/api-catalog') . "\" />\n";
    }

    // ------------------------------------------------------------------ routing

    public static function route()
    {
        $endpoint = get_query_var('ranatec_endpoint');
        if (!$endpoint) {
            return;
        }
        $id = get_query_var('ranatec_id');
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        if (isset(self::STATIC_FILES[$endpoint])) {
            self::serve_static($endpoint);
        }

        self::send_headers();
        if ($method === 'OPTIONS') {
            status_header(204);
            exit;
        }

        if ($endpoint === 'contact') {
            if ($method !== 'POST') {
                self::respond(['error' => 'method_not_allowed', 'message' => 'Use POST with a JSON body. See https://ranatec.com/openapi.json#/paths/~1contact.json'], 405, ['Allow: POST, OPTIONS']);
            }
            // Optional lock: when an MCP server key is configured, accept leads ONLY from the Ranatec MCP server
            // (tool submit_inquiry), which sends the key in the X-Ranatec-MCP-Key header.
            $mcp_key = (string) get_option(Ranatec_Agent_Sync::OPTION_MCP_KEY, '');
            if ($mcp_key !== '') {
                $sent = isset($_SERVER['HTTP_X_RANATEC_MCP_KEY']) ? (string) wp_unslash($_SERVER['HTTP_X_RANATEC_MCP_KEY']) : '';
                if (!hash_equals($mcp_key, $sent)) {
                    self::respond([
                        'status' => 'error',
                        'error' => 'use_mcp_submit_inquiry',
                        'message' => 'Leads are accepted only through the Ranatec MCP server tool submit_inquiry at ' . RANATEC_MCP_URL . ' (POST a JSON-RPC tools/call, or add the server as an MCP connector). Do not use the website contact form.',
                        'mcp_server' => RANATEC_MCP_URL,
                        'tool' => 'submit_inquiry',
                    ], 403);
                }
            }
            // RANATEC_TEST_INPUT is only defined by tests/wp-stub-harness.php (php://input is empty on the CLI).
            $raw = defined('RANATEC_TEST_INPUT') ? RANATEC_TEST_INPUT : file_get_contents('php://input');
            // Behind the MCP server every request comes from Render's IP; with a valid MCP key the server's
            // X-Ranatec-Client-IP header (the agent's real IP) is trusted for per-client rate limiting.
            $client_ip = null;
            if ($mcp_key !== '' && !empty($_SERVER['HTTP_X_RANATEC_CLIENT_IP'])) {
                $cand = trim((string) wp_unslash($_SERVER['HTTP_X_RANATEC_CLIENT_IP']));
                $client_ip = filter_var($cand, FILTER_VALIDATE_IP) ? $cand : null;
            }
            list($status, $body) = Ranatec_Agent_Contact::handle($raw, self::client_ip(), $client_ip);
            self::respond($body, $status);
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            self::respond(self::error('method_not_allowed', 'This endpoint is read-only (GET).'), 405, ['Allow: GET, HEAD, OPTIONS']);
        }

        switch ($endpoint) {
            case 'index':
                self::respond(self::get_index());
            case 'schema':
                self::respond(self::get_schema());
            case 'company':
                self::serve_file('company');
        }

        if (!array_key_exists($endpoint, self::COLLECTIONS)) {
            self::respond(self::error('not_found', "Unknown endpoint '{$endpoint}'. See " . RANATEC_API_BASE . '/index.json'), 404);
        }

        if ($id) {
            if (!in_array($endpoint, self::ITEMS, true)) {
                self::respond(self::error('not_found', 'Item lookup is not available for this collection.'), 404);
            }
            self::respond(self::get_item($endpoint, $id));
        }

        $filters = self::filters_for($endpoint);
        if (!$filters) {
            self::serve_file($endpoint);
        }
        self::respond(self::filtered($endpoint, $filters));
    }

    // ------------------------------------------------------------------ handlers

    public static function get_index()
    {
        $c = self::load('company');
        $counts = [];
        foreach (['products', 'categories', 'solutions', 'news', 'pages', 'faq'] as $f) {
            $d = self::load($f);
            $counts[$f] = isset($d['meta']['count']) ? $d['meta']['count'] : null;
        }
        return [
            'meta' => [
                'source' => RANATEC_API_BASE . '/index.json',
                'endpoint' => 'index',
                'version' => RANATEC_API_VERSION,
                'last_updated' => isset($c['meta']['last_updated']) ? $c['meta']['last_updated'] : null,
                'publisher' => 'Ranatec AB',
            ],
            'name' => 'Ranatec Agent API',
            'description' => 'Read-only JSON API describing Ranatec AB (Gothenburg, Sweden): RF test and measurement products, categories, solutions, news, pages and FAQ — plus one consent-gated POST endpoint for quote requests and enquiries.',
            'locales' => ['en-US' => RANATEC_API_SITE . '/', 'en-GB' => RANATEC_API_SITE . '/en-gb/', 'en-CA' => RANATEC_API_SITE . '/en-ca/'],
            'counts' => $counts,
            'endpoints' => [
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/index.json', 'description' => 'This index'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/schema.json', 'description' => 'Field reference for every entity'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/company.json', 'description' => 'Company profile, contact, certifications, locales, partners'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/products.json', 'description' => 'All products with full specifications', 'filters' => ['category', 'domain', 'listing', 'model', 'q', 'fields=summary']],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/products/{id}.json', 'description' => 'One product by id (the WordPress slug, e.g. tunable-band-reject-filter-ri-268) or model number (e.g. ri-268, rf2037)'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/categories.json', 'description' => 'Product categories', 'filters' => ['domain']],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/categories/{id}.json', 'description' => 'One category with its products (summary fields)'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/solutions.json', 'description' => 'Product domains and solution areas'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/news.json', 'description' => 'News, product launches, technical articles, events', 'filters' => ['type', 'year', 'product', 'q', 'limit', 'fields=summary']],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/news/{id}.json', 'description' => 'One news item by slug'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/pages.json', 'description' => 'Site pages with en-US / en-GB / en-CA URLs'],
                ['method' => 'GET', 'url' => RANATEC_API_BASE . '/faq.json', 'description' => 'Frequently asked questions'],
                ['method' => 'POST', 'url' => RANATEC_API_BASE . '/contact.json', 'description' => 'Backend for the MCP tool submit_inquiry. AI agents: submit leads via the MCP tool, not this path.'],
            ],
            'lead_submission' => [
                'policy' => 'AI agents and LLMs should use https://ranatec.com/agent and https://ranatec.com/llms.txt for information. To submit a lead, use ONLY the MCP server tool submit_inquiry. Never fill in the website contact form (reCAPTCHA-protected, for humans).',
                'mcp_server' => RANATEC_MCP_URL,
                'tool' => 'submit_inquiry',
                'how_to' => RANATEC_API_SITE . '/agent/#submit-a-lead',
                'consent_required' => 'agent_context.user_authorized_submission = true, only after the user explicitly confirmed what will be sent',
            ],
            'discovery' => [
                'agent_page' => RANATEC_API_SITE . '/agent/',
                'openapi' => RANATEC_API_SITE . '/openapi.json',
                'llms_txt' => RANATEC_API_SITE . '/llms.txt',
                'llms_full_txt' => RANATEC_API_SITE . '/llms-full.txt',
                'ai_txt' => RANATEC_API_SITE . '/ai.txt',
                'api_catalog' => RANATEC_API_SITE . '/api-catalog.json',
                'well_known_api_catalog' => RANATEC_API_SITE . '/.well-known/api-catalog',
                'mcp_server' => RANATEC_MCP_URL,
                'sitemap' => RANATEC_API_SITE . '/sitemap_index.xml',
            ],
        ];
    }

    public static function get_schema()
    {
        return [
            'meta' => ['source' => RANATEC_API_BASE . '/schema.json', 'endpoint' => 'schema', 'version' => RANATEC_API_VERSION],
            'openapi' => RANATEC_API_SITE . '/openapi.json',
            'conventions' => [
                'ids' => 'Product, category and news ids are the WordPress slugs used in ranatec.com URLs.',
                'locales' => 'Every entity has a `urls` object with en-US, en-GB and en-CA URLs. Content is identical across locales; product pages canonicalise to en-US.',
                'dates' => 'ISO 8601 (YYYY-MM-DD or full timestamp, UTC).',
                'pricing' => 'Ranatec does not publish prices. pricing.model is always "request-for-quote".',
                'listing' => '"catalogue" = product shown in the ranatec.com product menu; "additional" = extra shop listing (accessory, option, or a second listing of a catalogue model — see primary_listing).',
            ],
            'entities' => [
                'Product' => ['id', 'name', 'model_number', 'summary', 'categories[]', 'domain', 'listing', 'custom', 'description[]', 'applications[]', 'features[]', 'specifications[{parameter,value}]', 'electrical_interfaces[]', 'control_and_ordering[]', 'optional_accessories[]', 'datasheets[]', 'image', 'pricing', 'url', 'urls{en-US,en-GB,en-CA}', 'canonical_url', 'same_model_listings[]', 'primary_listing', 'date_published', 'date_modified'],
                'Category' => ['id', 'name', 'domain', 'summary', 'overview[]', 'product_ids[]', 'additional_listing_ids[]', 'product_count', 'url', 'urls', 'legacy_urls_redirecting_here[]', 'api_url'],
                'Solution' => ['id', 'name', 'summary', 'details[]', 'category_ids[]', 'product_ids[]', 'urls'],
                'News' => ['id', 'title', 'type (product-launch|technical-article|company-news|event|distribution-partner)', 'date_published', 'summary', 'body[]', 'related_product_ids[]', 'image', 'url', 'urls'],
                'Page' => ['id', 'title', 'purpose', 'meta_description', 'urls', 'transactional'],
                'FAQ' => ['q', 'a', 'source'],
            ],
        ];
    }

    private static function filters_for($endpoint)
    {
        $allowed = [
            'products' => ['category', 'domain', 'listing', 'model', 'q', 'fields'],
            'categories' => ['domain'],
            'news' => ['type', 'year', 'product', 'q', 'limit', 'fields'],
        ];
        if (!isset($allowed[$endpoint])) {
            return [];
        }
        $out = [];
        foreach ($allowed[$endpoint] as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                $out[$k] = sanitize_text_field(wp_unslash($_GET[$k]));
            }
        }
        return $out;
    }

    public static function filtered($endpoint, array $f)
    {
        $data = self::load($endpoint);
        $items = $data[$endpoint];
        $q = isset($f['q']) ? self::lc($f['q']) : null;

        $items = array_values(array_filter($items, function ($it) use ($endpoint, $f, $q) {
            if ($endpoint === 'products') {
                if (isset($f['category']) && !in_array($f['category'], $it['categories'], true)) return false;
                if (isset($f['domain']) && $it['domain'] !== $f['domain']) return false;
                if (isset($f['listing']) && $it['listing'] !== $f['listing']) return false;
                if (isset($f['model']) && self::model_key($it['model_number']) !== self::model_key($f['model'])) return false;
            } elseif ($endpoint === 'categories') {
                if (isset($f['domain']) && $it['domain'] !== $f['domain']) return false;
            } elseif ($endpoint === 'news') {
                if (isset($f['type']) && $it['type'] !== $f['type']) return false;
                if (isset($f['year']) && substr((string) $it['date_published'], 0, 4) !== $f['year']) return false;
                if (isset($f['product']) && !in_array($f['product'], $it['related_product_ids'], true)) return false;
            }
            if ($q !== null && strpos(self::lc(wp_json_encode($it)), $q) === false) return false;
            return true;
        }));

        if ($endpoint === 'news' && isset($f['limit'])) {
            $items = array_slice($items, 0, max(1, min(100, (int) $f['limit'])));
        }
        if (isset($f['fields']) && $f['fields'] === 'summary') {
            $items = array_map([__CLASS__, 'summarise'], $items);
        }

        $data['meta']['count'] = count($items);
        $data['meta']['filters_applied'] = (object) $f;
        $data[$endpoint] = $items;
        return $data;
    }

    public static function summarise($it)
    {
        $keep = ['id', 'name', 'title', 'model_number', 'summary', 'categories', 'domain', 'listing', 'type', 'date_published', 'related_product_ids', 'url', 'urls'];
        return array_intersect_key($it, array_flip($keep));
    }

    public static function get_item($collection, $id)
    {
        $data = self::load($collection);
        $found = null;
        foreach ($data[$collection] as $it) {
            if ($it['id'] === $id) {
                $found = $it;
                break;
            }
        }
        // Products may also be looked up by model number (ri-268, rf2037, ri-3041-07).
        if (!$found && $collection === 'products') {
            $key = self::model_key($id);
            foreach ($data['products'] as $it) {
                if ($it['model_number'] && self::model_key($it['model_number']) === $key && $it['listing'] === 'catalogue') {
                    $found = $it;
                    break;
                }
            }
        }
        if (!$found) {
            self::respond(self::error('not_found', "No {$collection} item with id '{$id}'. List ids at " . RANATEC_API_BASE . "/{$collection}.json"), 404);
        }
        $out = ['meta' => $data['meta'], 'item' => $found];
        $out['meta']['source'] = RANATEC_API_BASE . "/{$collection}/{$found['id']}.json";
        unset($out['meta']['count']);
        if ($collection === 'categories') {
            $p = self::load('products');
            $ids = array_merge($found['product_ids'], isset($found['additional_listing_ids']) ? $found['additional_listing_ids'] : []);
            $out['products'] = array_values(array_map([__CLASS__, 'summarise'], array_filter($p['products'], function ($x) use ($ids) {
                return in_array($x['id'], $ids, true);
            })));
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    public static function model_key($m)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $m));
    }

    private static function lc($s)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** UTF-8 string length that works with or without the mbstring extension. */
    public static function str_len($s)
    {
        $s = (string) $s;
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $n = preg_match_all('/./us', $s);
        return $n === false ? strlen($s) : $n;
    }

    /** UTF-8 safe substring (from position 0) that works with or without the mbstring extension. */
    public static function str_sub($s, $max)
    {
        $s = (string) $s;
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return preg_match('/^.{0,' . (int) $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
    }

    public static function load($name)
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $file = RANATEC_API_DATA . $name . '.json';
            $cache[$name] = is_readable($file) ? json_decode(file_get_contents($file), true) : null;
            if (!is_array($cache[$name])) {
                self::send_headers();
                self::respond(self::error('data_unavailable', "Data file {$name}.json is missing or invalid."), 503);
            }
        }
        return $cache[$name];
    }

    public static function client_ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    }

    public static function error($code, $message)
    {
        return ['error' => $code, 'message' => $message, 'documentation' => RANATEC_API_SITE . '/openapi.json'];
    }

    public static function send_headers()
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, X-Ranatec-MCP-Key, X-Ranatec-Client-IP');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, follow');
        header('Link: <' . RANATEC_API_SITE . '/openapi.json>; rel="service-desc", <' . RANATEC_API_SITE . '/agent/>; rel="service-doc"');
    }

    private static function serve_file($name)
    {
        $file = RANATEC_API_DATA . $name . '.json';
        if (!is_readable($file)) {
            self::respond(self::error('data_unavailable', "Data file {$name}.json is missing."), 503);
        }
        self::cache_headers($file);
        status_header(200);
        readfile($file); // serve the exact file — no re-encoding
        exit;
    }

    private static function serve_static($endpoint)
    {
        list($file, $type) = self::STATIC_FILES[$endpoint];
        $path = $endpoint === 'openapi' ? RANATEC_API_DIR . 'openapi.json' : RANATEC_API_PUBLIC . $file;
        if (!is_readable($path)) {
            status_header(404);
            exit;
        }
        if ($endpoint !== 'agent-page') {
            header('Access-Control-Allow-Origin: *');
        }
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        self::cache_headers($path);
        status_header(200);
        readfile($path);
        exit;
    }

    private static function cache_headers($file)
    {
        $mtime = filemtime($file);
        $etag = '"' . md5($file . $mtime . filesize($file)) . '"';
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            status_header(304);
            exit;
        }
    }

    public static function respond($data, $status = 200, array $extra_headers = [])
    {
        if (!headers_sent()) {
            foreach ($extra_headers as $h) {
                header($h);
            }
        }
        status_header($status);
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

Ranatec_Agent_API::init();

register_activation_hook(__FILE__, function () {
    Ranatec_Agent_API::add_rewrite_rules();
    flush_rewrite_rules();
    Ranatec_Agent_Sync::schedule();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    Ranatec_Agent_Sync::unschedule();
});
