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

/* No double bookings — ever: the nights must STILL be free at confirmation time
   (Beds24 + fresh OTA feeds + our bookings, excluding this booking's own row).
   If they were taken while the guest paid, the booking becomes 'clash': it is
   NOT confirmed, nothing is written to Beds24, and the desk is told urgently. */
$clash = uv_clash_nights($b['type'], $b['checkin'], $b['checkout'], (int) ($b['rooms'] ?? 1), (string) $b['code']);
if ($clash) {
    $db->prepare("UPDATE bookings SET status='clash', updated_at=? WHERE code=?")
       ->execute(array(gmdate('Y-m-d H:i:s'), $code));
    uv_notify_all('URGENT: paid booking ' . $code . ' NOT confirmed — dates already taken',
        "A guest PAID online (Stripe) but these nights were booked elsewhere before the payment finished, so the booking was NOT confirmed (no double booking was made):\n\n"
        . uv_type_name($b['type']) . ' (' . $code . '): taken on ' . implode(', ', $clash)
        . "\n\nContact the guest TODAY: offer other dates or another room, or refund in full from the Stripe dashboard."
        . "\nGuest: {$b['name']} · {$b['email']} · {$b['phone']} · " . number_format((int) $b['amount']) . ' THB paid.');
    http_response_code(200);
    exit('clash');
}

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
    'rooms' => (int) ($b['rooms'] ?? 1),
));
uv_meta_set('st_paid_' . $code, (string) time());
uv_sheet_ping();

uv_send_confirmation($b, 'Stripe');
if (!$b24id) {
    uv_notify_all('Upper View: paid booking ' . $code . ' NOT in Beds24',
        'Payment captured (Stripe) but the Beds24 write failed — add it manually and close OTA calendars.');
}

http_response_code(200);
echo 'ok';
