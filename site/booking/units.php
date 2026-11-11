<?php
/**
 * Mini Villa unit assignment (2026-11-11). The pool is 8 physical villas;
 * Beds24 room 752258 carries them as units 1..8 ("Villa 1".."Villa 8") with
 * per-booking allocation. Rules of the house:
 *   - Airbnb stays are UNIT-LOCKED: each Airbnb listing IS one physical villa
 *     (iCal feed unit v2 = Villa 2, etc.), the guest booked that exact villa.
 *   - Everything else (Booking.com, Agoda, direct website, phone) books "a
 *     mini villa" and WE choose the physical unit.
 * The optimiser uses standard hotel room-assignment practice:
 *   1. Pin what cannot move: unit-locked stays, in-house guests, arrivals
 *      within 48h (housekeeping has already planned them).
 *   2. Assign flexible stays in arrival order, best-fit: prefer the unit
 *      where the stay sits back-to-back with existing stays (same-day
 *      turnover = zero vacant nights), penalise creating gaps shorter than
 *      the minimum stay (unsellable "orphan" nights), and prefer filling
 *      already-broken units before touching long open runs (keeps big
 *      blocks sellable for long stays).
 *   3. Respect housekeeping: checkout 12:00, check-in 15:00 gives a 3-hour
 *      window; at ~2h per villa a small team manages a limited number of
 *      same-day turnovers — cap them per day (config 'max_turnovers_per_day',
 *      default 4) and spill further arrivals to units vacant the night before.
 * Assignments are written to the Beds24 booking (unitId), so the control
 * panel, the sheet feed and every other consumer see the same allocation.
 */
declare(strict_types=1);

const UV_VILLA_ROOMID = 752258;
const UV_VILLA_UNITS = 8;

function uv_unit_name(int $u): string { return $u > 0 ? 'Villa ' . $u : 'Unallocated'; }

/** iCal feed unit key ('v2') -> physical unit number (2). */
function uv_unit_from_key(string $k): int {
    return preg_match('/^v([1-8])$/', $k, $m) ? (int)$m[1] : 0;
}

/**
 * All villa-pool stays in [$from,$to) with unit identity.
 * Each: ['from','to','unit'(0=unassigned),'locked'(bool),'b24'(id|0),'label','src']
 * Sources: Beds24 bookings (unitId; Airbnb-sourced = locked) and the Airbnb
 * iCal feeds (locked to their villa; skipped when a Beds24 stay for the same
 * unit already covers the range — same dedupe idea as the calendar).
 */
function uv_unit_stays(string $from, string $to): array {
    $stays = [];
    if (function_exists('b24_enabled') && b24_enabled()) {
        for ($page = 1; $page <= 6; $page++) {
            $res = b24_api('GET', '/bookings?roomId=' . UV_VILLA_ROOMID
                . '&arrivalTo=' . date('Y-m-d', strtotime($to . ' -1 day'))
                . '&departureFrom=' . $from . '&page=' . $page);
            if (!is_array($res) || !isset($res['data'])) break;
            foreach ($res['data'] as $b) {
                if (in_array($b['status'] ?? '', ['cancelled', 'inquiry', 'request'], true)) continue;
                if (($b['departure'] ?? '') <= $from || ($b['arrival'] ?? '') >= $to) continue;
                $src = function_exists('uv_cal_b24_source') ? uv_cal_b24_source($b) : 'other';
                $name = trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''));
                $stays[] = [
                    'from' => $b['arrival'], 'to' => $b['departure'],
                    'unit' => (int)($b['unitId'] ?? 0),
                    'locked' => $src === 'airbnb',   // Airbnb guest booked that exact villa
                    'b24' => (int)$b['id'], 'qty' => max(1, (int)($b['roomQty'] ?? 1)),
                    'label' => $name !== '' ? $name : ($src === 'blocked' ? 'Blocked' : $src),
                    'src' => $src,
                ];
            }
            if (empty($res['pages']['nextPageExists'])) break;
        }
    }
    // Airbnb iCal feeds: ground truth for villas 2/3/4 until channels connect.
    $st = uv_db()->prepare("SELECT * FROM ota_busy WHERE type='villa' AND dtstart < ? AND dtend > ? ORDER BY dtstart");
    $st->execute([$to, $from]);
    foreach ($st as $o) {
        $u = uv_unit_from_key((string)$o['unit_key']);
        if ($u === 0) continue;
        $covered = false;
        foreach ($stays as $x) {
            if ($x['unit'] === $u && $x['from'] <= $o['dtstart'] && $x['to'] >= $o['dtend']) { $covered = true; break; }
        }
        if ($covered) continue;
        $sum = (string)($o['summary'] ?? '');
        $reserved = $sum === '' || stripos($sum, 'reserved') !== false;
        $stays[] = ['from' => $o['dtstart'], 'to' => $o['dtend'], 'unit' => $u, 'locked' => true,
                    'b24' => 0, 'qty' => 1, 'label' => $reserved ? 'Airbnb guest' : 'Closed on Airbnb',
                    'src' => $reserved ? 'airbnb' : 'blocked'];
    }
    usort($stays, function ($a, $b) { return strcmp($a['from'], $b['from']); });
    return $stays;
}

