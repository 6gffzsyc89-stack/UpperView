<?php
/**
 * One-time Beds24 credential setup + status. Admin-key protected.
 * POST {invite_code}: server exchanges it for a refresh token (stored in
 * SQLite meta, never leaving the server) . GET: connection status.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';

header('Access-Control-Allow-Origin: https://beds24.com');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$cfg = uv_config();
if (!hash_equals($cfg['admin_key'], $_GET['k'] ?? '')) { http_response_code(403); exit('forbidden'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $code = trim((string)($in['invite_code'] ?? ''));
    if ($code === '') uv_json(['error' => 'invite_code required'], 400);
    $r = b24_setup($code);
    if (!$r['ok']) uv_json(['ok' => false, 'detail' => $r['detail']], 502);
    $probe = b24_api('GET', '/properties');
    uv_json(['ok' => true, 'properties' => is_array($probe)
        ? array_map(fn($p) => ($p['id'] ?? '?') . ' ' . ($p['name'] ?? ''), $probe['data'] ?? [])
        : 'token stored, probe failed']);
}

$status = [
    'refresh_token' => uv_meta_get('b24_refresh') !== null ? 'stored' : 'missing',
    'source' => b24_cfg()['source'] ?? 'local',
    'last_error' => uv_meta_get('b24_last_error'),
];
if (uv_meta_get('b24_refresh') !== null) {
    $probe = b24_api('GET', '/properties');
    $status['api'] = is_array($probe) ? 'ok' : 'FAILING';
    if (is_array($probe)) {
        $status['properties'] = array_map(
            fn($p) => ($p['id'] ?? '?') . ' ' . ($p['name'] ?? ''), $probe['data'] ?? []);
    }
}
uv_json($status);
