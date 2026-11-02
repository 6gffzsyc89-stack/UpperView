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
            $db->prepare("UPDATE bookings SET status='confirmed', updated_at=datetime('now') WHERE code=?")
               ->execute([$code]);
            $b24id = b24_create_booking([
                'code' => $code, 'type' => $b['type'], 'checkin' => $b['checkin'],
                'checkout' => $b['checkout'], 'guests' => $b['guests'], 'name' => (string)$b['name'],
                'email' => (string)$b['email'], 'phone' => (string)$b['phone'], 'status' => 'confirmed',
            ]);
            $summary = ($b['type'] === 'villa' ? 'Mini Villa' : 'Studio')
                . " | {$b['checkin']} -> {$b['checkout']} | {$b['name']} | {$b['email']} | code $code | PAID";
            uv_notify("Upper View: BOOKING CONFIRMED $code", $summary
                . ($b24id ? "\n\nSynced to Beds24 (booking $b24id)."
                          : "\n\nNOT in Beds24 — block OTA calendars manually."));
            uv_mail_guest((string)$b['email'], 'Upper View — booking confirmed',
                "Thank you {$b['name']}!\n\nYour booking is confirmed:\n"
                . ($b['type'] === 'villa' ? 'Mini Villa' : 'Studio')
                . " from {$b['checkin']} to {$b['checkout']}.\nReference: $code\n\n"
                . "We look forward to welcoming you.\nUpper View Samui");
        }
    }
}
http_response_code(200);
echo 'ok';
