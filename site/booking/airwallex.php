<?php
/**
 * Airwallex payment adapter (PHP 7.2-safe).
 * Config lives in data/config.php:
 *   'airwallex' => [
 *     'client_id'      => '...',   // Developer > API keys
 *     'api_key'        => '...',
 *     'webhook_secret' => '...',   // Developer > Webhooks signing secret
 *     'env'            => 'prod',  // 'prod' or 'demo'
 *   ],
 * Amounts: Airwallex uses MAJOR units (10.99 = $10.99). Our bookings store
 * minor units (satang), so divide by 100 here.
 * Statement descriptor / order carries "Upper View Samui" prominently.
 */

require_once __DIR__ . '/lib.php';

function aw_cfg() {
    $cfg = uv_config();
    return isset($cfg['airwallex']) ? $cfg['airwallex'] : null;
}

function aw_enabled() {
    $a = aw_cfg();
    return $a && !empty($a['client_id']) && !empty($a['api_key']);
}

function aw_base() {
    $a = aw_cfg();
    return (isset($a['env']) && $a['env'] === 'demo')
        ? 'https://api-demo.airwallex.com'
        : 'https://api.airwallex.com';
}

function aw_http($path, $payload, $bearer, $hdrs = array()) {
    $ch = curl_init(aw_base() . $path);
    $headers = array('Content-Type: application/json');
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    foreach ($hdrs as $h) $headers[] = $h;
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload === null ? '{}' : json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    return array($code, is_array($data) ? $data : array());
}

/** Access token, cached ~20 min in the meta table. */
function aw_token() {
    $db = uv_db();
    $tok = uv_meta_get('aw_token');
    $exp = (int) uv_meta_get('aw_token_exp');
    if ($tok && $exp > time() + 60) return $tok;
    $a = aw_cfg();
    list($code, $data) = aw_http('/api/v1/authentication/login', null, null, array(
        'x-client-id: ' . $a['client_id'],
        'x-api-key: '   . $a['api_key'],
    ));
    if ($code !== 200 || empty($data['token'])) return null;
    uv_meta_set('aw_token', $data['token']);
    uv_meta_set('aw_token_exp', (string) (time() + 20 * 60));
    return $data['token'];
}

/**
 * Create a PaymentIntent for a booking row.
 * Returns ['intent_id'=>..,'client_secret'=>..] or null.
 */
function aw_create_intent($booking) {
    $tok = aw_token();
    if (!$tok) return null;
    $cfg = uv_config();
    $site = rtrim($cfg['site_url'], '/');
    // Bookings store WHOLE BAHT and Airwallex takes major units, so pass it through.
    // (Fixed 2026-11-08: dividing by 100 would have charged 1/100 of the price.)
    $amountMajor = round((float) $booking['amount'], 2);
    $payload = array(
        'request_id'        => 'uv-' . $booking['code'] . '-' . substr(md5(uniqid('', true)), 0, 8),
        'amount'            => $amountMajor,
        'currency'          => strtoupper($booking['currency']),
        'merchant_order_id' => $booking['code'],
        'descriptor'        => 'Upper View Samui',
        'return_url'        => $site . '/booking/pay.php?code=' . rawurlencode($booking['code'])
                               . '&sig=' . uv_pay_sig($booking['code']) . '&ret=1',
        'metadata'          => array(
            'booking_code' => $booking['code'],
            'room_type'    => $booking['type'],
            'checkin'      => $booking['checkin'],
            'checkout'     => $booking['checkout'],
            'property'     => 'Upper View Samui',
        ),
        'order' => array(
            'products' => array(array(
                'name'       => 'Upper View Samui — ' . uv_type_name($booking['type']),
                'desc'       => $booking['checkin'] . ' to ' . $booking['checkout'],
                'quantity'   => 1,
                'unit_price' => $amountMajor,
            )),
        ),
    );
    list($code, $data) = aw_http('/api/v1/pa/payment_intents/create', $payload, $tok);
    if (!in_array($code, array(200, 201)) || empty($data['id']) || empty($data['client_secret'])) {
        return null;
    }
    uv_meta_set('aw_intent_' . $booking['code'], json_encode(array(
        'intent_id' => $data['id'],
        'client_secret' => $data['client_secret'],
        'amount' => $amountMajor,
        'currency' => strtoupper($booking['currency']),
        'created' => time(),
    )));
    return array('intent_id' => $data['id'], 'client_secret' => $data['client_secret']);
}

/** Fetch a stored intent for a booking code (creates one if missing/stale). */
function aw_intent_for($booking) {
    $raw = uv_meta_get('aw_intent_' . $booking['code']);
    if ($raw) {
        $st = json_decode($raw, true);
        // reuse while fresh (<23h) — Airwallex intents stay valid long enough
        if (is_array($st) && !empty($st['intent_id']) && (time() - $st['created']) < 82800) {
            return $st;
        }
    }
    return aw_create_intent($booking);
}

/** Retrieve intent status server-side (verification, never trust the client). */
function aw_intent_status($intentId) {
    $tok = aw_token();
    if (!$tok) return null;
    $ch = curl_init(aw_base() . '/api/v1/pa/payment_intents/' . rawurlencode($intentId));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $tok),
        CURLOPT_TIMEOUT => 15,
    ));
    $res = curl_exec($ch);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    return is_array($data) ? $data : null;
}

/** Verify an Airwallex webhook: HMAC-SHA256(timestamp . body, secret). */
function aw_webhook_valid($timestamp, $body, $signature) {
    $a = aw_cfg();
    if (empty($a['webhook_secret']) || $timestamp === '' || $signature === '') return false;
    $calc = hash_hmac('sha256', $timestamp . $body, $a['webhook_secret']);
    return hash_equals($calc, $signature);
}
