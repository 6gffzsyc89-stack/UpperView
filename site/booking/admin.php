<?php
/** Minimal admin: list bookings, block dates, cancel. Key-protected. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = uv_config();
$k2 = (string) uv_meta_get('admin_key2');
$kin = (string) ($_GET['k'] ?? '');
if (!hash_equals($cfg['admin_key'], $kin) && ($k2 === '' || !hash_equals($k2, $kin))) {
    http_response_code(403); exit('forbidden');
}
$k = htmlspecialchars($kin);
$db = uv_db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'block' && uv_valid_date($_POST['ci'] ?? null) && uv_valid_date($_POST['co'] ?? null)
        && uv_valid_type($_POST['type'] ?? '') && $_POST['co'] > $_POST['ci']) {
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare("INSERT INTO bookings (code,type,checkin,checkout,guests,name,status,source,currency,created_at,updated_at)
                      VALUES (?,?,?,?,0,?,'blocked','admin',?,?,?)")
           ->execute([uv_booking_code(), $_POST['type'], $_POST['ci'], $_POST['co'],
                      $_POST['note'] ?? 'manual block', $cfg['currency'], $now, $now]);
        $msg = 'Dates blocked.';
    }
    if ($act === 'cancel' && !empty($_POST['code'])) {
        $db->prepare("UPDATE bookings SET status='cancelled', updated_at=datetime('now') WHERE code=?")
           ->execute([$_POST['code']]);
        $msg = 'Cancelled ' . htmlspecialchars($_POST['code']) . '.';
    }
    if ($act === 'confirm' && !empty($_POST['code'])) {
        $db->prepare("UPDATE bookings SET status='confirmed', updated_at=datetime('now') WHERE code=?")
           ->execute([$_POST['code']]);
        $msg = 'Confirmed ' . htmlspecialchars($_POST['code']) . '.';
    }
    if ($act === 'refresh') { uv_refresh_ota(true); $msg = 'OTA calendars refreshed.'; }
    if ($act === 'stripekey') {
        $sk = trim((string)($_POST['sk'] ?? ''));
        if (preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', $sk)) {
            uv_meta_set('stripe_secret_key', $sk);
            uv_meta_set('payment_provider', 'stripe');
            $msg = 'Stripe key saved (…' . htmlspecialchars(substr($sk, -4)) . '). Payment provider set to Stripe.';
        } else {
            $msg = 'That does not look like a Stripe secret/restricted key (sk_… or rk_…). Nothing saved.';
        }
    }
    if ($act === 'provider' && in_array($_POST['provider'] ?? '', ['stripe', 'airwallex'], true)) {
        uv_meta_set('payment_provider', $_POST['provider']);
        $msg = 'Payment provider set to ' . htmlspecialchars($_POST['provider']) . '.';
    }
}

$rows = $db->query("SELECT * FROM bookings WHERE status != 'cancelled'
                    ORDER BY checkin ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$ota = $db->query("SELECT source, unit_key, type, dtstart, dtend FROM ota_busy
                   WHERE dtend >= date('now') ORDER BY dtstart LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$fetched = uv_meta_get('ota_fetched_at');
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
button{background:#4F6446;color:#fff;border:none;cursor:pointer}.msg{background:#e7f0e7;padding:8px 12px;margin:0 0 16px}
</style>
<h1>Upper View — bookings</h1>
<?php if ($msg): ?><p class="msg"><?= $msg ?></p><?php endif; ?>
<p>OTA calendars last pulled: <?= $fetched ? gmdate('Y-m-d H:i', (int)$fetched) . ' UTC' : 'never' ?>
<form class="inline" method="post"><input type="hidden" name="act" value="refresh"><button>Pull now</button></form></p>

<h2>Bookings &amp; blocks</h2>
<table><tr><th>Code</th><th>Type</th><th>Check-in</th><th>Check-out</th><th>Guests</th><th>Name</th><th>Contact</th><th>Status</th><th>Amount</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= htmlspecialchars($r['code']) ?></td><td><?= $r['type'] ?></td>
<td><?= $r['checkin'] ?></td><td><?= $r['checkout'] ?></td><td><?= $r['guests'] ?></td>
<td><?= htmlspecialchars((string)$r['name']) ?></td>
<td><?= htmlspecialchars((string)$r['email']) ?> <?= htmlspecialchars((string)$r['phone']) ?></td>
<td class="s-<?= $r['status'] ?>"><?= $r['status'] ?></td>
<td><?= $r['amount'] ? number_format((float)$r['amount']) . ' ' . $r['currency'] : '—' ?></td>
<td>
<?php if ($r['status'] === 'inquiry'): ?>
<form class="inline" method="post"><input type="hidden" name="act" value="confirm"><input type="hidden" name="code" value="<?= $r['code'] ?>"><button>Confirm</button></form>
<?php endif; ?>
<form class="inline" method="post" onsubmit="return confirm('Cancel <?= $r['code'] ?>?')"><input type="hidden" name="act" value="cancel"><input type="hidden" name="code" value="<?= $r['code'] ?>"><button style="background:#a33">Cancel</button></form>
</td></tr>
<?php endforeach; ?></table>

<h2>Payments</h2>
<?php $pskSet = uv_meta_get('stripe_secret_key'); $pprov = uv_meta_get('payment_provider'); ?>
<p>Active provider: <b><?= htmlspecialchars($pprov ?: 'auto') ?></b> ·
Stripe key: <b><?= $pskSet ? 'saved (…' . htmlspecialchars(substr($pskSet, -4)) . ')' : 'NOT SET' ?></b></p>
<form method="post"><input type="hidden" name="act" value="stripekey">
<input name="sk" type="password" placeholder="Paste Stripe secret key (sk_live_… or rk_live_…)" style="width:380px" autocomplete="off">
<button>Save Stripe key</button></form>
<form class="inline" method="post"><input type="hidden" name="act" value="provider"><input type="hidden" name="provider" value="stripe"><button>Use Stripe</button></form>
<form class="inline" method="post"><input type="hidden" name="act" value="provider"><input type="hidden" name="provider" value="airwallex"><button>Use Airwallex</button></form>

<h2>Block dates (maintenance, phone bookings…)</h2>
<form method="post"><input type="hidden" name="act" value="block">
<select name="type"><option value="villa">Mini Villa (1 of 8)</option><option value="superior">Superior Suite</option><option value="deluxe">Deluxe Suite</option><option value="king">King Suite</option></select>
<input type="date" name="ci" required> → <input type="date" name="co" required>
<input name="note" placeholder="note"><button>Block</button></form>

<h2>OTA busy dates (imported)</h2>
<table><tr><th>Source</th><th>Unit</th><th>Type</th><th>From</th><th>To</th></tr>
<?php foreach ($ota as $o): ?>
<tr><td><?= $o['source'] ?></td><td><?= $o['unit_key'] ?></td><td><?= $o['type'] ?></td><td><?= $o['dtstart'] ?></td><td><?= $o['dtend'] ?></td></tr>
<?php endforeach; ?></table>
