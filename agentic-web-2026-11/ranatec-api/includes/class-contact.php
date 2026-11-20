<?php
/**
 * Agent lead submissions — two separate flows, never mixed:
 *   POST /agent/v1/contact.json  (MCP tool submit_inquiry) → contact-form enquiry → Advanced CF7 DB (form 50)
 *   POST /agent/v1/quote.json    (MCP tool request_quote)  → product quote → WooCommerce order (Request a Quote)
 *
 * Safety gate (layer 3 of 3 — the MCP tool description and Zod schema are layers 1 and 2):
 * agent_context.user_authorized_submission MUST be boolean true, otherwise 403.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ranatec_Agent_Contact
{
    // Contact-form enquiry types. Product quotes are a separate flow (handle_quote / request_quote).
    const TYPES = ['technical_question', 'custom_solution', 'distributor_inquiry', 'general'];
    const CHECKOUT_LABELS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'company' => 'Company', 'phone' => 'Phone',
        'address_1' => 'Address', 'city' => 'City', 'state' => 'State/County', 'postcode' => 'Postal code',
    ];
    // Block-checkout additional field "Additional Note" (custom-checkout/additional-note, location: order).
    const CHECKOUT_NOTE_META = '_wc_other/custom-checkout/additional-note';
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
     * Contact-form enquiry (POST /agent/v1/contact.json, MCP tool submit_inquiry) — the equivalent of the
     * ranatec.com/contact-us/ form. Stored with the contact-form leads in Advanced CF7 DB, never as an order.
     * Product quote requests are a separate flow: handle_quote() / quote.json / MCP tool request_quote.
     *
     * @return array [int $status, array $body]
     */
    public static function handle($raw, $ip, $client_ip = null)
    {
        list($body, $fail) = self::decode_and_gate($raw);
        if ($fail) {
            return $fail;
        }
        $person = isset($body['person']) && is_array($body['person']) ? $body['person'] : [];
        $company = isset($body['company']) && is_array($body['company']) ? $body['company'] : [];
        $inq = isset($body['inquiry']) && is_array($body['inquiry']) ? $body['inquiry'] : [];
        $ctx = $body['agent_context'];

        // Product quotes never go through the contact form.
        $type = isset($inq['type']) && $inq['type'] !== '' ? (string) $inq['type'] : 'general';
        if ($type === 'quote_request' || !empty($inq['products'])) {
            return [400, self::err('use_request_quote', 'This is the contact-form enquiry endpoint and does not take products. To request a quote for products, use the MCP tool request_quote (endpoint ' . RANATEC_API_BASE . '/quote.json), which creates a quote order exactly like Add to RFQ + checkout on ranatec.com and needs the checkout fields (name, email, phone, company, full address).')];
        }

        $name = self::text(isset($person['name']) ? $person['name'] : '', 120);
        $email = sanitize_email(isset($person['email']) ? $person['email'] : '');
        $phone = self::text(isset($person['phone']) ? $person['phone'] : '', 40);
        $title = self::text(isset($person['job_title']) ? $person['job_title'] : '', 120);
        $cname = self::text(isset($company['name']) ? $company['name'] : '', 160);
        $country = self::text(isset($company['country']) ? $company['country'] : '', 80);
        $site = esc_url_raw(isset($company['website']) ? $company['website'] : '');
        $message = self::textarea(isset($inq['message']) ? $inq['message'] : '', 5000);
        $application = self::text(isset($inq['application']) ? $inq['application'] : '', 300);
        $timeline = self::text(isset($inq['timeline']) ? $inq['timeline'] : '', 120);
        $locale = isset($inq['preferred_locale']) && in_array($inq['preferred_locale'], ['en-US', 'en-GB', 'en-CA'], true) ? $inq['preferred_locale'] : 'en-US';

        // Required fields = the required fields of the ranatec.com contact form (Name, Phone, E-mail, Company) + a message.
        $errors = [];
        if ($name === '') $errors['person.name'] = 'required (contact form field "Name")';
        if (!$email || !is_email($email)) $errors['person.email'] = 'valid email required (contact form field "E-mail")';
        if ($phone === '') $errors['person.phone'] = 'required (contact form field "Phone Number")';
        if ($cname === '') $errors['company.name'] = 'required (contact form field "Company Name")';
        if (!in_array($type, self::TYPES, true)) $errors['inquiry.type'] = 'one of: ' . implode(', ', self::TYPES);
        if (Ranatec_Agent_API::str_len($message) < 10) $errors['inquiry.message'] = 'required (min 10 characters)';
        $errors += self::sample_data_errors($body, $email, $ctx);
        if ($errors) {
            return [400, self::err('validation_failed', 'One or more fields are invalid.', $errors)];
        }

        if (self::is_dry_run($ctx)) {
            return [200, [
                'status' => 'valid',
                'submitted' => false,
                'dry_run' => true,
                'flow' => 'contact_form',
                'type' => $type,
                'message' => 'Dry run: the enquiry is valid. NOTHING was stored or sent to Ranatec. To submit for real, the user must explicitly ask you to contact Ranatec; then call again without dry_run and with agent_context.user_request_summary.',
            ]];
        }
        if ($limited = self::rate_limited($client_ip ?: $ip)) {
            return $limited;
        }

        $lead_id = 'ranatec-lead-' . gmdate('Y') . '-' . self::rand_id();
        $labels = ['technical_question' => 'Technical question', 'custom_solution' => 'Custom solution enquiry', 'distributor_inquiry' => 'Distributor enquiry', 'general' => 'General enquiry'];
        $lines = [];
        $l = compact('lead_id', 'type', 'name', 'email', 'phone', 'title', 'cname', 'country', 'site', 'message', 'application', 'timeline', 'locale', 'lines');
        $l['label'] = $labels[$type];
        $l['agent'] = self::agent_name($ctx);
        $l['summary'] = self::text($ctx['user_request_summary'], 500);
        $l['client_ip'] = $client_ip ?: $ip;

        $stored = self::store_advanced_cf7_db($l);   // ['ok'=>bool,'entry_id'=>int|null,'detail'=>string]
        $mailed = self::notify_enabled() ? self::send_email($l, $body) : null; // bool|null (null = disabled)
        do_action('ranatec_api_contact_received', $lead_id, $type, $body, $lines, $stored['ok'] || $mailed, ['stored' => $stored, 'mailed' => $mailed]);

        if (!$stored['ok'] && !$mailed) {
            return [502, self::err('delivery_failed', 'The enquiry could not be stored or delivered (' . $stored['detail'] . '). Please email info@ranatec.com or call +46 31 706 16 60.')];
        }
        $out = [
            'status' => 'received',
            'flow' => 'contact_form',
            'lead_id' => $lead_id,
            'type' => $type,
            'stored_in' => $stored['ok'] ? 'ranatec.com contact form leads (Advanced CF7 DB, form ' . self::cf7_form_id() . ')' : null,
            'entry_id' => $stored['ok'] ? $stored['entry_id'] : null,
            'notification_email' => $mailed === null ? 'disabled' : ($mailed ? 'sent' : 'failed'),
            'message' => 'Thank you — your ' . strtolower($labels[$type]) . ' has been submitted to Ranatec AB.',
            'next_step' => 'Ranatec will reply to ' . $email . '. For urgent matters call +46 31 706 16 60 or email info@ranatec.com.',
        ];
        if (!$stored['ok']) {
            $out['note'] = 'Not stored in the lead database (' . $stored['detail'] . '); delivered by email.';
        }
        return [200, $out];
    }

    /**
     * Product quote request (POST /agent/v1/quote.json, MCP tool request_quote) — the equivalent of
     * "Add to RFQ" + the ranatec.com quote checkout. Creates a WooCommerce order (payment method
     * yith-request-a-quote); requires the same fields as that checkout. Never stored as a contact-form lead.
     *
     * @return array [int $status, array $body]
     */
    public static function handle_quote($raw, $ip, $client_ip = null)
    {
        list($body, $fail) = self::decode_and_gate($raw);
        if ($fail) {
            return $fail;
        }
        if (!function_exists('wc_create_order') || !function_exists('WC')) {
            return [503, self::err('quotes_unavailable', 'Product quotes are temporarily unavailable (WooCommerce is not active). Please email info@ranatec.com or call +46 31 706 16 60.')];
        }
        $ctx = $body['agent_context'];
        $c = isset($body['customer']) && is_array($body['customer']) ? $body['customer'] : [];
        $get = function ($k, $max) use ($c) {
            return self::text(isset($c[$k]) ? $c[$k] : '', $max);
        };
        $cust = [
            'first_name' => $get('first_name', 80),
            'last_name' => $get('last_name', 80),
            'email' => sanitize_email(isset($c['email']) ? $c['email'] : ''),
            'phone' => $get('phone', 40),
            'company' => $get('company', 160),
            'country' => '',
            'address_1' => $get('address_1', 200),
            'address_2' => $get('address_2', 200),
            'city' => $get('city', 100),
            'state' => '',
            'postcode' => $get('postcode', 20),
        ];
        $note = self::textarea(isset($body['note']) ? $body['note'] : '', 2000);
        $locale = isset($body['preferred_locale']) && in_array($body['preferred_locale'], ['en-US', 'en-GB', 'en-CA'], true) ? $body['preferred_locale'] : 'en-US';

        // Required fields = the required fields of the ranatec.com quote checkout.
        $errors = [];
        $labels = self::CHECKOUT_LABELS;
        foreach (['first_name', 'last_name', 'company', 'phone', 'address_1', 'city'] as $k) {
            if ($cust[$k] === '') $errors["customer.$k"] = "required (checkout field \"{$labels[$k]}\")";
        }
        if (!$cust['email'] || !is_email($cust['email'])) $errors['customer.email'] = 'valid email required (checkout field "Email address")';
        $cc = self::country_code(isset($c['country']) ? (string) $c['country'] : '');
        if ($cc === '') {
            $errors['customer.country'] = 'required: ISO 3166-1 alpha-2 country code (e.g. SE, DE, US, GB) of a country Ranatec sells to (checkout field "Country/Region")';
        } else {
            $cust['country'] = $cc;
            $req = self::country_required($cc);
            $states = WC()->countries->get_states($cc);
            $state_in = $get('state', 100);
            if (is_array($states) && $states) {
                $code = self::state_code($states, $state_in);
                if ($code !== '') {
                    $cust['state'] = $code;
                } elseif ($state_in !== '' || $req['state']) {
                    $errors['customer.state'] = ($state_in === '' ? 'required for ' . $cc : "unknown state '{$state_in}' for {$cc}") . ' (checkout field "State/County"); use one of: ' . implode(', ', array_slice(array_keys($states), 0, 80));
                }
            } elseif ($req['state'] && $state_in === '') {
                $errors['customer.state'] = 'required for ' . $cc . ' (checkout field "State/County")';
            } else {
                $cust['state'] = $state_in;
            }
            if ($req['postcode'] && $cust['postcode'] === '') {
                $errors['customer.postcode'] = 'required (checkout field "Postal code")';
            }
        }

        $req_products = isset($body['products']) && is_array($body['products']) ? array_slice($body['products'], 0, 50) : [];
        $lines = self::parse_products($req_products, $errors, 'products');
        if (!$lines && !preg_grep('/^products\[/', array_keys($errors))) {
            $errors['products'] = 'at least one product is required (ids from https://ranatec.com/agent/v1/products.json). For a question without products, use the contact enquiry (MCP tool submit_inquiry).';
        }
        $errors += self::sample_data_errors($body, $cust['email'], $ctx);
        if ($errors) {
            return [400, self::err('validation_failed', 'One or more fields are invalid. The quote needs the same fields as the ranatec.com checkout — ask the user for any that are missing.', $errors)];
        }
        // Every product must exist in the shop, so a quote never ends up half-built.
        list($items, $missing) = self::resolve_wc_items($lines);
        if ($missing) {
            return [409, self::err('product_not_in_shop', "'{$missing}' is not available in the ranatec.com shop. Please email info@ranatec.com for this product.")];
        }

        if (self::is_dry_run($ctx)) {
            return [200, [
                'status' => 'valid',
                'submitted' => false,
                'dry_run' => true,
                'flow' => 'product_quote',
                'customer' => $cust,
                'products' => $lines,
                'message' => 'Dry run: the quote request is valid. NOTHING was stored or sent to Ranatec. To submit for real, the user must explicitly ask you to request this quote; then call again without dry_run and with agent_context.user_request_summary.',
            ]];
        }
        if ($limited = self::rate_limited($client_ip ?: $ip)) {
            return $limited;
        }

        $lead_id = 'ranatec-rfq-' . gmdate('Y') . '-' . self::rand_id();
        $q = [
            'lead_id' => $lead_id, 'customer' => $cust, 'note' => $note, 'locale' => $locale, 'lines' => $lines,
            'agent' => self::agent_name($ctx), 'summary' => self::text($ctx['user_request_summary'], 500),
        ];
        $order = self::create_wc_quote_order($q, $items);
        if (!$order['ok']) {
            do_action('ranatec_api_quote_failed', $lead_id, $body, $order['detail']);
            return [502, self::err('quote_failed', 'The quote request could not be created (' . $order['detail'] . '). Nothing was submitted. Please email info@ranatec.com or call +46 31 706 16 60.')];
        }
        $mailed = self::notify_enabled() ? self::send_quote_email($q, $order) : null;
        return [200, [
            'status' => 'received',
            'flow' => 'product_quote',
            'quote_id' => $lead_id,
            'order_id' => $order['order_id'],
            'order_number' => $order['order_number'],
            'products' => $lines,
            'stored_in' => 'WooCommerce order #' . $order['order_number'] . ' (status: ' . $order['status_label'] . ')',
            'notification_email' => $mailed === null ? 'disabled' : ($mailed ? 'sent' : 'failed'),
            'message' => 'Thank you — your quote request has been submitted to Ranatec AB.',
            'next_step' => 'Ranatec will send the quote to ' . $cust['email'] . '. For urgent matters call +46 31 706 16 60 or email info@ranatec.com.',
        ]];
    }

    // ------------------------------------------------------------------ shared checks

    /** @return array [array|null $body, array|null $failure] */
    private static function decode_and_gate($raw)
    {
        if (strlen((string) $raw) > self::MAX_BODY_BYTES) {
            return [null, [413, self::err('payload_too_large', 'Request body must be under 20 KB.')]];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return [null, [400, self::err('invalid_json', 'Request body must be a JSON object. See https://ranatec.com/openapi.json')]];
        }
        // Consent gate.
        if ((isset($body['agent_context']['user_authorized_submission']) ? $body['agent_context']['user_authorized_submission'] : null) !== true) {
            return [null, [403, self::err('not_authorized', 'agent_context.user_authorized_submission must be true. Only submit after the user has explicitly confirmed that this request, including their contact details, may be sent to Ranatec.')]];
        }
        return [$body, null];
    }

    private static function is_dry_run(array $ctx)
    {
        return isset($ctx['dry_run']) && $ctx['dry_run'] === true;
    }

    /** Guards against test / sample submissions (e.g. an agent "trying out" the endpoint while reviewing the site). */
    private static function sample_data_errors(array $body, $email, array $ctx)
    {
        $errors = [];
        $vals = $body;
        unset($vals['agent_context']);
        if (preg_match('/<[a-z][^<>]{0,60}>/i', (string) json_encode($vals))) {
            $errors['placeholders'] = 'template placeholders such as <full name> were not replaced';
        }
        if ($email && preg_match('/@([a-z0-9-]+\.)*(example\.(com|org|net)|[a-z0-9-]+\.(test|invalid|example|localhost))$/i', $email)) {
            $errors['email'] = 'example/test addresses are not accepted — real submissions need the user\'s real email (to test the endpoint, set agent_context.dry_run = true)';
        }
        $user_request = isset($ctx['user_request_summary']) ? self::text($ctx['user_request_summary'], 500) : '';
        if (!self::is_dry_run($ctx) && Ranatec_Agent_API::str_len($user_request) < 10) {
            $errors['agent_context.user_request_summary'] = 'required: describe what the user asked you to send to Ranatec (min 10 characters). Only submit when the user explicitly asked to contact Ranatec or request a quote — reviewing or testing the website is not a reason to submit; use agent_context.dry_run = true to test.';
        }
        return $errors;
    }

    private static function rate_limited($client_ip)
    {
        $key = 'ranatec_api_rl_' . md5($client_ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::RATE_LIMIT) {
            return [429, self::err('rate_limited', 'Too many submissions from this address. Try again later or email info@ranatec.com.')];
        }
        set_transient($key, $hits + 1, self::RATE_WINDOW);
        return null;
    }

    private static function rand_id()
    {
        return strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    private static function agent_name(array $ctx)
    {
        return self::text(isset($ctx['agent_name']) ? $ctx['agent_name'] : 'unspecified', 120);
    }

    /** Validates product lines (and optional per-unit configuration) against products.json. */
    private static function parse_products(array $req_products, array &$errors, $path)
    {
        $lines = [];
        $known = [];
        foreach (Ranatec_Agent_API::load('products')['products'] as $p) {
            $known[$p['id']] = $p;
        }
        foreach ($req_products as $i => $rp) {
            $pid = is_array($rp) && isset($rp['id']) ? sanitize_title($rp['id']) : '';
            $qty = is_array($rp) && isset($rp['quantity']) ? (int) $rp['quantity'] : 1;
            if (!isset($known[$pid])) {
                $errors["{$path}[$i].id"] = "unknown product id '{$pid}' — use ids from https://ranatec.com/agent/v1/products.json";
                continue;
            }
            if ($qty < 1 || $qty > 10000) {
                $errors["{$path}[$i].quantity"] = 'integer 1–10000';
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
                    $errors["{$path}[$i].configuration"] = "{$known[$pid]['name']} has no configurable options";
                } else {
                    $line['configuration'] = [];
                    foreach (array_slice($rp['configuration'], 0, 30) as $j => $opt) {
                        $oid = is_array($opt) && isset($opt['id']) ? sanitize_title($opt['id']) : '';
                        $oq = is_array($opt) && isset($opt['quantity']) ? (int) $opt['quantity'] : 1;
                        if (!isset($allowed[$oid])) {
                            $errors["{$path}[$i].configuration[$j].id"] = "'{$oid}' is not an option of {$known[$pid]['name']}; allowed: " . implode(', ', array_keys($allowed));
                        } elseif ($oq < 0 || $oq > 100) {
                            $errors["{$path}[$i].configuration[$j].quantity"] = 'integer 0–100 per unit';
                        } elseif ($oq > 0) {
                            $line['configuration'][] = ['id' => $oid, 'name' => $allowed[$oid], 'quantity_per_unit' => $oq];
                        }
                    }
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Country (code or name) → ISO code among the countries the shop sells to; '' if unknown. */
    private static function country_code($in)
    {
        $in = trim($in);
        if ($in === '') {
            return '';
        }
        $countries = WC()->countries->get_allowed_countries();
        $aliases = ['USA' => 'US', 'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US', 'UK' => 'GB', 'UNITED KINGDOM' => 'GB', 'GREAT BRITAIN' => 'GB', 'ENGLAND' => 'GB', 'SCOTLAND' => 'GB', 'WALES' => 'GB'];
        $up = strtoupper($in);
        if (isset($aliases[$up])) {
            $up = $aliases[$up];
        }
        if (isset($countries[$up])) {
            return $up;
        }
        foreach ($countries as $code => $label) {
            $plain = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', html_entity_decode((string) $label, ENT_QUOTES, 'UTF-8')));
            if (strcasecmp($plain, $in) === 0 || strcasecmp((string) $label, $in) === 0) {
                return $code;
            }
        }
        return '';
    }

    /** Whether State/County and Postal code are required for a country (checkout default: required; WooCommerce locale may relax). */
    private static function country_required($cc)
    {
        $req = ['state' => true, 'postcode' => true];
        $locale = WC()->countries->get_country_locale();
        foreach (array_keys($req) as $f) {
            if (isset($locale[$cc][$f])) {
                $l = $locale[$cc][$f];
                if (!empty($l['hidden']) || (isset($l['required']) && !$l['required'])) {
                    $req[$f] = false;
                }
            }
        }
        $states = WC()->countries->get_states($cc);
        if (is_array($states) && !$states) {
            $req['state'] = false;   // country without states
        }
        return $req;
    }

    private static function state_code(array $states, $in)
    {
        if ($in === '') {
            return '';
        }
        foreach ($states as $code => $label) {
            if (strcasecmp((string) $code, $in) === 0 || strcasecmp(html_entity_decode((string) $label, ENT_QUOTES, 'UTF-8'), $in) === 0) {
                return (string) $code;
            }
        }
        return '';
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

    /** One readable message for the contact form's message field. */
    private static function compose_message(array $l)
    {
        $m = ["[{$l['label']} via AI agent — lead {$l['lead_id']}]", '', $l['message']];
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
     * Resolves every product and configured option to its WooCommerce product.
     * @return array [array $items, string|null $missing]
     */
    private static function resolve_wc_items(array $lines)
    {
        $items = [];
        foreach ($lines as $line) {
            $product = self::wc_product_by_slug($line['id']);
            if (!$product) {
                return [[], $line['name']];
            }
            $opts = [];
            foreach (isset($line['configuration']) ? $line['configuration'] : [] as $o) {
                $op = self::wc_product_by_slug($o['id']);
                if (!$op) {
                    return [[], $o['name']];
                }
                $opts[] = [$op, $o];
            }
            $items[] = [$product, $line, $opts];
        }
        return [$items, null];
    }

    /**
     * Creates the WooCommerce order for a product quote, like the ranatec.com Request a Quote checkout:
     * billing (and shipping) address = the checkout fields, one line per product (configured options as their
     * own lines with "Addon/Accessory for"), "Additional Note" checkout field + customer note = the user's note,
     * payment method yith-request-a-quote, private order note = AI-agent origin + quote id. Prices stay as set in
     * WooCommerce; Ranatec prices the quote in the order as usual.
     */
    private static function create_wc_quote_order(array $q, array $items)
    {
        $res = ['ok' => false, 'order_id' => null, 'order_number' => null, 'status' => '', 'status_label' => '', 'detail' => ''];
        try {
            $order = wc_create_order(['created_via' => 'ranatec-agent-api', 'status' => self::quote_order_status()]);
            if (is_wp_error($order)) {
                $res['detail'] = $order->get_error_message();
                return $res;
            }
            $c = $q['customer'];
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'] as $f) {
                $order->{"set_billing_$f"}($c[$f]);
                $order->{"set_shipping_$f"}($c[$f]);
            }
            $order->set_billing_email($c['email']);
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
            $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
            $order->set_payment_method(self::QUOTE_PAYMENT_METHOD);
            $order->set_payment_method_title(isset($gateways[self::QUOTE_PAYMENT_METHOD]) ? $gateways[self::QUOTE_PAYMENT_METHOD]->get_title() : 'Request a Quote');
            if ($q['note'] !== '') {
                $order->update_meta_data(self::CHECKOUT_NOTE_META, $q['note']);
                $order->set_customer_note($q['note']);
            }
            $order->update_meta_data('_ranatec_agent_lead_id', $q['lead_id']);
            $order->update_meta_data('_ranatec_agent_name', $q['agent']);
            $order->calculate_totals(false);
            $order->save();
            $order->add_order_note('Quote request created by AI agent "' . $q['agent'] . '" via the Ranatec MCP tool request_quote, on behalf of the customer with their explicit consent. Quote ID: ' . $q['lead_id'] . '. User request (agent summary): ' . rtrim($q['summary'], '.') . '. Preferred site: ' . $q['locale'] . '.');
            do_action('ranatec_api_quote_order_created', $order->get_id(), $q);
            $labels = wc_get_order_statuses();
            $res = ['ok' => true, 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'status' => $order->get_status(),
                    'status_label' => isset($labels['wc-' . $order->get_status()]) ? $labels['wc-' . $order->get_status()] : $order->get_status(), 'detail' => ''];
        } catch (Throwable $e) {
            $res['detail'] = $e->getMessage();
        }
        return $res;
    }

    /** Notification email for a product quote (can be switched off in Tools → Ranatec Agent API). */
    private static function send_quote_email(array $q, array $order)
    {
        $c = $q['customer'];
        $name = trim($c['first_name'] . ' ' . $c['last_name']);
        $recipient = apply_filters('ranatec_api_contact_recipient', get_option('ranatec_api_contact_recipient', self::DEFAULT_RECIPIENT), 'quote_request', $q);
        $subject = sprintf('[Agent quote request] %s — %s (order #%s, %s)', $c['company'], $name, $order['order_number'], $q['lead_id']);
        $t = ["New quote request via AI agent — WooCommerce order #{$order['order_number']} ({$order['status_label']})", ''];
        $t[] = "Name:     {$name}";
        $t[] = "Email:    {$c['email']}";
        $t[] = "Phone:    {$c['phone']}";
        $t[] = "Company:  {$c['company']}";
        $t[] = 'Address:  ' . implode(', ', array_filter([$c['address_1'], $c['address_2'], trim($c['postcode'] . ' ' . $c['city']), $c['state'], $c['country']]));
        $t[] = '';
        $t[] = 'Products:';
        foreach ($q['lines'] as $p) {
            $t[] = "- {$p['quantity']} x {$p['name']}";
            foreach (isset($p['configuration']) ? $p['configuration'] : [] as $o) {
                $t[] = "    each unit with {$o['quantity_per_unit']} x {$o['name']}";
            }
        }
        if ($q['note'] !== '') {
            $t[] = '';
            $t[] = "Additional note: {$q['note']}";
        }
        $t[] = '';
        $t[] = '---';
        $t[] = "Submitted by AI agent \"{$q['agent']}\" with the customer's explicit consent. User request (agent summary): {$q['summary']}";
        $headers = ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . str_replace(['<', '>', ','], '', $name) . " <{$c['email']}>"];
        return (bool) wp_mail($recipient, $subject, implode("\n", $t), $headers);
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
