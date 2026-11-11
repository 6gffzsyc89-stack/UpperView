<?php
/**
 * Live bookings CSV for the Google Sheets mirror (and anything else that wants
 * a table). One row per stay, every channel, PENDING requests included.
 *   calendar-feed.php?t=<token>[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 * Defaults to today → +180 days. Same token as the WhatsApp picture link
 * (admin → Calendar). In Google Sheets put in A1 of a tab:
 *   =IMPORTDATA("https://upperviewsamui.com/booking/calendar-feed.php?t=<token>")
 * and the sheet refreshes itself (Google re-fetches roughly hourly).
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';
require __DIR__ . '/calendar.php';

// The feed has its OWN low-privilege token (meta cal_feed_token): it ends up
// inside a link-shared Google Sheet formula, where viewers can read it, so it
// must unlock NOTHING but this CSV. The kiosk/picture token also works for
// backward compatibility (its powers are a superset).
$tokFeed = (string) uv_meta_get('cal_feed_token');
$tokImg  = (string) uv_meta_get('cal_image_token');
$tIn = (string) ($_GET['t'] ?? '');
$okT = ($tokFeed !== '' && hash_equals($tokFeed, $tIn)) || ($tokImg !== '' && hash_equals($tokImg, $tIn));
if (!$okT) { http_response_code(403); exit('forbidden'); }
uv_rate_limit('calfeed', 60, 600);

$from = uv_valid_date($_GET['from'] ?? null) ? (string)$_GET['from'] : date('Y-m-d');
$to   = uv_valid_date($_GET['to'] ?? null) ? (string)$_GET['to'] : date('Y-m-d', strtotime('+180 days'));
if ($to <= $from || strtotime($to) - strtotime($from) > 400 * 86400) { http_response_code(400); exit('bad range'); }

// Every sheet pull re-pulls the Airbnb calendars when they are older than the
// configured age (5 min), so portal-side changes reach the sheet by themselves.
uv_refresh_ota();

// Collect bars month by month (the calendar's own merged view: Beds24 + our
// bookings + the Airbnb feeds, deduped), then dedupe stays spanning months.
$rows = []; $seen = [];
for ($ym = substr($from, 0, 7); $ym <= substr($to, 0, 7); $ym = date('Y-m', strtotime($ym . '-01 +1 month'))) {
    $d = uv_cal_month($ym);
    foreach ($d['bars'] as $type => $bars) {
        foreach ($bars as $b) {
            if ($b['to'] <= $from || $b['from'] >= $to) continue;
            $key = $type . '|' . $b['from'] . '|' . $b['to'] . '|' . $b['label'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $info = $b['info'];
            $status = strtolower((string)($info['Status'] ?? ''));
            if ($b['src'] === 'request' || in_array($status, ['inquiry', 'request', 'pending', 'new'], true)) $status = 'PENDING';
            elseif ($b['src'] === 'blocked') $status = 'blocked/closed';
            elseif ($status === '') $status = 'confirmed';
            $rows[] = [
                $status,
                uv_type_name($type),
                $type === 'villa' ? uv_unit_name((int)($b['unit'] ?? 0)) : uv_type_name($type),
                max(1, (int)($b['qty'] ?? 1)),
                $b['from'],
                $b['to'],
                (int) round((strtotime($b['to']) - strtotime($b['from'])) / 86400),
                (string)($info['Guest'] ?? $b['label']),
                (string)($info['Source'] ?? ''),
                (string)($info['Channel ref'] ?? ($info['Beds24 ID'] ?? '')),
                (string)($info['Guests'] ?? ''),
                (string)($info['Price'] ?? ($info['Amount'] ?? '')),
                (string)($info['Promo'] ?? ''),
                (string)($info['Note'] ?? ($info['Feed says'] ?? '')),
            ];
        }
    }
}
usort($rows, function ($a, $b) { return strcmp($a[3] . $a[1], $b[3] . $b[1]); });

header('Content-Type: text/csv; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$out = fopen('php://output', 'w');
fputcsv($out, ['Updated ' . date('Y-m-d H:i') . ' (Samui time)', 'upperviewsamui.com', $from . ' -> ' . $to, '', '', '', '', '', '', '', '', '', '', '']);
fputcsv($out, ['Status', 'Room', 'Unit', 'Qty', 'Check-in', 'Check-out', 'Nights', 'Guest', 'Source', 'Ref', 'Guests', 'Amount', 'Promo', 'Note']);
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
