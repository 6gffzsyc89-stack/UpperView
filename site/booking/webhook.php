<?php
/** Stripe webhook: confirms a pending booking on checkout.session.completed. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';

$cfg = uv_config();
$secret = $cfg['stripe']['webhook_secret'] ?? '';
$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($secret === '' || $payload === '' || $sig === '') {
    http_response_code(400); exit('bad request');
}

// Verify signature: Stripe-Signature: t=...,v1=hmac_sha256("$t.$payload", secret)
$parts = [];
foreach (explode(',', $sig) as $kv) {
    [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
    $parts[$k][] = $v;
}
$t = $parts['t'][0] ?? '';
$ok = false;
foreach ($parts['v1'] ?? [] as $v1) {
    if (hash_equals(hash_hmac('sha256', $t . '.' . $payload, $secret), $v1)) { $ok = true; break; }
}
if (!$ok || abs(time() - (int)$t) > 600) { http_response_code(400); exit('bad signature'); }

$event = json_decode($payload, true);
if (($event['type'] ?? '') === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $code = $session['metadata']['booking_code'] ?? ($session['client_reference_id'] ?? '');
    if ($code !== '') {
        $db = uv_db();
        $st = $db->prepare('SELECT * FROM bookings WHERE code=?');
        $st->execute([$code]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if ($b && $b['status'] !== 'confirmed') {
            // No double bookings — ever: confirm-time re-check (see webhook-stripe.php).
            $clash = uv_clash_nights($b['type'], $b['checkin'], $b['checkout'], (int)($b['rooms'] ?? 1), (string)$b['code']);
            if ($clash) {
                $db->prepare("UPDATE bookings SET status='clash', updated_at=datetime('now') WHERE code=?")
                   ->execute([$code]);
                uv_notify('URGENT: paid booking ' . $code . ' NOT confirmed — dates already taken',
                    "A guest PAID online (Stripe) but these nights were booked elsewhere first, so the booking was NOT confirmed (no double booking was made):\n\n"
                    . uv_type_name($b['type']) . ' (' . $code . '): taken on ' . implode(', ', $clash)
                    . "\n\nContact the guest TODAY: offer other dates or another room, or refund in full."
                    . "\nGuest: {$b['name']} · {$b['email']} · {$b['phone']}");
                http_response_code(200); exit('clash');
            }
            $db->prepare("UPDATE bookings SET status='confirmed', updated_at=datetime('now') WHERE code=?")
               ->execute([$code]);
            $b24id = b24_create_booking([
                'code' => $code, 'type' => $b['type'], 'checkin' => $b['checkin'],
                'checkout' => $b['checkout'], 'guests' => $b['guests'], 'name' => (string)$b['name'],
                'email' => (string)$b['email'], 'phone' => (string)$b['phone'], 'status' => 'confirmed',
                'paid' => true, 'amount' => (int)$b['amount'], 'provider' => 'Stripe',
                'cot' => (int)($b['cot'] ?? 0), 'rooms' => (int)($b['rooms'] ?? 1),
            ]);
            $summary = uv_type_name($b['type'])
                . " | {$b['checkin']} -> {$b['checkout']} | {$b['name']} | {$b['email']} | code $code | PAID";
            uv_notify("Upper View: BOOKING CONFIRMED $code", $summary
                . ($b24id ? "\n\nSynced to Beds24 (booking $b24id)."
                          : "\n\nNOT in Beds24 — block OTA calendars manually."));
            uv_mail_guest((string)$b['email'], 'Upper View — booking confirmed',
                "Thank you {$b['name']}!\n\nYour booking is confirmed:\n"
                . uv_type_name($b['type'])
                . " from {$b['checkin']} to {$b['checkout']}.\nReference: $code\n\n"
                . "We look forward to welcoming you.\nUpper View Samui");
        }
    }
}
http_response_code(200);
echo 'ok';
