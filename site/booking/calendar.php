<?php
/**
 * Admin occupancy calendar (2026-11-11, ported from Sonata v3.0): ONE month at a time,
 * one row per room type, every stay drawn as a bar coloured by where it came from
 * (Airbnb, Booking.com, Agoda, direct website…). Click a bar for the booking details
 * (loaded live from Beds24). Calendar / List switch, room filter.
 *
 * Upper View difference vs Sonata: the Mini Villa row is a POOL of 8 units, so
 * overlapping bars there are normal. "Double booking" on a pooled row means a night
 * where more units are booked than exist (weight > capacity); on the one-of-each
 * suites (capacity 1) it is any two overlapping stays, exactly like Sonata.
 *
 * Sources, merged per room type:
 *   1. Beds24 bookings (channel / apiSource / referer say where they came from)
 *   2. Our own bookings table (direct requests, confirmed bookings, admin blocks, clash)
 *   3. The Airbnb iCal feeds (ota_busy) — only events no earlier bar fully covers
 */
declare(strict_types=1);

const UV_CAL_SOURCES = [
    // key => [label, bar colour, badge text, badge text colour]
    'airbnb'  => ['Airbnb', '#FF385C', 'A', '#fff'],
    'booking' => ['Booking.com', '#003580', 'B.', '#fff'],
    'agoda'   => ['Agoda', '#5A2C82', 'ag', '#fff'],
    'trip'    => ['Trip.com', '#287DFA', 'T', '#fff'],
    'expedia' => ['Expedia', '#00355F', 'E', '#FFC72C'],
    'vrbo'    => ['Vrbo', '#245ABC', 'V', '#fff'],
    'direct'  => ['Direct (our website)', '#4F6446', 'UV', '#fff'],
    'request' => ['Request, not confirmed', '#F7F0E9', '?', '#4F6446'],
    'blocked' => ['Blocked / closed', '#8A8378', '✕', '#fff'],
    'other'   => ['Other channel', '#6B7352', '•', '#fff'],
];

/** Which source a Beds24 booking came from. */
function uv_cal_b24_source(array $b): string {
    if (($b['status'] ?? '') === 'black') return 'blocked';
    $hay = strtolower(($b['channel'] ?? '') . ' ' . ($b['apiSource'] ?? '') . ' ' . ($b['referer'] ?? ''));
    foreach (['airbnb' => 'airbnb', 'booking' => 'booking', 'agoda' => 'agoda', 'trip' => 'trip', 'ctrip' => 'trip',
              'expedia' => 'expedia', 'vrbo' => 'vrbo', 'homeaway' => 'vrbo', 'upperviewsamui' => 'direct'] as $needle => $src) {
        if (strpos($hay, $needle) !== false) return $src;
    }
    if (preg_match('/^UV/', (string)($b['apiReference'] ?? ''))) return 'direct';
    if (in_array($b['status'] ?? '', ['request', 'inquiry', 'new'], true) && trim($hay) === '') return 'request';
    return trim($hay) === '' || ($b['channel'] ?? '') === 'direct' ? 'direct' : 'other';
}

/** Beds24 bookings touching [$first, $after) for this property; cached 2 minutes. null = Beds24 not reachable. */
function uv_cal_b24_month(string $first, string $after): ?array {
    if (!function_exists('b24_enabled') || !b24_enabled()) return null;
    $key = 'cal_b24_' . $first;
    $c = json_decode((string) uv_meta_get($key), true);
    if (is_array($c) && time() - (int)$c['at'] < 120) return $c['rows'];
    $pid = (int)(b24_cfg()['property_id'] ?? 365322);   // Upper View on Beds24
    $rows = [];
    for ($page = 1; $page <= 6; $page++) {
        $res = b24_api('GET', '/bookings?propertyId=' . $pid . '&arrivalTo=' . date('Y-m-d', strtotime($after . ' -1 day'))
            . '&departureFrom=' . $first . '&page=' . $page);
        if (!is_array($res) || !isset($res['data'])) return $page === 1 ? null : $rows;
        foreach ($res['data'] as $b) $rows[] = $b;
        if (empty($res['pages']['nextPageExists'])) break;
    }
    uv_meta_set($key, json_encode(['at' => time(), 'rows' => $rows]));
    return $rows;
}

