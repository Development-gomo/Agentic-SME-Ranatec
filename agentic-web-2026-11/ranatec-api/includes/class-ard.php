<?php
/**
 * Agentic Resource Discovery (ARD v0.91) — WordPress adapter.
 *
 * The manifest itself is generated at build time by tools/ard.py (via tools/build_static.py) into
 * public/ard.json from the package's existing metadata. This class only publishes it:
 *   - GET /.well-known/ard.json            (rewrite rule + route in ranatec-api.php)
 *   - <link rel="ard"> in every page head  (and in the static /agent/ page)
 *   - "Agentmap:" line in WordPress' virtual robots.txt
 *   - the "ard_manifest" discovery link in /agent/v1/index.json
 * Switch: Tools → Ranatec Agent API → "Publish ARD manifest" (option ranatec_api_ard_enabled, default on).
 * When the switch is off — or the build produced no manifest — every one of these disappears.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ranatec_Agent_ARD
{
    const OPTION_ENABLED = 'ranatec_api_ard_enabled';
    const FILE = 'ard.json';
    const PATH = '/.well-known/ard.json';

    /**
     * Physical copies in the web root override the plugin (the web server serves them without asking WordPress).
     * Maps the public path to the plugin's own copy, so stale manual uploads can be detected.
     */
    const OVERRIDABLE = [
        '.well-known/ard.json' => 'public/ard.json',
        '.well-known/api-catalog' => 'public/well-known-api-catalog.json',
        'llms.txt' => 'public/llms.txt',
        'llms-full.txt' => 'public/llms-full.txt',
        'ai.txt' => 'public/ai.txt',
        'api-catalog.json' => 'public/api-catalog.json',
        'openapi.json' => 'openapi.json',
    ];

    public static function init()
    {
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 20, 2);
        add_action('admin_notices', [__CLASS__, 'stale_copy_notice']);
    }

    /** @return array<string,string> public path => 'current' | 'outdated' for every physical copy found in the web root. */
    public static function static_copies()
    {
        $out = [];
        $managed = class_exists('Ranatec_Agent_Static_Files') ? Ranatec_Agent_Static_Files::status() : [];
        foreach (self::OVERRIDABLE as $path => $own) {
            $copy = ABSPATH . $path;
            $mine = RANATEC_API_DIR . $own;
            if (!is_file($copy) || !is_readable($mine) || (($managed['files'][$path]['state'] ?? '') === 'static')) {
                continue;   // missing, or written by the plugin itself (fast agent files)
            }
            $endpoint = class_exists('Ranatec_Agent_Static_Files') && isset(Ranatec_Agent_Static_Files::FILES[$path]) ? Ranatec_Agent_Static_Files::FILES[$path][0] : null;
            $served = $endpoint ? Ranatec_Agent_API::render_static($endpoint) : null;
            $out[$path] = md5_file($copy) === ($served !== null ? md5($served) : md5_file($mine)) ? 'current' : 'outdated';
        }
        return $out;
    }

    /** Warns administrators when a manually uploaded copy no longer matches the plugin's version. */
    public static function stale_copy_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $old = array_keys(array_filter(self::static_copies(), function ($s) { return $s === 'outdated'; }));
        if ($old) {
            echo '<div class="notice notice-warning"><p><strong>Ranatec Agent API:</strong> these files were uploaded to the web root by hand and are older than the plugin (' . esc_html(RANATEC_API_VERSION) . '): <code>'
                . implode('</code>, <code>', array_map('esc_html', $old)) . '</code>. The web server serves them instead of the plugin. Replace them with the copies in the package\'s <code>web-root/</code> folder, or delete them so the plugin serves the current version.</p></div>';
        }
    }

    /** The generated manifest file (absent when ARD was disabled at build time or nothing could be published). */
    public static function file()
    {
        return RANATEC_API_PUBLIC . self::FILE;
    }

    public static function enabled()
    {
        return get_option(self::OPTION_ENABLED, '1') !== '0';
    }

    /** Published = switched on AND a generated manifest exists. Only then is anything advertised. */
    public static function active()
    {
        return self::enabled() && is_readable(self::file());
    }

    /**
     * Absolute URL of the manifest on THIS site. Uses the raw "home" option (not home_url(), which language
     * plugins such as WPML rewrite to /en-gb/ etc.), so staging and production each advertise their own copy.
     */
    public static function url()
    {
        $home = (string) get_option('home', '');
        $home = $home !== '' ? untrailingslashit($home) : RANATEC_API_SITE;
        return $home . self::PATH;
    }

    public static function head_link()
    {
        if (self::active()) {
            echo '<link rel="ard" type="application/json" href="' . esc_url(self::url()) . "\" />\n";
        }
    }

    /** Adds "Agentmap: <manifest>" to WordPress' virtual robots.txt (a physical robots.txt is edited by hand). */
    public static function robots_txt($output, $public = '1')
    {
        if (!self::active() || (string) $public === '0' || stripos((string) $output, 'Agentmap:') !== false) {
            return $output;
        }
        return rtrim((string) $output) . "\n\n# Agentic Resource Discovery (ARD)\nAgentmap: " . self::url() . "\n";
    }

    /** Removes the build-time ARD references (<link rel="ard">, the LLM Discovery item and the Metadata row) from the /agent/ page while ARD is switched off. */
    public static function strip_link($html)
    {
        $html = preg_replace('#<link rel="ard"[^>]*>\s*#i', '', (string) $html);
        return preg_replace(['#<li data-ard>.*?</li>\s*#is', '#<tr data-ard>.*?</tr>\s*#is'], '', $html);
    }

    /** '' when the static page can be served as built; otherwise a cache variant key for filter_page(). */
    public static function page_variant()
    {
        if (!self::active()) {
            return 'no-ard';
        }
        return self::url() === RANATEC_API_SITE . self::PATH ? '' : 'ard:' . self::url();
    }

    /** Applies page_variant(): strip the link (switched off) or point it at this site's manifest URL. */
    public static function filter_page($html)
    {
        if (!self::active()) {
            return self::strip_link($html);
        }
        $from = RANATEC_API_SITE . self::PATH;
        return str_replace(['href="' . $from . '"', '>' . $from . '<'], ['href="' . esc_url(self::url()) . '"', '>' . esc_html(self::url()) . '<'], (string) $html);
    }

    /** @return array{count:int, identifiers:string[]} for the admin status line. */
    public static function summary()
    {
        $m = is_readable(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : null;
        $ids = [];
        foreach (isset($m['entries']) && is_array($m['entries']) ? $m['entries'] : [] as $e) {
            $ids[] = isset($e['identifier']) ? (string) $e['identifier'] : '?';
        }
        return ['count' => count($ids), 'identifiers' => $ids];
    }
}
