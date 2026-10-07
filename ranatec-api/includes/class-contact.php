<?php
/**
 * POST /agent/v1/contact.json — agent-submitted quote requests and enquiries.
 *
 * Safety gate (layer 3 of 3 — the MCP tool description and Zod schema are layers 1 and 2):
 * agent_context.user_authorized_submission MUST be boolean true, otherwise 403.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ranatec_Agent_Contact
{
    const TYPES = ['quote_request', 'technical_question', 'custom_solution', 'distributor_inquiry', 'general'];
    const MAX_BODY_BYTES = 20000;
    const RATE_LIMIT = 5;          // submissions …
    const RATE_WINDOW = HOUR_IN_SECONDS; // … per IP per hour
    const DEFAULT_RECIPIENT = 'info@ranatec.com';
    // Lead storage: the same backend as website leads — Advanced CF7 DB (Vsourz), under the contact form
    // on ranatec.com/contact-us/ (Contact Form 7, form ID 50), using that form's own field names.
    const DEFAULT_CF7_FORM_ID = 50;
    // Payment method of RFQ orders created by the ranatec.com checkout (YITH WooCommerce Request a Quote).
    const QUOTE_PAYMENT_METHOD = 'yith-request-a-quote';
    const DEFAULT_CF7_FIELDS = [
        'name' => 'your-name',
        'phone' => 'tel-882',
        'email' => 'your-email',
        'company' => 'text-863',
        'message' => 'your-message',
        'source_url' => 'curn-url',
    ];

    /**
     * @return array [int $status, array $body]
     */
    public static function handle($raw, $ip, $client_ip = null)
    {
        $client_ip = $client_ip ?: $ip;
        if (strlen((string) $raw) > self::MAX_BODY_BYTES) {
            return [413, self::err('payload_too_large', 'Request body must be under 20 KB.')];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return [400, self::err('invalid_json', 'Request body must be a JSON object. See https://ranatec.com/openapi.json')];
        }

        // ---- consent gate ----
        if ((isset($body['agent_context']['user_authorized_submission']) ? $body['agent_context']['user_authorized_submission'] : null) !== true) {
            return [403, self::err('not_authorized', 'agent_context.user_authorized_submission must be true. Only submit after the user has explicitly confirmed that this enquiry, including their contact details, may be sent to Ranatec.')];
        }

        // ---- honeypot (humans posting via a form builder) ----
        if (!empty($body['website_url_confirm'])) {
            return [200, ['status' => 'received', 'message' => 'Thank you.']];
        }

        $person = isset($body['person']) && is_array($body['person']) ? $body['person'] : [];
        $company = isset($body['company']) && is_array($body['company']) ? $body['company'] : [];
        $inq = isset($body['inquiry']) && is_array($body['inquiry']) ? $body['inquiry'] : [];
        $ctx = $body['agent_context'];

        $name = self::text(isset($person['name']) ? $person['name'] : '', 120);
        $email = sanitize_email(isset($person['email']) ? $person['email'] : '');
        $phone = self::text(isset($person['phone']) ? $person['phone'] : '', 40);
        $title = self::text(isset($person['job_title']) ? $person['job_title'] : '', 120);
        $cname = self::text(isset($company['name']) ? $company['name'] : '', 160);
        $country = self::text(isset($company['country']) ? $company['country'] : '', 80);
        $site = esc_url_raw(isset($company['website']) ? $company['website'] : '');
        $type = isset($inq['type']) ? (string) $inq['type'] : '';
        $message = self::textarea(isset($inq['message']) ? $inq['message'] : '', 5000);
        $application = self::text(isset($inq['application']) ? $inq['application'] : '', 300);
        $timeline = self::text(isset($inq['timeline']) ? $inq['timeline'] : '', 120);
        $locale = isset($inq['preferred_locale']) && in_array($inq['preferred_locale'], ['en-US', 'en-GB', 'en-CA'], true) ? $inq['preferred_locale'] : 'en-US';

        $errors = [];
        if ($name === '') $errors['person.name'] = 'required';
        if (!$email || !is_email($email)) $errors['person.email'] = 'valid email required';
        if ($phone === '') $errors['person.phone'] = 'required (the Ranatec contact form requires a phone number)';
        if ($cname === '') $errors['company.name'] = 'required';
        if (!in_array($type, self::TYPES, true)) $errors['inquiry.type'] = 'one of: ' . implode(', ', self::TYPES);
        if (Ranatec_Agent_API::str_len($message) < 10) $errors['inquiry.message'] = 'required (min 10 characters)';

        // ---- products (validated against products.json) ----
        $lines = [];
        $known = [];
        foreach (Ranatec_Agent_API::load('products')['products'] as $p) {
            $known[$p['id']] = $p;
        }
        $req_products = isset($inq['products']) && is_array($inq['products']) ? array_slice($inq['products'], 0, 50) : [];
        foreach ($req_products as $i => $rp) {
            $pid = is_array($rp) && isset($rp['id']) ? sanitize_title($rp['id']) : '';
            $qty = is_array($rp) && isset($rp['quantity']) ? (int) $rp['quantity'] : 1;
            if (!isset($known[$pid])) {
                $errors["inquiry.products[$i].id"] = "unknown product id '{$pid}' — use ids from https://ranatec.com/agent/v1/products.json";
                continue;
            }
            if ($qty < 1 || $qty > 10000) {
                $errors["inquiry.products[$i].quantity"] = 'integer 1–10000';
                continue;
            }
            $line = ['id' => $pid, 'name' => $known[$pid]['name'], 'quantity' => $qty, 'url' => $known[$pid]['url']];
            // Optional per-unit configuration, as with "Configure and Add to RFQ" on the product page.
            if (!empty($rp['configuration']) && is_array($rp['configuration'])) {
                $cfg = isset($known[$pid]['configurator']['options']) ? $known[$pid]['configurator']['options'] : [];
                $allowed = [];
                foreach ($cfg as $o) {
                    $allowed[$o['id']] = $o['name'];
                }
                if (!$allowed) {
                    $errors["inquiry.products[$i].configuration"] = "{$known[$pid]['name']} has no configurable options";
                } else {
                    $line['configuration'] = [];
                    foreach (array_slice($rp['configuration'], 0, 30) as $j => $opt) {
                        $oid = is_array($opt) && isset($opt['id']) ? sanitize_title($opt['id']) : '';
                        $oq = is_array($opt) && isset($opt['quantity']) ? (int) $opt['quantity'] : 1;
                        if (!isset($allowed[$oid])) {
                            $errors["inquiry.products[$i].configuration[$j].id"] = "'{$oid}' is not an option of {$known[$pid]['name']}; allowed: " . implode(', ', array_keys($allowed));
                        } elseif ($oq < 0 || $oq > 100) {
                            $errors["inquiry.products[$i].configuration[$j].quantity"] = 'integer 0–100 per unit';
                        } elseif ($oq > 0) {
                            $line['configuration'][] = ['id' => $oid, 'name' => $allowed[$oid], 'quantity_per_unit' => $oq];
                        }
                    }
                }
            }
            $lines[] = $line;
        }
        if ($type === 'quote_request' && !$lines && !isset($errors['inquiry.products[0].id'])) {
            $errors['inquiry.products'] = 'at least one product is required for a quote_request (use custom_solution for bespoke requirements)';
        }
        // ---- guard against test / sample submissions (e.g. an agent "trying out" the endpoint while reviewing the site) ----
        $dry_run = isset($ctx['dry_run']) && $ctx['dry_run'] === true;
        $raw_values = json_encode([isset($body['person']) ? $body['person'] : null, isset($body['company']) ? $body['company'] : null, isset($body['inquiry']) ? $body['inquiry'] : null]);
        if (preg_match('/<[a-z][^<>]{0,60}>/i', (string) $raw_values)) {
            $errors['placeholders'] = 'template placeholders such as <full name> were not replaced';
        }
        if ($email && preg_match('/@([a-z0-9-]+\.)*(example\.(com|org|net)|[a-z0-9-]+\.(test|invalid|example|localhost))$/i', $email)) {
            $errors['person.email'] = 'example/test addresses are not accepted — real submissions need the user\'s real email (to test the endpoint, set agent_context.dry_run = true)';
        }
        $user_request = isset($ctx['user_request_summary']) ? self::text($ctx['user_request_summary'], 500) : '';
        if (!$dry_run && Ranatec_Agent_API::str_len($user_request) < 10) {
            $errors['agent_context.user_request_summary'] = 'required: describe what the user asked you to send to Ranatec (min 10 characters). Only submit when the user explicitly asked to contact Ranatec or request a quote — reviewing or testing the website is not a reason to submit; use agent_context.dry_run = true to test.';
        }
        if ($errors) {
            return [400, self::err('validation_failed', 'One or more fields are invalid.', $errors)];
        }

        // ---- dry run: everything validated, nothing stored or sent ----
        if ($dry_run) {
            return [200, [
                'status' => 'valid',
                'submitted' => false,
                'dry_run' => true,
                'type' => $type,
                'products' => $lines,
                'message' => 'Dry run: the request is valid. NOTHING was stored or sent to Ranatec. To submit for real, the user must explicitly ask you to contact Ranatec; then call again without dry_run and with agent_context.user_request_summary.',
            ]];
        }

        // ---- rate limit ----
        $key = 'ranatec_api_rl_' . md5($client_ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::RATE_LIMIT) {
            return [429, self::err('rate_limited', 'Too many submissions from this address. Try again later or email info@ranatec.com.')];
        }
        set_transient($key, $hits + 1, self::RATE_WINDOW);

        // ---- deliver: store in the lead backend + notify ----
        $lead_id = 'ranatec-' . ($type === 'quote_request' ? 'rfq' : 'lead') . '-' . gmdate('Y') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $labels = ['quote_request' => 'Quote request', 'technical_question' => 'Technical question', 'custom_solution' => 'Custom solution enquiry', 'distributor_inquiry' => 'Distributor enquiry', 'general' => 'General enquiry'];
        $l = compact('lead_id', 'type', 'name', 'email', 'phone', 'title', 'cname', 'country', 'site', 'message', 'application', 'timeline', 'locale', 'lines');
        $l['label'] = $labels[$type];
        $l['agent'] = self::text(isset($ctx['agent_name']) ? $ctx['agent_name'] : 'unspecified', 120);
        $l['summary'] = !empty($ctx['user_request_summary']) ? self::text($ctx['user_request_summary'], 500) : '';
        $l['client_ip'] = $client_ip;

        // Product quote requests become WooCommerce orders (how ranatec.com manages quotes / YITH Request a Quote);
        // every other enquiry is stored with the contact-form leads in Advanced CF7 DB.
        $order = null;
        if ($type === 'quote_request' && $lines) {
            $order = self::create_wc_quote_order($l);   // ['ok'=>bool,'order_id'=>int|null,'status'=>string,'detail'=>string]
        }
        if ($order && $order['ok']) {
            $stored = ['ok' => true, 'entry_id' => null, 'detail' => '', 'where' => 'woocommerce'];
        } else {
            $stored = self::store_advanced_cf7_db($l);   // ['ok'=>bool,'entry_id'=>int|null,'detail'=>string]
            $stored['where'] = 'advanced_cf7_db';
        }
        $mailed = self::notify_enabled() ? self::send_email($l, $body) : null; // bool|null (null = disabled)

        do_action('ranatec_api_contact_received', $lead_id, $type, $body, $lines, $stored['ok'] || $mailed, ['stored' => $stored, 'mailed' => $mailed]);

        if (!$stored['ok'] && !$mailed) {
            return [502, self::err('delivery_failed', 'The enquiry could not be stored or delivered (' . $stored['detail'] . '). Please email info@ranatec.com or call +46 31 706 16 60.')];
        }
        $out = [
            'status' => 'received',
            'lead_id' => $lead_id,
            'type' => $type,
            'products' => $lines,
            'stored_in' => !$stored['ok'] ? null : ($stored['where'] === 'woocommerce'
                ? 'WooCommerce order #' . $order['order_number'] . ' (status: ' . $order['status_label'] . ')'
                : 'ranatec.com contact form leads (Advanced CF7 DB, form ' . self::cf7_form_id() . ')'),
            'order_id' => $stored['ok'] && $stored['where'] === 'woocommerce' ? $order['order_id'] : null,
            'entry_id' => $stored['ok'] && $stored['where'] === 'advanced_cf7_db' ? $stored['entry_id'] : null,
            'notification_email' => $mailed === null ? 'disabled' : ($mailed ? 'sent' : 'failed'),
            'message' => 'Thank you — your ' . strtolower($labels[$type]) . ' has been submitted to Ranatec AB.',
            'next_step' => 'Ranatec will reply to ' . $email . '. For urgent matters call +46 31 706 16 60 or email info@ranatec.com.',
        ];
        if ($order && !$order['ok'] && $stored['ok']) {
            $out['note'] = 'Could not create a WooCommerce order (' . $order['detail'] . '); saved with the contact-form leads instead.';
        } elseif (!$stored['ok']) {
            $out['note'] = 'Not stored in the lead database (' . $stored['detail'] . '); delivered by email.';
        }
        return [200, $out];
    }

    public static function cf7_form_id()
    {
        return (int) get_option('ranatec_api_cf7_form_id', self::DEFAULT_CF7_FORM_ID);
    }

    public static function cf7_fields()
    {
        $saved = get_option('ranatec_api_cf7_fields', '');
        $map = is_string($saved) && $saved !== '' ? json_decode($saved, true) : (is_array($saved) ? $saved : null);
        return is_array($map) ? array_merge(self::DEFAULT_CF7_FIELDS, array_filter($map, 'is_string')) : self::DEFAULT_CF7_FIELDS;
    }

    public static function notify_enabled()
    {
        return get_option('ranatec_api_notify_email', '1') !== '0';
    }

    /** Table names used by Advanced CF7 DB (Vsourz). */
    public static function acf7db_tables()
    {
        global $wpdb;
        return [$wpdb->prefix . 'cf7_vdata', $wpdb->prefix . 'cf7_vdata_entry'];
    }

    /** True when both Advanced CF7 DB tables exist. */
    public static function acf7db_available()
    {
        global $wpdb;
        foreach (self::acf7db_tables() as $t) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) !== $t) {
                return false;
            }
        }
        return true;
    }

    /** One readable message for the form's message field (the form has no product/quantity fields). */
    private static function compose_message(array $l)
    {
        $m = ["[{$l['label']} via AI agent — lead {$l['lead_id']}]", '', $l['message']];
        if ($l['lines']) {
            $m[] = '';
            $m[] = 'Products:';
            foreach ($l['lines'] as $p) {
                $m[] = "- {$p['quantity']} x {$p['name']} ({$p['url']})";
                if (!empty($p['configuration'])) {
                    $m[] = '    each unit configured with:';
                    foreach ($p['configuration'] as $o) {
                        $m[] = "      {$o['quantity_per_unit']} x {$o['name']}";
                    }
                }
            }
        }
        $extra = array_filter([
            'Job title' => $l['title'], 'Country' => $l['country'], 'Website' => $l['site'],
            'Application' => $l['application'], 'Timeline' => $l['timeline'], 'Preferred locale' => $l['locale'],
        ]);
        if ($extra) {
            $m[] = '';
            foreach ($extra as $k => $v) {
                $m[] = "{$k}: {$v}";
            }
        }
        $m[] = '';
        $m[] = '---';
        $m[] = "Submitted by AI agent \"{$l['agent']}\" on behalf of the user, with the user's explicit consent, via the Ranatec MCP tool submit_inquiry.";
        if ($l['summary']) {
            $m[] = 'User request (agent summary): ' . $l['summary'];
        }
        return implode("\n", $m);
    }

    /**
     * Saves the lead as an entry of the contact form in Advanced CF7 DB, using the form's own field names,
     * so it appears in the same backend list as website submissions. No CF7 submission is made and no
     * reCAPTCHA/spam protection is touched.
     */
    private static function store_advanced_cf7_db(array $l)
    {
        global $wpdb;
        if (!self::acf7db_available()) {
            return ['ok' => false, 'entry_id' => null, 'detail' => 'Advanced CF7 DB tables not found'];
        }
        list($data_table, $entry_table) = self::acf7db_tables();
        $form_id = self::cf7_form_id();
        $f = self::cf7_fields();
        $fields = [
            $f['name'] => $l['name'],
            $f['phone'] => $l['phone'],
            $f['email'] => $l['email'],
            $f['company'] => $l['cname'],
            $f['message'] => self::compose_message($l),
        ];
        if (!empty($f['source_url'])) {
            $fields[$f['source_url']] = 'https://ranatec.com/agent/ (AI agent via MCP submit_inquiry, lead ' . $l['lead_id'] . ')';
        }
        $fields['submit_time'] = current_time('mysql');
        $fields['submit_ip'] = $l['client_ip'];
        $fields = apply_filters('ranatec_api_acf7db_fields', $fields, $l, $form_id);

        $contact_form = function_exists('wpcf7_contact_form') ? wpcf7_contact_form($form_id) : null;
        do_action('vsz_cf7_before_insert_db', $contact_form);

        if ($wpdb->insert($data_table, ['created' => current_time('mysql')], ['%s']) === false) {
            return ['ok' => false, 'entry_id' => null, 'detail' => 'database insert failed'];
        }
        $data_id = (int) $wpdb->insert_id;
        foreach ($fields as $name => $value) {
            // Stored HTML-escaped, as Advanced CF7 DB stores form submissions.
            $ok = $wpdb->insert($entry_table, [
                'cf7_id' => $form_id,
                'data_id' => $data_id,
                'name' => htmlspecialchars((string) $name, ENT_QUOTES),
                'value' => htmlspecialchars((string) $value, ENT_QUOTES),
            ], ['%d', '%d', '%s', '%s']);
            if ($ok === false) {
                return ['ok' => false, 'entry_id' => $data_id, 'detail' => 'database insert failed for field ' . $name];
            }
        }
        do_action('vsz_cf7_after_insert_db', $contact_form, $form_id, $data_id);
        return ['ok' => true, 'entry_id' => $data_id, 'detail' => ''];
    }

    /** Order status for agent quote orders: setting, else YITH "new quote request" if registered, else pending. */
    public static function quote_order_status()
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $opt = (string) get_option('ranatec_api_quote_status', '');
        if ($opt !== '' && isset($statuses['wc-' . $opt])) {
            return $opt;
        }
        return isset($statuses['wc-ywraq-new']) ? 'ywraq-new' : 'pending';
    }

    private static function wc_product_by_slug($slug)
    {
        $post = get_page_by_path($slug, OBJECT, 'product');
        return $post ? wc_get_product($post->ID) : null;
    }

    /**
     * Creates a WooCommerce order for a product quote request, like the site's Request a Quote flow:
     * billing = customer, one line per product (configured options as their own lines with a note),
     * customer note = message, private note = AI-agent origin + lead id. Prices stay as set in WooCommerce;
     * Ranatec prices the quote in the order as usual.
     */
    private static function create_wc_quote_order(array $l)
    {
        $res = ['ok' => false, 'order_id' => null, 'order_number' => null, 'status' => '', 'status_label' => '', 'detail' => ''];
        if (!function_exists('wc_create_order') || !function_exists('wc_get_product')) {
            $res['detail'] = 'WooCommerce not active';
            return $res;
        }
        // Resolve every product first, so a missing product never leaves a half-built order.
        $items = [];
        foreach ($l['lines'] as $line) {
            $product = self::wc_product_by_slug($line['id']);
            if (!$product) {
                $res['detail'] = "product '{$line['id']}' not found in WooCommerce";
                return $res;
            }
            $opts = [];
            foreach (isset($line['configuration']) ? $line['configuration'] : [] as $o) {
                $op = self::wc_product_by_slug($o['id']);
                if (!$op) {
                    $res['detail'] = "option '{$o['id']}' not found in WooCommerce";
                    return $res;
                }
                $opts[] = [$op, $o];
            }
            $items[] = [$product, $line, $opts];
        }
        try {
            $status = self::quote_order_status();
            $order = wc_create_order(['created_via' => 'ranatec-agent-api', 'status' => $status]);
            if (is_wp_error($order)) {
                $res['detail'] = $order->get_error_message();
                return $res;
            }
            $parts = preg_split('/\s+/', trim($l['name']), 2);
            $order->set_billing_first_name($parts[0]);
            $order->set_billing_last_name(isset($parts[1]) ? $parts[1] : '');
            $order->set_billing_email($l['email']);
            $order->set_billing_phone($l['phone']);
            $order->set_billing_company($l['cname']);
            if ($l['country'] && function_exists('WC') && WC()->countries) {
                foreach (WC()->countries->get_countries() as $code => $label) {
                    if (strcasecmp($label, $l['country']) === 0 || strcasecmp($code, $l['country']) === 0) {
                        $order->set_billing_country($code);
                        break;
                    }
                }
            }
            foreach ($items as $it) {
                list($product, $line, $opts) = $it;
                $order->add_product($product, $line['quantity']);
                // Configured options as separate line items, exactly like the ranatec.com RFQ cart/checkout
                // ("Addon/Accessory for: <main product>", quantity = per-unit quantity x number of units).
                foreach ($opts as $po) {
                    list($op, $o) = $po;
                    $oid = $order->add_product($op, $o['quantity_per_unit'] * $line['quantity']);
                    if ($oid) {
                        wc_add_order_item_meta($oid, 'Addon/Accessory for', $product->get_name());
                    }
                }
            }
            // Same payment method as RFQ orders placed through the ranatec.com checkout (YITH Request a Quote).
            $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
            $order->set_payment_method(self::QUOTE_PAYMENT_METHOD);
            $order->set_payment_method_title(isset($gateways[self::QUOTE_PAYMENT_METHOD]) ? $gateways[self::QUOTE_PAYMENT_METHOD]->get_title() : 'Request a Quote');
            $order->set_customer_note(self::compose_message($l));
            $order->update_meta_data('_ranatec_agent_lead_id', $l['lead_id']);
            $order->update_meta_data('_ranatec_agent_name', $l['agent']);
            $order->calculate_totals(false);
            $order->save();
            $order->add_order_note('Quote request created by AI agent "' . $l['agent'] . '" via the Ranatec MCP tool submit_inquiry, on behalf of the customer with their explicit consent. Lead ID: ' . $l['lead_id'] . '.');
            do_action('ranatec_api_quote_order_created', $order->get_id(), $l);
            $labels = wc_get_order_statuses();
            $res = ['ok' => true, 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'status' => $order->get_status(),
                    'status_label' => isset($labels['wc-' . $order->get_status()]) ? $labels['wc-' . $order->get_status()] : $order->get_status(), 'detail' => ''];
        } catch (Throwable $e) {
            $res['detail'] = $e->getMessage();
        }
        return $res;
    }

    /** Notification email (can be switched off in Tools → Ranatec Agent API). */
    private static function send_email(array $l, array $body)
    {
        $recipient = apply_filters('ranatec_api_contact_recipient', get_option('ranatec_api_contact_recipient', self::DEFAULT_RECIPIENT), $l['type'], $body);
        $subject = sprintf('[Agent %s] %s — %s (%s)', $l['label'], $l['cname'], $l['name'], $l['lead_id']);
        $text = "Name:    {$l['name']}\nEmail:   {$l['email']}\nPhone:   {$l['phone']}\nCompany: {$l['cname']}\n\n" . self::compose_message($l);
        $headers = ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . str_replace(['<', '>', ','], '', $l['name']) . " <{$l['email']}>"];
        return (bool) wp_mail($recipient, $subject, $text, $headers);
    }

    private static function text($v, $max)
    {
        return Ranatec_Agent_API::str_sub(sanitize_text_field(is_scalar($v) ? (string) $v : ''), $max);
    }

    private static function textarea($v, $max)
    {
        return Ranatec_Agent_API::str_sub(sanitize_textarea_field(is_scalar($v) ? (string) $v : ''), $max);
    }

    private static function err($code, $message, $fields = null)
    {
        $e = ['status' => 'error', 'error' => $code, 'message' => $message];
        if ($fields) {
            $e['fields'] = $fields;
        }
        return $e;
    }
}
