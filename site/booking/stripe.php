<?php
/**
 * Stripe payment adapter (PHP 7.2-safe) — Stripe Checkout (hosted page).
 * Used while Airwallex payment acceptance is under review; the active
 * provider is selected by uv_pay_provider() (config 'payment_provider'
 * or meta key 'payment_provider': 'stripe' | 'airwallex').
 *
 * Secret key is NEVER in the repo. It lives either in
 *   data/config.php:  'stripe' => ['secret_key' => 'sk_live_...'],
 * or in the meta table key 'stripe_secret_key' (set via admin.php by the
 * owner pasting it directly — Claude never handles the key value).
 *
 * Amounts: bookings store minor units (satang); Stripe THB also uses
 * minor units, so amounts pass through unchanged.
 */

require_once __DIR__ . '/lib.php';

function st_secret() {
    $cfg = uv_config();
    if (!empty($cfg['stripe']['secret_key'])) return $cfg['stripe']['secret_key'];
    $k = uv_meta_get('stripe_secret_key');
    return $k ? $k : null;
}

function st_enabled() {
    return st_secret() !== null;
}

/** Active payment provider: 'stripe' or 'airwallex' (or '' when none ready). */
function uv_pay_provider() {
    $cfg = uv_config();
    $p = isset($cfg['payment_provider']) ? $cfg['payment_provider'] : uv_meta_get('payment_provider');
    if ($p === 'stripe' && st_enabled()) return 'stripe';
    if ($p === 'airwallex') return 'airwallex';
    // auto: prefer airwallex when configured, else stripe
    if (function_exists('aw_enabled') && aw_enabled()) return 'airwallex';
    if (st_enabled()) return 'stripe';
    return '';
}

/** form-encoded call to the Stripe API. $params uses Stripe's bracket syntax keys. */
function st_http($method, $path, $params = array()) {
    $ch = curl_init('https://api.stripe.com' . $path);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . st_secret(),
            'Stripe-Version: 2024-06-20',
        ),
        CURLOPT_TIMEOUT => 20,
    );
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    return array($code, is_array($data) ? $data : array());
}

/**
 * Create (or reuse) a Stripe Checkout Session for a booking row.
 * Returns ['session_id'=>..,'url'=>..] or null.
 */
function st_checkout_for($booking) {
    if (!st_enabled()) return null;
    $code = $booking['code'];
    $raw = uv_meta_get('st_session_' . $code);
    if ($raw) {
        $s = json_decode($raw, true);
        // Checkout Sessions expire after 24h; reuse while <20h old
        if (is_array($s) && !empty($s['url']) && (time() - $s['created']) < 72000) {
            return $s;
        }
    }
    $cfg = uv_config();
    $site = rtrim($cfg['site_url'], '/');
    $ret  = $site . '/booking/pay.php?code=' . rawurlencode($code)
          . '&sig=' . uv_pay_sig($code);
    $params = array(
        'mode' => 'payment',
        'client_reference_id' => $code,
        'customer_email' => (string) $booking['email'],
        'success_url' => $ret . '&done=1',
        'cancel_url'  => $ret,
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => strtolower($booking['currency']),
        'line_items[0][price_data][unit_amount]' => (int) $booking['amount'],
        'line_items[0][price_data][product_data][name]' =>
            'Upper View Samui — ' . uv_type_name($booking['type']),
        'line_items[0][price_data][product_data][description]' =>
            $booking['checkin'] . ' to ' . $booking['checkout'],
        'metadata[booking_code]' => $code,
        'payment_intent_data[metadata][booking_code]' => $code,
        'payment_intent_data[statement_descriptor_suffix]' => 'UPPER VIEW',
    );
    list($http, $data) = st_http('POST', '/v1/checkout/sessions', $params);
    if ($http !== 200 && isset($params['payment_intent_data[statement_descriptor_suffix]'])) {
        // some account/currency combos reject suffixes — retry without it
        unset($params['payment_intent_data[statement_descriptor_suffix]']);
        list($http, $data) = st_http('POST', '/v1/checkout/sessions', $params);
    }
    if ($http !== 200 || empty($data['id']) || empty($data['url'])) {
        uv_meta_set('st_last_error', gmdate('c') . ' checkout.create HTTP ' . $http . ' ' .
            substr(json_encode(isset($data['error']) ? $data['error'] : $data), 0, 400));
        return null;
    }
    $s = array('session_id' => $data['id'], 'url' => $data['url'], 'created' => time());
    uv_meta_set('st_session_' . $code, json_encode($s));
    return $s;
}

/** Retrieve a Checkout Session server-side (verification; never trust the client). */
function st_session_status($sessionId) {
    if (!st_enabled()) return null;
    list($http, $data) = st_http('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));
    return $http === 200 ? $data : null;
}

/** True when the stored session for this booking code is paid (verified via API). */
function st_booking_paid($code) {
    $raw = uv_meta_get('st_session_' . $code);
    if (!$raw) return false;
    $s = json_decode($raw, true);
    if (!is_array($s) || empty($s['session_id'])) return false;
    $live = st_session_status($s['session_id']);
    return is_array($live) && isset($live['payment_status']) && $live['payment_status'] === 'paid';
}
