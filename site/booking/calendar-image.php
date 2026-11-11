<?php
/**
 * Phone-friendly calendar PICTURE (PNG) for the WhatsApp assistant (Yomied) to send on request.
 *   calendar-image.php?t=<token>&m=2026-12&v=all|villa|superior|deluxe|king&view=cal|list&who=guest|staff
 * who=guest (default): no names, no sources — just Booked / Free (safe to send to anyone).
 * who=staff: coloured by source (Airbnb, Booking.com…) with guest names — for the team only.
 * view=cal: days down the side, one column per room type. view=list: guest → free dates; staff → bookings.
 * The token is created in the admin (Calendar → "WhatsApp picture link"); it lives only in this
 * site's database (meta cal_image_token), never in git or chat.
 * Upper View pool: the Mini Villa column counts 8 units — guest view shows how many villas are free.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');   // Samui time for "today" and the footer (this script only)
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';
require __DIR__ . '/calendar.php';

$tok = (string) uv_meta_get('cal_image_token');
if ($tok === '' || !hash_equals($tok, (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('forbidden'); }
if (!function_exists('imagettftext')) { http_response_code(500); exit('GD with FreeType is not available on this server'); }
uv_rate_limit('calimg', 60, 600);

$ym = (isset($_GET['m']) && preg_match('/^20\d\d-(0[1-9]|1[0-2])$/', (string) $_GET['m'])) ? (string) $_GET['m'] : date('Y-m');
$who = ($_GET['who'] ?? 'guest') === 'staff' ? 'staff' : 'guest';
$view = ($_GET['view'] ?? 'cal') === 'list' ? 'list' : 'cal';
$d = uv_cal_month($ym);
$inv = uv_config()['inventory'] ?? [];
$types = array_keys($d['bars']);
$vq = (string) ($_GET['v'] ?? 'all');
if ($vq !== 'all' && in_array($vq, $types, true)) $types = [$vq];
$first = $d['first']; $days = (int) date('t', strtotime($first));
$tname = function ($t) use ($inv) {
    $n = str_replace(' Suite', '', uv_type_name($t));
    return $t === 'villa' ? 'Mini Villas (' . (int)($inv[$t] ?? 8) . ')' : $n;
};

$R = __DIR__ . '/fonts/Lato-Regular.ttf'; $B = __DIR__ . '/fonts/Lato-Bold.ttf';
$W = 1080;
function rgb($im, string $hex) { $hex = ltrim($hex, '#'); if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))); }
function rrect($im, int $x1, int $y1, int $x2, int $y2, int $r, $c): void {
    $r = max(0, min($r, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2)));
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $c); imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $c);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as $p) imagefilledellipse($im, $p[0], $p[1], 2 * $r, 2 * $r, $c);
}
function txt($im, float $size, int $x, int $y, $c, string $font, string $s, int $maxW = 0): void {
    if ($maxW > 0) {
        $w = function ($t) use ($size, $font) { $b = imagettfbbox($size, 0, $font, $t); return $b[2] - $b[0]; };
        if ($w($s) > $maxW) { while (mb_strlen($s) > 1 && $w($s . '…') > $maxW) $s = mb_substr($s, 0, -1); $s .= '…'; }
    }
    imagettftext($im, $size, 0, $x, $y, $c, $font, $s);
}
$glyph = function (string $t) { return strtr($t, ['✕' => 'X', '•' => '·']); };
$nights = function ($a, $b) { return (int) round((strtotime($b) - strtotime($a)) / 86400); };
// guests never see requests (they are not bookings)
foreach ($d['bars'] as $t => $list) if ($who === 'guest') $d['bars'][$t] = array_values(array_filter($list, function ($b) { return $b['src'] !== 'request'; }));
// per-night weight (units taken) per type
$weight = [];
foreach ($types as $t) {
    $w = [];
    foreach ($d['bars'][$t] as $b) {
        if ($b['src'] === 'request') continue;
        for ($x = max($b['from'], $first); $x < min($b['to'], $d['after']); $x = date('Y-m-d', strtotime($x . ' +1 day'))) {
            $w[$x] = ($w[$x] ?? 0) + max(1, (int)($b['qty'] ?? 1));
        }
    }
    $weight[$t] = $w;
}

/* ---------- list view ---------- */
if ($view === 'list') {
    $lines = [];
    foreach ($types as $t) {
        $cap = max(1, (int)($inv[$t] ?? 1));
        $lines[] = ['h', $tname($t)];
        if ($who === 'guest') {
            // free date ranges this month (a night is free while at least one unit is free)
            $run = null; $any = false; $minFree = $cap;
            for ($i = 1; $i <= $days + 1; $i++) {
                $dt = $i <= $days ? $ym . '-' . sprintf('%02d', $i) : $d['after'];
                $isFree = $i <= $days && (($weight[$t][$dt] ?? 0) < $cap);
                if ($isFree) $minFree = min($minFree, $cap - ($weight[$t][$dt] ?? 0));
                if ($isFree && $run === null) { $run = $dt; $minFree = $cap - ($weight[$t][$dt] ?? 0); }
                if (!$isFree && $run !== null) {
                    $n = $nights($run, $dt); $any = true;
                    $lines[] = ['free', date('D j M', strtotime($run)) . '  →  ' . date('D j M', strtotime($dt)),
                        $n . ' night' . ($n > 1 ? 's' : '') . ($cap > 1 ? ' · ' . $minFree . '+ free' : ' free')];
                    $run = null;
                }
            }
            if (!$any) $lines[] = ['none', 'Fully booked this month'];
        } else {
            if (!$d['bars'][$t]) $lines[] = ['none', 'No bookings this month'];
            foreach ($d['bars'][$t] as $b) {
                $lines[] = ['bk', $b['src'], $b['label'], date('D j M', strtotime($b['from'])) . ' → ' . date('D j M', strtotime($b['to'])), $nights($b['from'], $b['to']) . 'n'];
            }
        }
    }
    $H = 230 + count($lines) * 74 + 90;
    $im = imagecreatetruecolor($W, $H);
    imagefill($im, 0, 0, rgb($im, 'F7F0E9'));
    $ink = rgb($im, '2B2B26'); $mut = rgb($im, '8A8376'); $green = rgb($im, '4F6446'); $white = rgb($im, 'FFFFFF');
    imagefilledrectangle($im, 0, 0, $W, 150, $green);
    txt($im, 40, 56, 82, $white, $B, 'UPPER VIEW');
    txt($im, 26, 56, 128, $white, $R, ($who === 'guest' ? 'Free dates · ' : 'Bookings · ') . date('F Y', strtotime($first)));
    $y = 210;
    $ok = rgb($im, '3E8E5B');
    foreach ($lines as $l) {
        if ($l[0] === 'h') { txt($im, 30, 56, $y + 10, $ink, $B, $l[1]); $y += 74; continue; }
        if ($l[0] === 'none') { txt($im, 26, 80, $y, $mut, $R, $l[1]); $y += 74; continue; }
        rrect($im, 56, $y - 44, $W - 56, $y + 18, 14, $white);
        if ($l[0] === 'free') {
            rrect($im, 72, $y - 32, 96, $y + 6, 6, $ok);
            txt($im, 26, 116, $y - 2, $ink, $B, $l[1]);
            txt($im, 24, $W - 320, $y - 2, $ok, $B, $l[2]);
        } else {
            $s = UV_CAL_SOURCES[$l[1]];
            rrect($im, 70, $y - 36, 124, $y + 10, 8, rgb($im, $s[1] === '#F7F0E9' ? '#4F6446' : $s[1]));
            txt($im, 18, 80, $y - 4, rgb($im, $s[3] === '#4F6446' ? '#FFFFFF' : $s[3]), $B, $glyph($s[2]));
            txt($im, 24, 140, $y - 2, $ink, $B, $l[2], 420);
            txt($im, 22, 580, $y - 2, $mut, $R, $l[3]);
            txt($im, 22, $W - 110, $y - 2, $ink, $B, $l[4]);
        }
        $y += 74;
    }
    txt($im, 18, 56, $H - 36, $mut, $R, 'upperviewsamui.com · updated ' . date('j M Y H:i') . ' (Samui time)');
} else {
/* ---------- calendar view: days down the side, one column per room type ---------- */
    $rowH = 52; $top = 290; $left = 190; $gapC = 12;
    $colW = (int) floor(($W - $left - 40 - $gapC * (count($types) - 1)) / max(1, count($types)));
    $H = $top + $days * $rowH + 170;
    $im = imagecreatetruecolor($W, $H);
    imagefill($im, 0, 0, rgb($im, 'F7F0E9'));
    $ink = rgb($im, '2B2B26'); $mut = rgb($im, '8A8376'); $green = rgb($im, '4F6446'); $white = rgb($im, 'FFFFFF');
    $line = rgb($im, 'E3DCCF'); $we = rgb($im, 'FAF6EF'); $freeC = rgb($im, 'E6F2EA'); $ok = rgb($im, '3E8E5B');
    imagefilledrectangle($im, 0, 0, $W, 150, $green);
    txt($im, 40, 56, 82, $white, $B, 'UPPER VIEW');
    txt($im, 26, 56, 128, $white, $R, date('F Y', strtotime($first)) . ($who === 'guest' ? ' · availability' : ' · bookings'));
    foreach ($types as $ci => $t) {
        $x = $left + $ci * ($colW + $gapC);
        txt($im, 22, $x + 8, 240, $ink, $B, $tname($t), $colW - 10);
    }
    $today = date('Y-m-d');
    for ($i = 1; $i <= $days; $i++) {
        $dt = $ym . '-' . sprintf('%02d', $i); $y = $top + ($i - 1) * $rowH; $w = (int) date('N', strtotime($dt));
        txt($im, 22, 40, $y + 34, $dt === $today ? $green : ($w >= 6 ? $ink : $mut), $dt === $today || $w >= 6 ? $B : $R, date('D j', strtotime($dt)));
        foreach ($types as $ci => $t) {
            $x = $left + $ci * ($colW + $gapC);
            imagefilledrectangle($im, $x, $y, $x + $colW, $y + $rowH - 1, $w >= 6 ? $we : $white);
            imageline($im, $x, $y + $rowH - 1, $x + $colW, $y + $rowH - 1, $line);
        }
    }
    foreach ($types as $ci => $t) {
        $x = $left + $ci * ($colW + $gapC);
        $cap = max(1, (int)($inv[$t] ?? 1));
        $w = $weight[$t];
        if ($who === 'guest') {
            /* guest view: per-night Free / "n free" / booked — no bars, no sources */
            for ($i = 1; $i <= $days; $i++) {
                $dt = $ym . '-' . sprintf('%02d', $i); $y = $top + ($i - 1) * $rowH;
                $used = (int)($w[$dt] ?? 0);
                if ($used < $cap) {
                    imagefilledrectangle($im, $x, $y, $x + $colW, $y + $rowH - 2, $freeC);
                    txt($im, 18, $x + $colW - ($cap > 1 ? 90 : 70), $y + 32, $ok, $B, $cap > 1 ? ($cap - $used) . ' free' : 'Free');
                } else {
                    rrect($im, $x + 6, $y + 5, $x + $colW - 6, $y + $rowH - 7, 10, rgb($im, '8A8378'));
                    txt($im, 17, $x + 16, $y + 32, $white, $B, 'Booked');
                }
            }
            continue;
        }
        /* staff view: bars with lanes, coloured by source, guest names */
        $laneEnd = []; $lanes = [];
        foreach ($d['bars'][$t] as $i => $b) { $l = 0; while (isset($laneEnd[$l]) && $laneEnd[$l] > $b['from']) $l++; $laneEnd[$l] = $b['to']; $lanes[$i] = $l; }
        $nl = max(1, count($laneEnd)); $lw = (int) floor($colW / $nl);
        [$cflags] = uv_cal_conflicts($d['bars'][$t], $cap);
        foreach ($d['bars'][$t] as $i => $b) {
            $bx = $x + $lanes[$i] * $lw; $bw = $lw;
            $a = (strtotime($b['from']) - strtotime($first)) / 86400 + 0.5; $z = (strtotime($b['to']) - strtotime($first)) / 86400 + 0.5;
            $a = max(0, $a); $z = min($days, $z); if ($z <= $a) continue;
            $y1 = (int) ($top + $a * $rowH) + 3; $y2 = (int) ($top + $z * $rowH) - 3;
            $s = UV_CAL_SOURCES[$b['src']];
            $col = $b['src'] === 'request' ? '#DCE3D4' : $s[1];
            if ($cflags[$i] === 'dbl') rrect($im, $bx + 1, $y1 - 5, $bx + $bw - 1, $y2 + 5, 14, rgb($im, '#B3261E'));
            rrect($im, $bx + 6, $y1, $bx + $bw - 6, $y2, 12, rgb($im, $col === '#F7F0E9' ? '#DCE3D4' : $col));
            $tc = rgb($im, $b['src'] === 'request' ? '#3D4C36' : ($s[3] === '#4F6446' ? '#FFFFFF' : $s[3]));
            if ($y2 - $y1 >= 30) {
                $badgeC = $s[1] === '#F7F0E9' ? '#4F6446' : $s[1];
                if ($bw >= 100) { rrect($im, $bx + 14, $y1 + 8, $bx + 52, $y1 + 40, 7, $white); txt($im, 15, $bx + 20, $y1 + 31, rgb($im, $badgeC), $B, $glyph($s[2])); }
                txt($im, 19, $bx + ($bw >= 100 ? 62 : 14), $y1 + 32, $tc, $B,
                    ($cflags[$i] === 'dbl' ? '! ' : '') . $b['label'] . ' · ' . $nights($b['from'], $b['to']) . 'n', $bw - ($bw >= 100 ? 80 : 26));
            }
        }
    }
    // legend
    $y = $top + $days * $rowH + 60;
    if ($who === 'guest') {
        rrect($im, 56, $y - 26, 100, $y + 4, 6, rgb($im, '8A8378')); txt($im, 20, 112, $y, $ink, $R, 'Booked');
        rrect($im, 300, $y - 26, 344, $y + 4, 6, $freeC); txt($im, 20, 356, $y, $ink, $R, 'Free — ask us to book');
    } else {
        $lx = 56;
        foreach (['airbnb', 'booking', 'agoda', 'trip', 'direct', 'blocked'] as $k) {
            $s = UV_CAL_SOURCES[$k];
            $nm = $s[0] === 'Direct (our website)' ? 'Direct' : ($s[0] === 'Blocked / closed' ? 'Closed' : $s[0]);
            $bb = imagettfbbox(17, 0, $R, $nm);
            rrect($im, $lx, $y - 26, $lx + 34, $y + 4, 6, rgb($im, $s[1])); txt($im, 17, $lx + 42, $y, $ink, $R, $nm);
            $lx += 42 + ($bb[2] - $bb[0]) + 26;
        }
    }
    txt($im, 18, 56, $H - 36, $mut, $R, 'upperviewsamui.com · updated ' . date('j M Y H:i') . ' (Samui time)');
}
header('Content-Type: image/png');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
imagepng($im, null, 6);
