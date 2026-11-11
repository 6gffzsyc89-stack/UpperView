<?php
/**
 * Upper View booking engine — shared library.
 * Config + SQLite live OUTSIDE the web root (../../data relative to this file
 * on Hostinger: domains/upperviewsamui.com/data/). See config.sample.php.
 */

declare(strict_types=1);

const UV_DATA_DIR_CANDIDATES = [
    __DIR__ . '/../../data',   // production: public_html/booking -> domain/data
    __DIR__ . '/../_devdata',  // local dev fallback
];

function uv_data_dir(): string {
    foreach (UV_DATA_DIR_CANDIDATES as $d) {
        if (is_dir($d)) return rtrim($d, '/');
    }
    http_response_code(500);
    exit(json_encode(['error' => 'data dir missing']));
}

function uv_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require uv_data_dir() . '/config.php';
    }
    return $cfg;
}

function uv_db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . uv_data_dir() . '/bookings.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            type TEXT NOT NULL,
            checkin TEXT NOT NULL,
            checkout TEXT NOT NULL,
            guests INTEGER NOT NULL DEFAULT 2,
            name TEXT, email TEXT, phone TEXT,
            status TEXT NOT NULL,            -- pending|inquiry|confirmed|cancelled|blocked
            source TEXT NOT NULL,            -- direct|admin
            amount INTEGER, currency TEXT,
            stripe_session TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS ota_busy (
            source TEXT NOT NULL,            -- airbnb|booking|...
            unit_key TEXT NOT NULL,          -- v1..v8, s1..s3
            type TEXT NOT NULL,              -- villa|studio
            dtstart TEXT NOT NULL,           -- inclusive date
            dtend TEXT NOT NULL,             -- exclusive date (iCal convention)
            uid TEXT,
            PRIMARY KEY (source, unit_key, dtstart, dtend, uid)
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
        // lazy migration: baby-cot flag on bookings (0/1)
        try { $db->exec('ALTER TABLE bookings ADD COLUMN cot INTEGER NOT NULL DEFAULT 0'); }
        catch (PDOException $e) { /* column already exists */ }
        // lazy migration: number of units booked (multi-villa parties)
        try { $db->exec('ALTER TABLE bookings ADD COLUMN rooms INTEGER NOT NULL DEFAULT 1'); }
        catch (PDOException $e) { /* column already exists */ }
        // lazy migration (calendar, 2026-11-11): keep the iCal SUMMARY
        // ("Reserved" = Airbnb guest, "Not available" = closed on Airbnb)
        try { $db->exec('ALTER TABLE ota_busy ADD COLUMN summary TEXT'); }
        catch (PDOException $e) { /* column already exists */ }
        // promo codes (2026-11-11, ported from Sonata v2.7): what was applied
        // to a booking and the price before it (amount stays the NET charged)
        foreach (['promo_code TEXT', 'promo_note TEXT', 'gross INTEGER', 'discount INTEGER'] as $c) {
            try { $db->exec('ALTER TABLE bookings ADD COLUMN ' . $c); }
            catch (PDOException $e) { /* column already exists */ }
        }
        // guest reviews (2026-11-11): written on the review page (iPad kiosk or
        // guest's own phone), emailed to the guest ready to paste on the portal
        // they booked through; staff picked by the guest earn salary bonuses.
        $db->exec('CREATE TABLE IF NOT EXISTS reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            code TEXT,                         -- booking ref when known (UV… / Beds24 id)
            guest TEXT, email TEXT, extra_email TEXT,
            source TEXT,                       -- airbnb|booking|direct|other
            rating INTEGER,                    -- 1..5
            text TEXT,
            staff TEXT                         -- JSON list of staff names credited (bonus)
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS promos (
            code TEXT PRIMARY KEY,             -- UPPERCASE letters/digits, e.g. TRANSFER26
            title TEXT NOT NULL,               -- banner text
            kind TEXT NOT NULL,                -- percent | service
            percent INTEGER,                   -- kind=percent: % off the room rate
            service TEXT,                      -- kind=service: what is included
            valid_until TEXT,                  -- last booking date the code works, optional
            min_nights INTEGER,                -- optional
            banner INTEGER NOT NULL DEFAULT 0, -- show in the top promotion bar
            active INTEGER NOT NULL DEFAULT 0, -- new codes start OFF (Andy, 2026-11-11)
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )');
    }
    return $db;
}