/** One booking from Beds24, with invoice and info items, for the click-through panel. */
function uv_cal_b24_detail(int $id): array {
    if ($id <= 0 || !function_exists('b24_enabled') || !b24_enabled()) return ['error' => 'Beds24 is not connected.'];
    $res = b24_api('GET', '/bookings?id=' . $id . '&includeInvoiceItems=true&includeInfoItems=true');
    if (!is_array($res) || empty($res['data'][0])) return ['error' => 'Beds24 did not return booking ' . $id . '.'];
    $b = $res['data'][0];
    $keep = ['id', 'status', 'subStatus', 'arrival', 'departure', 'arrivalTime', 'numAdult', 'numChild', 'title', 'firstName', 'lastName',
             'email', 'phone', 'mobile', 'country', 'lang', 'channel', 'apiSource', 'referer', 'apiReference', 'reference', 'price', 'deposit',
             'tax', 'commission', 'comments', 'notes', 'message', 'groupNote', 'bookingTime', 'modifiedTime', 'masterId', 'rateDescription', 'roomQty'];
    $out = array_intersect_key($b, array_flip($keep));
    $out['invoiceItems'] = array_map(function ($i) {
        return array_intersect_key($i, array_flip(['type', 'description', 'qty', 'amount', 'lineTotal', 'createTime']));
    }, $b['invoiceItems'] ?? []);
    $out['infoItems'] = array_map(function ($i) { return array_intersect_key($i, array_flip(['code', 'text'])); }, $b['infoItems'] ?? []);
    $out['source'] = uv_cal_b24_source($b);
    return $out;
}

/**
 * Bars for one month. Returns ['bars' => [type => [bar…]], 'b24ok' => bool, 'first', 'after'];
 * each bar: from, to (check-in / check-out), src (UV_CAL_SOURCES key), qty (units it takes),
 * label, info (for the panel), b24 (Beds24 id or 0).
 */
