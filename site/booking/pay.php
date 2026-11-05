<?php
/**
 * Upper View checkout — mirrors the Nobu reservation checkout:
 * contact info -> address -> payment -> policies -> acknowledgement ->
 * CONFIRM BOOKING, with a Price Details invoice panel. Mobile-first.
 * Card entry is the embedded Airwallex Drop-in (PCI handled by Airwallex).
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/beds24.php';
require_once __DIR__ . '/airwallex.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/emails.php';

$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$sig  = isset($_GET['sig'])  ? (string) $_GET['sig']  : '';
if ($code === '' || !hash_equals(uv_pay_sig($code), $sig)) {
    http_response_code(403);
    exit('Invalid link');
}

$db = uv_db();
$st = $db->prepare('SELECT * FROM bookings WHERE code = ?');
$st->execute(array($code));
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b) { http_response_code(404); exit('Booking not found'); }

/* ---- AJAX: save guest details, return payment intent ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) { http_response_code(400); echo '{"error":"bad body"}'; exit; }
    $first = trim((string)($in['first'] ?? ''));
    $last  = trim((string)($in['last'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
        http_response_code(422); echo '{"error":"missing"}'; exit;
    }
    $db->prepare('UPDATE bookings SET name=?, email=?, phone=?, updated_at=? WHERE code=?')
       ->execute(array($first . ' ' . $last, $email, $phone, gmdate('Y-m-d H:i:s'), $code));
    uv_meta_set('aw_guest_' . $code, json_encode(array(
        'country' => (string)($in['country'] ?? ''),
        'address' => (string)($in['address'] ?? ''),
        'city'    => (string)($in['city'] ?? ''),
        'zip'     => (string)($in['zip'] ?? ''),
        'news'    => !empty($in['news']),
    )));
    $b['email'] = $email;
    if (uv_pay_provider() === 'stripe') {
        $sess = st_checkout_for($b);
        if (!$sess) { http_response_code(502); echo '{"error":"payment unavailable"}'; exit; }
        echo json_encode(array('checkout_url' => $sess['url']));
        exit;
    }
    $intent = aw_enabled() ? aw_intent_for($b) : null;
    if (!$intent) { http_response_code(502); echo '{"error":"payment unavailable"}'; exit; }
    echo json_encode(array(
        'intent_id' => $intent['intent_id'],
        'client_secret' => $intent['client_secret'],
        'currency' => strtoupper($b['currency']),
    ));
    exit;
}

/* ---- return / confirmation state ---- */
$confirmed = ($b['status'] === 'confirmed');
if (!$confirmed && isset($_GET['done'])) {
    if (st_booking_paid($code)) {
        $confirmed = true; // webhook finalises Beds24 + emails
    } else {
        $raw = uv_meta_get('aw_intent_' . $code);
        $stI = $raw ? json_decode($raw, true) : null;
        if (is_array($stI) && !empty($stI['intent_id'])) {
            $live = aw_intent_status($stI['intent_id']);
            if (is_array($live) && isset($live['status']) && $live['status'] === 'SUCCEEDED') {
                $confirmed = true; // webhook finalises Beds24 + emails
            }
        }
    }
}

