<?php
/**
 * Beds24 API v2 adapter. Beds24 is the master system when configured;
 * the local SQLite + iCal engine remains the tested FALLBACK:
 * - availability: beds24 when config['beds24']['source']==='beds24',
 *   automatic fallback to local on any API failure
 * - bookings: written to BOTH local DB (site record) and Beds24 (master)
 * Credentials: refresh token lives in SQLite meta (server-side only),
 * obtained once via beds24-setup.php; access tokens cached ~24h.
 */

declare(strict_types=1);

const B24_API = 'https://api.beds24.com/v2';

function b24_cfg(): array {
    return uv_config()['beds24'] ?? [];
}

function b24_enabled(): bool {
    return (b24_cfg()['source'] ?? 'local') === 'beds24' && uv_meta_get('b24_refresh') !== null;
}

/** Exchange invite code for a refresh token (called from beds24-setup.php). */
function b24_setup(string $inviteCode): array {
    $res = b24_http('GET', '/authentication/setup', null, [
        'code: ' . $inviteCode,
        'deviceName: upperview-site',
    ]);
    if (!is_array($res) || empty($res['refreshToken'])) {
        return ['ok' => false, 'detail' => $res];
    }
    uv_meta_set('b24_refresh', $res['refreshToken']);
    if (!empty($res['token'])) {
        uv_meta_set('b24_token', $res['token']);
        uv_meta_set('b24_token_exp', (string)(time() + 85000));
    }
    return ['ok' => true];
}

function b24_token(): ?string {
    $exp = (int)(uv_meta_get('b24_token_exp') ?? '0');
    $tok = uv_meta_get('b24_token');
    if ($tok !== null && $exp > time() + 60) return $tok;
    $refresh = uv_meta_get('b24_refresh');
    if ($refresh === null) return null;
    $res = b24_http('GET', '/authentication/token', null, ['refreshToken: ' . $refresh]);
    if (!is_array($res) || empty($res['token'])) return null;
    uv_meta_set('b24_token', $res['token']);
    uv_meta_set('b24_token_exp', (string)(time() + (int)($res['expiresIn'] ?? 86400) - 1800));
    return $res['token'];
}

function b24_api(string $method, string $path, ?array $body = null) {
    $tok = b24_token();
    if ($tok === null) return null;
    return b24_http($method, $path, $body, ['token: ' . $tok]);
}

function b24_http(string $method, string $path, ?array $body, array $headers) {
    $ch = curl_init(B24_API . $path);
    $headers[] = 'Accept: application/json';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $code < 200 || $code >= 300) {
        uv_meta_set('b24_last_error', gmdate('c') . " $method $path HTTP $code " . substr((string)$raw, 0, 300));
        return null;
    }
    return json_decode($raw, true);
}

/* ---------- availability (master: beds24, fallback: local) ---------- */

/** date => free unit count; falls back to the local engine on any failure. */
function uv_avail(string $type, string $from, string $to): array {
    if (b24_enabled()) {
        $a = b24_availability($type, $from, $to);
        if ($a !== null) {
            /* TRANSITION MODE ("both"): while the OTA channels are not yet
               connected in Beds24, Beds24 is blind to OTA bookings. If OTA
               iCal feeds are configured for this type, intersect: a date is
               only as free as the most pessimistic source says. Remove the
               feeds from config once Beds24 channel sync is live+verified. */
            $hasFeeds = false;
            foreach ((uv_config()['ical_feeds'] ?? []) as $f) {
                if (($f['type'] ?? '') === $type && !empty($f['url'])) { $hasFeeds = true; break; }
            }
            if ($hasFeeds) {
                uv_refresh_ota();
                $local = uv_availability($type, $from, $to);
                foreach ($a as $d => $n) $a[$d] = min($n, $local[$d] ?? $n);
            }
            return $a;
        }
        uv_notify('Upper View: Beds24 availability FAILED, using local fallback',
            'Check /booking/beds24-setup.php status. Last error: ' . (uv_meta_get('b24_last_error') ?? '?'));
    }
    uv_refresh_ota();
    return uv_availability($type, $from, $to);
}

/** No double bookings — ever (2026-11-11, mirrors Sonata v3.0): the nights in
 *  [$ci, $co) that can NOT take $rooms more unit(s) of this type right now —
 *  Beds24 (every portal it manages) + the OTA calendar feeds (pulled fresh) +
 *  our own bookings, excluding $excludeCode's own row so a booking never
 *  clashes with itself. Empty array = the stay can be confirmed. */
