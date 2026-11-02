<?php
/**
 * iCal export for OTAs to pull. iCal can only say "blocked", not "3 of 8 left",
 * so a date is exported as blocked only when NO unit of the type is free.
 * (This limitation is inherent to iCal; a channel-manager API removes it.)
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = uv_config();
$type = $_GET['type'] ?? '';
$key = $_GET['k'] ?? '';
if (!in_array($type, ['villa', 'studio'], true) || !hash_equals($cfg['export_key'], $key)) {
    http_response_code(403); exit('forbidden');
}

uv_refresh_ota();
$from = date('Y-m-d');
$to = date('Y-m-d', strtotime('+365 days'));
$free = uv_availability($type, $from, $to);

// Merge consecutive fully-booked dates into ranges.
$ranges = []; $start = null; $prev = null;
foreach ($free as $d => $n) {
    if ($n < 1) {
        if ($start === null) $start = $d;
        $prev = $d;
    } elseif ($start !== null) {
        $ranges[] = [$start, date('Y-m-d', strtotime($prev . ' +1 day'))];
        $start = null;
    }
}
if ($start !== null) $ranges[] = [$start, date('Y-m-d', strtotime($prev . ' +1 day'))];

header('Content-Type: text/calendar; charset=utf-8');
$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Upper View Samui//Booking//EN\r\n";
foreach ($ranges as $i => [$s, $e]) {
    $out .= "BEGIN:VEVENT\r\n"
        . 'UID:uv-' . $type . '-' . str_replace('-', '', $s) . "@upperviewsamui.com\r\n"
        . 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n"
        . 'DTSTART;VALUE=DATE:' . str_replace('-', '', $s) . "\r\n"
        . 'DTEND;VALUE=DATE:' . str_replace('-', '', $e) . "\r\n"
        . "SUMMARY:Unavailable\r\nEND:VEVENT\r\n";
}
echo $out . "END:VCALENDAR\r\n";