function uv_meta_get(string $k): ?string {
    $st = uv_db()->prepare('SELECT v FROM meta WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function uv_meta_set(string $k, string $v): void {
    uv_db()->prepare('INSERT INTO meta(k,v) VALUES(?,?)
        ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

/* ---------- OTA iCal import (our pull: fast; their pull of us: slow) ---------- */

function uv_refresh_ota(bool $force = false): array {
    $cfg = uv_config();
    $last = (int)(uv_meta_get('ota_fetched_at') ?? '0');
    $maxAge = $cfg['ota_refresh_seconds'] ?? 300;
    if (!$force && time() - $last < $maxAge) {
        return ['refreshed' => false, 'age' => time() - $last];
    }
    // Lock so parallel requests don't stampede the feeds.
    uv_meta_set('ota_fetched_at', (string)time());

    $feeds = $cfg['ical_feeds'] ?? [];
    $results = [];
    $db = uv_db();
    foreach ($feeds as $feed) {
        if (empty($feed['url'])) continue;
        $ics = uv_http_get($feed['url'], 8);
        if ($ics === null) { $results[$feed['unit']] = 'fetch-failed'; continue; }
        $events = uv_parse_ics($ics);
        $db->beginTransaction();
        $db->prepare('DELETE FROM ota_busy WHERE source=? AND unit_key=?')
           ->execute([$feed['source'], $feed['unit']]);
        $ins = $db->prepare('INSERT OR IGNORE INTO ota_busy(source,unit_key,type,dtstart,dtend,uid,summary)
                             VALUES(?,?,?,?,?,?,?)');
        foreach ($events as $ev) {
            $ins->execute([$feed['source'], $feed['unit'], $feed['type'],
                           $ev['start'], $ev['end'], $ev['uid'], $ev['summary']]);
        }
        $db->commit();
        $results[$feed['unit']] = count($events) . ' events';
    }
    return ['refreshed' => true, 'feeds' => $results];
}

function uv_http_get(string $url, int $timeout): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'UpperViewBooking/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return (is_string($body) && $code >= 200 && $code < 300) ? $body : null;
}

/** Minimal VEVENT parser: DTSTART/DTEND (date or datetime), UID. */
function uv_parse_ics(string $ics): array {
    $ics = str_replace(["\r\n ", "\r\n\t"], '', $ics); // unfold lines
    $events = [];
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) return $events;
    foreach ($blocks[1] as $b) {
        $get = function (string $prop) use ($b): ?string {
            return preg_match('/^' . $prop . '[^:]*:(.+)$/mi', $b, $m) ? trim($m[1]) : null;
        };
        $s = $get('DTSTART'); $e = $get('DTEND');
        if (!$s) continue;
        $start = uv_ics_date($s);
        $end   = $e ? uv_ics_date($e) : $start;
        if ($end <= $start) $end = date('Y-m-d', strtotime($start . ' +1 day'));
        $events[] = ['start' => $start, 'end' => $end, 'uid' => $get('UID') ?? '',
                     'summary' => (string)($get('SUMMARY') ?? '')];
    }
    return $events;
}

function uv_ics_date(string $v): string {
    $v = preg_replace('/[^0-9TZ]/', '', $v);
    return substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
}

/* ---------- Availability ---------- */

/** Units of a type that are OTA-busy or direct-booked, per date. */
function uv_availability(string $type, string $from, string $to): array {
    $cfg = uv_config();
    $total = $cfg['inventory'][$type] ?? 0;
    $db = uv_db();

    // Expire stale pending holds.
    $db->prepare("UPDATE bookings SET status='cancelled', updated_at=datetime('now')
                  WHERE status='pending' AND created_at < datetime('now','-25 minutes')")
       ->execute();

    $busy = array_fill_keys(uv_date_range($from, $to), 0);

    // OTA events: each busy unit_key blocks 1 unit on each covered date.
    $st = $db->prepare('SELECT unit_key, dtstart, dtend FROM ota_busy
                        WHERE type=? AND dtstart < ? AND dtend > ?');
    $st->execute([$type, $to, $from]);
    $otaPerDate = [];
    foreach ($st as $row) {
        foreach (uv_date_range(max($row['dtstart'], $from), min($row['dtend'], $to)) as $d) {
            $otaPerDate[$d][$row['unit_key']] = true;
        }
    }
    foreach ($otaPerDate as $d => $units) {
        if (isset($busy[$d])) $busy[$d] += count($units);
    }

    // Direct bookings (confirmed, fresh payment holds, admin blocks).
    // UNPAID requests ('inquiry') do NOT block availability — owner rule
    // 2026-11-11: an email/website request is PENDING until staff confirm it
    // and take payment; only then are dates closed.
    // A multi-villa booking takes `rooms` units per night, not 1.
    // $GLOBALS['uv_avail_exclude'] (set by uv_clash_nights) leaves out one
    // booking's own row, so re-checking a booking never clashes with itself.
    $ex = isset($GLOBALS['uv_avail_exclude']) ? (string)$GLOBALS['uv_avail_exclude'] : '';
    $st = $db->prepare("SELECT checkin, checkout, rooms FROM bookings
                        WHERE type=? AND status IN ('confirmed','pending','blocked')
                        AND code != ? AND checkin < ? AND checkout > ?");
    $st->execute([$type, $ex, $to, $from]);
    foreach ($st as $row) {
        $take = max(1, (int)($row['rooms'] ?? 1));
        foreach (uv_date_range(max($row['checkin'], $from), min($row['checkout'], $to)) as $d) {
            if (isset($busy[$d])) $busy[$d] += $take;
        }
    }

    $out = [];
    foreach ($busy as $d => $n) $out[$d] = max(0, $total - $n);
    return $out; // date => free unit count
}

/** Dates from $from inclusive to $to exclusive. */
function uv_date_range(string $from, string $to): array {
    $out = [];
    for ($d = $from; $d < $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $out[] = $d;
        if (count($out) > 400) break;
    }
    return $out;
}

/* ---------- Helpers ---------- */

function uv_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

const UV_TYPE_NAMES = [
    'villa' => 'Mini Villa', 'superior' => 'Superior Suite',
    'deluxe' => 'Deluxe Suite', 'king' => 'King Suite', 'studio' => 'Studio',
];
function uv_type_name(string $t): string { return UV_TYPE_NAMES[$t] ?? $t; }
function uv_valid_type(string $t): bool {
    return array_key_exists($t, uv_config()['inventory']);
}

function uv_valid_date(?string $d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

function uv_booking_code(): string {
    return 'UV' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/** Effective notification list: meta override (set via admin API) wins,
 *  then config 'notify_emails', then legacy single 'notify_email'. */
function uv_notify_list(): array {
    $meta = uv_meta_get('notify_emails');
    if ($meta !== null) {
        $j = json_decode($meta, true);
        if (is_array($j) && $j !== []) return array_values(array_unique(array_filter($j)));
    }
    $cfg = uv_config();
    $list = (!empty($cfg['notify_emails']) && is_array($cfg['notify_emails']))
        ? $cfg['notify_emails']
        : [(string)($cfg['notify_email'] ?? '')];
    return array_values(array_unique(array_filter($list)));
}

function uv_notify(string $subject, string $body): void {
    foreach (uv_notify_list() as $to) {
        @mail($to, $subject, $body,
              "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com",
              '-f booking@upperviewsamui.com');
    }
}

function uv_mail_guest(string $email, string $subject, string $body): void {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    @mail($email, $subject, $body,
          "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com",
          '-f booking@upperviewsamui.com');
}

/** Very small per-IP rate limit for public endpoints (calendar pictures). */
function uv_rate_limit(string $bucket, int $max, int $windowSec): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $k = 'rl_' . $bucket . '_' . md5($ip);
    $now = time();
    $raw = uv_meta_get($k);
    $hits = $raw ? array_filter(array_map('intval', explode(',', $raw)), function ($t) use ($now, $windowSec) { return $t > $now - $windowSec; }) : [];
    if (count($hits) >= $max) { http_response_code(429); exit('Too many requests, please try again later.'); }
    $hits[] = $now;
    uv_meta_set($k, implode(',', $hits));
}

/* ---------- promo codes (2026-11-11, ported from Sonata v2.7) ----------
   Managed on our own admin page (not in Beds24): a code changes the website's
   direct rate or adds an included service. The result (code, what it does,
   discount) is stored on the booking, shown at checkout and on the invoice
   email, and written to Beds24 (notes, info item, invoice lines) so staff and
   the WhatsApp agent can see it. The feature ships SWITCHED OFF: no codes
   exist, new codes start inactive, and the promotion bar only appears when an
   active code is marked for it. */

function uv_promo_code_clean(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }

/** What the promo does, in one line (invoice, emails, Beds24). */
function uv_promo_note(array $p): string {
    if ($p['kind'] === 'percent') return (int)$p['percent'] . '% off the room rate';
    return 'Included: ' . trim((string)$p['service']);
}

/** Look up a code for a stay. Returns the promo row (+ 'note'), or ['error' => message]. */
function uv_promo_find(string $code, ?string $ci = null, ?string $co = null): array {
    $code = uv_promo_code_clean($code);
    if ($code === '') return ['error' => 'empty'];
    $st = uv_db()->prepare('SELECT * FROM promos WHERE code=?'); $st->execute([$code]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || !(int)$p['active']) return ['error' => 'This promo code is not valid.'];
    if (!empty($p['valid_until']) && date('Y-m-d') > $p['valid_until']) return ['error' => 'This promo code has expired.'];
    if ($ci && $co && (int)$p['min_nights'] > 0) {
        $n = (int)((strtotime($co) - strtotime($ci)) / 86400);
        if ($n < (int)$p['min_nights']) return ['error' => 'This code needs a stay of ' . (int)$p['min_nights'] . ' nights or more.'];
    }
    $p['note'] = uv_promo_note($p);
    return $p;
}

/** Discount in whole baht for a gross amount (0 for service promos). */
function uv_promo_discount(?array $p, int $gross): int {
    if (!$p || isset($p['error']) || $p['kind'] !== 'percent') return 0;
    $pct = max(0, min(90, (int)$p['percent']));
    return (int)round($gross * $pct / 100);
}

/** Promotions shown in the bar at the top of the website. */
function uv_promos_banner(): array {
    $rows = uv_db()->query("SELECT code,title,kind,percent,service,valid_until FROM promos WHERE active=1 AND banner=1 ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    return array_values(array_filter($rows, function ($p) use ($today) { return empty($p['valid_until']) || $p['valid_until'] >= $today; }));
}

/* ---------- Payment adapter seam (stripe today, airwallex later) ---------- */

function uv_payment_provider(): string {
    $cfg = uv_config();
    if (!empty($cfg['airwallex']['client_id']) && !empty($cfg['airwallex']['api_key'])) return 'airwallex';
    if (!empty($cfg['stripe']['secret_key'])) return 'stripe';
    return 'none';
}

/** Signature for checkout-page links so booking codes can't be enumerated. */
function uv_pay_sig(string $code): string {
    $cfg = uv_config();
    return substr(hash_hmac('sha256', 'pay|' . $code, $cfg['admin_key']), 0, 16);
}

require_once __DIR__ . '/sheet-ping.php';   // near-real-time Google Sheet mirror

/** Create a hosted checkout for a booking. Returns ['url' => ...] or null. */
function uv_checkout_create(array $booking): ?array {
    if (uv_payment_provider() === 'airwallex') {
        $cfg = uv_config();
        $site = rtrim($cfg['site_url'], '/');
        return [
            'url' => $site . '/booking/pay.php?code=' . rawurlencode($booking['code'])
                   . '&sig=' . uv_pay_sig($booking['code']),
            'session_id' => 'awx',
        ];
    }
    if (uv_payment_provider() !== 'stripe') return null;
    $cfg = uv_config();
    $site = rtrim($cfg['site_url'], '/');
    $fields = [
        'mode' => 'payment',
        'success_url' => $site . '/?booked=' . $booking['code'] . '#top',
        'cancel_url'  => $site . '/?cancelled=' . $booking['code'] . '#top',
        'client_reference_id' => $booking['code'],
        'customer_email' => $booking['email'],
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => strtolower($booking['currency']),
        'line_items[0][price_data][unit_amount]' => $booking['amount'],
        'line_items[0][price_data][product_data][name]' =>
            'Upper View — ' . uv_type_name($booking['type'])
            . ' · ' . $booking['checkin'] . ' → ' . $booking['checkout'],
        'metadata[booking_code]' => $booking['code'],
    ];
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERPWD => $cfg['stripe']['secret_key'] . ':',
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($data) || empty($data['url'])) return null;
    return ['url' => $data['url'], 'session_id' => $data['id'] ?? ''];
}
