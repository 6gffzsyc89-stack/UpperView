<?php
/**
 * Near-real-time Google Sheet mirror — SHARED CORE module (first merged file
 * of the one-backend superset, 2026-11-12). core_sheet_ping() fires one
 * non-blocking HTTP GET per page request to the Apps Script web app stored in
 * meta 'sheet_ping_url'; the 10-minute Apps Script trigger remains the
 * backstop. Profile adapter: the constant CORE_META_GET names the engine's
 * meta getter ('uv_meta_get' / 'sn_meta_get'), set by the profile shim.
 */
declare(strict_types=1);

function core_sheet_ping(): void {
    static $done = false;
    if ($done) return;
    $get = defined('CORE_META_GET') ? CORE_META_GET : 'uv_meta_get';
    $url = (string) (call_user_func($get, 'sheet_ping_url') ?? '');
    if ($url === '' || strpos($url, 'https://script.google.com/') !== 0) return;
    $done = true;
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
