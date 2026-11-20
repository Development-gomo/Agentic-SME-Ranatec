<?php
/**
 * Fast agent files: writes llms.txt, llms-full.txt, ai.txt, api-catalog.json and .well-known/api-catalog into the
 * web root, so LiteSpeed serves them directly instead of booting WordPress for every request (~1.5 s → static).
 *
 * - Content: exactly what the plugin itself would serve (Ranatec_Agent_API::render_static, with the live product
 *   numbers), rewritten after every sync (daily cron, "Sync now", plugin upgrade) and on demand from the Tools page.
 * - Headers: .htaccess rules (between "# BEGIN/END Ranatec Agent API" markers) set Content-Type with charset,
 *   Cache-Control public, max-age=3600, Access-Control-Allow-Origin * and nosniff — the same headers PHP sends.
 * - Safety: after writing, every URL is fetched over HTTP. A file whose content or headers don't come back exactly
 *   right is deleted again, so that URL keeps being served by PHP: the result is fast, or unchanged, never wrong.
 * - Deactivating the plugin or switching this off removes the files and the rules.
 *
 * Switch: Tools → Ranatec Agent API → "Serve the agent files as static files" (option ranatec_api_static_enabled).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ranatec_Agent_Static_Files
{
    const OPTION_ENABLED = 'ranatec_api_static_enabled';
    const OPTION_STATUS = 'ranatec_api_static_status';
    const MARKER = 'Ranatec Agent API';

    /** web-root path => [plugin endpoint, Content-Type] */
    const FILES = [
        'llms.txt' => ['llms.txt', 'text/plain; charset=utf-8'],
        'llms-full.txt' => ['llms-full.txt', 'text/plain; charset=utf-8'],
        'ai.txt' => ['ai.txt', 'text/plain; charset=utf-8'],
        'api-catalog.json' => ['api-catalog.json', 'application/json; charset=utf-8'],
        '.well-known/api-catalog' => ['well-known-api-catalog', 'application/linkset+json; charset=utf-8'],
    ];

    public static function enabled()
    {
        return get_option(self::OPTION_ENABLED, '1') !== '0';
    }

    public static function root()
    {
        return rtrim(ABSPATH, '/') . '/';
    }

    /** The .htaccess blocks: [file => rule lines]. */
    public static function rules()
    {
        $headers = [
            '<IfModule mod_headers.c>',
            'Header set Access-Control-Allow-Origin "*"',
            'Header set Cache-Control "public, max-age=3600"',
            'Header set X-Content-Type-Options "nosniff"',
            '</IfModule>',
        ];
        return [
            self::root() . '.htaccess' => array_merge(
                ['# Agent files written by the Ranatec Agent API plugin: same headers as when the plugin serves them.',
                 '<FilesMatch "^(llms|llms-full|ai)\.txt$">', 'ForceType "text/plain; charset=utf-8"'], $headers, ['</FilesMatch>',
                 '<FilesMatch "^api-catalog\.json$">', 'ForceType "application/json; charset=utf-8"'], $headers, ['</FilesMatch>']),
            self::root() . '.well-known/.htaccess' => array_merge(
                ['<Files "api-catalog">', 'ForceType "application/linkset+json; charset=utf-8"'], $headers, ['</Files>']),
        ];
    }

    private static function load_misc()
    {
        if (!function_exists('insert_with_markers') && is_readable(ABSPATH . 'wp-admin/includes/misc.php')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        return function_exists('insert_with_markers');
    }

    private static function write_atomic($path, $body)
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $body) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Writes every file and the header rules, then checks each URL over HTTP and removes any file that does not
     * come back exactly right. Returns and stores the status per file.
     */
    public static function publish()
    {
        if (!self::enabled()) {
            self::remove();
            return self::store(['state' => 'off']);
        }
        $status = ['time' => gmdate('c'), 'files' => []];
        if (!self::load_misc()) {
            return self::store($status + ['state' => 'error', 'error' => 'insert_with_markers() is unavailable']);
        }
        if (!is_dir(self::root() . '.well-known')) {
            @mkdir(self::root() . '.well-known', 0755);
        }
        foreach (self::rules() as $file => $lines) {
            if (!insert_with_markers($file, self::MARKER, $lines)) {
                $status['rules_error'][] = basename(dirname($file)) . '/' . basename($file) . ' is not writable';
            }
        }
        foreach (self::FILES as $rel => $def) {
            $body = Ranatec_Agent_API::render_static($def[0]);
            if ($body === null || !self::write_atomic(self::root() . $rel, $body)) {
                $status['files'][$rel] = ['state' => 'php', 'reason' => 'could not write the file (permissions?)'];
                continue;
            }
            $status['files'][$rel] = self::verify($rel, $body, $def[1]);
            if ($status['files'][$rel]['state'] !== 'static') {
                @unlink(self::root() . $rel);   // wrong headers or content: let PHP serve this URL again
            }
        }
        $status['state'] = 'done';
        return self::store($status);
    }

    /** Fetches the URL and compares body and headers with what PHP would send. */
    public static function verify($rel, $body, $type)
    {
        $url = home_url('/' . $rel) . '?ranatec-static-check=' . time();
        $r = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);
        if (is_wp_error($r)) {
            return ['state' => 'php', 'reason' => 'the site could not fetch its own URL: ' . $r->get_error_message()];
        }
        $got_type = strtolower(str_replace(' ', '', (string) wp_remote_retrieve_header($r, 'content-type')));
        $problems = [];
        if ((int) wp_remote_retrieve_response_code($r) !== 200) {
            $problems[] = 'HTTP ' . wp_remote_retrieve_response_code($r);
        }
        if (md5((string) wp_remote_retrieve_body($r)) !== md5($body)) {
            $problems[] = 'different content';
        }
        if ($got_type !== strtolower(str_replace(' ', '', $type))) {
            $problems[] = 'Content-Type "' . wp_remote_retrieve_header($r, 'content-type') . '"';
        }
        if (trim((string) wp_remote_retrieve_header($r, 'access-control-allow-origin')) !== '*') {
            $problems[] = 'no Access-Control-Allow-Origin: *';
        }
        if (stripos((string) wp_remote_retrieve_header($r, 'cache-control'), 'max-age=3600') === false) {
            $problems[] = 'Cache-Control "' . wp_remote_retrieve_header($r, 'cache-control') . '"';
        }
        return $problems ? ['state' => 'php', 'reason' => implode(', ', $problems)] : ['state' => 'static'];
    }

    /** Removes the files this plugin wrote and its .htaccess blocks (deactivation, switch off). */
    public static function remove()
    {
        foreach (array_keys(self::FILES) as $rel) {
            $path = self::root() . $rel;
            $def = self::FILES[$rel];
            // Only files that are a copy of this plugin's output (never someone else's file with the same name).
            if (is_file($path) && self::is_ours($path, $def[0])) {
                @unlink($path);
            }
        }
        if (self::load_misc()) {
            foreach (array_keys(self::rules()) as $file) {
                if (is_file($file)) {
                    insert_with_markers($file, self::MARKER, []);
                }
            }
        }
    }

    private static function is_ours($path, $endpoint)
    {
        $status = get_option(self::OPTION_STATUS, []);
        $known = isset($status['files']) ? array_keys($status['files']) : [];
        $body = Ranatec_Agent_API::render_static($endpoint);
        return in_array(substr($path, strlen(self::root())), $known, true) || ($body !== null && md5_file($path) === md5($body));
    }

    private static function store(array $status)
    {
        update_option(self::OPTION_STATUS, $status, false);
        return $status;
    }

    public static function status()
    {
        $s = get_option(self::OPTION_STATUS, []);
        return is_array($s) ? $s : [];
    }
}
