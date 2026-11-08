<?php
/**
 * Upper View's side of the admin network (see network.php — the SAME file as Sonata's).
 * Added 2026-11-08 from the Sonata session on Andy's authority. Status, payment method and
 * notification emails, used by this admin AND by Sonata's admin via api.php?action=network.
 * Promo codes are not built on Upper View yet (spec: docs/promo-codes-from-sonata_v1.0_2026-11-08.md);
 * when they are, add net_local_promo() here and 'promos' to 'features' — Sonata's admin then
 * lets you apply a promo to Upper View too.
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
        'emails' => uv_notify_list(), 'features' => array('payments', 'emails'), 'promos' => array(),
    );
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
