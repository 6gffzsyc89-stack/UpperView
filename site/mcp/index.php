<?php
/**
 * Beds24 remote MCP server (Streamable HTTP, JSON-RPC 2.0).
 * Endpoint: https://upperviewsamui.com/mcp/?k=<access token>
 *
 * Multi-property: talks to the Beds24 API v2 with the account-level
 * refresh token already stored server-side (SQLite meta, set up via
 * booking/beds24-setup.php), so every property on the Beds24 account is
 * reachable. Tools take propertyId/roomId arguments; use list_properties
 * to discover ids.
 *
 * Access tokens live in data/config.php under 'mcp_tokens':
 *   'mcp_tokens' => [ '<token>' => ['name' => 'Andy', 'role' => 'manage'] ]
 * role 'read' = read-only tools; 'manage' adds create_block / set_prices.
 */

declare(strict_types=1);

require_once __DIR__ . '/../booking/lib.php';
require_once __DIR__ . '/../booking/beds24.php';

const MCP_PROTOCOL = '2025-03-26';
const MCP_MAX_TEXT = 60000;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, MCP-Protocol-Version');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'POST only. This is an MCP (Model Context Protocol) endpoint.']);
    exit;
}

/* ---------- auth ---------- */
function mcp_member(): ?array {
    $tokens = uv_config()['mcp_tokens'] ?? [];
    $k = (string)($_GET['k'] ?? '');
    if ($k === '' && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $k = $m[1];
    if ($k !== '' && isset($tokens[$k])) return $tokens[$k];
    return null;
}

$member = mcp_member();
if ($member === null) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32001, 'message' => 'Unauthorized: missing or unknown access token (?k=...)']]);
    exit;
}

/* ---------- JSON-RPC plumbing ---------- */
$raw = file_get_contents('php://input');
$msg = json_decode($raw, true);
if (!is_array($msg)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32700, 'message' => 'Parse error']]);
    exit;
}

function mcp_reply($id, array $result): void {
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    exit;
}
function mcp_error($id, int $code, string $message): void {
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}
function mcp_text($id, $data, bool $isError = false): void {
    $text = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (strlen($text) > MCP_MAX_TEXT) $text = substr($text, 0, MCP_MAX_TEXT) . "\n...[truncated]";
    mcp_reply($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
}

$rpc = $msg['method'] ?? '';
$id  = $msg['id'] ?? null;
$par = $msg['params'] ?? [];

/* notifications (no id) -> 202 */
if ($id === null && str_starts_with($rpc, 'notifications/')) { http_response_code(202); exit; }

if ($rpc === 'initialize') {
    $req = (string)($par['protocolVersion'] ?? MCP_PROTOCOL);
    $ver = in_array($req, ['2025-06-18', '2025-03-26', '2024-11-05'], true) ? $req : MCP_PROTOCOL;
    mcp_reply($id, [
        'protocolVersion' => $ver,
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => ['name' => 'beds24-mcp', 'title' => 'Beds24 (Upper View) property management', 'version' => '1.0.0'],
        'instructions' => 'Beds24 channel-manager access for all properties on this account ('
            . $member['name'] . ', role: ' . $member['role'] . '). Beds24 is the MASTER system: '
            . 'prices and blocks written here sync to Booking.com, Airbnb, Agoda and the website. '
            . 'Start with list_properties to discover property/room ids. Dates are YYYY-MM-DD. '
            . 'Prices are nightly rates in the property currency (THB). Rate changes and blocks are real '
            . '— only make them when a human explicitly asked for that change.',
    ]);
}
if ($rpc === 'ping') mcp_reply($id, (object)[]);

/* ---------- tool definitions ---------- */
$READ_TOOLS = [
    ['name' => 'account_status',
     'description' => 'Beds24 connection health: token scopes, validity, and what this token can access.',
     'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false]],
    ['name' => 'list_properties',
     'description' => 'List all properties on the Beds24 account, with their room types and ids. Use this first to find propertyId / roomId values.',
     'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false]],
    ['name' => 'get_calendar',
     'description' => 'Daily calendar for a room type: nightly price (price1) and units available per date. Works for any property on the account.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'roomId' => ['type' => 'integer', 'description' => 'Beds24 room (room type) id — from list_properties'],
         'propertyId' => ['type' => 'integer', 'description' => 'Alternative: all rooms of this property'],
         'startDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'endDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
     ], 'required' => ['startDate', 'endDate'], 'additionalProperties' => false]],
    ['name' => 'get_availability',
     'description' => 'Units available per date for rooms (availability only, no prices).',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'roomId' => ['type' => 'integer'],
         'propertyId' => ['type' => 'integer'],
         'startDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'endDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
     ], 'required' => ['startDate', 'endDate'], 'additionalProperties' => false]],
    ['name' => 'list_bookings',
     'description' => 'Bookings from Beds24 (all channels: Booking.com, Airbnb, Agoda, direct). Filter by property, room, dates, status or guest name.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'propertyId' => ['type' => 'integer'],
         'roomId' => ['type' => 'integer'],
         'bookingId' => ['type' => 'integer', 'description' => 'One booking by id'],
         'arrivalFrom' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'arrivalTo' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'status' => ['type' => 'string', 'description' => 'confirmed | new | request | cancelled | black'],
         'searchString' => ['type' => 'string', 'description' => 'Guest name / reference search'],
     ], 'additionalProperties' => false]],
];
$MANAGE_TOOLS = [
    ['name' => 'create_block',
     'description' => 'Block one unit of a room type for a date range (maintenance / phone booking). Syncs to all channels. REAL write — needs explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'roomId' => ['type' => 'integer'],
         'from' => ['type' => 'string', 'description' => 'first blocked night, YYYY-MM-DD'],
         'to' => ['type' => 'string', 'description' => 'checkout date (exclusive), YYYY-MM-DD'],
         'note' => ['type' => 'string'],
     ], 'required' => ['roomId', 'from', 'to'], 'additionalProperties' => false]],
    ['name' => 'set_prices',
     'description' => 'Set the nightly price (price1) for a room type over a date range. Syncs to all channels. REAL write — needs explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'roomId' => ['type' => 'integer'],
         'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
         'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD (inclusive)'],
         'price' => ['type' => 'number', 'description' => 'nightly rate in property currency'],
     ], 'required' => ['roomId', 'from', 'to', 'price'], 'additionalProperties' => false]],
];

