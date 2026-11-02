<?php
/** Public booking API: availability + create booking. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

if ($action === 'availability') {
    $type = $_GET['type'] ?? 'villa';
    if (!in_array($type, ['villa', 'studio'], true)) uv_json(['error' => 'bad type'], 400);
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to'] ?? date('Y-m-d', strtotime('+120 days'));
    if (!uv_valid_date($from) || !uv_valid_date($to) || $to <= $from) {
        uv_json(['error' => 'bad range'], 400);
    }
    uv_refresh_ota(); // pulls OTA iCals if cache older than 5 min
    $cfg = uv_config();
    uv_json([
        'type' => $type,
        'free' => uv_availability($type, $from, $to),
        'min_date' => $cfg['opening_date'],
        'rates_confirmed' => (bool)($cfg['rates_confirmed'] ?? false),
        'nightly' => ($cfg['rates_confirmed'] ?? false) ? $cfg['rates'][$type] : null,
        'currency' => $cfg['currency'],
        'payment' => uv_payment_provider(),
    ]);
}

if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $in['type'] ?? '';
    $ci = $in['checkin'] ?? ''; $co = $in['checkout'] ?? '';
    $guests = max(1, min(6, (int)($in['guests'] ?? 2)));
    $name = trim((string)($in['name'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));

    if (!in_array($type, ['villa', 'studio'], true)) uv_json(['error' => 'bad type'], 400);
    if (!uv_valid_date($ci) || !uv_valid_date($co) || $co <= $ci) uv_json(['error' => 'bad dates'], 400);
    $cfg = uv_config();
    if ($ci < $cfg['opening_date']) uv_json(['error' => 'before opening'], 400);
    $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights > 30) uv_json(['error' => 'too long'], 400);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        uv_json(['error' => 'name and valid email required'], 400);
    }

    uv_refresh_ota();
    foreach (uv_availability($type, $ci, $co) as $d => $free) {
        if ($free < 1) uv_json(['error' => 'no availability', 'date' => $d], 409);
    }

    $paid = uv_payment_provider() !== 'none' && ($cfg['rates_confirmed'] ?? false);
    $amount = $paid ? $nights * $cfg['rates'][$type] : null;
    $code = uv_booking_code();
    $now = gmdate('Y-m-d H:i:s');
    uv_db()->prepare('INSERT INTO bookings
        (code,type,checkin,checkout,guests,name,email,phone,status,source,amount,currency,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$code, $type, $ci, $co, $guests, $name, $email, $phone,
                   $paid ? 'pending' : 'inquiry', 'direct',
                   $amount, $cfg['currency'], $now, $now]);

    $summary = ($type === 'villa' ? 'Mini Villa' : 'Studio')
        . " | $ci -> $co ($nights nights) | $guests guests | $name | $email | $phone | code $code";

    if ($paid) {
        $checkout = uv_checkout_create([
            'code' => $code, 'type' => $type, 'checkin' => $ci, 'checkout' => $co,
            'email' => $email, 'amount' => $amount * 100, 'currency' => $cfg['currency'],
        ]);
        if ($checkout === null) uv_json(['error' => 'payment init failed'], 502);
        uv_db()->prepare('UPDATE bookings SET stripe_session=? WHERE code=?')
               ->execute([$checkout['session_id'], $code]);
        uv_notify("Upper View: payment started $code", $summary);
        uv_json(['mode' => 'pay', 'code' => $code, 'url' => $checkout['url']]);
    }

    uv_notify("Upper View: NEW BOOKING REQUEST $code — block OTA calendars now", $summary
        . "\n\nReminder: block these dates on Booking.com and Airbnb immediately.");
    uv_mail_guest($email, 'Upper View — booking request received',
        "Thank you $name!\n\nWe received your request for a "
        . ($type === 'villa' ? 'Mini Villa' : 'Studio')
        . " from $ci to $co ($nights nights, $guests guests).\n"
        . "Your reference is $code. We will confirm by email shortly.\n\nUpper View Samui");
    uv_json(['mode' => 'inquiry', 'code' => $code]);
}

uv_json(['error' => 'unknown action'], 404);