function uv_clash_nights(string $type, string $ci, string $co, int $rooms = 1, string $excludeCode = '', bool $fresh = true): array {
    static $pulled = false;
    if ($fresh && !$pulled) { uv_refresh_ota(true); $pulled = true; }
    $GLOBALS['uv_avail_exclude'] = $excludeCode;
    $a = uv_avail($type, $ci, $co);
    unset($GLOBALS['uv_avail_exclude']);
    $need = max(1, $rooms);
    $out = [];
    foreach ($a as $d => $n) if ((int)$n < $need) $out[] = $d;
    return $out;
}

function b24_availability(string $type, string $from, string $to): ?array {
    $roomId = b24_cfg()['room_map'][$type] ?? null;
    if ($roomId === null) return null;
    $lastDate = date('Y-m-d', strtotime($to . ' -1 day'));
    $res = b24_api('GET', '/inventory/rooms/calendar?roomId=' . $roomId
        . '&startDate=' . $from . '&endDate=' . $lastDate . '&includeNumAvail=true');
    if (!is_array($res) || !isset($res['data'][0]['calendar'])) return null;
    $out = [];
    foreach ($res['data'][0]['calendar'] as $range) {
        if (!isset($range['from'], $range['to'])) continue;
        $n = max(0, (int)($range['numAvail'] ?? 0));
        for ($d = max($range['from'], $from); $d <= min($range['to'], $lastDate);
             $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[$d] = $n;
        }
    }
    // any date the calendar left out counts as closed, not open
    foreach (uv_date_range($from, $to) as $d) {
        if (!isset($out[$d])) $out[$d] = 0;
    }
    ksort($out);
    return $out;
}

/** Create the booking in Beds24. Returns beds24 booking id or null.
 *  $b['status']==='inquiry' writes a PENDING request (Beds24 status 'inquiry',
 *  which does NOT deduct inventory — owner rule 2026-11-11: unpaid requests
 *  never close dates). If this code was already written as an inquiry, the
 *  existing Beds24 booking is UPDATED instead of duplicated. */
