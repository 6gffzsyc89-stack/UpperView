<?php
/**
 * Admin network: lets the admin page of one property change settings on the others
 * (payment method now; promo codes where the other site supports them).
 * THE SAME FILE lives in every property's repo (Sonata: site/booking/network.php,
 * Upper View: site/booking/network.php). Keep the copies identical.
 *
 * Each site provides its own adapter (network-local.php) with:
 *   net_self(): ['id','name']            net_peers(): [['id','name','api'], ...]
 *   net_meta_get($k) / net_meta_set($k,$v)
 *   net_local_status(): array            net_local_set_provider(string $p): array
 *   net_local_set_emails(array $list): array
 *   net_local_promo(string $op, array $d): array   (only if the site supports promos)
 *
 * Security: every call is signed with HMAC-SHA256 using a shared secret kept in each
 * site's database (meta 'network_key', never in git), and must be under 5 minutes old.
 * PHP 7.2-safe (no typed properties, no arrow functions).
 */

function net_key() {
    $k = net_meta_get('network_key');
    return ($k && strlen($k) >= 32) ? $k : null;
}

function net_sign($ts, $op, $body) {
    return hash_hmac('sha256', $ts . '|' . $op . '|' . $body, (string) net_key());
}

/** Call another property. Returns ['ok'=>bool, ...response] or ['ok'=>false,'error'=>...]. */
function net_call($site, $op, $data = array()) {
    if (!net_key()) return array('ok' => false, 'error' => 'network key not set on this site');
    $body = json_encode(array('op' => $op, 'data' => $data, 'from' => net_self()['id']));
    $ts = (string) time();
    $ch = curl_init($site['api'] . (strpos($site['api'], '?') === false ? '?' : '&') . 'action=network');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Net-Ts: ' . $ts, 'X-Net-Sig: ' . net_sign($ts, $op, $body),
                                    'User-Agent: property-admin-network/1'),
    ));
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($j)) return array('ok' => false, 'error' => 'no answer from ' . $site['name'] . ' (HTTP ' . $http . ')');
    if ($http !== 200 && empty($j['error'])) $j['error'] = 'HTTP ' . $http;
    if (!isset($j['ok'])) $j['ok'] = $http === 200;
    return $j;
}

/** Handle ?action=network on this site (called from api.php). Never returns. */
function net_handle() {
    $out = function ($a, $code = 200) {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8');
        echo json_encode($a); exit;
    };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(array('ok' => false, 'error' => 'POST only'), 405);
    if (!net_key()) $out(array('ok' => false, 'error' => 'network not set up on this site'), 503);
    $body = file_get_contents('php://input');
    $ts = isset($_SERVER['HTTP_X_NET_TS']) ? (string) $_SERVER['HTTP_X_NET_TS'] : '';
    $sig = isset($_SERVER['HTTP_X_NET_SIG']) ? (string) $_SERVER['HTTP_X_NET_SIG'] : '';
    $j = json_decode((string) $body, true);
    $op = is_array($j) && isset($j['op']) ? (string) $j['op'] : '';
    if ($ts === '' || abs(time() - (int) $ts) > 300 || !hash_equals(net_sign($ts, $op, (string) $body), $sig)) {
        $out(array('ok' => false, 'error' => 'bad signature'), 401);
    }
    $data = isset($j['data']) && is_array($j['data']) ? $j['data'] : array();
    if ($op === 'status') $out(array('ok' => true, 'status' => net_local_status()));
    if ($op === 'set_provider') {
        $p = isset($data['provider']) ? (string) $data['provider'] : '';
        if (!in_array($p, array('stripe', 'airwallex', 'manual'), true)) $out(array('ok' => false, 'error' => 'unknown provider'), 400);
        $r = net_local_set_provider($p);
        $out(array('ok' => !empty($r['ok']), 'error' => isset($r['error']) ? $r['error'] : null, 'status' => net_local_status()));
    }
    if ($op === 'set_emails') {
        $list = isset($data['emails']) && is_array($data['emails']) ? $data['emails'] : array();
        $r = net_local_set_emails($list);
        $out(array('ok' => !empty($r['ok']), 'error' => isset($r['error']) ? $r['error'] : null, 'status' => net_local_status()));
    }
    if (strpos($op, 'promo_') === 0) {
        if (!function_exists('net_local_promo')) $out(array('ok' => false, 'error' => 'promo codes are not set up on this site yet'), 501);
        $r = net_local_promo($op, $data);
        $out(array('ok' => !empty($r['ok']), 'error' => isset($r['error']) ? $r['error'] : null, 'status' => net_local_status()));
    }
    $out(array('ok' => false, 'error' => 'unknown op'), 400);
}

/** Status of this site and every peer, for the admin page. */
function net_all_status() {
    $all = array(array('site' => net_self(), 'self' => true, 'status' => net_local_status()));
    foreach (net_peers() as $p) {
        $r = net_call($p, 'status');
        $all[] = array('site' => $p, 'self' => false, 'status' => !empty($r['ok']) ? $r['status'] : null, 'error' => !empty($r['ok']) ? null : ($r['error'] ?? 'no answer'));
    }
    return $all;
}

/* ---------- links between the admin pages ----------
   A link from one property's admin to another carries a short-lived signed token (15 min).
   The receiving admin checks it with the shared key and opens itself, so you move between
   the admins without pasting keys. */
function net_admin_token() {
    $ts = (string) time();
    return $ts . '.' . substr(hash_hmac('sha256', 'admin|' . $ts, (string) net_key()), 0, 40);
}
function net_admin_token_ok($t) {
    if (!net_key() || !is_string($t) || strpos($t, '.') === false) return false;
    list($ts, $sig) = explode('.', $t, 2);
    if (abs(time() - (int) $ts) > 900) return false;
    return hash_equals(substr(hash_hmac('sha256', 'admin|' . $ts, (string) net_key()), 0, 40), $sig);
}
function net_admin_links() {
    $out = array();
    foreach (net_peers() as $p) $out[] = array('name' => $p['name'], 'url' => $p['admin'] . '?nt=' . rawurlencode(net_admin_token()));
    return $out;
}