$tools = $READ_TOOLS;
if (($member['role'] ?? 'read') === 'manage') $tools = array_merge($tools, $MANAGE_TOOLS);

if ($rpc === 'tools/list') mcp_reply($id, ['tools' => $tools]);

if ($rpc !== 'tools/call') mcp_error($id, -32601, 'Method not found: ' . $rpc);

/* ---------- tools/call ---------- */
$tool = (string)($par['name'] ?? '');
$a = $par['arguments'] ?? [];
$allowed = array_column($tools, 'name');
if (!in_array($tool, $allowed, true)) mcp_error($id, -32602, 'Unknown tool (or not permitted for this token): ' . $tool);

function mcp_b24(string $m, string $path, ?array $body, $id) {
    $res = b24_api($m, $path, $body);
    if ($res === null) {
        mcp_text($id, 'Beds24 API call failed. Last error: ' . (uv_meta_get('b24_last_error') ?? 'unknown'), true);
    }
    return $res;
}
function mcp_date(string $s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

switch ($tool) {
    case 'account_status': {
        $details = mcp_b24('GET', '/authentication/details', null, $id);
        mcp_text($id, ['beds24_token' => $details,
                       'note' => 'validToken true means the stored refresh token works. scopes/propertyIds show what this account token can reach.']);
    }
    case 'list_properties': {
        $res = mcp_b24('GET', '/properties?includeAllRooms=true', null, $id);
        mcp_text($id, $res);
    }
    case 'get_calendar':
    case 'get_availability': {
        $sd = (string)($a['startDate'] ?? ''); $ed = (string)($a['endDate'] ?? '');
        if (!mcp_date($sd) || !mcp_date($ed)) mcp_text($id, 'startDate and endDate must be YYYY-MM-DD', true);
        $q = 'startDate=' . $sd . '&endDate=' . $ed;
        if (!empty($a['roomId'])) $q .= '&roomId=' . (int)$a['roomId'];
        if (!empty($a['propertyId'])) $q .= '&propertyId=' . (int)$a['propertyId'];
        $path = $tool === 'get_calendar'
            ? '/inventory/rooms/calendar?includeNumAvail=true&includePrices=true&' . $q
            : '/inventory/rooms/availability?' . $q;
        mcp_text($id, mcp_b24('GET', $path, null, $id));
    }
    case 'list_bookings': {
        $q = [];
        if (!empty($a['bookingId'])) $q[] = 'id=' . (int)$a['bookingId'];
        if (!empty($a['propertyId'])) $q[] = 'propertyId=' . (int)$a['propertyId'];
        if (!empty($a['roomId'])) $q[] = 'roomId=' . (int)$a['roomId'];
        foreach (['arrivalFrom', 'arrivalTo'] as $f) {
            if (!empty($a[$f])) { if (!mcp_date((string)$a[$f])) mcp_text($id, $f . ' must be YYYY-MM-DD', true); $q[] = $f . '=' . $a[$f]; }
        }
        if (!empty($a['status'])) $q[] = 'status=' . rawurlencode((string)$a['status']);
        if (!empty($a['searchString'])) $q[] = 'searchString=' . rawurlencode((string)$a['searchString']);
        mcp_text($id, mcp_b24('GET', '/bookings' . ($q ? '?' . implode('&', $q) : ''), null, $id));
    }
    case 'create_block': {
        $from = (string)($a['from'] ?? ''); $to = (string)($a['to'] ?? '');
        if (!mcp_date($from) || !mcp_date($to) || $to <= $from) mcp_text($id, 'from/to must be YYYY-MM-DD with to after from', true);
        $res = mcp_b24('POST', '/bookings', [[
            'roomId' => (int)$a['roomId'], 'status' => 'black',
            'arrival' => $from, 'departure' => $to,
            'firstName' => 'BLOCK', 'lastName' => (string)($a['note'] ?? 'maintenance'),
            'apiReference' => 'MCP-' . substr(bin2hex(random_bytes(4)), 0, 8),
        ]], $id);
        mcp_text($id, $res);
    }
    case 'set_prices': {
        $from = (string)($a['from'] ?? ''); $to = (string)($a['to'] ?? '');
        $price = (float)($a['price'] ?? 0);
        if (!mcp_date($from) || !mcp_date($to) || $to < $from) mcp_text($id, 'from/to must be YYYY-MM-DD', true);
        if ($price <= 0) mcp_text($id, 'price must be > 0', true);
        $res = mcp_b24('POST', '/inventory/rooms/calendar', [[
            'roomId' => (int)$a['roomId'],
            'calendar' => [['from' => $from, 'to' => $to, 'price1' => $price]],
        ]], $id);
        mcp_text($id, $res);
    }
}
mcp_error($id, -32602, 'Unhandled tool');
