<?php
/**
 * Stripe webhook: checkout.session.completed confirms the booking,
 * writes it to Beds24 as confirmed, and sends the confirmation emails.
 * Verification: the event payload is treated only as a hint — the session
 * is re-fetched from the Stripe API with the secret key and must report
 * payment_status=paid before anything is confirmed. No signing secret needed.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/beds24.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/emails.php';

$body = file_get_contents('php://input');
$evt = json_decode($body, true);
if (!is_array($evt)) { http_response_code(400); exit('bad body'); }

$type = isset($evt['type']) ? $evt['type'] : '';
if ($type !== 'checkout.session.completed' && $type !== 'checkout.session.async_payment_succeeded') {
    http_response_code(200);
    exit('ignored');
}

$obj = isset($evt['data']['object']) ? $evt['data']['object'] : array();
$sessionId = isset($obj['id']) ? (string) $obj['id'] : '';
if ($sessionId === '') { http_response_code(200); exit('no session'); }

// Verify against the Stripe API — never trust the webhook payload itself.
$live = st_session_status($sessionId);
if (!is_array($live) || !isset($live['payment_status']) || $live['payment_status'] !== 'paid') {
    http_response_code(200);
    exit('not paid');
}

$code = '';
if (!empty($live['client_reference_id'])) $code = (string) $live['client_reference_id'];
if ($code === '' && !empty($live['metadata']['booking_code'])) $code = (string) $live['metadata']['booking_code'];
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
    'provider' => 'Stripe',
    'cot' => (int) ($b['cot'] ?? 0),
));
uv_meta_set('st_paid_' . $code, (string) time());

uv_send_confirmation($b, 'Stripe');
if (!$b24id) {
    uv_notify_all('Upper View: paid booking ' . $code . ' NOT in Beds24',
        'Payment captured (Stripe) but the Beds24 write failed — add it manually and close OTA calendars.');
}

http_response_code(200);
echo 'ok';
