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
    const OPTION_LOCK = 'ranatec_api_sync_lock';
    const OPTION_STALE_STATIC = 'ranatec_api_unpublished_in_static';
    const LOCK_TTL = 600;
    const MODEL_RE = '/\b(RI ?\d{3,4}(?:-\d{2})?B?|RF ?\d{4}B?)\b/i';
    const LOCALE_PREFIX_RE = '#^/(en-gb|en-ca)(/|$)#';

    public static function init()
    {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_notices', [__CLASS__, 'stale_static_notice']);
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
        // One sync at a time: "Sync now" and the daily cron both read, rebuild and rewrite the same files.
        $token = self::acquire_lock();
        if ($token === null) {
            $report['skipped'] = 'another sync is already running — try again in a minute';
            return $report;
        }
        self::$lock_token = $token;
        try {
            return self::run_locked($report);
        } finally {
            if (self::$lock_token !== null) {
                self::release_lock(self::$lock_token);
            }
            self::$lock_token = null;
        }
    }

    /**
     * Takes the sync lock atomically; returns this run's token, or null when another run holds it.
     * add_option() is not usable here: it runs INSERT … ON DUPLICATE KEY UPDATE, so two runs could both "add" it.
     * INSERT IGNORE inserts for exactly one run; an expired lock (crashed run) is taken over with an UPDATE that
     * only matches the value just read, so only one run can win the takeover too. Public for the tests only.
     */
    public static function acquire_lock()
    {
        global $wpdb;
        $token = time() . ':' . bin2hex(random_bytes(8));
        $name = self::OPTION_LOCK;
        if ((int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token)) === 1) {
            self::forget_lock_cache();
            return $token;
        }
        $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($current === null || (int) $current > time() - self::LOCK_TTL) {
            return null;   // held by a live run (or released this instant: the next run will pick it up)
        }
        $won = (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $current)) === 1;
        self::forget_lock_cache();
        return $won ? $token : null;
    }

    /** The token of the lock this process holds while run() works; renewed as the sync progresses. */
    private static $lock_token = null;

    /**
     * Proves this run still owns the lock and restarts its LOCK_TTL clock (a new token, swapped in only if the old
     * one is still there). Called while the sync makes progress and right before every file is replaced, so a run
     * slower than LOCK_TTL keeps its lock while it works, and a run whose lock was taken over (it stalled for longer
     * than LOCK_TTL) stops before it writes anything: two runs never write at the same time.
     */
    private static function renew_lock()
    {
        global $wpdb;
        if (self::$lock_token === null) {
            throw new RuntimeException('the sync lock is not held — nothing was written');
        }
        $new = time() . ':' . bin2hex(random_bytes(8));
        $ok = (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, self::OPTION_LOCK, self::$lock_token)) === 1;
        self::forget_lock_cache();
        if (!$ok) {
            self::$lock_token = null;
            throw new RuntimeException('another sync took over the lock (this run stalled for more than ' . (self::LOCK_TTL / 60) . ' minutes) — this run stopped without writing');
        }
        self::$lock_token = $new;
    }

    /** Releases the lock only if this run still owns it (a run that outlived LOCK_TTL must not free a newer run's lock). */
    public static function release_lock($token)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_LOCK, $token));
        self::forget_lock_cache();
    }

    private static function forget_lock_cache()
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_LOCK, 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }

    /**
     * llms.txt, llms-full.txt and the /agent/ page are built offline (tools/build_static.py) and cannot be rewritten
     * by the sync. When an item leaves the API (password-protected, unpublished or deleted) but those files still
     * contain its URL, it is remembered here until the files no longer contain it (a rebuilt plugin), or the item
     * is public again. stale_static() lists them for the admin notice and the Tools page.
     */
    private static function track_unpublished(array $existing, array $out)
    {
        $list = get_option(self::OPTION_STALE_STATIC, []);
        $list = is_array($list) ? $list : [];
        foreach (array_diff_key($existing, $out) as $id => $item) {
            $urls = array_values(array_filter(array_merge([isset($item['url']) ? $item['url'] : ''], isset($item['urls']) && is_array($item['urls']) ? array_values($item['urls']) : [])));
            $list[$id] = ['title' => isset($item['title']) ? $item['title'] : (isset($item['name']) ? $item['name'] : $id), 'urls' => array_values(array_unique($urls))];
        }
        foreach (array_keys($out) as $id) {
            unset($list[$id]);   // public again
        }
        update_option(self::OPTION_STALE_STATIC, $list, false);
    }

    /** Items no longer public whose URL is still in a static file: [id => ['title' => …, 'files' => [...]]]. */
    public static function stale_static()
    {
        $list = get_option(self::OPTION_STALE_STATIC, []);
        if (!is_array($list) || !$list) {
            return [];
        }
        $files = [];
        foreach (['llms.txt', 'llms-full.txt', 'agent-page.html'] as $f) {
            $files[$f] = is_readable(RANATEC_API_PUBLIC . $f) ? (string) file_get_contents(RANATEC_API_PUBLIC . $f) : '';
        }
        $stale = [];
        foreach ($list as $id => $it) {
            $in = [];
            foreach ($files as $f => $text) {
                foreach ((array) $it['urls'] as $u) {
                    if ($u !== '' && strpos($text, $u) !== false) {   // site URLs end in "/", so one never matches inside another
                        $in[] = $f === 'agent-page.html' ? '/agent/' : '/' . $f;
                        break;
                    }
                }
            }
            if ($in) {
                $stale[$id] = ['title' => $it['title'], 'files' => $in];
            }
        }
        return $stale;
    }

    public static function stale_static_notice()
    {
        if (!current_user_can('manage_options') || !($stale = self::stale_static())) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Ranatec Agent API:</strong> ' . count($stale) . ' item(s) are no longer public on the site (password-protected, unpublished or deleted) but still appear in the AI-agent files built into the plugin:</p><ul style="list-style:disc;margin-left:2em">';
        foreach ($stale as $id => $it) {
            echo '<li>' . esc_html($it['title']) . ' <code>' . esc_html($id) . '</code> — ' . esc_html(implode(', ', $it['files'])) . '</li>';
        }
        echo '</ul><p>The data API already leaves them out. To remove them from these files, rebuild the package (<code>python3 tools/build_static.py</code>, then <code>bash tools/release.sh</code>) and upload the new plugin. Replace any copies of these files uploaded to <code>public_html</code> by hand too.</p></div>';
    }

    private static function run_locked(array $report)
    {
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
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'has_password' => false, 'suppress_filters' => false]);   // password-protected posts are private content
        $i = 0;
        foreach ($posts as $post) {
            if (++$i % 25 === 0) {
                self::renew_lock();
            }
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
        if (!$out && !get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'has_password' => true, 'suppress_filters' => false])) {
            // Nothing published at all usually means a broken query or a site problem: keep the last good file.
            throw new RuntimeException('No published posts found — refusing to write an empty news.json.');
        }
        // (Published posts that are all password-protected: an empty list is correct — none of them is public.)
        $items = array_values($out);
        usort($items, function ($a, $b) {
            return strcmp((string) $b['date_published'], (string) $a['date_published']);
        });
        $removed = count(array_diff(array_keys($existing), array_keys($out)));
        $types = array_values(array_unique(array_column($items, 'type')));
        sort($types);
        self::write('news', ['meta' => self::meta('news', count($items), ['filters' => ['type', 'year', 'product', 'q'], 'types' => $types]), 'news' => $items]);
        self::track_unpublished($existing, $out);
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
        $protected = 0;
        $wc_products = wc_get_products(['status' => 'publish', 'limit' => -1]);
        self::renew_lock();
        $i = 0;
        foreach ($wc_products as $wc) {
            if (++$i % 25 === 0) {
                self::renew_lock();
            }
            if ($wc->get_post_password() !== '') {
                $protected++;
                continue;   // password-protected product: not public
            }
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
                    'pricing' => ['model' => 'request-for-quote', 'public_price' => null, 'how_to_buy' => 'Humans: add to the RFQ list on the product page and submit. AI agents: use ONLY the MCP tool request_quote (product id or model number + the checkout fields), never the website forms.'],
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
                $item['date_published'] = gmdate('c', $created->getTimestamp());   // WC_DateTime::date() prints local time labelled +00:00
            }
            if ($modified) {
                $item['date_modified'] = gmdate('c', $modified->getTimestamp());
            }
            $out[$slug] = $item;
        }
        if (!$out && !$protected) {
            // Nothing published at all usually means a broken query or a site problem: keep the last good file.
            throw new RuntimeException('No published products found — refusing to write an empty products.json.');
        }
        $items = array_values($out);
        $removed = count(array_diff(array_keys($existing), array_keys($out)));
        self::write('products', ['meta' => self::meta('products', count($items), ['filters' => ['category', 'domain', 'listing', 'q', 'model']]), 'products' => $items]);
        self::track_unpublished($existing, $out);

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
        $tmp = $target . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';   // unique per run: never shared
        if (file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            throw new RuntimeException("Could not write {$target} — check that the data/ directory is writable by PHP.");
        }
        try {
            self::renew_lock();   // still ours? (and restarts the clock) — never replace a file after losing the lock
        } catch (RuntimeException $e) {
            @unlink($tmp);
            throw $e;
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
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

        echo '<h2>Agent submissions (contact enquiries and product quotes)</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ranatec_api_settings');
        echo '<input type="hidden" name="action" value="ranatec_api_settings" />';
        echo '<p><label>Notification emails for agent enquiries and quotes go to: <input type="email" name="recipient" class="regular-text" value="' . esc_attr($recipient) . '" /></label></p>';
        $key_set = get_option(self::OPTION_MCP_KEY, '') !== '';
        echo '<p><label>MCP server key: <input type="password" name="mcp_key" class="regular-text" autocomplete="new-password" placeholder="' . ($key_set ? '•••••••• (set — leave empty to keep)' : 'not set — leads accepted from any caller') . '" /></label><br>';
        echo '<span class="description">When set, <code>contact.json</code> and <code>quote.json</code> accept submissions <strong>only</strong> from the Ranatec MCP server (tools <code>submit_inquiry</code> and <code>request_quote</code>). Use the same value as the <code>RANATEC_MCP_KEY</code> environment variable on Render. Long random value, e.g. 40+ characters.</span><br>';
        echo '<label><input type="checkbox" name="mcp_key_clear" value="1" /> Remove the key (accept leads from any caller again)</label></p>';
        $acf = Ranatec_Agent_Contact::acf7db_available();
        echo '<h3>Contact enquiries</h3><p>Agent contact enquiries (MCP tool <code>submit_inquiry</code>, no products) are saved in <strong>Advanced CF7 DB</strong> as entries of the contact form, next to website leads: '
            . ($acf ? '<span style="color:#008a20">tables found ✓</span>' : '<strong style="color:#b32d2e">tables not found — activate Advanced CF7 DB, otherwise leads are only emailed</strong>') . '</p>';
        echo '<p><label>Contact Form 7 form ID: <input type="number" name="cf7_form_id" min="1" value="' . esc_attr(Ranatec_Agent_Contact::cf7_form_id()) . '" class="small-text" /></label> <span class="description">(ranatec.com/contact-us/ uses form 50)</span></p>';
        echo '<p><label>Field mapping (JSON, lead field → form field):<br><textarea name="cf7_fields" rows="4" cols="70" class="code">' . esc_textarea(wp_json_encode(Ranatec_Agent_Contact::cf7_fields(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</textarea></label></p>';
        if (function_exists('wc_get_order_statuses')) {
            $cur = (string) get_option('ranatec_api_quote_status', '');
            echo '<h3>Product quote requests</h3><p>Agent product quotes (MCP tool <code>request_quote</code>, with the checkout fields) are created as <strong>WooCommerce orders</strong>, never as contact-form leads (currently status: <code>' . esc_html(Ranatec_Agent_Contact::quote_order_status()) . '</code>). Status: <select name="quote_status"><option value="">Automatic (YITH “new quote request” if available, else Pending)</option>';
            foreach (wc_get_order_statuses() as $k => $label) {
                $v = substr($k, 3);
                echo '<option value="' . esc_attr($v) . '"' . selected($cur, $v, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></p>';
        } else {
            echo '<h3>Product quote requests</h3><p><strong>WooCommerce not active</strong> — agent product quotes are refused (the agent is told to email info@ranatec.com).</p>';
        }
        echo '<p><label><input type="checkbox" name="notify_email" value="1" ' . (Ranatec_Agent_Contact::notify_enabled() ? 'checked' : '') . ' /> Also send a notification email to the recipient above</label></p>';
        $copies = Ranatec_Agent_ARD::static_copies();
        if ($copies) {
            echo '<h3>Files uploaded to the web root by hand</h3><p>The web server serves these instead of the plugin:</p><ul>';
            foreach ($copies as $path => $state) {
                echo '<li><code>' . esc_html($path) . '</code> — ' . ($state === 'current' ? '<span style="color:#008a20">matches the plugin ✓</span>' : '<strong style="color:#b32d2e">older than the plugin — replace it with <code>web-root/' . esc_html($path) . '</code> from the package, or delete it</strong>') . '</li>';
            }
            echo '</ul>';
        }
        $ard = Ranatec_Agent_ARD::summary();
        echo '<h3>Agentic Resource Discovery (ARD)</h3>';
        echo '<p><label><input type="checkbox" name="ard_enabled" value="1" ' . (Ranatec_Agent_ARD::enabled() ? 'checked' : '') . ' /> Publish the ARD manifest at <code>/.well-known/ard.json</code>, with <code>&lt;link rel="ard"&gt;</code> in page heads and an <code>Agentmap:</code> line in the virtual robots.txt</label></p>';
        if ($ard['count']) {
            echo '<p>' . (Ranatec_Agent_ARD::active() ? '<span style="color:#008a20">Published ✓</span> <a href="' . esc_url(Ranatec_Agent_ARD::url()) . '" target="_blank" rel="noopener">' . esc_html(Ranatec_Agent_ARD::url()) . '</a>' : '<strong>Switched off</strong> — the URL returns 404 and nothing is advertised.')
                . ' — ' . (int) $ard['count'] . ' entries: <code>' . implode('</code>, <code>', array_map('esc_html', $ard['identifiers'])) . '</code></p>';
        } else {
            echo '<p><strong>No manifest in this build</strong> (ARD disabled in <code>ard.config.json</code>, or no resources to publish). Rebuild with <code>tools/release.sh</code> to generate one.</p>';
        }
        submit_button('Save', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public static function handle_sync()
    {
        self::guard('ranatec_api_sync');
        $r = self::run('manual');
        $msg = !empty($r['skipped']) ? 'Sync not started: ' . $r['skipped'] . '.' : ($r['errors'] ? 'Sync finished with errors: ' . implode('; ', $r['errors']) : 'Sync complete.');
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
        $form_id = isset($_POST['cf7_form_id']) ? absint($_POST['cf7_form_id']) : 0;
        if ($form_id > 0) {
            update_option('ranatec_api_cf7_form_id', $form_id, false);
        }
        if (isset($_POST['cf7_fields'])) {
            $map = json_decode(wp_unslash($_POST['cf7_fields']), true);
            if (is_array($map) && isset($map['name'], $map['email'], $map['phone'], $map['company'], $map['message'])) {
                update_option('ranatec_api_cf7_fields', wp_json_encode(array_map('sanitize_key', array_filter($map, 'is_string'))), false);
            } else {
                $msg .= ' Field mapping NOT saved: needs JSON with name, email, phone, company and message.';
            }
        }
        update_option('ranatec_api_notify_email', empty($_POST['notify_email']) ? '0' : '1', false);
        update_option(Ranatec_Agent_ARD::OPTION_ENABLED, empty($_POST['ard_enabled']) ? '0' : '1', true);
        if (isset($_POST['quote_status'])) {
            update_option('ranatec_api_quote_status', sanitize_key(wp_unslash($_POST['quote_status'])), false);
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
                    $msg .= ' MCP server key saved — contact.json and quote.json now accept submissions only from the MCP server.';
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