function b24_create_booking(array $b): ?int {
    $roomId = b24_cfg()['room_map'][$b['type']] ?? null;
    if ($roomId === null || !b24_enabled()) return null;
    $pending = ($b['status'] ?? '') === 'inquiry';
    $name = trim($b['name']);
    $sp = strrpos($name, ' ');
    $first = $sp ? substr($name, 0, $sp) : $name;
    $last = $sp ? substr($name, $sp + 1) : '';
    // Notes written so the AI assistant (Yomied) can read, per booking:
    // where it was made, whether payment was taken, and OTA calendar status.
    $notes = ['SOURCE: ' . (string)($b['source_label'] ?? 'direct website upperviewsamui.com') . ' — ref ' . $b['code']];
    if (!empty($b['paid'])) {
        $notes[] = 'PAYMENT: PAID'
            . (!empty($b['amount']) ? ' ' . number_format((int)$b['amount']) . ' THB' : '')
            . ' via ' . ($b['provider'] ?? 'card') . ' (verified online payment)';
    } else {
        $notes[] = 'PAYMENT: NOT TAKEN — '
            . (!empty($b['manual']) ? 'manually confirmed from the admin page, collect payment separately'
                                    : ($pending ? 'PENDING request, awaiting staff confirmation' : 'unpaid request'));
    }
    if ($pending) {
        $notes[] = 'PENDING REQUEST: does not close availability. To accept: confirm + take payment '
                 . '(admin page, Beds24, or the WhatsApp assistant).';
    } else {
        $notes[] = 'OTA CALENDARS: CLOSED automatically by this Beds24 booking '
                 . '(availability reduced on Booking.com / Agoda / Airbnb / all channels)';
    }
    if (!empty($b['cot'])) $notes[] = 'BABY COT requested (free option, suites only)';
    $qty = max(1, (int)($b['rooms'] ?? 1));
    if ($qty > 1) $notes[] = "MULTI-UNIT: $qty mini villas booked together (party of " . (int)$b['guests'] . ')';
    // Promo (2026-11-11): the code and what it gives, readable by staff and the
    // WhatsApp agent; services get their own TO-ARRANGE line.
    $promo = !empty($b['promo_code']) ? $b['promo_code'] . ' — ' . (string)($b['promo_note'] ?? '') : null;
    $pDisc = (int)($b['discount'] ?? 0);
    if ($promo) $notes[] = 'PROMO: ' . $promo . ($pDisc ? ' (−' . number_format($pDisc) . ' THB, already off the total)' : '');
    if ($promo && strpos((string)($b['promo_note'] ?? ''), 'Included:') === 0) {
        $notes[] = 'SERVICE TO ARRANGE: ' . preg_replace('/^Included:\s*/', '', (string)$b['promo_note']);
    }
    $payload = [[
        'roomId' => $roomId,
        'roomQty' => $qty,
        'status' => $pending ? 'inquiry' : ($b['status'] === 'confirmed' ? 'confirmed' : 'new'),
        'arrival' => $b['checkin'],
        'departure' => $b['checkout'],
        'numAdult' => (int)$b['guests'],
        'firstName' => $first,
        'lastName' => $last,
        'email' => $b['email'],
        'mobile' => $b['phone'],
        'referer' => 'upperviewsamui.com',
        'apiReference' => $b['code'],
        'notes' => implode("\n", $notes),
    ]];
    if ($promo) $payload[0]['infoItems'] = [['code' => 'PROMO', 'text' => $promo]];
    if (!empty($b['request_note'])) {
        $payload[0]['infoItems'][] = ['code' => 'REQUEST', 'text' => mb_substr((string)$b['request_note'], 0, 300)];
    }
    // Same code already in Beds24 (a pending request being confirmed, or a
    // re-send): UPDATE that booking instead of creating a duplicate.
    $known = (int)(uv_meta_get('b24id_' . $b['code']) ?? 0);
    if ($known > 0) $payload[0]['id'] = $known;
    $res = b24_api('POST', '/bookings', $payload);
    $newId = null;
    if (is_array($res) && isset($res[0]['new']['id'])) $newId = (int)$res[0]['new']['id'];
    elseif (is_array($res) && isset($res[0]['modified']) && $known > 0) $newId = $known;
    elseif (is_array($res) && isset($res[0]['success']) && $res[0]['success'] && isset($res[0]['id'])) $newId = (int)$res[0]['id'];
    if ($newId !== null) {
        uv_meta_set('b24id_' . $b['code'], (string)$newId);
        if ($pending) return $newId;   // no invoice lines on a pending request
        // Best-effort: record the charge (at the gross rate), the promo line and
        // the payment on the Beds24 invoice so money is visible in the booking's
        // invoice tab, not only in the notes.
        if (!empty($b['amount'])) {
            $gross = (int)($b['gross'] ?? 0); if ($gross <= 0) $gross = (int)$b['amount'];
            $items = [['type' => 'charge', 'description' => 'Direct booking ' . $b['code'],
                       'qty' => 1, 'amount' => $gross]];
            if ($promo) $items[] = ['type' => 'charge', 'description' => 'Promo ' . $promo,
                                    'qty' => 1, 'amount' => $pDisc ? -$pDisc : 0];
            if (!empty($b['paid'])) $items[] = ['type' => 'payment',
                'description' => 'Paid online via ' . ($b['provider'] ?? 'card'), 'amount' => (int)$b['amount']];
            b24_api('POST', '/bookings', [['id' => $newId, 'invoiceItems' => $items]]);
        }
        return $newId;
    }
    uv_notify('Upper View: Beds24 booking write FAILED for ' . $b['code'],
        'The booking exists on the website DB but NOT in Beds24. Add it manually. '
        . 'Last error: ' . (uv_meta_get('b24_last_error') ?? json_encode($res)));
    return null;
}

/**
 * Live total for a stay: Beds24 nightly price1 summed * direct multiplier,
 * with minimum availability across the range. Local fallback uses config
 * rates. Returns ['free'=>int,'total'=>int|null] — total in whole THB.
 */
function uv_live_total(string $type, string $from, string $to): array {
    $cfg = uv_config();
    $nights = (int)((strtotime($to) - strtotime($from)) / 86400);
    $mult = $cfg['direct_multiplier'] ?? 0.90;
    $roomId = $cfg['beds24']['room_map'][$type] ?? null;
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
            if ($covered === $nights && $sum > 0) {
                return ['free' => $freeMin === PHP_INT_MAX ? 0 : $freeMin,
                        'total' => (int)round($sum * $mult)];
            }
        }
    }
    $f = uv_avail($type, $from, $to);
    $free = $f ? min($f) : 0;
    $rate = $cfg['rates'][$type] ?? 0;
    return ['free' => $free, 'total' => $rate > 0 ? $rate * $nights : null];
}
