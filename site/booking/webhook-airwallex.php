<?php
/**
 * Airwallex webhook: payment_intent.succeeded confirms the booking,
 * writes it to Beds24 as confirmed, and sends the confirmation emails.
 * Signature: HMAC-SHA256(x-timestamp . raw_body, webhook_secret) == x-signature.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/beds24.php';
require_once __DIR__ . '/airwallex.php';
require_once __DIR__ . '/emails.php';

$body = file_get_contents('php://input');
$ts   = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
$sig  = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!aw_webhook_valid($ts, $body, $sig)) {
    http_response_code(401);
    exit('bad signature');
}

$evt = json_decode($body, true);
if (!is_array($evt)) { http_response_code(400); exit('bad body'); }

$name = $evt['name'] ?? '';
if ($name !== 'payment_intent.succeeded') {
    http_response_code(200);
    exit('ignored');
}

$obj  = $evt['data']['object'] ?? array();
$code = $obj['merchant_order_id'] ?? ($obj['metadata']['booking_code'] ?? '');
if ($code === '') { http_response_code(200); exit('no code'); }

$db = uv_db();
$st = $db->prepare('SELECT * FROM bookings WHERE code = ?');
$st->execute(array($code));
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b) { http_response_code(200); exit('unknown booking'); }

if ($b['status'] === 'confirmed') { http_response_code(200); exit('already confirmed'); }

$db->prepare("UPDATE bookings SET status='confirmed', updated_at=? WHERE code=?")
   ->execute(array(gmdate('Y-m-d H:i:s'), $code));
$b['status'] = 'confirmed';

$b24id = b24_create_booking(array(
    'code' => $code,
    'type' => $b['type'],
    'checkin' => $b['checkin'],
    'checkout' => $b['checkout'],
    'guests' => (int) $b['guests'],
    'name' => (string) $b['name'],
    'email' => (string) $b['email'],
    'phone' => (string) $b['phone'],
    'status' => 'confirmed',
    'paid' => true,
    'amount' => (int) $b['amount'],
    'provider' => 'Airwallex',
    'cot' => (int) ($b['cot'] ?? 0),
));
uv_meta_set('aw_paid_' . $code, (string) time());

uv_send_confirmation($b, 'Airwallex');
if (!$b24id) {
    uv_notify_all('Upper View: paid booking ' . $code . ' NOT in Beds24',
        'Payment captured but the Beds24 write failed — add it manually and close OTA calendars.');
}

http_response_code(200);
echo 'ok';