$cfg    = uv_config();
$type   = uv_type_name($b['type']);
$nights = (int)((strtotime($b['checkout']) - strtotime($b['checkin'])) / 86400);
$total  = (int) $b['amount'];
$nightly = $nights > 0 ? (int) round($total / $nights) : $total;
$ciL = date('D, M j, Y', strtotime($b['checkin']));
$coL = date('D, M j, Y', strtotime($b['checkout']));
$cancelBy = date('M j, Y', strtotime($b['checkin'] . ' -1 day'));
$roomImgs = array(
    'villa'    => 'upper-view-106.jpg',
    'superior' => 'upper-view-043.jpg',
    'deluxe'   => 'upper-view-038.jpg',
    'king'     => 'upper-view-060.jpg',
);
$img = '/photos/' . (isset($roomImgs[$b['type']]) ? $roomImgs[$b['type']] : 'upper-view-106.jpg');
$awEnv = (aw_cfg() && ($awc = aw_cfg()) && isset($awc['env']) && $awc['env'] === 'demo') ? 'demo' : 'prod';
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Checkout · Upper View Samui</title>
<script src="https://static.airwallex.com/components/sdk/v1/index.js"></script>
<style>
:root{--green:#3D4C36;--cream:#F7F0E9;--ink:#2b2b26;--mut:#8a8376;--line:#e4dcd2}
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:var(--ink);font-family:Georgia,'Times New Roman',serif;-webkit-font-smoothing:antialiased}
.top{background:var(--green);text-align:center;padding:22px 16px}
.top .wm{color:var(--cream);letter-spacing:.35em;font-size:17px;text-decoration:none}
.top .sub{color:#b9c0ae;font-size:9px;letter-spacing:.3em;margin-top:4px}
.wrap{max-width:980px;margin:0 auto;padding:0 16px 60px}
.hd{display:flex;align-items:center;gap:12px;padding:22px 0 6px}
.hd a{color:var(--ink);text-decoration:none;font-size:20px}
.hd h1{font-size:19px;letter-spacing:.25em;font-weight:normal}
.cols{display:block}
@media(min-width:880px){.cols{display:grid;grid-template-columns:1fr 360px;gap:36px;align-items:start}}
.sec{border:1px solid var(--line);padding:20px;margin-top:18px}
.sec h2{font-size:12px;letter-spacing:.28em;font-weight:normal;color:var(--mut);margin-bottom:16px;text-transform:uppercase}
.req{color:#a33;font-size:11px;float:right}
.grid2{display:grid;grid-template-columns:1fr;gap:12px}
@media(min-width:560px){.grid2{grid-template-columns:1fr 1fr}}
label{display:block;font-size:11px;letter-spacing:.12em;color:var(--mut);margin-bottom:5px}
input[type=text],input[type=email],input[type=tel],select{width:100%;padding:13px 12px;border:1px solid #c9c0b4;background:#fff;font:inherit;font-size:15px;border-radius:0}
input:focus,select:focus{outline:1px solid var(--green)}
.note{font-size:11.5px;color:var(--mut);margin-top:6px}
.sumcard{border:1px solid var(--line);margin-top:18px}
.sumcard img{width:100%;height:190px;object-fit:cover;display:block}
.sumcard .pad{padding:14px 16px}
.sumcard b{font-size:15px}
.sumcard .mut{color:var(--mut);font-size:13px;margin-top:4px}
.price{border:1px solid var(--line);margin-top:18px}
.price h2{font-size:12px;letter-spacing:.28em;color:var(--mut);font-weight:normal;padding:16px 16px 0;text-transform:uppercase}
.price .rows{padding:8px 16px 16px;font-size:14px}
.price .r{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--line)}
.price .r .l small{display:block;color:var(--mut);font-size:12px;margin-top:3px}
.price .tot{display:flex;justify-content:space-between;padding:13px 0 0;font-weight:bold;border-top:2px solid var(--green);margin-top:8px}
.price .inc{color:var(--mut);font-size:11.5px;margin-top:4px}
.pol{background:var(--cream);padding:14px 16px;font-size:12.5px;line-height:1.65;margin-top:14px}
.pol b{letter-spacing:.08em}
.ack label{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--ink);letter-spacing:0;margin:10px 0;cursor:pointer}
.ack input{margin-top:3px}
.cta{display:block;width:100%;background:var(--green);color:var(--cream);border:none;padding:18px;font-size:12px;letter-spacing:.3em;cursor:pointer;margin-top:18px;font-family:inherit;text-transform:uppercase}
.cta[disabled]{opacity:.45;cursor:default}
.err{color:#a33;font-size:13px;margin-top:10px;display:none}
#dropIn{margin-top:8px;min-height:60px}
.secure{font-size:11.5px;color:var(--mut);margin-bottom:10px}
.ok{ text-align:center;padding:60px 18px}
.ok .big{font-size:24px;margin-top:14px}
.ok .code{font-size:19px;letter-spacing:.2em;margin-top:8px}
.ok .mut{color:var(--mut);margin-top:12px;font-size:14px;line-height:1.7}
.steps{display:none}
.on{display:block}
</style>
</head>
<body>
<div class="top">
  <a class="wm" href="/">UPPER VIEW</a>
  <div class="sub">KOH SAMUI</div>
</div>
<div class="wrap">
<?php if ($confirmed): ?>
  <div class="ok">
    <div style="font-size:11px;letter-spacing:.3em;color:var(--mut)">RESERVATION CONFIRMED</div>
    <div class="big">We look forward to welcoming you.</div>
    <div style="font-size:11px;letter-spacing:.2em;color:var(--mut);margin-top:22px">CONFIRMATION NUMBER</div>
    <div class="code"><?php echo h($code); ?></div>
    <div class="mut"><?php echo h($type); ?> · <?php echo h($ciL); ?> → <?php echo h($coL); ?> · <?php echo (int) $b['guests']; ?> guests<br>
    A confirmation with your invoice is on its way to your email.</div>
    <a class="cta" style="max-width:340px;margin:30px auto 0;text-decoration:none" href="/">RETURN TO UPPER VIEW</a>
  </div>
<?php else: ?>
  <div class="hd"><a href="/#top" aria-label="Back">←</a><h1>CHECKOUT</h1></div>
  <div class="cols">
    <div>
      <form id="guest" autocomplete="on" novalidate>
        <div class="sec">
          <h2>Contact Info <span class="req">* Required</span></h2>
          <div class="grid2">
            <div><label for="f_first">First Name *</label><input id="f_first" type="text" autocomplete="given-name" required></div>
            <div><label for="f_last">Last Name *</label><input id="f_last" type="text" autocomplete="family-name" required></div>
            <div><label for="f_phone">Phone *</label><input id="f_phone" type="tel" autocomplete="tel" required></div>
            <div><label for="f_email">Email Address *</label><input id="f_email" type="email" autocomplete="email" required>
              <div class="note">This is the email we will send your confirmation to.</div></div>
          </div>
        </div>
        <div class="sec">
          <h2>Address</h2>
          <div class="grid2">
            <div><label for="f_country">Country *</label><input id="f_country" type="text" autocomplete="country-name" required></div>
            <div><label for="f_address">Address 1 *</label><input id="f_address" type="text" autocomplete="address-line1" required></div>
            <div><label for="f_city">City *</label><input id="f_city" type="text" autocomplete="address-level2" required></div>
            <div><label for="f_zip">Zip / Postal Code *</label><input id="f_zip" type="text" autocomplete="postal-code" required></div>
          </div>
        </div>
        <div class="sec">
          <h2>Policies</h2>
          <div class="pol">
            <b>Check-in</b> after 3:00 PM &nbsp;·&nbsp; <b>Check-out</b> before 12:00 PM<br><br>
            <b><?php echo strtoupper(h($type)); ?></b><br>
            Reservation confirmation requires a valid credit card. Cancel for free until 3:00 PM (hotel local time)
            on <?php echo h($cancelBy); ?> — one day before arrival. Cancellations after that time are charged one
            night (<?php echo uv_fmt_thb($nightly); ?>).
          </div>
        </div>
        <div class="sec ack">
          <h2>Acknowledgement</h2>
          <label><input type="checkbox" id="f_news"> Yes, I would like to receive news and special offers by email.</label>
          <label><input type="checkbox" id="f_priv"> * I agree with the Privacy Terms.</label>
          <label><input type="checkbox" id="f_cond"> * I agree with the Booking Conditions.</label>
        </div>
        <button class="cta" id="toPay" type="submit">CONTINUE TO PAYMENT</button>
        <div class="err" id="err1">Please complete all required fields and acknowledgements.</div>
      </form>
      <div class="sec steps" id="paySec">
        <h2>Payment</h2>
        <div class="secure">We use secure transmission and encrypted storage to protect your personal information.
        Your card statement will show <b>Upper View Samui</b>.</div>
        <div id="dropIn"></div>
        <div class="err" id="err2"></div>
      </div>
    </div>
    <div>
      <div class="sumcard">
        <img src="<?php echo h($img); ?>" alt="<?php echo h($type); ?>">
        <div class="pad">
          <b><?php echo h($type); ?></b>
          <div class="mut">Direct Rate · Room Only</div>
          <div class="mut"><?php echo h($ciL); ?> – <?php echo h($coL); ?> · <?php echo $nights; ?> night<?php echo $nights > 1 ? 's' : ''; ?> stay</div>
          <div class="mut"><?php echo (int) $b['guests']; ?> Adults</div>
        </div>
      </div>
      <div class="price">
        <h2>Price Details</h2>
        <div class="rows">
          <div class="r">
            <div class="l"><?php echo h($type); ?><small><?php echo uv_fmt_thb($nightly); ?> per night × <?php echo $nights; ?></small></div>
            <div><?php echo uv_fmt_thb($total); ?></div>
          </div>
          <div class="r"><div class="l" style="color:var(--mut);font-size:12.5px">Taxes and fees</div>
            <div style="color:var(--mut);font-size:12.5px">Included</div></div>
          <div class="tot"><div>Total</div><div><?php echo uv_fmt_thb($total); ?> THB</div></div>
          <div class="inc">Including taxes and fees</div>
        </div>
      </div>
    </div>
  </div>
<script>
(function(){
  var payUrl = location.pathname + location.search.replace(/&(done|ret)=1/g,'');
  var started = false;
  document.getElementById('guest').addEventListener('submit', function(e){
    e.preventDefault();
    if (started) return;
    var v = function(id){ return document.getElementById(id).value.trim(); };
    var ok = v('f_first') && v('f_last') && v('f_phone') &&
             /.+@.+\..+/.test(v('f_email')) && v('f_country') && v('f_address') &&
             v('f_city') && v('f_zip') &&
             document.getElementById('f_priv').checked &&
             document.getElementById('f_cond').checked;
    var e1 = document.getElementById('err1');
    if (!ok) { e1.style.display = 'block'; return; }
    e1.style.display = 'none';
    started = true;
    var btn = document.getElementById('toPay');
    btn.disabled = true; btn.textContent = 'PREPARING SECURE PAYMENT…';
    fetch(payUrl, { method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ first: v('f_first'), last: v('f_last'), phone: v('f_phone'),
        email: v('f_email'), country: v('f_country'), address: v('f_address'),
        city: v('f_city'), zip: v('f_zip'), news: document.getElementById('f_news').checked })
    }).then(function(r){ if(!r.ok) throw 0; return r.json(); })
    .then(function(d){
      if (d.checkout_url) { location.href = d.checkout_url; return; }
      document.getElementById('paySec').classList.add('on');
      btn.style.display = 'none';
      return window.AirwallexComponentsSDK.init({ env: '<?php echo $awEnv; ?>', enabledElements: ['payments'] })
        .then(function(){
          return window.AirwallexComponentsSDK.createElement('dropIn', {
            intent_id: d.intent_id, client_secret: d.client_secret, currency: d.currency });
        })
        .then(function(el){
          el.mount('dropIn');
          el.on('success', function(){ location.href = payUrl + '&done=1'; });
          el.on('error', function(ev){
            var e2 = document.getElementById('err2');
            e2.textContent = 'Payment could not be completed. Please try again or use another card.';
            e2.style.display = 'block';
          });
          document.getElementById('paySec').scrollIntoView({behavior:'smooth'});
        });
    })
    .catch(function(){
      started = false; btn.disabled = false; btn.textContent = 'CONTINUE TO PAYMENT';
      var e1b = document.getElementById('err1');
      e1b.textContent = 'We could not start the payment. Please try again.';
      e1b.style.display = 'block';
    });
  });
})();
</script>
<?php endif; ?>
</div>
</body>
</html>