function uv_cal_month(string $ym): array {
    $first = $ym . '-01';
    $after = date('Y-m-d', strtotime($first . ' +1 month'));
    $types = array_keys(uv_config()['inventory'] ?? []);
    $bars = array_fill_keys($types, []);
    $roomToType = array_flip(array_map('intval', b24_cfg()['room_map'] ?? []));
    $nights = function (string $a, string $b): int { return (int) round((strtotime($b) - strtotime($a)) / 86400); };

    // 1. Beds24
    $b24 = uv_cal_b24_month($first, $after);
    $b24refs = [];
    foreach ($b24 ?? [] as $b) {
        $t = $roomToType[(int)($b['roomId'] ?? 0)] ?? null;
        if ($t === null || ($b['status'] ?? '') === 'cancelled' || empty($b['arrival']) || empty($b['departure'])) continue;
        if ($b['departure'] <= $first || $b['arrival'] >= $after) continue;
        $src = uv_cal_b24_source($b);
        $qty = max(1, (int)($b['roomQty'] ?? 1));
        $name = trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''));
        if (!empty($b['apiReference'])) $b24refs[$b['apiReference']] = true;
        // The list endpoint blanks apiReference, but our code is in the notes we
        // write on every booking ("… ref UVxxxxxx" / "Direct website booking UVxxxxxx").
        if (!empty($b['notes']) && preg_match_all('/\b(UV[0-9A-F]{6})\b/', (string)$b['notes'], $mm)) {
            foreach ($mm[1] as $ref) $b24refs[$ref] = true;
        }
        $bars[$t][] = ['from' => $b['arrival'], 'to' => $b['departure'], 'src' => $src, 'qty' => $qty, 'b24' => (int)$b['id'],
            'label' => ($qty > 1 ? $qty . ' × ' : '') . ($src === 'blocked' ? 'Blocked' : ($name !== '' ? $name : UV_CAL_SOURCES[$src][0])),
            'info' => array_filter([
                'Guest' => $name, 'Source' => UV_CAL_SOURCES[$src][0] . (!empty($b['apiSource']) ? ' (' . $b['apiSource'] . ')' : ''),
                'Status' => $b['status'] ?? '', 'Check-in' => $b['arrival'], 'Check-out' => $b['departure'],
                'Nights' => (string) $nights($b['arrival'], $b['departure']),
                'Units' => $qty > 1 ? $qty . ' × ' . uv_type_name($t) : '',
                'Guests' => trim(((int)($b['numAdult'] ?? 0)) . ' adults' . (!empty($b['numChild']) ? ', ' . (int)$b['numChild'] . ' children' : '')),
                'Price' => !empty($b['price']) ? number_format((float)$b['price']) . ' THB' : '',
                'Beds24 ID' => (string)($b['id'] ?? ''), 'Channel ref' => (string)($b['apiReference'] ?? ''),
            ], 'strlen')];
    }

    // 2. Our own bookings table (skip rows our code already wrote to Beds24)
    $st = uv_db()->prepare("SELECT * FROM bookings WHERE status IN ('confirmed','blocked','inquiry','pending','clash')
                            AND checkin < ? AND checkout > ? ORDER BY checkin");
    $st->execute([$after, $first]);
    foreach ($st as $r) {
        $t = $r['type'];
        if (!isset($bars[$t]) || isset($b24refs[$r['code']])) continue;
        $src = $r['status'] === 'blocked' ? 'blocked' : ($r['status'] === 'confirmed' ? 'direct' : 'request');
        // Fallback dedupe: our rows written to Beds24 sometimes come back with an
        // empty apiReference, so also treat a Beds24 bar with the SAME type and
        // IDENTICAL dates as the same stay when it is the same kind of entry
        // (both blocks, a matching channel ref, or the same guest name).
        $name = trim((string)$r['name']);
        $dupe = false;
        foreach ($bars[$t] as $x) {
            if ($x['b24'] <= 0 || $x['from'] !== $r['checkin'] || $x['to'] !== $r['checkout']) continue;
            if ($src === 'blocked' && $x['src'] === 'blocked') { $dupe = true; break; }
            if (($x['info']['Channel ref'] ?? '') === $r['code']) { $dupe = true; break; }
            if ($src !== 'blocked' && $name !== '' && stripos((string)$x['label'], $name) !== false) { $dupe = true; break; }
        }
        if ($dupe) continue;
        $qty = max(1, (int)($r['rooms'] ?? 1));
        $bars[$t][] = ['from' => $r['checkin'], 'to' => $r['checkout'], 'src' => $src, 'qty' => $qty, 'b24' => 0,
            'label' => ($qty > 1 ? $qty . ' × ' : '') . ($src === 'blocked' ? ('Blocked' . ($r['name'] ? ' · ' . $r['name'] : '')) : (string)$r['name']),
            'info' => array_filter([
                'Guest' => (string)$r['name'], 'Source' => UV_CAL_SOURCES[$src][0] . ' · our ref ' . $r['code'],
                'Status' => $r['status'], 'Check-in' => $r['checkin'], 'Check-out' => $r['checkout'],
                'Nights' => (string) $nights($r['checkin'], $r['checkout']),
                'Units' => $qty > 1 ? $qty . ' × ' . uv_type_name($t) : '',
                'Guests' => (string)(int)$r['guests'] . (!empty($r['cot']) ? ' + baby cot' : ''),
                'Email' => (string)$r['email'], 'Phone' => (string)$r['phone'],
                'Amount' => $r['amount'] ? number_format((float)$r['amount']) . ' ' . $r['currency'] : '',
                'Note' => $r['status'] === 'clash' ? 'PAID but NOT confirmed: the nights were taken before the payment finished. Offer other dates/rooms or refund.' : ($src === 'request' ? 'Not confirmed yet: these nights are still open on the OTAs.' : ''),
            ], 'strlen')];
    }

    // 3. Airbnb iCal feeds: only events no earlier bar FULLY covers (full-coverage
    //    test, not any-overlap: in the villa pool two different units genuinely overlap).
    $st = uv_db()->prepare('SELECT * FROM ota_busy WHERE dtstart < ? AND dtend > ? ORDER BY dtstart');
    $st->execute([$after, $first]);
    foreach ($st as $o) {
        $t = $o['type'];
        if (!isset($bars[$t])) continue;
        $covered = false;
        foreach ($bars[$t] as $x) if ($x['from'] <= $o['dtstart'] && $x['to'] >= $o['dtend']) { $covered = true; break; }
        if ($covered) continue;
        $sum = (string)($o['summary'] ?? '');
        $reserved = $sum === '' || stripos($sum, 'reserved') !== false;
        $src = $reserved ? 'airbnb' : 'blocked';
        $bars[$t][] = ['from' => $o['dtstart'], 'to' => $o['dtend'], 'src' => $src, 'qty' => 1, 'b24' => 0,
            'label' => ($reserved ? 'Airbnb guest' : 'Closed on Airbnb') . ' (' . $o['unit_key'] . ')',
            'info' => array_filter([
                'Unit' => (string)$o['unit_key'],
                'Source' => $reserved ? 'Airbnb (from the Airbnb calendar feed)' : 'Closed on the Airbnb calendar: another channel\'s booking or a manual block',
                'Feed says' => $sum, 'From' => $o['dtstart'], 'To' => $o['dtend'], 'Nights' => (string) $nights($o['dtstart'], $o['dtend']),
                'Note' => 'Not in Beds24, so there are no guest details. Open the listing calendar on Airbnb for more.',
            ], 'strlen')];
    }
    foreach ($bars as &$list) usort($list, function ($a, $b) { return strcmp($a['from'], $b['from']); });
    unset($list);
    return ['bars' => $bars, 'b24ok' => $b24 !== null, 'first' => $first, 'after' => $after];
}

/**
 * Conflicts for one row, capacity-aware. A night is OVERBOOKED when the summed
 * weight (qty) of real stays + blocks exceeds the row's unit count. Returns
 * [flags per bar index ('dbl'|'req-clash'|''), per-night weight, overbooked nights].
 */
