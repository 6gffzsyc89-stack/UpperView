<?php
/** Public booking API: availability + create booking. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';

$action = $_GET['action'] ?? '';

if ($action === 'availability') {
    $type = $_GET['type'] ?? 'villa';
    if (!uv_valid_type($type)) uv_json(['error' => 'bad type'], 400);
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to'] ?? date('Y-m-d', strtotime('+120 days'));
    if (!uv_valid_date($from) || !uv_valid_date($to) || $to <= $from) {
        uv_json(['error' => 'bad range'], 400);
    }
    $cfg = uv_config();
    uv_json([
        'type' => $type,
        'free' => uv_avail($type, $from, $to),
        'source' => b24_enabled() ? 'beds24' : 'local',
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

    if (!uv_valid_type($type)) uv_json(['error' => 'bad type'], 400);
    if (!uv_valid_date($ci) || !uv_valid_date($co) || $co <= $ci) uv_json(['error' => 'bad dates'], 400);
    $cfg = uv_config();
    if ($ci < $cfg['opening_date']) uv_json(['error' => 'before opening'], 400);
    $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights > 30) uv_json(['error' => 'too long'], 400);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        uv_json(['error' => 'name and valid email required'], 400);
    }

    foreach (uv_avail($type, $ci, $co) as $d => $free) {
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

    $summary = uv_type_name($type)
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

    $b24id = b24_create_booking([
        'code' => $code, 'type' => $type, 'checkin' => $ci, 'checkout' => $co,
        'guests' => $guests, 'name' => $name, 'email' => $email, 'phone' => $phone,
        'status' => 'new',
    ]);
    uv_notify("Upper View: NEW BOOKING REQUEST $code", $summary
        . ($b24id ? "\n\nSynced to Beds24 (booking $b24id) — OTA calendars close automatically."
                  : "\n\nNOT in Beds24 — block OTA calendars manually."));
    uv_mail_guest($email, 'Upper View — booking request received',
        "Thank you $name!\n\nWe received your request for a "
        . uv_type_name($type)
        . " from $ci to $co ($nights nights, $guests guests).\n"
        . "Your reference is $code. We will confirm by email shortly.\n\nUpper View Samui");
    uv_json(['mode' => 'inquiry', 'code' => $code]);
}

/* ---------- admin API: backend for the Upper View MCP ---------- */
if (strpos($action, 'admin-') === 0) {
    if (!hash_equals(uv_config()['admin_key'], $_GET['k'] ?? '')) uv_json(['error' => 'forbidden'], 403);

    if ($action === 'admin-occupancy') {
        $from = uv_valid_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d');
        $to = uv_valid_date($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d', strtotime($from . ' +30 days'));
        $inv = uv_config()['inventory'];
        $out = ['from' => $from, 'to' => $to, 'types' => [], 'source' => b24_enabled() ? 'beds24' : 'local'];
        $allNights = 0; $allBooked = 0;
        foreach ($inv as $type => $total) {
            $free = uv_avail($type, $from, $to);
            $nights = count($free) * $total;
            $booked = $nights - array_sum($free);
            $out['types'][$type] = [
                'units' => $total,
                'nights_booked' => $booked,
                'nights_total' => $nights,
                'occupancy_pct' => $nights ? round($booked / $nights * 100, 1) : 0,
                'free_by_date' => $free,
            ];
            $allNights += $nights; $allBooked += $booked;
        }
        $out['occupancy_pct'] = $allNights ? round($allBooked / $allNights * 100, 1) : 0;
        uv_json($out);
    }

    if ($action === 'admin-bookings') {
        $rows = uv_db()->query("SELECT code,type,checkin,checkout,guests,name,email,phone,status,source,amount,currency
                                FROM bookings WHERE status != 'cancelled' ORDER BY checkin LIMIT 300")
                       ->fetchAll(PDO::FETCH_ASSOC);
        $b24 = null;
        if (b24_enabled()) {
            $b24 = b24_api('GET', '/bookings?arrivalFrom=' . date('Y-m-d', strtotime('-7 days'))
                . '&arrivalTo=' . date('Y-m-d', strtotime('+365 days')));
        }
        uv_json(['website_db' => $rows, 'beds24' => $b24['data'] ?? null]);
    }

    if ($action === 'admin-block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $type = $in['type'] ?? ''; $ci = $in['checkin'] ?? ''; $co = $in['checkout'] ?? '';
        if (!uv_valid_type($type) || !uv_valid_date($ci) || !uv_valid_date($co) || $co <= $ci) {
            uv_json(['error' => 'type/checkin/checkout required'], 400);
        }
        $code = uv_booking_code(); $now = gmdate('Y-m-d H:i:s');
        uv_db()->prepare("INSERT INTO bookings (code,type,checkin,checkout,guests,name,status,source,currency,created_at,updated_at)
                          VALUES (?,?,?,?,0,?,'blocked','admin',?,?,?)")
               ->execute([$code, $type, $ci, $co, $in['note'] ?? 'MCP block', uv_config()['currency'], $now, $now]);
        $b24id = null;
        if (b24_enabled()) {
            $roomId = b24_cfg()['room_map'][$type] ?? null;
            $res = $roomId ? b24_api('POST', '/bookings', [[
                'roomId' => $roomId, 'status' => 'black',
                'arrival' => $ci, 'departure' => $co,
                'firstName' => 'BLOCK', 'lastName' => $in['note'] ?? 'maintenance',
                'apiReference' => $code,
            ]]) : null;
            $b24id = $res[0]['new']['id'] ?? ($res[0]['id'] ?? null);
        }
        uv_json(['ok' => true, 'code' => $code, 'beds24_id' => $b24id]);
    }

    if ($action === 'admin-rate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $type = $in['type'] ?? ''; $from = $in['from'] ?? ''; $to = $in['to'] ?? '';
        $price = (float)($in['price'] ?? 0);
        $roomId = b24_cfg()['room_map'][$type] ?? null;
        if ($roomId === null || !uv_valid_date($from) || !uv_valid_date($to) || $price <= 0) {
            uv_json(['error' => 'type/from/to/price required'], 400);
        }
        if (!b24_enabled()) uv_json(['error' => 'beds24 not enabled'], 409);
        $res = b24_api('POST', '/inventory/rooms/calendar', [[
            'roomId' => $roomId,
            'calendar' => [['from' => $from, 'to' => $to, 'price1' => $price]],
        ]]);
        uv_json(['beds24_response' => $res]);
    }

    if ($action === 'admin-beds24-status') {
        uv_json([
            'enabled' => b24_enabled(),
            'token' => uv_meta_get('b24_refresh') !== null ? 'stored' : 'missing',
            'last_error' => uv_meta_get('b24_last_error'),
        ]);
    }
    uv_json(['error' => 'unknown admin action'], 404);
}

uv_json(['error' => 'unknown action'], 404);
