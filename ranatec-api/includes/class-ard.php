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

    public static function init()
    {
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 20, 2);
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

    /** Removes the build-time ARD references (<link rel="ard"> and the visible "ARD manifest" item) from the /agent/ page while ARD is switched off. */
    public static function strip_link($html)
    {
        $html = preg_replace('#<link rel="ard"[^>]*>\s*#i', '', (string) $html);
        return preg_replace('#<li data-ard>.*?</li>\s*#is', '', $html);
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
        return str_replace('href="' . RANATEC_API_SITE . self::PATH . '"', 'href="' . esc_url(self::url()) . '"', (string) $html);
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
