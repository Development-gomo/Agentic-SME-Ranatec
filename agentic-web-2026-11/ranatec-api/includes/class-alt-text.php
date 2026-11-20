<?php
/**
 * Homepage alt text helper (Tools → Ranatec Agent API → "Image alt text on the homepage").
 *
 * Scans the rendered homepage for <img> elements without alt text, maps each to its Media Library attachment and
 * proposes alt text from the attachment title. Images whose file name marks them as decoration (background, shape,
 * divider, …) are proposed as decorative (empty alt). The administrator reviews and edits the proposals, then saves:
 * the text is stored as the attachment's own alt text (_wp_attachment_image_alt), so WordPress and the page builder
 * output it everywhere the image is used. Alt text that is already set is never changed.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ranatec_Agent_Alt_Text
{
    const TRANSIENT = 'ranatec_api_alt_scan';

    public static function init()
    {
        add_action('admin_post_ranatec_api_alt_scan', [__CLASS__, 'handle_scan']);
        add_action('admin_post_ranatec_api_alt_save', [__CLASS__, 'handle_save']);
    }

    /** Cleans an attachment title or file name into readable alt text; '' when it carries no meaning. */
    public static function proposal($title, $file)
    {
        $base = pathinfo((string) $file, PATHINFO_FILENAME);
        $name = strtolower($base);
        if (preg_match('/(^|[-_ .])(bg|background|shape|pattern|divider|separator|spacer|overlay|gradient|texture|dots?|swoosh|blob|wave)([-_ .]|\d|$)/', $name)) {
            return '';   // decorative
        }
        $t = trim((string) $title) !== '' ? (string) $title : $base;
        $t = preg_replace('/-(\d+x\d+|scaled|e\d{10,})$/i', '', $t);
        $t = trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $t)));
        // export leftovers in this site's file names: "Main image Menu Product page Cart etc.", "webb", camera dates
        $t = preg_replace('/^main image menu product page cart etc\.?\s*/i', '', $t);
        $t = trim(preg_replace(['/\b(webb|web|studio|scaled|final|copy)\b/i', '/\b(ranatec\s+)?\d{8}(\s+\d{1,3})*\b/i', '/\s+/'], ['', '', ' '], $t));
        if (preg_match('/^(ranatec)?$/i', $t)) {
            return '';
        }
        if ($t === '' || preg_match('/^(img|image|dsc|dscn|photo|screenshot|pxl)?\s*[\d\s]+$/i', $t) || preg_match('/^[a-f0-9]{16,}$/i', $t)) {
            return '';   // camera numbers, hashes: no meaning to describe
        }
        return function_exists('mb_substr') ? mb_substr($t, 0, 125) : substr($t, 0, 125);
    }

    /** Finds the attachment behind an <img>: the wp-image-N class, then the URL without size suffixes. */
    public static function attachment_id($src, $class)
    {
        if (preg_match('/\bwp-image-(\d+)\b/', (string) $class, $m)) {
            return (int) $m[1];
        }
        $url = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', strtok((string) $src, '?'));
        $id = attachment_url_to_postid($url);
        if (!$id) {
            $id = attachment_url_to_postid(preg_replace('/(\.[a-z0-9]+)$/i', '-scaled$1', $url));
        }
        return (int) $id;
    }

    /** URL (any locale) => product or category name, from the same data files the API serves. */
    public static function link_names()
    {
        $names = [];
        $norm = function ($u) { return rtrim(strtok((string) $u, '?#'), '/') . '/'; };
        foreach (['products' => 'name', 'categories' => 'name'] as $f => $k) {
            foreach (Ranatec_Agent_API::load($f)[$f] as $x) {
                foreach (array_merge([isset($x['url']) ? $x['url'] : ''], isset($x['urls']) ? array_values((array) $x['urls']) : []) as $u) {
                    if ($u) {
                        $names[$norm($u)] = $x[$k];
                    }
                }
            }
        }
        return $names;
    }

    /** Scans the given HTML: [attachment id or src => ['src', 'id', 'current', 'proposal', 'decorative', 'source']]. */
    public static function scan_html($html)
    {
        $out = [];
        $html = (string) $html;
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        $names = self::link_names();
        foreach ($tags[0] as $hit) {
            list($tag, $pos) = $hit;
            // The link the image sits in: product tiles name the product they open.
            $before = substr($html, max(0, $pos - 3000), min(3000, $pos));
            $a = strrpos($before, '<a ');
            $linked = null;
            if ($a !== false && ($c = strrpos($before, '</a>')) === false || ($a !== false && $a > $c)) {
                if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', substr($before, $a), $m)) {
                    $key = rtrim(strtok(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), '?#'), '/') . '/';
                    $linked = isset($names[$key]) ? $names[$key] : null;
                }
            }
            $attr = function ($n) use ($tag) {
                return preg_match('/\s' . $n . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m) ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : null;
            };
            $alt = $attr('alt');
            if ($alt !== null && trim($alt) !== '') {
                continue;   // has alt text
            }
            $src = (string) ($attr('src') ?: $attr('data-orig-src') ?: $attr('data-src'));
            if ($src === '' || strpos($src, 'data:') === 0) {
                $src = (string) ($attr('data-orig-src') ?: $attr('data-src'));
            }
            if ($src === '') {
                continue;
            }
            $id = self::attachment_id($src, $attr('class'));
            $key = $id ? 'id:' . $id : 'src:' . $src;
            if (isset($out[$key])) {
                continue;
            }
            $current = $id ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '';
            $prop = $linked !== null ? $linked : ($id ? self::proposal(get_the_title($id), get_attached_file($id) ?: $src) : self::proposal('', $src));
            $out[$key] = [
                'src' => $src,
                'id' => $id,
                'current' => $current,
                'proposal' => $prop,
                'source' => $linked !== null ? 'linked product or category' : 'image title',
                'decorative' => $prop === '' || ($linked === null && ($attr('role') === 'presentation' || $attr('aria-hidden') === 'true')),
            ];
        }
        return $out;
    }

    public static function handle_scan()
    {
        self::guard('ranatec_api_alt_scan');
        $r = wp_remote_get(home_url('/') . '?ranatec-alt-scan=' . time(), ['timeout' => 30, 'sslverify' => false]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            $msg = 'Could not load the homepage: ' . (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r));
        } else {
            $found = self::scan_html(wp_remote_retrieve_body($r));
            set_transient(self::TRANSIENT, $found, DAY_IN_SECONDS);
            $msg = count($found) . ' homepage image(s) without alt text found — review the proposals below and save.';
        }
        wp_safe_redirect(add_query_arg('ranatec_msg', rawurlencode($msg), admin_url('tools.php?page=ranatec-agent-api')) . '#alt-text');
        exit;
    }

    public static function handle_save()
    {
        self::guard('ranatec_api_alt_save');
        $alts = isset($_POST['alt']) && is_array($_POST['alt']) ? wp_unslash($_POST['alt']) : [];
        $saved = 0;
        foreach ($alts as $id => $text) {
            $id = (int) $id;
            $text = sanitize_text_field(is_scalar($text) ? (string) $text : '');
            if ($id <= 0 || $text === '' || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
                continue;   // empty = decorative: nothing to store
            }
            if (trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)) !== '') {
                continue;   // never overwrite alt text that is already set
            }
            update_post_meta($id, '_wp_attachment_image_alt', $text);
            $saved++;
        }
        delete_transient(self::TRANSIENT);
        if (function_exists('do_action')) {
            do_action('litespeed_purge_all');   // LiteSpeed Cache: show the new alt text right away
        }
        wp_safe_redirect(add_query_arg('ranatec_msg', rawurlencode("Alt text saved for {$saved} image(s). Scan again to check the homepage."), admin_url('tools.php?page=ranatec-agent-api')) . '#alt-text');
        exit;
    }

    public static function render_admin()
    {
        echo '<h2 id="alt-text">Image alt text on the homepage</h2><p>Finds homepage images without alt text and proposes text from each image\'s Media Library title. Leave a field empty for purely decorative images (they keep <code>alt=""</code>). Saving stores the text in the Media Library (the image\'s own alt text), so it applies wherever the image is used; alt text that is already set is never changed.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ranatec_api_alt_scan');
        echo '<input type="hidden" name="action" value="ranatec_api_alt_scan" />';
        submit_button('Scan the homepage', 'secondary', 'submit', false);
        echo '</form>';
        $found = get_transient(self::TRANSIENT);
        if (!is_array($found) || !$found) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ranatec_api_alt_save');
        echo '<input type="hidden" name="action" value="ranatec_api_alt_save" />';
        echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Image</th><th>Alt text to save (empty = decorative)</th></tr></thead><tbody>';
        $outside = [];
        foreach ($found as $f) {
            if (!$f['id']) {
                $outside[] = $f['src'];
                continue;
            }
            if ($f['current'] !== '') {
                continue;   // set in the Media Library, but the page builder printed alt="" — see the note below
            }
            echo '<tr><td><img src="' . esc_url($f['src']) . '" alt="" style="max-width:120px;max-height:60px" /><br /><code>#' . (int) $f['id'] . '</code></td>'
                . '<td><input type="text" class="large-text" name="alt[' . (int) $f['id'] . ']" value="' . esc_attr($f['decorative'] ? '' : $f['proposal']) . '" placeholder="decorative (alt=&quot;&quot;)" /></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save alt text', 'primary', 'submit', false);
        echo '</form>';
        $preset = array_filter($found, function ($f) { return $f['id'] && $f['current'] !== ''; });
        if ($preset) {
            echo '<p>' . count($preset) . ' image(s) already have alt text in the Media Library, but the homepage prints <code>alt=""</code>: the page-builder element sets its own (empty) alt. Edit those elements in the page builder and clear the alt field, or type the text there.</p>';
        }
        if ($outside) {
            echo '<p>' . count($outside) . ' image(s) are not in the Media Library (set in theme options or external): <code>' . implode('</code>, <code>', array_map('esc_html', array_map('basename', array_slice($outside, 0, 20)))) . '</code>. Set their alt text in the theme options (for example Avada → Options → Header → Logo).</p>';
        }
    }

    private static function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer($action);
    }
}