function uv_cal_conflicts(array $bars, int $cap): array {
    $flag = array_fill(0, count($bars), '');
    $w = [];
    foreach ($bars as $b) {
        if ($b['src'] === 'request') continue;
        for ($d = $b['from']; $d < $b['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $w[$d] = ($w[$d] ?? 0) + max(1, (int)($b['qty'] ?? 1));
        }
    }
    $over = [];
    foreach ($w as $d => $n) if ($n > $cap) $over[$d] = $n;
    foreach ($bars as $i => $b) {
        if ($b['src'] === 'request') {
            for ($d = $b['from']; $d < $b['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (($w[$d] ?? 0) + max(1, (int)($b['qty'] ?? 1)) > $cap) { $flag[$i] = 'req-clash'; break; }
            }
            continue;
        }
        foreach ($over as $d => $n) {
            if ($d >= $b['from'] && $d < $b['to']) { $flag[$i] = 'dbl'; break; }
        }
    }
    ksort($over);
    return [$flag, $w, $over];
}

/** Consecutive overbooked dates folded to "j M → j M: N bookings for C units" strings. */
function uv_cal_over_ranges(array $over, int $cap): array {
    $out = []; $run = null; $prev = null; $peak = 0;
    foreach ($over as $d => $n) {
        if ($run === null) { $run = $d; $peak = $n; }
        elseif ($prev !== null && strtotime($d) - strtotime($prev) > 86400) {
            $out[] = [$run, $prev, $peak]; $run = $d; $peak = $n;
        }
        $peak = max($peak, $n); $prev = $d;
    }
    if ($run !== null) $out[] = [$run, $prev, $peak];
    $msgs = [];
    foreach ($out as $x) {
        $msgs[] = date('j M Y', strtotime($x[0])) . ($x[1] !== $x[0] ? ' → ' . date('j M Y', strtotime($x[1])) : '')
                . ': ' . $x[2] . ' booking' . ($x[2] > 1 ? 's' : '') . ' for ' . $cap . ' unit' . ($cap > 1 ? 's' : '');
    }
    return $msgs;
}

/** The calendar section HTML (calendar + list views). $self = '?k=…' (admin link), $ym = 'YYYY-MM'. */
function uv_cal_render(string $self, string $ym): string {
    $d = uv_cal_month($ym);
    $first = $d['first']; $days = (int) date('t', strtotime($first));
    $today = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
    $cfg = uv_config();
    $inv = $cfg['inventory'] ?? [];
    $minN = (int)($cfg['min_nights'] ?? 1);
    $prev = date('Y-m', strtotime($first . ' -1 month')); $next = date('Y-m', strtotime($first . ' +1 month'));
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $nightsOf = function ($b) { return (int) round((strtotime($b['to']) - strtotime($b['from'])) / 86400); };
    $attrs = function ($b, $extraInfo = []) use ($e) {
        $s = UV_CAL_SOURCES[$b['src']];
        return ' data-b24="' . (int)$b['b24'] . '" data-src="' . $e($b['src']) . '" data-info="' . $e(json_encode($extraInfo + $b['info'])) . '" style="--c:' . $s[1] . ';--t:' . $s[3] . '"';
    };

    $o = '<div class="cal-nav"><a class="cbtn" href="' . $e($self . '&m=' . $prev) . '#calendar">◀ ' . $e(date('M', strtotime($prev . '-01'))) . '</a>'
       . '<b class="cal-title">' . $e(date('F Y', strtotime($first))) . '</b>'
       . '<a class="cbtn" href="' . $e($self . '&m=' . $next) . '#calendar">' . $e(date('M', strtotime($next . '-01'))) . ' ▶</a>'
       . ($ym !== date('Y-m') ? '<a class="cbtn ghost" href="' . $e($self) . '#calendar">This month</a>' : '')
       . '<span class="seg" role="group" aria-label="View"><button type="button" data-view="cal" class="on">Calendar</button><button type="button" data-view="list">List</button></span>'
       . '<select id="calvilla" aria-label="Room"><option value="">All rooms</option>';
    foreach (array_keys($d['bars']) as $t) $o .= '<option value="' . $e($t) . '">' . $e(uv_type_name($t)) . '</option>';
    $o .= '</select><span class="cal-src">' . ($d['b24ok'] ? 'Live from Beds24 + our bookings + the Airbnb calendar feeds' : 'Beds24 not reachable — showing our bookings + the Airbnb calendar feeds only') . '</span></div>';

    // conflicts first, so they show at the top
    $flags = []; $weights = []; $alertsHtml = '';
    foreach ($d['bars'] as $t => $bars) {
        $cap = max(1, (int)($inv[$t] ?? 1));
        [$flags[$t], $weights[$t], $over] = uv_cal_conflicts($bars, $cap);
        foreach (uv_cal_over_ranges($over, $cap) as $m) {
            $alertsHtml .= '<li><b>' . $e(uv_type_name($t)) . ':</b> ' . $e($m) . '</li>';
        }
    }
    if ($alertsHtml !== '') $o .= '<div class="cal-alert"><b>Double booking — act now.</b> More stays than units on the same night. Move a guest to another room or other dates (or cancel one) today.<ul>' . $alertsHtml . '</ul></div>';

    /* ---- calendar view ---- */
    $o .= '<div class="cal-view" data-view="cal"><div class="cal-wrap"><div class="cal" style="--n:' . $days . '">';
    $o .= '<div class="cal-row cal-head"><div class="cal-name"></div><div class="cal-days">';
    for ($i = 1; $i <= $days; $i++) {
        $dt = $ym . '-' . sprintf('%02d', $i); $w = (int) date('N', strtotime($dt));
        $o .= '<span class="' . ($w >= 6 ? 'we ' : '') . ($dt === $today ? 'td' : '') . '"><small>' . $e(substr(date('D', strtotime($dt)), 0, 2)) . '</small>' . $i . '</span>';
    }
    $o .= '</div></div>';
    foreach ($d['bars'] as $t => $bars) {
        $cap = max(1, (int)($inv[$t] ?? 1));
        $wN = $weights[$t];
        $free = 0;
        for ($i = 1; $i <= $days; $i++) { if (($wN[$ym . '-' . sprintf('%02d', $i)] ?? 0) < $cap) $free++; }
        // lanes: stack overlapping bars (normal in the villa pool)
        $laneEnd = []; $lanes = [];
        foreach ($bars as $i => $b) { $l = 0; while (isset($laneEnd[$l]) && $laneEnd[$l] > $b['from']) $l++; $laneEnd[$l] = $b['to']; $lanes[$i] = $l; }
        $o .= '<div class="cal-row" data-villa="' . $e($t) . '" style="--lanes:' . max(1, count($laneEnd)) . '"><div class="cal-name"><b>' . $e(uv_type_name($t)) . '</b><small>'
            . ($cap > 1 ? $cap . ' units · ' : '') . $free . ' free night' . ($free === 1 ? '' : 's') . '</small></div><div class="cal-days">';
        for ($i = 1; $i <= $days; $i++) {
            $dt = $ym . '-' . sprintf('%02d', $i); $w = (int) date('N', strtotime($dt));
            $o .= '<span class="' . ($w >= 6 ? 'we ' : '') . ($dt === $today ? 'td ' : '') . ((($wN[$dt] ?? 0) < $cap) ? 'fr' : '') . '"></span>';
        }
        // short gaps between two stays — only meaningful on one-unit rows
        if ($cap === 1 && $minN > 1) {
            $sorted = array_values(array_filter($bars, function ($b) { return $b['src'] !== 'request'; }));
            for ($k = 1; $k < count($sorted); $k++) {
                $gFrom = $sorted[$k - 1]['to']; $gTo = $sorted[$k]['from'];
                $gn = (int) round((strtotime($gTo) - strtotime($gFrom)) / 86400);
                if ($gn > 0 && $gn < $minN && $gTo > $first && $gFrom < $d['after']) {
                    $a = max(0, (strtotime($gFrom) - strtotime($first)) / 86400 + 0.5);
                    $z = min($days, (strtotime($gTo) - strtotime($first)) / 86400 + 0.5);
                    $o .= '<em class="gap" style="left:calc(' . $a . ' / var(--n) * 100%);width:calc(' . ($z - $a) . ' / var(--n) * 100%)" title="' . $gn . '-night gap (minimum stay ' . $minN . '): hard to sell"></em>';
                }
            }
        }
        foreach ($bars as $i => $b) {
            $s = UV_CAL_SOURCES[$b['src']];
            $a = (strtotime($b['from']) - strtotime($first)) / 86400 + 0.5;   // check-in afternoon
            $z = (strtotime($b['to']) - strtotime($first)) / 86400 + 0.5;     // check-out morning
            $cutL = $a < 0; $cutR = $z > $days;
            $a = max(0, $a); $z = min($days, $z);
            if ($z <= $a) continue;
            $n = $nightsOf($b); $fl = $flags[$t][$i];
            $extra = $fl === 'dbl' ? ['WARNING' => 'DOUBLE BOOKING: this night has more stays than units'] : ($fl === 'req-clash' ? ['WARNING' => 'These nights are already taken: this request cannot be confirmed'] : []);
            $o .= '<button type="button" class="bar s-' . $b['src'] . ($cutL ? ' cl' : '') . ($cutR ? ' cr' : '') . ($fl ? ' ' . $fl : '') . '"'
                . str_replace(' style="', ' style="top:' . (9 + 40 * $lanes[$i]) . 'px;left:calc(' . $a . ' / var(--n) * 100%);width:calc(' . ($z - $a) . ' / var(--n) * 100%);', $attrs($b, $extra))
                . ' title="' . $e(($fl === 'dbl' ? 'DOUBLE BOOKING · ' : '') . $s[0] . ' · ' . $b['label'] . ' · ' . $b['from'] . ' → ' . $b['to'] . ' (' . $n . ' nights)') . '">'
                . '<i class="cb">' . $e($s[2]) . '</i><span>' . ($fl === 'dbl' ? '⚠ ' : '') . $e($b['label']) . ' · ' . $n . 'n</span></button>';
        }
        $o .= '</div></div>';
    }
    $o .= '</div></div>';
    $o .= '<div class="cal-legend">';
    foreach (UV_CAL_SOURCES as $k => $s) {
        $o .= '<span><i class="bar-mini s-' . $k . '" style="--c:' . $s[1] . ';--t:' . $s[3] . '"><i class="cb">' . $e($s[2]) . '</i></i>' . $e($s[0]) . '</span>';
    }
    if ($minN > 1) $o .= '<span><i class="gap-mini"></i>Gap shorter than the ' . $minN . '-night minimum</span>';
    $o .= '<span><i class="dbl-mini"></i>Double booking (never allowed)</span></div>';
    $o .= '<p class="hint">Bars start on the check-in afternoon and end on the check-out morning. The Mini Villa row is a pool of '
        . (int)($inv['villa'] ?? 8) . ' villas, so overlapping bars there are normal — red means MORE stays than villas on a night. White days have at least one free unit. Click a bar for the booking details.</p></div>';

    /* ---- list view ---- */
    $all = [];
    foreach ($d['bars'] as $t => $bars) foreach ($bars as $i => $b) { $b['villa'] = $t; $b['flag'] = $flags[$t][$i]; $all[] = $b; }
    usort($all, function ($a, $b) { return strcmp($a['from'] . $a['villa'], $b['from'] . $b['villa']); });
    $o .= '<div class="cal-view" data-view="list" hidden><table class="cal-list"><tr><th>Source</th><th>Room</th><th>Guest</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Status</th></tr>';
    if (!$all) $o .= '<tr><td colspan="7">Nothing booked this month.</td></tr>';
    foreach ($all as $b) {
        $s = UV_CAL_SOURCES[$b['src']];
        $extra = $b['flag'] === 'dbl' ? ['WARNING' => 'DOUBLE BOOKING: this night has more stays than units'] : ($b['flag'] === 'req-clash' ? ['WARNING' => 'These nights are already taken: this request cannot be confirmed'] : []);
        $o .= '<tr class="lrow' . ($b['flag'] ? ' ' . $b['flag'] : '') . '" data-villa="' . $e($b['villa']) . '"' . $attrs($b, $extra) . '>'
            . '<td><i class="cb solid">' . $e($s[2]) . '</i> ' . $e($s[0]) . '</td><td>' . $e(uv_type_name($b['villa'])) . '</td><td>' . ($b['flag'] === 'dbl' ? '⚠ ' : '') . $e($b['label']) . '</td>'
            . '<td>' . $e(date('D j M', strtotime($b['from']))) . '</td><td>' . $e(date('D j M', strtotime($b['to']))) . '</td><td>' . $nightsOf($b) . '</td>'
            . '<td>' . $e($b['info']['Status'] ?? ($b['src'] === 'blocked' ? 'closed' : '')) . ($b['flag'] === 'dbl' ? ' · <b style="color:#b3261e">DOUBLE BOOKING</b>' : ($b['flag'] === 'req-clash' ? ' · <b style="color:#b3261e">dates taken</b>' : '')) . '</td></tr>';
    }
    $o .= '</table><p class="hint">Click a row for the booking details.</p></div>';
    return $o;
}

/** Styles + the click-through panel script (printed once, inside the admin page). */
function uv_cal_assets(string $self): string {
    $h = <<<HTML
<style>
.cal-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px}
.cal-title{font-size:20px;min-width:170px;text-align:center}
.cbtn{display:inline-block;padding:7px 14px;border-radius:999px;background:#4F6446;color:#fff;text-decoration:none;font-size:13px}
.cbtn.ghost{background:transparent;color:#4F6446;border:1px solid #4F6446}
.cal-src{font-size:12px;color:#7d776d;margin-left:auto}
.cal-wrap{overflow-x:auto;border:1px solid #e3dccf;border-radius:12px;background:#fff}
.cal{min-width:980px}
.cal-row{display:flex;border-top:1px solid #eee6d9}
.cal-row:first-child{border-top:0}
.cal-name{flex:0 0 130px;padding:8px 10px;display:flex;flex-direction:column;justify-content:center;border-right:1px solid #eee6d9;background:#fbf8f3}
.cal-name small{color:#7d776d;font-size:11px}
.cal-days{flex:1;position:relative;display:grid;grid-template-columns:repeat(var(--n),1fr);min-height:calc(var(--lanes,1) * 40px + 12px)}
.cal-days>span{border-left:1px solid #f1ebe1}
.cal-days>span.we{background:#faf6ef}
.cal-days>span.fr{background:#fff}
.cal-days>span.we.fr{background:#fdfbf7}
.cal-days>span.td{box-shadow:inset 2px 0 0 #4F6446}
.cal-head .cal-days{min-height:0}
.cal-head .cal-days>span{text-align:center;font-size:12px;padding:6px 0;line-height:1.2}
.cal-head .cal-days>span small{display:block;color:#9a9286;font-size:10px}
.cal-head .cal-days>span.td{color:#4F6446;font-weight:700}
.bar{position:absolute;top:9px;height:34px;border:0;border-radius:8px;background:var(--c);color:var(--t);display:flex;align-items:center;gap:6px;padding:0 8px 0 4px;font:12px/1 inherit;cursor:pointer;overflow:hidden;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.15);margin:0 1px}
.bar span{overflow:hidden;text-overflow:ellipsis}
.bar:hover{filter:brightness(1.08);z-index:2}
.bar.cl{border-top-left-radius:0;border-bottom-left-radius:0}
.bar.cr{border-top-right-radius:0;border-bottom-right-radius:0}
.cb{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 3px;border-radius:6px;background:rgba(255,255,255,.95);color:var(--c);font:700 11px/1 Arial,sans-serif;font-style:normal}
.s-request{background:repeating-linear-gradient(135deg,#fff 0 6px,#eef0e8 6px 12px)!important;color:#4F6446!important;outline:1.5px dashed #4F6446;outline-offset:-1.5px}
.s-request .cb{background:#4F6446;color:#fff}
.s-blocked{background:repeating-linear-gradient(135deg,#8A8378 0 6px,#9b948a 6px 12px)!important}
.s-expedia .cb{background:#FFC72C;color:#00355F}
.seg{display:inline-flex;border:1px solid #4F6446;border-radius:999px;overflow:hidden}
.seg button{border:0;background:transparent;color:#4F6446;padding:7px 14px;font:inherit;font-size:13px;cursor:pointer;border-radius:0;margin:0}
.seg button.on{background:#4F6446;color:#fff}
#calvilla{padding:6px 10px;border-radius:999px;border:1px solid #cfc6b8;font:inherit;font-size:13px}
.cal-alert{background:#fdecea;border:1px solid #b3261e;color:#7a1a14;border-radius:10px;padding:10px 14px;margin:0 0 10px}
.cal-alert ul{margin:6px 0 0 18px;padding:0}
.bar.dbl{outline:3px solid #b3261e;outline-offset:1px;animation:dblp 1.2s ease-in-out infinite alternate}
@keyframes dblp{to{outline-color:#f28b82}}
.bar.req-clash{outline-color:#b3261e!important}
.dbl-mini{display:inline-block;width:34px;height:16px;border-radius:5px;outline:3px solid #b3261e;background:#ddd}
.cal-list{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden}
.cal-list th,.cal-list td{padding:9px 10px;border-bottom:1px solid #eee6d9;text-align:left;font-size:14px}
.cal-list tr.lrow{cursor:pointer}
.cal-list tr.lrow:hover{background:#faf6ef}
.cal-list tr.dbl{background:#fdecea}
.cb.solid{background:var(--c);color:var(--t)}
.gap{position:absolute;top:4px;bottom:4px;background:repeating-linear-gradient(45deg,#ffe9a8 0 5px,#fff4cf 5px 10px);border:1px solid #e5b93a;border-radius:6px}
.cal-legend{display:flex;flex-wrap:wrap;gap:8px 18px;margin:10px 0 0;font-size:12px;color:#4a4740}
.cal-legend span{display:inline-flex;align-items:center;gap:6px}
.bar-mini{display:inline-flex;align-items:center;width:38px;height:22px;border-radius:6px;background:var(--c);padding:0 2px;font-style:normal}
.bar-mini .cb{min-width:18px;height:18px;font-size:10px}
.gap-mini{display:inline-block;width:38px;height:18px;border-radius:5px;background:repeating-linear-gradient(45deg,#ffe9a8 0 5px,#fff4cf 5px 10px);border:1px solid #e5b93a}
#calpanel{position:fixed;top:0;right:0;bottom:0;width:min(440px,100%);background:#fff;box-shadow:-10px 0 40px rgba(0,0,0,.18);z-index:60;transform:translateX(105%);transition:transform .25s;overflow-y:auto;padding:22px}
#calpanel.open{transform:none}
#calpanel h3{margin:0 0 4px;display:flex;align-items:center;gap:8px}
#calpanel .x{position:absolute;top:14px;right:16px;border:0;background:none;font-size:26px;cursor:pointer;color:#7d776d}
#calpanel dl{display:grid;grid-template-columns:120px 1fr;gap:6px 10px;margin:14px 0;font-size:14px}
#calpanel dt{color:#7d776d}
#calpanel dd{margin:0;white-space:pre-wrap;word-break:break-word}
#calpanel table{width:100%;font-size:13px}
#calpanel .load{color:#7d776d;font-size:13px}
</style>
<aside id="calpanel" aria-hidden="true"><button class="x" type="button" aria-label="Close">×</button><div id="calbody"></div></aside>
<script>
(function(){
  var SELF = %SELF%;
  var P = document.getElementById('calpanel'), B = document.getElementById('calbody');
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]});}
  function dl(o){var h='<dl>';for(var k in o){if(o[k]!==''&&o[k]!=null)h+='<dt>'+esc(k)+'</dt><dd>'+esc(o[k])+'</dd>';}return h+'</dl>';}
  function close(){P.classList.remove('open');P.setAttribute('aria-hidden','true');}
  P.querySelector('.x').onclick = close;
  // Calendar / List switch and room filter (remembered in this browser)
  function store(k,v){try{if(v===undefined)return localStorage.getItem(k);localStorage.setItem(k,v);}catch(e){return null;}}
  function setView(v){document.querySelectorAll('.cal-view').forEach(function(x){x.hidden=x.getAttribute('data-view')!==v;});
    document.querySelectorAll('.seg button').forEach(function(x){x.classList.toggle('on',x.getAttribute('data-view')===v);}); store('uv_cal_view',v);}
  function setVilla(v){document.querySelectorAll('.cal-row[data-villa],.lrow').forEach(function(x){x.style.display=(!v||x.getAttribute('data-villa')===v)?'':'none';});
    var s=document.getElementById('calvilla'); if(s) s.value=v; store('uv_cal_villa',v);}
  document.querySelectorAll('.seg button').forEach(function(x){x.onclick=function(){setView(x.getAttribute('data-view'));};});
  var sel=document.getElementById('calvilla'); if(sel) sel.onchange=function(){setVilla(sel.value);};
  setView(store('uv_cal_view')||'cal'); setVilla(store('uv_cal_villa')||'');
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  document.addEventListener('click',function(e){
    var b = e.target.closest && e.target.closest('.bar,.lrow'); if(!b) return;
    var info = JSON.parse(b.getAttribute('data-info')||'{}'), id = +b.getAttribute('data-b24');
    var cb = b.querySelector('.cb'); var head = '<h3><i class="cb" style="background:'+getComputedStyle(b).getPropertyValue('--c')+';color:'+getComputedStyle(b).getPropertyValue('--t')+'">'+esc(cb?cb.textContent:'')+'</i>'+esc(info.Guest||info.Source||'Booking')+'</h3>'+(info.WARNING?'<p style="color:#b3261e;font-weight:700">'+esc(info.WARNING)+'</p>':'');
    B.innerHTML = head + dl(info) + (id ? '<p class="load">Loading the full booking from Beds24…</p>' : '');
    P.classList.add('open'); P.setAttribute('aria-hidden','false');
    if(!id) return;
    fetch(SELF+'&bk='+id,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(d.error){B.querySelector('.load').textContent=d.error;return;}
      var name=[d.title,d.firstName,d.lastName].filter(Boolean).join(' ');
      var main={'Guest':name,'Status':d.status+(d.subStatus&&d.subStatus!=='none'?' ('+d.subStatus+')':''),'Channel':[d.channel,d.apiSource,d.referer].filter(Boolean).join(' · '),
        'Check-in':d.arrival+(d.arrivalTime?' · '+d.arrivalTime:''),'Check-out':d.departure,'Units':d.roomQty>1?d.roomQty+' ×':'','Adults':d.numAdult,'Children':d.numChild||'',
        'Email':d.email,'Phone':d.phone||d.mobile,'Country':d.country,'Price':d.price?Number(d.price).toLocaleString()+' THB':'','Deposit':d.deposit||'',
        'Commission':d.commission||'','Booked':(d.bookingTime||'').replace('T',' ').slice(0,16),'Beds24 ID':d.id,'Channel ref':d.apiReference||d.reference||'',
        'Guest message':d.comments||d.message||'','Notes':d.notes||'','Group':d.masterId?'Part of group '+d.masterId:''};
      var h=head+dl(main);
      if(d.infoItems&&d.infoItems.length){h+='<p><b>Info items</b></p>'+dl(Object.fromEntries(d.infoItems.map(function(i){return[i.code,i.text];})));}
      if(d.invoiceItems&&d.invoiceItems.length){h+='<p><b>Invoice</b></p><table>';d.invoiceItems.forEach(function(i){h+='<tr><td>'+esc(i.type)+'</td><td>'+esc(i.description)+'</td><td style="text-align:right">'+esc(i.lineTotal!=null?i.lineTotal:i.amount)+'</td></tr>';});h+='</table>';}
      B.innerHTML=h;
    }).catch(function(){var l=B.querySelector('.load');if(l)l.textContent='Could not reach Beds24 just now.';});
  });
})();
</script>
HTML;
    return str_replace('%SELF%', json_encode($self, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $h);
}
