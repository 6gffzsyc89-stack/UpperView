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
        'serverInfo' => ['name' => 'beds24-mcp', 'title' => 'Beds24 (Upper View) property management', 'version' => '1.1.0'],
        'instructions' => 'Beds24 channel-manager access for all properties on this account ('
            . $member['name'] . ', role: ' . $member['role'] . '). Beds24 is the MASTER system: '
            . 'prices and blocks written here sync to Booking.com, Airbnb, Agoda and the website. '
            . 'Start with list_properties to discover property/room ids. Dates are YYYY-MM-DD. '
            . 'Prices are nightly rates in the property currency (THB). Rate changes and blocks are real '
            . '— only make them when a human explicitly asked for that change. '
            . 'beds24_get / beds24_write mirror the ENTIRE Beds24 API v2 (any endpoint). '
            . 'Note: the Beds24 API does not expose every control-panel screen — channel settings '
            . '(get_channel_settings) cover iCal/Airbnb/VRBO; Booking.com/Agoda connection details beyond '
            . 'that live only in the Beds24 control panel. Any booking existing in Beds24 closes the OTA '
            . 'calendars automatically on all connected channels; website bookings carry SOURCE/PAYMENT/OTA '
            . 'note lines. Guest replies: send_guest_message goes through the booking\'s OTA channel; '
            . 'send_email sends as booking@upperviewsamui.com.',
    ]);
}
if ($rpc === 'ping') {
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object)[]]);
    exit;
}

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
    ['name' => 'get_channel_settings',
     'description' => 'Channel/OTA configuration per room from Beds24 (Alpha API): iCal export/import links, Airbnb and VRBO connection settings. NOTE: Booking.com/Agoda connection details beyond this are not exposed by the Beds24 API — they live only in the Beds24 control panel. Which OTAs deliver bookings is visible from list_bookings (channel/apiSource fields).',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'propertyId' => ['type' => 'integer', 'description' => 'required; Upper View = 365322'],
         'roomId' => ['type' => 'integer'],
         'channel' => ['type' => 'string', 'description' => 'iCalExport | iCalImport | airbnb | vrbo'],
     ], 'required' => ['propertyId'], 'additionalProperties' => false]],
    ['name' => 'get_guest_messages',
     'description' => 'Guest messages for bookings (conversations through Booking.com / Airbnb / channel messaging). Filter by bookingId, unread, age or side (guest/host).',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'bookingId' => ['type' => 'integer'],
         'propertyId' => ['type' => 'integer'],
         'filter' => ['type' => 'string', 'description' => 'read | unread'],
         'maxAge' => ['type' => 'integer', 'description' => 'only messages from the last N days'],
         'source' => ['type' => 'string', 'description' => 'host | guest | internalNote | system'],
     ], 'additionalProperties' => false]],
    ['name' => 'get_invoices',
     'description' => 'Invoice and payment lines per booking from Beds24 — shows whether and how a booking was paid. Website bookings paid online carry a charge + payment line.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'bookingId' => ['type' => 'integer'],
         'propertyId' => ['type' => 'integer'],
     ], 'additionalProperties' => false]],
    ['name' => 'get_notify_emails',
     'description' => 'The list of email addresses that receive ALL website booking notifications.',
     'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false]],
    ['name' => 'beds24_get',
     'description' => 'Full READ mirror of the Beds24 API v2: call ANY GET endpoint. Paths: /properties, /bookings, /bookings/messages, /bookings/invoices, /channels/settings, /channels/airbnb/users, /channels/airbnb/listings, /channels/airbnb/reviews, /channels/booking/reviews, /channels/stripe/paymentMethods, /channels/stripe/charges, /inventory/rooms/calendar, /inventory/rooms/availability, /inventory/rooms/offers, /inventory/rooms/unitBookings, /inventory/fixedPrices, /accounts, /authentication/details. query = raw query string without the leading ?.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'path' => ['type' => 'string', 'description' => 'e.g. /bookings/messages'],
         'query' => ['type' => 'string', 'description' => 'e.g. bookingId=95735792&filter=unread'],
     ], 'required' => ['path'], 'additionalProperties' => false]],
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
    ['name' => 'send_guest_message',
     'description' => 'Reply to a guest THROUGH their booking channel (Booking.com / Airbnb messaging). The message is delivered by the OTA. REAL send — needs explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'bookingId' => ['type' => 'integer', 'description' => 'Beds24 booking id'],
         'message' => ['type' => 'string'],
     ], 'required' => ['bookingId', 'message'], 'additionalProperties' => false]],
    ['name' => 'send_email',
     'description' => 'Send an email FROM booking@upperviewsamui.com (the hotel address). Use for email replies to guests, sending booking info etc. REAL send — needs explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'to' => ['type' => 'string', 'description' => 'recipient email'],
         'subject' => ['type' => 'string'],
         'body' => ['type' => 'string', 'description' => 'plain text (or HTML when html=true)'],
         'html' => ['type' => 'boolean'],
     ], 'required' => ['to', 'subject', 'body'], 'additionalProperties' => false]],
    ['name' => 'set_notify_emails',
     'description' => 'Replace the list of addresses that receive ALL website booking notifications. Takes the FULL new list. REAL change — needs explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'emails' => ['type' => 'array', 'items' => ['type' => 'string']],
     ], 'required' => ['emails'], 'additionalProperties' => false]],
    ['name' => 'beds24_write',
     'description' => 'Full WRITE mirror of the Beds24 API v2: POST/PATCH/DELETE any endpoint. body is the JSON payload Beds24 expects (usually an ARRAY of objects). Examples: modify a booking: path=/bookings body=[{"id":123,"status":"cancelled"}]; mark message read: path=/bookings/messages body=[{"id":456,"read":true}]. REAL writes that sync to OTAs — only on explicit human instruction.',
     'inputSchema' => ['type' => 'object', 'properties' => [
         'path' => ['type' => 'string'],
         'method' => ['type' => 'string', 'description' => 'POST (default) | PATCH | DELETE'],
         'query' => ['type' => 'string', 'description' => 'raw query string without ?'],
         'body' => ['description' => 'JSON payload'],
     ], 'required' => ['path'], 'additionalProperties' => false]],
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
    case 'get_channel_settings': {
        if (empty($a['propertyId'])) mcp_text($id, 'propertyId is required (Upper View = 365322)', true);
        $q = 'propertyId=' . (int)$a['propertyId'];
        if (!empty($a['roomId'])) $q .= '&roomId=' . (int)$a['roomId'];
        if (!empty($a['channel'])) $q .= '&channel=' . rawurlencode((string)$a['channel']);
        mcp_text($id, mcp_b24('GET', '/channels/settings?' . $q, null, $id));
    }
    case 'get_guest_messages': {
        $q = [];
        foreach (['bookingId' => 'bookingId', 'propertyId' => 'propertyId', 'maxAge' => 'maxAge'] as $k => $p) {
            if (!empty($a[$k])) $q[] = $p . '=' . (int)$a[$k];
        }
        if (!empty($a['filter'])) $q[] = 'filter=' . rawurlencode((string)$a['filter']);
        if (!empty($a['source'])) $q[] = 'source=' . rawurlencode((string)$a['source']);
        mcp_text($id, mcp_b24('GET', '/bookings/messages' . ($q ? '?' . implode('&', $q) : ''), null, $id));
    }
    case 'get_invoices': {
        $q = [];
        if (!empty($a['bookingId'])) $q[] = 'bookingId=' . (int)$a['bookingId'];
        if (!empty($a['propertyId'])) $q[] = 'propertyId=' . (int)$a['propertyId'];
        mcp_text($id, mcp_b24('GET', '/bookings/invoices' . ($q ? '?' . implode('&', $q) : ''), null, $id));
    }
    case 'get_notify_emails': {
        mcp_text($id, ['notify_emails' => uv_notify_list(),
                       'source' => uv_meta_get('notify_emails') !== null ? 'meta (set via API)' : 'config.php']);
    }
    case 'beds24_get': {
        $path = (string)($a['path'] ?? '');
        if (!preg_match('#^/[A-Za-z0-9/._-]*$#', $path)) mcp_text($id, 'bad path (must start with /)', true);
        $qs = (string)($a['query'] ?? '');
        if ($qs !== '' && !preg_match('/^[A-Za-z0-9=&%._,+\[\]-]*$/', $qs)) mcp_text($id, 'bad query string', true);
        mcp_text($id, mcp_b24('GET', $path . ($qs !== '' ? '?' . $qs : ''), null, $id));
    }
    case 'send_guest_message': {
        $bid = (int)($a['bookingId'] ?? 0); $txt = trim((string)($a['message'] ?? ''));
        if ($bid <= 0 || $txt === '') mcp_text($id, 'bookingId and message required', true);
        mcp_text($id, mcp_b24('POST', '/bookings/messages', [['bookingId' => $bid, 'message' => $txt]], $id));
    }
    case 'send_email': {
        $to = trim((string)($a['to'] ?? ''));
        $subject = trim((string)($a['subject'] ?? '')); $body = (string)($a['body'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) mcp_text($id, 'invalid to address', true);
        if ($subject === '' || $body === '') mcp_text($id, 'subject and body required', true);
        $headers = !empty($a['html'])
            ? "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com"
            : "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com";
        $ok = @mail($to, $subject, $body, $headers, '-f booking@upperviewsamui.com');
        mcp_text($id, ['sent' => (bool)$ok, 'to' => $to, 'from' => 'booking@upperviewsamui.com'], !$ok);
    }
    case 'set_notify_emails': {
        $emails = $a['emails'] ?? null;
        if (!is_array($emails) || $emails === []) mcp_text($id, 'emails: non-empty array required', true);
        $clean = [];
        foreach ($emails as $e) {
            $e = trim((string)$e);
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) mcp_text($id, 'invalid email: ' . $e, true);
            $clean[] = $e;
        }
        uv_meta_set('notify_emails', json_encode(array_values(array_unique($clean))));
        mcp_text($id, ['ok' => true, 'notify_emails' => $clean]);
    }
    case 'beds24_write': {
        $path = (string)($a['path'] ?? '');
        $m = strtoupper((string)($a['method'] ?? 'POST'));
        if (!preg_match('#^/[A-Za-z0-9/._-]*$#', $path)) mcp_text($id, 'bad path (must start with /)', true);
        if (!in_array($m, ['POST', 'PATCH', 'DELETE'], true)) mcp_text($id, 'method must be POST/PATCH/DELETE', true);
        $qs = (string)($a['query'] ?? '');
        if ($qs !== '' && !preg_match('/^[A-Za-z0-9=&%._,+\[\]-]*$/', $qs)) mcp_text($id, 'bad query string', true);
        $body = $a['body'] ?? null;
        mcp_text($id, mcp_b24($m, $path . ($qs !== '' ? '?' . $qs : ''), is_array($body) ? $body : null, $id));
    }
}
mcp_error($id, -32602, 'Unhandled tool');
