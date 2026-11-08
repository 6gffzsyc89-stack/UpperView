<?php
/** Minimal admin: list bookings, block dates, cancel. Key-protected.
 *  Payments panel (2026-11-08, from the Sonata session on Andy's authority): clear status
 *  badges, a read-only "Test Stripe connection", and every save returns to the section it
 *  came from with the message shown there (post / redirect / get). */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/beds24.php';
require_once __DIR__ . '/stripe.php';
if (is_file(__DIR__ . '/airwallex.php')) require_once __DIR__ . '/airwallex.php';

$cfg = uv_config();
$k2 = (string) uv_meta_get('admin_key2');
$kin = (string) ($_GET['k'] ?? '');
if (!hash_equals($cfg['admin_key'], $kin) && ($k2 === '' || !hash_equals($k2, $kin))) {
    http_response_code(403); exit('forbidden');
}
$k = htmlspecialchars($kin);
$db = uv_db();
$msg = ''; $ok = true; $anchor = '';
$self = '?k=' . rawurlencode($kin);

/** Live check of the saved Stripe key: lists one Checkout Session (read-only, charges nothing). */
function uv_admin_stripe_check(): array {
    if (!st_enabled()) { $r = ['ok' => false, 'at' => time(), 'why' => 'no key saved']; uv_meta_set('stripe_check', json_encode($r)); return $r; }
    list($http, $data) = st_http('GET', '/v1/checkout/sessions?limit=1');
    $r = ['ok' => $http === 200, 'at' => time(), 'why' => $http === 200 ? '' : ('HTTP ' . $http . ' ' . ($data['error']['message'] ?? ''))];
    uv_meta_set('stripe_check', json_encode($r));
    return $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'block' && uv_valid_date($_POST['ci'] ?? null) && uv_valid_date($_POST['co'] ?? null)
        && uv_valid_type($_POST['type'] ?? '') && $_POST['co'] > $_POST['ci']) {
        $now = gmdate('Y-m-d H:i:s');
        $code = uv_booking_code();
        $db->prepare("INSERT INTO bookings (code,type,checkin,checkout,guests,name,status,source,currency,created_at,updated_at)
                      VALUES (?,?,?,?,0,?,'blocked','admin',?,?,?)")
           ->execute([$code, $_POST['type'], $_POST['ci'], $_POST['co'],
                      $_POST['note'] ?? 'manual block', $cfg['currency'], $now, $now]);
        // Beds24 is master: the block is only real once it exists there too
        // (that is what closes the OTA calendars).
        $b24id = null;
        if (b24_enabled()) {
            $roomId = b24_cfg()['room_map'][$_POST['type']] ?? null;
            $res = $roomId ? b24_api('POST', '/bookings', [[
                'roomId' => $roomId, 'status' => 'black',
                'arrival' => $_POST['ci'], 'departure' => $_POST['co'],
                'firstName' => 'BLOCK', 'lastName' => $_POST['note'] ?? 'maintenance',
                'apiReference' => $code,
            ]]) : null;
            $b24id = $res[0]['new']['id'] ?? ($res[0]['id'] ?? null);
        }
        $msg = $b24id
            ? "Dates blocked ($code) — written to Beds24 (#$b24id), OTA calendars closed for that unit."
            : "Dates blocked ($code) locally, but the Beds24 write FAILED — the block is NOT live on the OTA calendars. Retry or block it in Beds24 directly.";
    }
    if ($act === 'cancel' && !empty($_POST['code'])) {
        $db->prepare("UPDATE bookings SET status='cancelled', updated_at=datetime('now') WHERE code=?")
           ->execute([$_POST['code']]);
        $msg = 'Cancelled ' . htmlspecialchars($_POST['code']) . '.';
    }
    if ($act === 'confirm' && !empty($_POST['code'])) {
        // Manual close: confirming an unpaid request writes it to Beds24,
        // which closes the OTA calendars. Paid bookings do this automatically.
        $st = $db->prepare('SELECT * FROM bookings WHERE code=?');
        $st->execute([$_POST['code']]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $db->prepare("UPDATE bookings SET status='confirmed', updated_at=datetime('now') WHERE code=?")
               ->execute([$b['code']]);
            $b24id = b24_create_booking([
                'code' => $b['code'], 'type' => $b['type'],
                'checkin' => $b['checkin'], 'checkout' => $b['checkout'],
                'guests' => (int)$b['guests'], 'name' => (string)$b['name'],
                'email' => (string)$b['email'], 'phone' => (string)$b['phone'],
                'status' => 'confirmed', 'manual' => true,
                'cot' => (int)($b['cot'] ?? 0),
                'rooms' => (int)($b['rooms'] ?? 1),
            ]);
            $msg = 'Confirmed ' . htmlspecialchars($b['code'])
                 . ($b24id ? " — written to Beds24 (#$b24id), OTA calendars now closed."
                           : ' — WARNING: Beds24 write failed, OTA calendars are still OPEN. Close them manually in Beds24.');
        } else {
            $msg = 'Unknown booking code.';
        }
    }
    if ($act === 'refresh') { uv_refresh_ota(true); $msg = 'OTA calendars refreshed.'; }
    if ($act === 'stripekey') {
        $sk = trim((string)($_POST['sk'] ?? ''));
        if (preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', $sk)) {
            uv_meta_set('stripe_secret_key', $sk);
            uv_meta_set('payment_provider', 'stripe');
            $chk = uv_admin_stripe_check(); $ok = $chk['ok'];
            $msg = 'Stripe key saved (ending …' . htmlspecialchars(substr($sk, -4)) . ') and Stripe is the payment provider. '
                 . ($chk['ok'] ? 'Stripe accepted the key.' : 'But Stripe did not accept it: ' . htmlspecialchars($chk['why']) . ' — check the key\'s permissions (Checkout Sessions: Write).');
        } else {
            $msg = 'That does not look like a Stripe secret/restricted key (sk_… or rk_…). Nothing saved.'; $ok = false;
        }
        $anchor = 'payments';
    }
    if ($act === 'stripetest') {
        $chk = uv_admin_stripe_check(); $ok = $chk['ok']; $anchor = 'payments';
        $msg = $chk['ok'] ? 'Stripe connection OK — the saved key works.' : 'Stripe connection FAILED: ' . htmlspecialchars($chk['why']);
    }
    if ($act === 'provider' && in_array($_POST['provider'] ?? '', ['stripe', 'airwallex'], true)) {
        uv_meta_set('payment_provider', $_POST['provider']); $anchor = 'payments';
        $msg = 'Payment provider set to ' . htmlspecialchars($_POST['provider']) . (uv_pay_provider() !== $_POST['provider'] ? ' — but it is not in effect (missing credentials or a config-file override).' : '.');
    }
    if ($msg === '') $msg = 'Nothing changed.';
    uv_meta_set('admin_flash', json_encode(['msg' => $msg, 'ok' => $ok, 'anchor' => $anchor]));
    header('Location: ' . $self . ($anchor ? '#' . $anchor : ''), true, 303);
    exit;
}
$flash = json_decode((string) uv_meta_get('admin_flash'), true);
if ($flash) uv_meta_set('admin_flash', '');
function uv_flash_box($where) {
    global $flash;
    if (!is_array($flash) || empty($flash['msg']) || ($flash['anchor'] ?? '') !== $where) return '';
    return '<p class="msg ' . (!empty($flash['ok']) ? 'ok' : 'bad') . '">' . $flash['msg'] . '</p>';
}
function uv_badge($on, $yes, $no) { return '<span class="badge ' . ($on ? 'g' : 'a') . '">' . ($on ? $yes : $no) . '</span>'; }
$pskSet = uv_meta_get('stripe_secret_key') ?: (uv_config()['stripe']['secret_key'] ?? '');
$effProv = uv_pay_provider();
$payOn = in_array($effProv, ['stripe', 'airwallex'], true);
$stChk = json_decode((string) uv_meta_get('stripe_check'), true);
if ($pskSet && (!$stChk || time() - (int)$stChk['at'] > 3600)) $stChk = uv_admin_stripe_check(); // re-check hourly on page load

