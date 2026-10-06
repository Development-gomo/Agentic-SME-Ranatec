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

    /**
     * @return array [int $status, array $body]
     */
    public static function handle($raw, $ip)
    {
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
        if ($cname === '') $errors['company.name'] = 'required';
        if (!in_array($type, self::TYPES, true)) $errors['inquiry.type'] = 'one of: ' . implode(', ', self::TYPES);
        if (mb_strlen($message) < 10) $errors['inquiry.message'] = 'required (min 10 characters)';

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
            $lines[] = ['id' => $pid, 'name' => $known[$pid]['name'], 'quantity' => $qty, 'url' => $known[$pid]['url']];
        }
        if ($type === 'quote_request' && !$lines && !isset($errors['inquiry.products[0].id'])) {
            $errors['inquiry.products'] = 'at least one product is required for a quote_request (use custom_solution for bespoke requirements)';
        }
        if ($errors) {
            return [400, self::err('validation_failed', 'One or more fields are invalid.', $errors)];
        }

        // ---- rate limit ----
        $key = 'ranatec_api_rl_' . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::RATE_LIMIT) {
            return [429, self::err('rate_limited', 'Too many submissions from this address. Try again later or email info@ranatec.com.')];
        }
        set_transient($key, $hits + 1, self::RATE_WINDOW);

        // ---- deliver ----
        $lead_id = 'ranatec-' . ($type === 'quote_request' ? 'rfq' : 'lead') . '-' . gmdate('Y') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $recipient = apply_filters('ranatec_api_contact_recipient', get_option('ranatec_api_contact_recipient', self::DEFAULT_RECIPIENT), $type, $body);
        $labels = ['quote_request' => 'Quote request', 'technical_question' => 'Technical question', 'custom_solution' => 'Custom solution enquiry', 'distributor_inquiry' => 'Distributor enquiry', 'general' => 'General enquiry'];
        $subject = sprintf('[Agent %s] %s — %s (%s)', $labels[$type], $cname, $name, $lead_id);

        $m = [];
        $m[] = "{$labels[$type]} submitted by an AI agent on behalf of a user (consent confirmed by the agent).";
        $m[] = '';
        $m[] = "Lead ID:   {$lead_id}";
        $m[] = "Received:  " . gmdate('Y-m-d H:i') . ' UTC';
        $m[] = "Locale:    {$locale}";
        $m[] = '';
        $m[] = "Name:      {$name}" . ($title ? " ({$title})" : '');
        $m[] = "Email:     {$email}";
        if ($phone) $m[] = "Phone:     {$phone}";
        $m[] = "Company:   {$cname}" . ($country ? ", {$country}" : '');
        if ($site) $m[] = "Website:   {$site}";
        if ($lines) {
            $m[] = '';
            $m[] = 'Products:';
            foreach ($lines as $l) {
                $m[] = "  - {$l['quantity']} × {$l['name']}  <{$l['url']}>";
            }
        }
        if ($application) $m[] = "\nApplication: {$application}";
        if ($timeline) $m[] = "Timeline:    {$timeline}";
        $m[] = '';
        $m[] = 'Message:';
        $m[] = $message;
        $m[] = '';
        $m[] = '---';
        $m[] = 'Agent: ' . self::text(isset($ctx['agent_name']) ? $ctx['agent_name'] : 'unspecified', 120);
        if (!empty($ctx['user_request_summary'])) $m[] = 'User request (agent summary): ' . self::text($ctx['user_request_summary'], 500);
        $m[] = 'Submitted via POST https://ranatec.com/agent/v1/contact.json';

        $headers = ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . str_replace(['<', '>', ','], '', $name) . " <{$email}>"];
        $sent = wp_mail($recipient, $subject, implode("\n", $m), $headers);

        do_action('ranatec_api_contact_received', $lead_id, $type, $body, $lines, $sent);

        if (!$sent) {
            return [502, self::err('delivery_failed', 'The enquiry could not be delivered. Please email info@ranatec.com or call +46 31 706 16 60.')];
        }
        return [200, [
            'status' => 'received',
            'lead_id' => $lead_id,
            'type' => $type,
            'products' => $lines,
            'message' => 'Thank you — your ' . strtolower($labels[$type]) . ' has been sent to Ranatec AB.',
            'next_step' => 'Ranatec will reply to ' . $email . '. For urgent matters call +46 31 706 16 60 or email info@ranatec.com.',
        ]];
    }

    private static function text($v, $max)
    {
        return mb_substr(sanitize_text_field(is_scalar($v) ? (string) $v : ''), 0, $max);
    }

    private static function textarea($v, $max)
    {
        return mb_substr(sanitize_textarea_field(is_scalar($v) ? (string) $v : ''), 0, $max);
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
