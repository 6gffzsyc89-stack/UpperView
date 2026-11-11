<?php
/**
 * Upper View's side of the admin network (see network.php — the SAME file as Sonata's).
 * Added 2026-11-08 from the Sonata session on Andy's authority. Status, payment method and
 * notification emails, used by this admin AND by Sonata's admin via api.php?action=network.
 * Promo codes added 2026-11-11 (net_local_promo + 'promos' feature) — either admin can now
 * apply a promo to Upper View. New codes start switched OFF (Andy's rule).
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/stripe.php';
if (is_file(__DIR__ . '/airwallex.php')) require_once __DIR__ . '/airwallex.php';
require_once __DIR__ . '/network.php';

function net_self() { return array('id' => 'upperview', 'name' => 'Upper View', 'admin' => 'https://upperviewsamui.com/booking/admin.php'); }
function net_peers() {
    return array(
        array('id' => 'sonata', 'name' => 'Sonata Samui', 'api' => 'https://sonatasamui.com/booking/api.php', 'admin' => 'https://sonatasamui.com/booking/admin.php'),
    );
}
function net_meta_get($k) { return uv_meta_get($k); }
function net_meta_set($k, $v) { uv_meta_set($k, (string) $v); }

function net_local_status() {
    $cfg = uv_config();
    $sk = uv_meta_get('stripe_secret_key') ?: ($cfg['stripe']['secret_key'] ?? '');
    $chk = json_decode((string) uv_meta_get('stripe_check'), true);
    $prov = uv_pay_provider();
    return array(
        'id' => 'upperview', 'name' => 'Upper View',
        'provider' => $prov ?: 'manual', 'provider_setting' => (string) (uv_meta_get('payment_provider') ?? 'auto'),
        'stripe' => array('saved' => (bool) $sk, 'last4' => $sk ? substr($sk, -4) : '', 'ok' => is_array($chk) ? !empty($chk['ok']) : null, 'checked' => is_array($chk) ? (int) $chk['at'] : 0),
        'airwallex' => array('configured' => function_exists('aw_enabled') && aw_enabled()),
        'rates_confirmed' => null, 'card_on' => in_array($prov, array('stripe', 'airwallex'), true),
        'emails' => uv_notify_list(), 'features' => array('payments', 'emails', 'promos'),
        'promos' => net_local_promo_list(),
    );
}

function net_local_promo_list() {
    $out = array();
    $rows = uv_db()->query('SELECT * FROM promos ORDER BY active DESC, created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $p) {
        $out[] = array('code' => $p['code'], 'title' => $p['title'], 'note' => uv_promo_note($p),
                       'active' => (int) $p['active'], 'banner' => (int) $p['banner'],
                       'valid_until' => $p['valid_until'], 'min_nights' => (int) $p['min_nights']);
    }
    return $out;
}

/** Promo operations, local or called from the Sonata admin over the signed network. */
function net_local_promo($op, $d) {
    $db = uv_db();
    $code = uv_promo_code_clean((string) ($d['code'] ?? ''));
    if ($op === 'promo_save') {
        $title = mb_substr(trim(strip_tags((string) ($d['title'] ?? ''))), 0, 160);
        $kind = ($d['kind'] ?? '') === 'percent' ? 'percent' : 'service';
        $pct = max(0, min(90, (int) ($d['percent'] ?? 0)));
        $service = mb_substr(trim(strip_tags((string) ($d['service'] ?? ''))), 0, 160);
        $until = uv_valid_date($d['valid_until'] ?? null) ? (string) $d['valid_until'] : null;
        $minN = max(0, (int) ($d['min_nights'] ?? 0));
        if (strlen($code) < 3 || strlen($code) > 20) return array('ok' => false, 'error' => 'Promo code must be 3–20 letters or digits.');
        if ($title === '') return array('ok' => false, 'error' => 'Add the promotion text for the top bar.');
        if ($kind === 'percent' && $pct < 1) return array('ok' => false, 'error' => 'Enter the discount percentage (1–90).');
        if ($kind === 'service' && $service === '') return array('ok' => false, 'error' => 'Describe the included service.');
        // New codes start OFF (Andy's rule); saving an existing code keeps its on/off state unless told.
        $active = isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : null;
        $banner = !empty($d['banner']) ? 1 : 0;
        $db->prepare("INSERT INTO promos(code,title,kind,percent,service,valid_until,min_nights,banner,active,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))
                      ON CONFLICT(code) DO UPDATE SET title=excluded.title,kind=excluded.kind,percent=excluded.percent,service=excluded.service,
                      valid_until=excluded.valid_until,min_nights=excluded.min_nights,banner=excluded.banner,
                      active=" . ($active === null ? 'promos.active' : 'excluded.active') . ",updated_at=datetime('now')")
           ->execute(array($code, $title, $kind, $kind === 'percent' ? $pct : null, $kind === 'service' ? $service : null, $until, $minN ?: null, $banner, $active === null ? 0 : $active));
        return array('ok' => true);
    }
    if ($code === '') return array('ok' => false, 'error' => 'code required');
    $exists = $db->prepare('SELECT COUNT(*) FROM promos WHERE code=?'); $exists->execute(array($code));
    if (!(int) $exists->fetchColumn()) return array('ok' => false, 'error' => 'Promo ' . $code . ' does not exist on Upper View.');
    if ($op === 'promo_active') { $db->prepare("UPDATE promos SET active=?, updated_at=datetime('now') WHERE code=?")->execute(array(!empty($d['on']) ? 1 : 0, $code)); return array('ok' => true); }
    if ($op === 'promo_banner') { $db->prepare("UPDATE promos SET banner=?, updated_at=datetime('now') WHERE code=?")->execute(array(!empty($d['on']) ? 1 : 0, $code)); return array('ok' => true); }
    if ($op === 'promo_delete') { $db->prepare('DELETE FROM promos WHERE code=?')->execute(array($code)); return array('ok' => true); }
    return array('ok' => false, 'error' => 'unknown promo action');
}

function net_local_set_provider($p) {
    uv_meta_set('payment_provider', $p);
    $eff = uv_pay_provider() ?: 'manual';
    if ($eff !== $p) return array('ok' => false, 'error' => 'Upper View has no ' . $p . ' credentials saved (or a config-file setting overrides it), so it stays on ' . $eff . '.');
    return array('ok' => true);
}

function net_local_set_emails($list) {
    $clean = array();
    foreach ($list as $e) { $e = strtolower(trim((string) $e)); if (filter_var($e, FILTER_VALIDATE_EMAIL)) $clean[] = $e; }
    $clean = array_values(array_unique($clean));
    if (!$clean) return array('ok' => false, 'error' => 'At least one valid email address is needed.');
    uv_meta_set('notify_emails', json_encode($clean));
    return array('ok' => true);
}
