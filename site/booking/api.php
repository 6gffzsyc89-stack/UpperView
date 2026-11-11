<?php
/** Public booking API: availability + create booking. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';
require __DIR__ . '/airwallex.php';
require __DIR__ . '/stripe.php';

$action = $_GET['action'] ?? '';
if ($action === 'network') { require_once __DIR__ . '/network-local.php'; net_handle(); } // signed calls from the other properties' admins (2026-11-08)

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
        'payment' => uv_pay_provider(),   // meta-aware (admin-saved key), not just config
    ]);
}

if ($action === 'promos') {   // public: the promotion bar's content
    $out = [];
    foreach (uv_promos_banner() as $p) $out[] = ['code' => $p['code'], 'title' => $p['title'], 'note' => uv_promo_note($p)];
    uv_json(['promos' => $out]);
}

if ($action === 'quote') {
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
    if (!uv_valid_date($from) || !uv_valid_date($to) || $to <= $from) uv_json(['error' => 'bad range'], 400);
    $cfg = uv_config();
    $nights = (int)((strtotime($to) - strtotime($from)) / 86400);
    $mult = $cfg['direct_multiplier'] ?? 0.90; // direct booking = base rate -10%
    $promoIn = uv_promo_code_clean((string)($_GET['promo'] ?? ''));
    $promo = $promoIn !== '' ? uv_promo_find($promoIn, $from, $to) : null;
    $promoOk = ($promo && !isset($promo['error'])) ? $promo : null;
    $out = [];
    foreach ($cfg['inventory'] as $type => $units) {
        $roomId = $cfg['beds24']['room_map'][$type] ?? null;
        $free = null; $total = null;
        if ($roomId !== null && b24_enabled()) {
            $lastDate = date('Y-m-d', strtotime($to . ' -1 day'));
            $res = b24_api('GET', '/inventory/rooms/calendar?roomId=' . $roomId
                . '&startDate=' . $from . '&endDate=' . $lastDate
                . '&includeNumAvail=true&includePrices=true');
            if (is_array($res) && isset($res['data'][0]['calendar'])) {
                $freeMin = PHP_INT_MAX; $sum = 0.0; $covered = 0;
                foreach ($res['data'][0]['calendar'] as $r) {
                    $n = max(0, (int)($r['numAvail'] ?? 0));
                    $p = (float)($r['price1'] ?? 0);
                    for ($d = max($r['from'], $from); $d <= min($r['to'], $lastDate);
                         $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                        $freeMin = min($freeMin, $n);
                        $sum += $p; $covered++;
                    }
                }
                if ($covered === $nights) {
                    $free = ($freeMin === PHP_INT_MAX) ? 0 : $freeMin;
                    $total = $sum > 0 ? (int)round($sum * $mult) : null;
                }
            }
        }
        if ($free === null) { // fallback engine
            $f = uv_avail($type, $from, $to);
            $free = $f ? min($f) : 0;
        }
        $gross = $total;
        $disc = $total !== null ? uv_promo_discount($promoOk, (int)$total) : 0;
        if ($disc > 0) $total = $gross - $disc;
        $out[] = [
            'type' => $type,
            'name' => uv_type_name($type),
            'units' => $units,
            'free' => $free,
            'available' => $free > 0 && ($total !== null || !b24_enabled()),
            'nights' => $nights,
            'total' => $total,
            'nightly' => $total !== null && $nights ? (int)round($total / $nights) : null,
            'gross' => $disc > 0 ? $gross : null,
            'gross_nightly' => $disc > 0 && $nights ? (int)round($gross / $nights) : null,
            'currency' => $cfg['currency'],
        ];
    }
    uv_json(['from' => $from, 'to' => $to, 'nights' => $nights, 'rooms' => $out,
             'source' => b24_enabled() ? 'beds24' : 'local',
             'promo' => $promoOk ? ['code' => $promoOk['code'], 'title' => $promoOk['title'], 'note' => $promoOk['note'],
                                    'kind' => $promoOk['kind'], 'percent' => (int)$promoOk['percent']] : null,
             'promo_error' => ($promo && isset($promo['error'])) ? $promo['error'] : null]);
}

if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $in['type'] ?? '';
    $ci = $in['checkin'] ?? ''; $co = $in['checkout'] ?? '';
    // Hard occupancy cap: every suite and mini villa sleeps a maximum of 2.
    // Larger parties book MULTIPLE mini villas (W/Nobu-style multi-room):
    // rooms = ceil(guests / 2), villas only (suites exist once each).
    $guests = max(1, (int)($in['guests'] ?? 2));
    if ($guests > 6) uv_json(['error' => 'max 6 guests (3 mini villas) — contact us for larger groups'], 400);
    $rooms = intdiv($guests + 1, 2);
    if ($rooms > 1 && ($in['type'] ?? '') !== 'villa') {
        uv_json(['error' => 'suites sleep a maximum of 2 — for more guests book mini villas'], 400);
    }
    // Baby cot: optional, offered on the three suites only (not the mini villa).
    $cot = (!empty($in['cot']) && $type !== 'villa') ? 1 : 0;
    $name = trim((string)($in['name'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));

    if (!uv_valid_type($type)) uv_json(['error' => 'bad type'], 400);
    if (!uv_valid_date($ci) || !uv_valid_date($co) || $co <= $ci) uv_json(['error' => 'bad dates'], 400);
    $cfg = uv_config();
    if ($ci < $cfg['opening_date']) uv_json(['error' => 'before opening'], 400);
    $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights > 30) uv_json(['error' => 'too long'], 400);
    foreach (uv_avail($type, $ci, $co) as $d => $free) {
        if ($free < $rooms) uv_json(['error' => 'no availability', 'date' => $d], 409);
    }
    // Promo code: re-validated server-side; a bad code is refused with the reason.
    $promoIn = uv_promo_code_clean((string)($in['promo'] ?? ''));
    $promo = $promoIn !== '' ? uv_promo_find($promoIn, $ci, $co) : null;
    if ($promo && isset($promo['error'])) uv_json(['error' => $promo['error']], 400);

    /* Nobu-style flow: guest details + card are collected on the checkout
       page (booking/pay.php), so a hold is created without them.
       Applies to whichever payment provider is active (Stripe or Airwallex). */
    if (in_array(uv_pay_provider(), ['stripe', 'airwallex'], true)) {
        $q = uv_live_total($type, $ci, $co);
        if (($q['free'] ?? 0) < $rooms) uv_json(['error' => 'no availability'], 409);
        if (empty($q['total'])) uv_json(['error' => 'no rate for these dates'], 409);
        $gross = (int) $q['total'] * $rooms;
        $disc = uv_promo_discount($promo, $gross);
        $amount = $gross - $disc;
        $code = uv_booking_code();
        $now = gmdate('Y-m-d H:i:s');
        uv_db()->prepare('INSERT INTO bookings
            (code,type,checkin,checkout,guests,cot,rooms,name,email,phone,status,source,amount,currency,
             promo_code,promo_note,gross,discount,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$code, $type, $ci, $co, $guests, $cot, $rooms, $name, $email, $phone,
                       'pending', 'direct', $amount, $cfg['currency'],
                       $promo ? $promo['code'] : null, $promo ? $promo['note'] : null,
                       $disc > 0 ? $gross : null, $disc > 0 ? $disc : null, $now, $now]);
        $payUrl = rtrim($cfg['site_url'], '/') . '/booking/pay.php?code='
                . rawurlencode($code) . '&sig=' . uv_pay_sig($code);
        uv_json(['mode' => 'pay', 'code' => $code, 'url' => $payUrl,
                 'total' => $amount, 'currency' => $cfg['currency']]);
    }

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        uv_json(['error' => 'name and valid email required'], 400);
    }

    $paid = uv_payment_provider() !== 'none' && ($cfg['rates_confirmed'] ?? false);
    $grossL = $paid ? $nights * $cfg['rates'][$type] * $rooms : null;
    $discL = $grossL !== null ? uv_promo_discount($promo, (int)$grossL) : 0;
    $amount = $grossL !== null ? (int)$grossL - $discL : null;
    $code = uv_booking_code();
    $now = gmdate('Y-m-d H:i:s');
    uv_db()->prepare('INSERT INTO bookings
        (code,type,checkin,checkout,guests,cot,rooms,name,email,phone,status,source,amount,currency,
         promo_code,promo_note,gross,discount,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$code, $type, $ci, $co, $guests, $cot, $rooms, $name, $email, $phone,
                   $paid ? 'pending' : 'inquiry', 'direct',
                   $amount, $cfg['currency'],
                   $promo ? $promo['code'] : null, $promo ? $promo['note'] : null,
                   $discL > 0 ? (int)$grossL : null, $discL > 0 ? $discL : null, $now, $now]);

    $summary = ($rooms > 1 ? $rooms . ' × ' : '') . uv_type_name($type)
        . " | $ci -> $co ($nights nights) | $guests guests" . ($cot ? ' + baby cot' : '')
        . " | $name | $email | $phone | code $code"
        . ($promo ? "\nPROMO CODE: {$promo['code']} — {$promo['note']}" : '');

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

    /* UNPAID request: do NOT write to Beds24 and do NOT touch OTA calendars.
       Closing calendars for an unpaid request is a manual decision (admin page
       "Confirm + close OTAs"). Paid bookings close them automatically via the
       Stripe/Airwallex webhooks. */
    uv_notify("Upper View: NEW BOOKING REQUEST $code (unpaid)", $summary
        . "\n\nNO payment has been taken — this is a request only."
        . "\nOTA calendars are still OPEN (nothing was written to Beds24)."
        . "\nTo accept it and close the OTA calendars, open the admin page"
        . " (upperviewsamui.com/booking/admin.php) and press Confirm + close OTAs."
        . "\nIf the guest pays online instead, the calendars close automatically.");
    uv_mail_guest($email, 'Upper View — booking request received',
        "Thank you $name!\n\nWe received your request for a "
        . uv_type_name($type)
        . " from $ci to $co ($nights nights, $guests guest" . ($guests > 1 ? 's' : '')
        . ($cot ? ', baby cot requested' : '') . ").\n"
        . "Your reference is $code. We will confirm by email shortly.\n\nUpper View Samui");
    uv_json(['mode' => 'inquiry', 'code' => $code]);
}

