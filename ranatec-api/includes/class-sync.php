<?php
/**
 * Keeps data/news.json and data/products.json in step with WordPress / WooCommerce.
 *
 * - Runs daily via WP-Cron and on demand from Tools → Ranatec Agent API.
 * - Merges, never blindly overwrites: curated fields from the crawl (specifications, applications,
 *   features, news type, cleaned body text) are kept for items that already exist; new items get
 *   a base record built from WordPress data; unpublished items are removed.
 * - Writes the canonical `meta.source` URL (https://ranatec.com/agent/v1/{endpoint}.json) itself, so
 *   a sync can never re-introduce wrong source paths.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ranatec_Agent_Sync
{
    const HOOK = 'ranatec_api_daily_sync';
    const OPTION_REPORT = 'ranatec_api_last_sync';
    const OPTION_RECIPIENT = 'ranatec_api_contact_recipient';
    const OPTION_MCP_KEY = 'ranatec_api_mcp_key';
    const MODEL_RE = '/\b(RI ?\d{3,4}(?:-\d{2})?B?|RF ?\d{4}B?)\b/i';
    const LOCALE_PREFIX_RE = '#^/(en-gb|en-ca)(/|$)#';

    public static function init()
    {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_ranatec_api_sync', [__CLASS__, 'handle_sync']);
        add_action('admin_post_ranatec_api_flush', [__CLASS__, 'handle_flush']);
        add_action('admin_post_ranatec_api_settings', [__CLASS__, 'handle_settings']);
    }

    public static function schedule()
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    // ------------------------------------------------------------------ sync

    public static function run($trigger = 'cron')
    {
        $report = ['time' => gmdate('c'), 'trigger' => is_string($trigger) ? $trigger : 'cron', 'news' => null, 'products' => null, 'errors' => []];
        try {
            $report['news'] = self::sync_news();
        } catch (Throwable $e) {
            $report['errors'][] = 'news: ' . $e->getMessage();
        }
        try {
            $report['products'] = self::sync_products();
        } catch (Throwable $e) {
            $report['errors'][] = 'products: ' . $e->getMessage();
        }
        update_option(self::OPTION_REPORT, $report, false);
        return $report;
    }

    private static function sync_news()
    {
        $file = Ranatec_Agent_API::load('news');
        $existing = [];
        foreach ($file['news'] as $n) {
            $existing[$n['id']] = $n;
        }
        $products = self::catalogue_models();
        $out = [];
        $added = 0;
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false]);
        foreach ($posts as $post) {
            $slug = $post->post_name;
            if (isset($out[$slug])) {
                continue; // locale copies share the slug
            }
            $path = self::path_of(get_permalink($post));
            $item = isset($existing[$slug]) ? $existing[$slug] : null;
            if (!$item) {
                $added++;
                $body = self::content_paragraphs($post);
                $title = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
                $item = [
                    'id' => $slug,
                    'title' => $title,
                    'seo_title' => $title,
                    'type' => self::news_type($slug . ' ' . $title),
                    'body' => $body,
                    'related_product_ids' => self::related_products($title . ' ' . implode(' ', $body), $products),
                ];
            }
            $item['date_published'] = get_post_time('Y-m-d', true, $post);
            $item['date_modified'] = get_post_modified_time('Y-m-d', true, $post);
            $item['summary'] = self::meta_description($post) ?: (isset($item['summary']) ? $item['summary'] : null);
            $item['image'] = get_the_post_thumbnail_url($post, 'full') ?: (isset($item['image']) ? $item['image'] : null);
            $item['urls'] = self::locale_urls($path);
            $item['url'] = isset($item['url_status']) ? $item['urls']['en-GB'] : $item['urls']['en-US'];
            $out[$slug] = self::order_keys($item, ['id', 'title', 'seo_title', 'type', 'date_published', 'date_modified', 'summary', 'body', 'related_product_ids', 'image', 'url', 'urls']);
        }
        if (!$out) {
            throw new RuntimeException('No published posts found — refusing to write an empty news.json.');
        }
        $items = array_values($out);
        usort($items, function ($a, $b) {
            return strcmp((string) $b['date_published'], (string) $a['date_published']);
        });
        $removed = count(array_diff(array_keys($existing), array_keys($out)));
        $types = array_values(array_unique(array_column($items, 'type')));
        sort($types);
        self::write('news', ['meta' => self::meta('news', count($items), ['filters' => ['type', 'year', 'product', 'q'], 'types' => $types]), 'news' => $items]);
        return ['total' => count($items), 'added' => $added, 'removed' => $removed];
    }

    private static function sync_products()
    {
        if (!function_exists('wc_get_products')) {
            return ['skipped' => 'WooCommerce not active'];
        }
        $file = Ranatec_Agent_API::load('products');
        $existing = [];
        foreach ($file['products'] as $p) {
            $existing[$p['id']] = $p;
        }
        $out = [];
        $added = 0;
        foreach (wc_get_products(['status' => 'publish', 'limit' => -1]) as $wc) {
            $slug = $wc->get_slug();
            $terms = wp_get_post_terms($wc->get_id(), 'product_cat', ['fields' => 'slugs']);
            $cats = is_wp_error($terms) ? [] : array_values(array_diff($terms, ['uncategorized']));
            $item = isset($existing[$slug]) ? $existing[$slug] : null;
            if (!$item) {
                $added++;
                preg_match(self::MODEL_RE, $wc->get_name(), $m);
                $short = trim(wp_strip_all_tags($wc->get_short_description()));
                $item = [
                    'id' => $slug,
                    'model_number' => $m ? self::norm_model($m[1]) : null,
                    'summary' => $short ? Ranatec_Agent_API::str_sub($short, 200) : null,
                    'listing' => $cats ? 'catalogue' : 'additional',
                    'custom' => strpos($slug, 'customized-') === 0,
                    'description' => self::split_paragraphs($wc->get_description()),
                    'applications' => [], 'features' => [], 'specifications' => [], 'electrical_interfaces' => [],
                    'control_and_ordering' => [], 'technical_drawings_note' => null, 'optional_accessories' => [], 'datasheets' => [],
                    'pricing' => ['model' => 'request-for-quote', 'public_price' => null, 'how_to_buy' => 'Add to the RFQ list on the product page and submit, or use POST /agent/v1/contact.json with inquiry.type = "quote_request" and the product id(s).'],
                    'same_model_listings' => [],
                    'note' => 'Added automatically by WordPress sync; detailed specifications not yet captured — see the product page and datasheet.',
                ];
            }
            if ($cats) {
                $item['categories'] = $cats;
            } elseif (empty($item['categories'])) {
                $item['categories'] = ['accessories'];
            }
            $item['name'] = html_entity_decode($wc->get_name(), ENT_QUOTES, 'UTF-8') === 'Customized' && !empty($item['name']) ? $item['name'] : html_entity_decode($wc->get_name(), ENT_QUOTES, 'UTF-8');
            $path = self::path_of(get_permalink($wc->get_id()));
            $item['url'] = RANATEC_API_SITE . $path;
            $item['urls'] = self::locale_urls($path);
            $item['canonical_url'] = $item['url'];
            $img = wp_get_attachment_image_url($wc->get_image_id(), 'full');
            if ($img) {
                $item['image'] = $img;
            }
            $created = $wc->get_date_created();
            $modified = $wc->get_date_modified();
            if ($created && empty($item['date_published'])) {
                $item['date_published'] = $created->date('c');
            }
            if ($modified) {
                $item['date_modified'] = $modified->date('c');
            }
            $out[$slug] = $item;
        }
        if (!$out) {
            throw new RuntimeException('No published products found — refusing to write an empty products.json.');
        }
        $items = array_values($out);
        $removed = count(array_diff(array_keys($existing), array_keys($out)));
        self::write('products', ['meta' => self::meta('products', count($items), ['filters' => ['category', 'domain', 'listing', 'q', 'model']]), 'products' => $items]);

        // keep category membership consistent
        $cats = Ranatec_Agent_API::load('categories');
        foreach ($cats['categories'] as &$c) {
            $in = array_filter($items, function ($p) use ($c) {
                return in_array($c['id'], $p['categories'], true);
            });
            $c['product_ids'] = array_values(array_map(function ($p) { return $p['id']; }, array_filter($in, function ($p) { return $p['listing'] === 'catalogue'; })));
            $c['additional_listing_ids'] = array_values(array_map(function ($p) { return $p['id']; }, array_filter($in, function ($p) { return $p['listing'] !== 'catalogue'; })));
            if ($c['id'] === 'accessories') {
                $c['product_ids'] = array_merge($c['product_ids'], $c['additional_listing_ids']);
                $c['additional_listing_ids'] = [];
            }
            $c['product_count'] = count($c['product_ids']);
        }
        unset($c);
        self::write('categories', ['meta' => self::meta('categories', count($cats['categories'])), 'categories' => $cats['categories']]);
        return ['total' => count($items), 'added' => $added, 'removed' => $removed];
    }

    // ------------------------------------------------------------------ helpers

    public static function meta($endpoint, $count, array $extra = [])
    {
        return array_merge([
            'source' => RANATEC_API_BASE . '/' . $endpoint . '.json',
            'endpoint' => $endpoint,
            'version' => RANATEC_API_VERSION,
            'last_updated' => gmdate('Y-m-d'),
            'publisher' => 'Ranatec AB',
            'canonical_site' => RANATEC_API_SITE . '/',
            'locales' => ['en-US', 'en-GB', 'en-CA'],
            'locale_note' => 'ranatec.com publishes the same English content in three regional variants: en-US (default, https://ranatec.com/), en-GB (/en-gb/) and en-CA (/en-ca/). Content is identical; each entity lists all three URLs.',
            'count' => $count,
        ], $extra);
    }

    public static function locale_urls($path)
    {
        $path = '/' . ltrim($path, '/');
        return [
            'en-US' => RANATEC_API_SITE . $path,
            'en-GB' => RANATEC_API_SITE . '/en-gb' . $path,
            'en-CA' => RANATEC_API_SITE . '/en-ca' . $path,
        ];
    }

    /** Site-relative path of a permalink, without any /en-gb or /en-ca prefix. */
    private static function path_of($permalink)
    {
        $path = (string) wp_parse_url($permalink, PHP_URL_PATH);
        $path = preg_replace(self::LOCALE_PREFIX_RE, '/', $path);
        return '/' . ltrim($path, '/');
    }

    private static function write($name, array $data)
    {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$json) {
            throw new RuntimeException("Could not encode {$name}.json");
        }
        $target = RANATEC_API_DATA . $name . '.json';
        $tmp = $target . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $target)) {
            throw new RuntimeException("Could not write {$target} — check that the data/ directory is writable by PHP.");
        }
    }

    private static function meta_description($post)
    {
        $d = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        if ($d) {
            return wp_strip_all_tags($d);
        }
        return has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : null;
    }

    private static function content_paragraphs($post)
    {
        return self::split_paragraphs(apply_filters('the_content', $post->post_content));
    }

    private static function split_paragraphs($html)
    {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#si', '', (string) $html);
        $html = preg_replace('#</(p|li|h[1-6]|div|tr|blockquote)>|<br\s*/?>#i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html, false), ENT_QUOTES, 'UTF-8');
        $out = [];
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            if ($line !== '' && !preg_match('/^(❮|See all news|NEWS|READ TIME:.*|Related News|Ask for a Quote)$/u', $line)) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    private static function news_type($s)
    {
        $s = strtolower($s);
        foreach ([
            'event' => ['exhibition', 'emv', 'microwave week', 'microwave-week', 'eumw', 'meet us'],
            'distribution-partner' => ['distributor', 'distribute'],
            'company-news' => ['ceo', 'management', 'brand', 'order', 'delivers', 'selected', 'acquires'],
            'technical-article' => ['how to', 'how-to', 'compared', 'characteristics', 'you need', 'for carrier', 'for multiple', 'sharp slopes', 'testing'],
        ] as $type => $words) {
            foreach ($words as $w) {
                if (strpos($s, $w) !== false) {
                    return $type;
                }
            }
        }
        return 'product-launch';
    }

    private static function norm_model($m)
    {
        $m = strtoupper(preg_replace('/\s+/', '', $m));
        return strpos($m, 'RI') === 0 ? 'RI ' . substr($m, 2) : $m;
    }

    private static function catalogue_models()
    {
        $map = [];
        foreach (Ranatec_Agent_API::load('products')['products'] as $p) {
            if ($p['model_number'] && $p['listing'] === 'catalogue') {
                $map[$p['model_number']][] = $p['id'];
            }
        }
        return $map;
    }

    private static function related_products($text, array $models)
    {
        preg_match_all(self::MODEL_RE, $text, $m);
        $ids = [];
        foreach ($m[1] as $model) {
            $k = self::norm_model($model);
            if (isset($models[$k])) {
                $ids = array_merge($ids, $models[$k]);
            }
        }
        return array_values(array_unique($ids));
    }

    private static function order_keys(array $item, array $first)
    {
        $out = [];
        foreach ($first as $k) {
            if (array_key_exists($k, $item)) {
                $out[$k] = $item[$k];
            }
        }
        return $out + $item;
    }

    // ------------------------------------------------------------------ admin

    public static function admin_menu()
    {
        add_management_page('Ranatec Agent API', 'Ranatec Agent API', 'manage_options', 'ranatec-agent-api', [__CLASS__, 'render_admin']);
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $report = get_option(self::OPTION_REPORT);
        $recipient = get_option(self::OPTION_RECIPIENT, Ranatec_Agent_Contact::DEFAULT_RECIPIENT);
        $files = ['company', 'products', 'categories', 'solutions', 'news', 'pages', 'faq'];
        $next = wp_next_scheduled(self::HOOK);
        echo '<div class="wrap"><h1>Ranatec Agent API</h1>';
        if (isset($_GET['ranatec_msg'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['ranatec_msg']))) . '</p></div>';
        }
        echo '<h2>Endpoints</h2><ul>';
        foreach (['/agent/', '/agent/v1/index.json', '/openapi.json', '/llms.txt', '/llms-full.txt', '/ai.txt', '/api-catalog.json', '/.well-known/api-catalog'] as $p) {
            echo '<li><a href="' . esc_url(home_url($p)) . '" target="_blank" rel="noopener">' . esc_html($p) . '</a></li>';
        }
        echo '</ul><p>If any of these return 404, click <strong>Flush rewrite rules</strong> below (same effect as Settings → Permalinks → Save). If a physical <code>llms.txt</code> / <code>ai.txt</code> exists in the web root, the web server serves that file instead of the plugin copy.</p>';

        echo '<h2>Data files</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>File</th><th>Items</th><th>Last updated</th><th>Writable</th></tr></thead><tbody>';
        foreach ($files as $f) {
            $path = RANATEC_API_DATA . $f . '.json';
            $d = is_readable($path) ? json_decode(file_get_contents($path), true) : null;
            echo '<tr><td>' . esc_html($f) . '.json</td><td>' . esc_html(isset($d['meta']['count']) ? $d['meta']['count'] : '—') . '</td><td>' . esc_html(isset($d['meta']['last_updated']) ? $d['meta']['last_updated'] : '—') . '</td><td>' . (is_writable($path) ? 'yes' : '<strong>no</strong>') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Sync</h2><p>News and products are synced from WordPress/WooCommerce daily' . ($next ? ' (next run: ' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i')) . ')' : '') . '. Specifications captured from the crawl are kept; new products/posts get base records.</p>';
        if ($report) {
            echo '<pre style="background:#fff;padding:12px;max-width:720px;overflow:auto">' . esc_html(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('ranatec_api_sync');
        echo '<input type="hidden" name="action" value="ranatec_api_sync" />';
        submit_button('Sync now', 'primary', 'submit', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        wp_nonce_field('ranatec_api_flush');
        echo '<input type="hidden" name="action" value="ranatec_api_flush" />';
        submit_button('Flush rewrite rules', 'secondary', 'submit', false);
        echo '</form>';

        echo '<h2>Agent contact / RFQ endpoint</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ranatec_api_settings');
        echo '<input type="hidden" name="action" value="ranatec_api_settings" />';
        echo '<p><label>Deliver submissions from <code>POST /agent/v1/contact.json</code> to: <input type="email" name="recipient" class="regular-text" value="' . esc_attr($recipient) . '" /></label></p>';
        $key_set = get_option(self::OPTION_MCP_KEY, '') !== '';
        echo '<p><label>MCP server key: <input type="password" name="mcp_key" class="regular-text" autocomplete="new-password" placeholder="' . ($key_set ? '•••••••• (set — leave empty to keep)' : 'not set — leads accepted from any caller') . '" /></label><br>';
        echo '<span class="description">When set, <code>contact.json</code> accepts leads <strong>only</strong> from the Ranatec MCP server (tool <code>submit_inquiry</code>). Use the same value as the <code>RANATEC_MCP_KEY</code> environment variable on Render. Long random value, e.g. 40+ characters.</span><br>';
        echo '<label><input type="checkbox" name="mcp_key_clear" value="1" /> Remove the key (accept leads from any caller again)</label></p>';
        submit_button('Save', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public static function handle_sync()
    {
        self::guard('ranatec_api_sync');
        $r = self::run('manual');
        $msg = $r['errors'] ? 'Sync finished with errors: ' . implode('; ', $r['errors']) : 'Sync complete.';
        wp_safe_redirect(add_query_arg('ranatec_msg', rawurlencode($msg), admin_url('tools.php?page=ranatec-agent-api')));
        exit;
    }

    public static function handle_flush()
    {
        self::guard('ranatec_api_flush');
        Ranatec_Agent_API::add_rewrite_rules();
        flush_rewrite_rules();
        wp_safe_redirect(add_query_arg('ranatec_msg', rawurlencode('Rewrite rules flushed.'), admin_url('tools.php?page=ranatec-agent-api')));
        exit;
    }

    public static function handle_settings()
    {
        self::guard('ranatec_api_settings');
        $email = isset($_POST['recipient']) ? sanitize_email(wp_unslash($_POST['recipient'])) : '';
        if ($email && is_email($email)) {
            update_option(self::OPTION_RECIPIENT, $email, false);
            $msg = 'Recipient saved.';
        } else {
            $msg = 'Invalid email — not saved.';
        }
        if (!empty($_POST['mcp_key_clear'])) {
            delete_option(self::OPTION_MCP_KEY);
            $msg .= ' MCP server key removed.';
        } else {
            $key = isset($_POST['mcp_key']) ? trim((string) wp_unslash($_POST['mcp_key'])) : '';
            if ($key !== '') {
                if (strlen($key) < 24 || preg_match('/\s/', $key)) {
                    $msg .= ' MCP server key NOT saved: use at least 24 characters, no spaces.';
                } else {
                    update_option(self::OPTION_MCP_KEY, $key, false);
                    $msg .= ' MCP server key saved — contact.json now accepts leads only from the MCP server.';
                }
            }
        }
        wp_safe_redirect(add_query_arg('ranatec_msg', rawurlencode($msg), admin_url('tools.php?page=ranatec-agent-api')));
        exit;
    }

    private static function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer($action);
    }
}
