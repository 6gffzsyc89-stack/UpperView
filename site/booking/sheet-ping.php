<?php
/**
 * Near-real-time Google Sheet mirror: uv_sheet_ping() fires a non-blocking
 * HTTP GET to the Apps Script web app (meta 'sheet_ping_url') which re-pulls
 * both live feeds into the shared sheet within seconds of any change.
 * Called after every booking-state change; a 10-minute Apps Script time
 * trigger remains as the backstop, so a lost ping costs at most 10 minutes.
 */
declare(strict_types=1);

function uv_sheet_ping(): void {
    $url = (string) (uv_meta_get('sheet_ping_url') ?? '');
    if ($url === '' || strpos($url, 'https://script.google.com/') !== 0) return;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // script.google.com redirects to googleusercontent
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 4,             // fire-and-forget: never hold a guest request hostage
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