/* ---------- admin API: backend for the Upper View MCP ---------- */
if (strpos($action, 'admin-') === 0) {
    $k2 = (string) uv_meta_get('admin_key2');
    $kin = (string) ($_GET['k'] ?? '');
    if (!hash_equals(uv_config()['admin_key'], $kin) && ($k2 === '' || !hash_equals($k2, $kin))) {
        uv_json(['error' => 'forbidden'], 403);
    }

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

    /* ---- Generic Beds24 API v2 mirror (full read/write for trusted callers).
       Exposes every endpoint the Beds24 API offers (channel settings, guest
       messages, invoices, properties, fixed prices, ...) without the token
       ever leaving the server. ---- */
    if ($action === 'admin-beds24-get') {
        if (!b24_enabled()) uv_json(['error' => 'beds24 not enabled'], 409);
        $path = (string)($_GET['path'] ?? '');
        if (!preg_match('#^/[A-Za-z0-9/._-]*$#', $path)) uv_json(['error' => 'bad path (must start with /)'], 400);
        $qs = (string)($_GET['query'] ?? '');
        if ($qs !== '' && !preg_match('/^[A-Za-z0-9=&%._,+\[\]-]*$/', $qs)) uv_json(['error' => 'bad query string'], 400);
        $res = b24_api('GET', $path . ($qs !== '' ? '?' . $qs : ''));
        uv_json($res !== null ? $res
            : ['error' => 'beds24 request failed', 'detail' => uv_meta_get('b24_last_error')], $res !== null ? 200 : 502);
    }
    if ($action === 'admin-beds24-write' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!b24_enabled()) uv_json(['error' => 'beds24 not enabled'], 409);
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $path = (string)($in['path'] ?? '');
        $method = strtoupper((string)($in['method'] ?? 'POST'));
        if (!preg_match('#^/[A-Za-z0-9/._-]*$#', $path)) uv_json(['error' => 'bad path (must start with /)'], 400);
        if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) uv_json(['error' => 'method must be POST/PATCH/DELETE'], 400);
        $qs = (string)($in['query'] ?? '');
        if ($qs !== '' && !preg_match('/^[A-Za-z0-9=&%._,+\[\]-]*$/', $qs)) uv_json(['error' => 'bad query string'], 400);
        $res = b24_api($method, $path . ($qs !== '' ? '?' . $qs : ''), $in['body'] ?? null);
        uv_json($res !== null ? $res
            : ['error' => 'beds24 request failed', 'detail' => uv_meta_get('b24_last_error')], $res !== null ? 200 : 502);
    }

    /* ---- Email: send as booking@upperviewsamui.com (guest replies etc.) ---- */
    if ($action === 'admin-send-email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $tos = is_array($in['to'] ?? null) ? $in['to'] : [(string)($in['to'] ?? '')];
        $subject = trim((string)($in['subject'] ?? ''));
        $body = (string)($in['body'] ?? '');
        $isHtml = !empty($in['html']);
        if ($subject === '' || $body === '') uv_json(['error' => 'subject and body required'], 400);
        $sent = []; $failed = [];
        foreach ($tos as $to) {
            $to = trim((string)$to);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed[] = $to; continue; }
            if ($isHtml) {
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                         . "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com";
            } else {
                $headers = "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com";
            }
            $ok = @mail($to, $subject, $body, $headers, '-f booking@upperviewsamui.com');
            $ok ? $sent[] = $to : $failed[] = $to;
        }
        uv_json(['sent' => $sent, 'failed' => $failed]);
    }

    /* ---- Notification recipient list: read / change without server access ---- */
    if ($action === 'admin-notify-emails') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $emails = $in['emails'] ?? null;
            if (!is_array($emails) || $emails === []) uv_json(['error' => 'emails: non-empty array required'], 400);
            $clean = [];
            foreach ($emails as $e) {
                $e = trim((string)$e);
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) uv_json(['error' => "invalid email: $e"], 400);
                $clean[] = $e;
            }
            uv_meta_set('notify_emails', json_encode(array_values(array_unique($clean))));
            uv_json(['ok' => true, 'notify_emails' => $clean]);
        }
        uv_json(['notify_emails' => uv_notify_list(),
                 'source' => uv_meta_get('notify_emails') !== null ? 'meta (set via API)' : 'config.php']);
    }

    uv_json(['error' => 'unknown admin action'], 404);
}

uv_json(['error' => 'unknown action'], 404);