/** nights [$from,$to) each unit is taken, from a stays list. unit => [date => label] */
function uv_unit_grid(array $stays): array {
    $g = [];
    foreach ($stays as $i => $s) {
        if ($s['unit'] === 0) continue;
        for ($d = $s['from']; $d < $s['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $g[$s['unit']][$d] = $i;
        }
    }
    return $g;
}

/**
 * Propose units for every movable stay. Returns
 * ['moves' => [[b24, label, from, to, cur, unit, why], …], 'conflicts' => [text…]]
 * Movable = Beds24 stay, not Airbnb-locked, qty 1, not in-house, arrival > +48h
 * (or still unassigned, which is always worth placing).
 */
function uv_assign_units(string $from, string $to): array {
    $minN = (int)(uv_config()['min_nights'] ?? 2);
    $maxFlips = (int)(uv_config()['max_turnovers_per_day'] ?? 4);
    $soon = date('Y-m-d', strtotime('+2 days'));
    $today = date('Y-m-d');
    $stays = uv_unit_stays($from, $to);

    $fixed = []; $flex = []; $conflicts = [];
    foreach ($stays as $i => $s) {
        $movable = $s['b24'] > 0 && !$s['locked'] && $s['qty'] === 1
                && $s['from'] > $today                      // never move an in-house guest
                && ($s['unit'] === 0 || $s['from'] > $soon); // assigned + arriving <48h stays put
        if ($movable) $flex[$i] = $s; else $fixed[$i] = $s;
    }
    // double-check the fixed layer itself (two locked stays on one villa = real trouble)
    $grid = uv_unit_grid($fixed);
    foreach ($fixed as $i => $s) {
        if ($s['unit'] === 0) continue;
        for ($d = $s['from']; $d < $s['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $o = $grid[$s['unit']][$d] ?? $i;
            if ($o !== $i && isset($fixed[$o])) {
                $conflicts[] = uv_unit_name($s['unit']) . ': "' . $s['label'] . '" overlaps "' . $fixed[$o]['label'] . '" on ' . $d . ' — both are fixed; resolve by hand.';
                break;
            }
        }
    }

    // same-day turnover count per date from the fixed layer
    $outs = []; $ins = [];
    foreach ($fixed as $s) { if ($s['unit'] > 0) { $outs[$s['unit'] . '|' . $s['to']] = true; $ins[$s['unit'] . '|' . $s['from']] = true; } }
    $flipCount = [];
    foreach ($outs as $k => $x) { if (isset($ins[$k])) { $d = substr($k, strpos($k, '|') + 1); $flipCount[$d] = ($flipCount[$d] ?? 0) + 1; } }

    // assign flexible stays in arrival order, longest first on ties
    uasort($flex, function ($a, $b) {
        return $a['from'] === $b['from']
            ? (strtotime($b['to']) - strtotime($b['from'])) - (strtotime($a['to']) - strtotime($a['from']))
            : strcmp($a['from'], $b['from']);
    });
    $moves = [];
    foreach ($flex as $i => $s) {
        $best = 0; $bestScore = -PHP_INT_MAX; $bestWhy = '';
        for ($u = 1; $u <= UV_VILLA_UNITS; $u++) {
            // feasibility: every night free in this unit
            $ok = true;
            for ($d = $s['from']; $d < $s['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (isset($grid[$u][$d])) { $ok = false; break; }
            }
            if (!$ok) continue;
            // neighbours in this unit
            $prevEnd = null; $nextStart = null;
            foreach ($grid[$u] ?? [] as $d => $x) {
                if ($d < $s['from']) { $e = date('Y-m-d', strtotime($d . ' +1 day')); if ($prevEnd === null || $e > $prevEnd) $prevEnd = $e; }
                if ($d >= $s['to'] && ($nextStart === null || $d < $nextStart)) $nextStart = $d;
            }
            $gapBefore = $prevEnd === null ? null : (int)((strtotime($s['from']) - strtotime($prevEnd)) / 86400);
            $gapAfter  = $nextStart === null ? null : (int)((strtotime($nextStart) - strtotime($s['to'])) / 86400);
            $score = 0; $why = [];
            if ($gapBefore === 0) {
                // back-to-back: zero vacancy, but costs a same-day turnover
                if (($flipCount[$s['from']] ?? 0) >= $maxFlips) { $score -= 40; $why[] = 'turnover day full'; }
                else { $score += 50; $why[] = 'back-to-back with previous checkout'; }
            } elseif ($gapBefore !== null && $gapBefore < $minN) { $score -= 30 * ($minN - $gapBefore); $why[] = $gapBefore . '-night orphan gap before'; }
            if ($gapAfter === 0) { $score += 30; $why[] = 'next guest checks in on departure day'; }
            elseif ($gapAfter !== null && $gapAfter < $minN) { $score -= 30 * ($minN - $gapAfter); $why[] = $gapAfter . '-night orphan gap after'; }
            // best-fit: prefer units already broken up (more booked nights) so
            // long open runs stay whole for long stays
            $score += count($grid[$u] ?? []) / 10.0;
            // stability: keep the current unit on equal footing
            if ($s['unit'] === $u) $score += 5;
            if ($score > $bestScore) { $bestScore = $score; $best = $u; $bestWhy = $why ? implode(', ', $why) : 'first clean fit'; }
        }
        if ($best === 0) { $conflicts[] = 'No free villa fits "' . $s['label'] . '" ' . $s['from'] . '→' . $s['to'] . ' — pool genuinely full.'; continue; }
        // commit into the grid so later stays see it, and count a turnover if
        // this placement is back-to-back
        $nightBefore = date('Y-m-d', strtotime($s['from'] . ' -1 day'));
        if (isset($grid[$best][$nightBefore])) $flipCount[$s['from']] = ($flipCount[$s['from']] ?? 0) + 1;
        for ($d = $s['from']; $d < $s['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $grid[$best][$d] = $i;
        if ($best !== $s['unit']) {
            $moves[] = ['b24' => $s['b24'], 'label' => $s['label'], 'from' => $s['from'], 'to' => $s['to'],
                        'cur' => $s['unit'], 'unit' => $best, 'why' => $bestWhy];
        }
    }
    return ['moves' => $moves, 'conflicts' => $conflicts];
}

/** Write proposed assignments to Beds24 (unitId per booking). */
function uv_assign_apply(array $moves): array {
    $done = 0; $fail = [];
    $payload = [];
    foreach ($moves as $m) {
        $id = (int)($m['b24'] ?? 0); $u = (int)($m['unit'] ?? 0);
        if ($id > 0 && $u >= 1 && $u <= UV_VILLA_UNITS) $payload[] = ['id' => $id, 'unitId' => $u];
    }
    if ($payload) {
        $res = b24_api('POST', '/bookings', $payload);
        foreach ((is_array($res) ? $res : []) as $k => $r) {
            if (!empty($r['success']) || isset($r['modified'])) $done++;
            else $fail[] = ($payload[$k]['id'] ?? '?') . ': ' . json_encode($r);
        }
    }
    return ['done' => $done, 'failed' => $fail];
}

/** Best unit for one new stay (used when a paid pool booking is written). */
function uv_best_unit(string $ci, string $co): int {
    $from = date('Y-m-d', strtotime($ci . ' -21 days'));
    $to = date('Y-m-d', strtotime($co . ' +21 days'));
    $stays = uv_unit_stays($from, $to);
    $grid = uv_unit_grid($stays);
    $minN = (int)(uv_config()['min_nights'] ?? 2);
    $best = 0; $bestScore = -PHP_INT_MAX;
    for ($u = 1; $u <= UV_VILLA_UNITS; $u++) {
        $ok = true;
        for ($d = $ci; $d < $co; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (isset($grid[$u][$d])) { $ok = false; break; }
        }
        if (!$ok) continue;
        $prevEnd = null; $nextStart = null;
        foreach ($grid[$u] ?? [] as $d => $x) {
            if ($d < $ci) { $e = date('Y-m-d', strtotime($d . ' +1 day')); if ($prevEnd === null || $e > $prevEnd) $prevEnd = $e; }
            if ($d >= $co && ($nextStart === null || $d < $nextStart)) $nextStart = $d;
        }
        $gb = $prevEnd === null ? null : (int)((strtotime($ci) - strtotime($prevEnd)) / 86400);
        $ga = $nextStart === null ? null : (int)((strtotime($nextStart) - strtotime($co)) / 86400);
        $score = 0;
        if ($gb === 0) $score += 50; elseif ($gb !== null && $gb < $minN) $score -= 30 * ($minN - $gb);
        if ($ga === 0) $score += 30; elseif ($ga !== null && $ga < $minN) $score -= 30 * ($minN - $ga);
        $score += count($grid[$u] ?? []) / 10.0;
        if ($score > $bestScore) { $bestScore = $score; $best = $u; }
    }
    return $best;
}