$rows = $db->query("SELECT * FROM bookings WHERE status != 'cancelled'
                    ORDER BY checkin ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$ota = $db->query("SELECT source, unit_key, type, dtstart, dtend FROM ota_busy
                   WHERE dtend >= date('now') ORDER BY dtstart LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$fetched = uv_meta_get('ota_fetched_at');

/* Live view of the master system: every booking/block in Beds24, all channels. */
$b24rows = [];
$roomNames = [];
foreach ((b24_cfg()['room_map'] ?? []) as $t => $rid) $roomNames[$rid] = uv_type_name($t);
if (b24_enabled()) {
    $res = b24_api('GET', '/bookings?arrivalFrom=' . date('Y-m-d', strtotime('-7 days'))
        . '&arrivalTo=' . date('Y-m-d', strtotime('+365 days')));
    foreach (($res['data'] ?? []) as $bb) {
        if (($bb['status'] ?? '') === 'cancelled') continue;
        $b24rows[] = $bb;
    }
    usort($b24rows, function ($x, $y) { return strcmp($x['arrival'] ?? '', $y['arrival'] ?? ''); });
}
?><!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Upper View — bookings</title>
<style>
body{font:14px/1.5 -apple-system,system-ui,sans-serif;margin:24px;color:#111;background:#F7F0E9}
h1,h2{font-weight:500;letter-spacing:.04em} table{border-collapse:collapse;width:100%;background:#fff;margin:0 0 28px}
th,td{border:1px solid #ddd;padding:6px 10px;text-align:left;font-size:13px}
th{background:#4F6446;color:#fff;font-weight:500} .s-confirmed{color:#2c7a2c;font-weight:600}
.s-inquiry{color:#b8860b;font-weight:600} .s-pending{color:#888} .s-blocked{color:#a33}
form.inline{display:inline} input,select,button{font:inherit;padding:4px 8px;margin:2px}
button{background:#4F6446;color:#fff;border:none;cursor:pointer}.msg{padding:10px 14px;margin:0 0 14px;font-weight:500}
.msg.ok{background:#dff0df;color:#1d5e1d;border:1px solid #9cc89c}.msg.bad{background:#f8e1dc;color:#8b2d14;border:1px solid #e0a493}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;margin:2px 4px 2px 0}
.badge.g{background:#dff0df;color:#1d5e1d}.badge.a{background:#fbecd2;color:#8a5a00}
.paystate{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 16px;margin:0 0 14px;font-size:15px}
.paystate.on{background:#1d5e1d;color:#fff}.paystate.off{background:#fbecd2;color:#5a3b00}
</style>
<h1>Upper View — bookings</h1>
<?= uv_flash_box('') ?>
<div class="paystate <?= $payOn ? 'on' : 'off' ?>"><b>Online card payments: <?= $payOn ? 'ON' : 'OFF' ?></b>
<span><?= $payOn ? 'Guests pay by card (' . htmlspecialchars($effProv) . ') on the checkout page.' : 'No payment provider in effect — guests send requests.' ?></span>
<a href="#payments" style="color:inherit;text-decoration:underline">Payments settings</a></div>
<p>OTA calendars last pulled: <?= $fetched ? gmdate('Y-m-d H:i', (int)$fetched) . ' UTC' : 'never' ?>
<form class="inline" method="post"><input type="hidden" name="act" value="refresh"><button>Pull now</button></form></p>

<h2>Bookings &amp; blocks</h2>
<table><tr><th>Code</th><th>Type</th><th>Check-in</th><th>Check-out</th><th>Guests</th><th>Name</th><th>Contact</th><th>Status</th><th>Amount</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars($r['code']) ?></td><td><?= (int)($r['rooms'] ?? 1) > 1 ? (int)$r['rooms'] . ' × ' : '' ?><?= $r['type'] ?></td>
<td><?= $r['checkin'] ?></td><td><?= $r['checkout'] ?></td><td><?= $r['guests'] ?><?= !empty($r['cot']) ? ' +cot' : '' ?></td>
<td><?= htmlspecialchars((string)$r['name']) ?></td>
<td><?= htmlspecialchars((string)$r['email']) ?> <?= htmlspecialchars((string)$r['phone']) ?></td>
<td class="s-<?= $r['status'] ?>"><?= $r['status'] ?></td>
<td><?= $r['amount'] ? number_format((float)$r['amount']) . ' ' . $r['currency'] : '—' ?></td>
<td>
<?php if ($r['status'] === 'inquiry'): ?>
<form class="inline" method="post" onsubmit="return confirm('Confirm <?= $r['code'] ?> and close OTA calendars?')"><input type="hidden" name="act" value="confirm"><input type="hidden" name="code" value="<?= $r['code'] ?>"><button>Confirm + close OTAs</button></form>
<?php endif; ?>
<form class="inline" method="post" onsubmit="return confirm('Cancel <?= $r['code'] ?>?')"><input type="hidden" name="act" value="cancel"><input type="hidden" name="code" value="<?= $r['code'] ?>"><button style="background:#a33">Cancel</button></form>
</td></tr>
<?php endforeach; ?></table>

<h2 id="payments">Payments</h2>
<?= uv_flash_box('payments') ?>
<p>
<?= uv_badge((bool)$pskSet, 'Stripe key saved (ending …' . htmlspecialchars(substr((string)$pskSet, -4)) . ')', 'Stripe key not saved') ?>
<?= $pskSet ? uv_badge(!empty($stChk['ok']), 'Stripe accepts the key · checked ' . htmlspecialchars(gmdate('d M H:i', (int)($stChk['at'] ?? 0))) . ' UTC', 'Stripe rejected the key: ' . htmlspecialchars($stChk['why'] ?? '')) : '' ?>
<?= uv_badge($payOn, 'Provider in effect: ' . htmlspecialchars($effProv), 'No provider in effect') ?>
<?= uv_badge($payOn, 'Card payments ON', 'Card payments OFF') ?>
</p>
<form method="post"><input type="hidden" name="act" value="stripekey">
<input name="sk" type="password" placeholder="Paste Stripe secret key (sk_live_… or rk_live_…)" style="width:380px" autocomplete="off">
<button>Save Stripe key</button></form>
<?php if ($pskSet): ?><form class="inline" method="post"><input type="hidden" name="act" value="stripetest"><button style="background:#3D4C36">Test Stripe connection</button></form><?php endif; ?>
<form class="inline" method="post"><input type="hidden" name="act" value="provider"><input type="hidden" name="provider" value="stripe"><button>Use Stripe</button></form>
<form class="inline" method="post"><input type="hidden" name="act" value="provider"><input type="hidden" name="provider" value="airwallex"><button>Use Airwallex</button></form>

<h2>Block dates (maintenance, phone bookings…)</h2>
<form method="post"><input type="hidden" name="act" value="block">
<select name="type"><option value="villa">Mini Villa (1 of 8)</option><option value="superior">Superior Suite</option><option value="deluxe">Deluxe Suite</option><option value="king">King Suite</option></select>
<input type="date" name="ci" required> → <input type="date" name="co" required>
<input name="note" placeholder="note"><button>Block</button></form>

<h2>Beds24 — live bookings &amp; blocks (all channels)</h2>
<p style="font-size:12.5px;color:#555;margin:0 0 8px">This is the master system's view (next 12 months). Any entry here closes the
OTA calendars for its unit automatically. Website bookings appear after payment or after "Confirm + close OTAs".</p>
<?php if (!b24_enabled()): ?>
<p>Beds24 not connected.</p>
<?php elseif ($b24rows === []): ?>
<p>No upcoming bookings or blocks in Beds24.</p>
<?php else: ?>
<table><tr><th>Beds24 #</th><th>Channel</th><th>Room</th><th>Arrival</th><th>Departure</th><th>Guest</th><th>Status</th><th>Ref</th></tr>
<?php foreach ($b24rows as $bb): ?>
<tr><td><?= (int)($bb['id'] ?? 0) ?></td>
<td><?= htmlspecialchars((string)($bb['apiSource'] ?: ($bb['channel'] ?? ''))) ?></td>
<td><?= htmlspecialchars($roomNames[$bb['roomId'] ?? 0] ?? (string)($bb['roomId'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($bb['arrival'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($bb['departure'] ?? '')) ?></td>
<td><?= htmlspecialchars(trim(($bb['firstName'] ?? '') . ' ' . ($bb['lastName'] ?? ''))) ?></td>
<td><?= htmlspecialchars((string)($bb['status'] ?? '')) ?></td>
<td><?= htmlspecialchars((string)($bb['apiReference'] ?? '')) ?></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>

<?php if ($ota !== []): ?>
<h2>iCal fallback — imported busy dates</h2>
<p style="font-size:12.5px;color:#555;margin:0 0 8px">Only used if Beds24 is down (local fallback engine).</p>
<table><tr><th>Source</th><th>Unit</th><th>Type</th><th>From</th><th>To</th></tr>
<?php foreach ($ota as $o): ?>
<tr><td><?= $o['source'] ?></td><td><?= $o['unit_key'] ?></td><td><?= $o['type'] ?></td><td><?= $o['dtstart'] ?></td><td><?= $o['dtend'] ?></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>
