<?php
/**
 * Upper View — transactional emails.
 * Guest confirmation: high-end hotel standard (Nobu-class): confirmation
 * number, stay summary, embedded invoice, property intro, arrival info.
 * Internal notification: plain text to the management list.
 */

require_once __DIR__ . '/lib.php';

function uv_notify_emails(): array {
    $l = uv_notify_list();
    return $l !== [] ? $l
        : array('management@ableab.com', 'booking@upperviewsamui.com', 'upperviewsamui@gmail.com');
}

function uv_mail_html(string $to, string $subject, string $html): void {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "From: Upper View Samui <booking@upperviewsamui.com>\r\n"
             . "Reply-To: booking@upperviewsamui.com";
    @mail($to, $subject, $html, $headers, '-f booking@upperviewsamui.com');
}

function uv_notify_all(string $subject, string $body): void {
    foreach (uv_notify_emails() as $to) {
        @mail($to, $subject, $body,
              "From: Upper View Samui <booking@upperviewsamui.com>\r\nReply-To: booking@upperviewsamui.com",
              '-f booking@upperviewsamui.com');
    }
}

function uv_fmt_thb(int $n): string { return '฿' . number_format($n); }

function uv_fmt_date_long(string $d): string { return date('l, F j, Y', strtotime($d)); }

/** Nobu-standard confirmation email with embedded invoice. */
function uv_confirmation_email_html(array $b): string {
    $cfg   = uv_config();
    $type  = uv_type_name($b['type']);
    $roomsN = max(1, (int)($b['rooms'] ?? 1));
    if ($roomsN > 1) $type = $roomsN . ' × ' . $type;
    $nights = (int)((strtotime($b['checkout']) - strtotime($b['checkin'])) / 86400);
    $total  = (int) $b['amount'];
    $nightly = $nights > 0 ? (int) round($total / $nights) : $total;
    $code  = htmlspecialchars($b['code']);
    $name  = htmlspecialchars($b['name'] ?: 'Guest');
    $ci    = uv_fmt_date_long($b['checkin']);
    $co    = uv_fmt_date_long($b['checkout']);
    // Site palette + typography (index.html): green #4F6446, cream #F7F0E9,
    // Jost for display/labels, Archivo for body. Email-safe fallbacks included.
    $green = '#4F6446'; $cream = '#F7F0E9'; $ink = '#2b2b26';
    $muted = '#8a8376';                      // muted label tone on light panels
    $fcream70 = '#C4C6B8'; $fcream60 = '#B4B8A8'; // cream at .7/.6 over green (site footer)
    $disp = "'Jost','Archivo','Helvetica Neue',Helvetica,Arial,sans-serif";
    $body = "'Archivo','Jost','Helvetica Neue',Helvetica,Arial,sans-serif";
    $base = 'https://upperviewsamui.com';

    // Promo: the room line shows the gross (pre-code) rate; the code gets its own line.
    $gross = (int)($b['gross'] ?? 0); if ($gross <= 0) $gross = $total;
    $disc = (int)($b['discount'] ?? 0);
    $grossNightly = $nights > 0 ? (int) round($gross / $nights) : $gross;
    $rows = '';
    $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e4dcd2;">'
        . $type . ' &middot; ' . $nights . ' night' . ($nights > 1 ? 's' : '')
        . '<br><span style="color:' . $muted . ';font-size:12px;">' . uv_fmt_thb($grossNightly)
        . ' per night &times; ' . $nights . '</span></td>'
        . '<td align="right" style="padding:10px 0;border-bottom:1px solid #e4dcd2;">'
        . uv_fmt_thb($gross) . '</td></tr>';
    if (!empty($b['promo_code'])) {
        $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e4dcd2;">Promo code <b>'
            . htmlspecialchars((string)$b['promo_code']) . '</b><br><span style="color:' . $muted . ';font-size:12px;">'
            . htmlspecialchars((string)$b['promo_note']) . '</span></td>'
            . '<td align="right" style="padding:10px 0;border-bottom:1px solid #e4dcd2;">'
            . ($disc ? '&minus;' . uv_fmt_thb($disc) : 'Included') . '</td></tr>';
    }
    $rows .= '<tr><td style="padding:10px 0;color:' . $muted . ';font-size:12px;">Taxes and fees</td>'
        . '<td align="right" style="padding:10px 0;color:' . $muted . ';font-size:12px;">Included</td></tr>';
    $rows .= '<tr><td style="padding:14px 0;border-top:2px solid ' . $green . ';font-weight:600;">Total paid</td>'
        . '<td align="right" style="padding:14px 0;border-top:2px solid ' . $green . ';font-weight:600;">'
        . uv_fmt_thb($total) . ' THB</td></tr>';

    $label = 'font-family:' . $disp . ';font-size:11px;letter-spacing:.3em;text-transform:uppercase;color:' . $green . ';';

    return '<!doctype html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500&family=Archivo:wght@400;500;600&display=swap" rel="stylesheet">
</head><body style="margin:0;padding:0;background:' . $green . ';">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $green . ';padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0"
  style="max-width:600px;width:100%;background:#ffffff;font-family:' . $body . ';color:' . $ink . ';">
  <tr><td style="background:' . $green . ';padding:30px 40px 26px;text-align:center;">
    <img src="' . $base . '/brand/logo-stack-cream.png" alt="Upper View &mdash; Boutique Hotel, Koh Samui"
      width="150" style="width:150px;height:auto;display:inline-block;border:0;">
  </td></tr>
  <tr><td style="padding:40px 40px 10px;text-align:center;">
    <div style="' . $label . '">Reservation confirmed</div>
    <div style="font-family:' . $disp . ';font-weight:400;font-size:25px;letter-spacing:.02em;margin-top:12px;">We look forward to welcoming you, ' . $name . '</div>
    <div style="' . $label . 'font-size:10px;margin-top:16px;">Confirmation number</div>
    <div style="font-family:' . $disp . ';font-size:20px;letter-spacing:.2em;margin-top:4px;">' . $code . '</div>
  </td></tr>
  <tr><td style="padding:26px 40px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;font-family:' . $body . ';">
      <tr>
        <td width="50%" style="padding:14px;background:' . $cream . ';">
          <div style="' . $label . 'font-size:10px;letter-spacing:.25em;">Check-in</div>
          <div style="margin-top:6px;">' . $ci . '</div>
          <div style="color:' . $muted . ';font-size:12px;">from 3:00 PM</div>
        </td>
        <td width="4"></td>
        <td width="50%" style="padding:14px;background:' . $cream . ';">
          <div style="' . $label . 'font-size:10px;letter-spacing:.25em;">Check-out</div>
          <div style="margin-top:6px;">' . $co . '</div>
          <div style="color:' . $muted . ';font-size:12px;">by 12:00 PM</div>
        </td>
      </tr>
    </table>
    <div style="font-size:14px;margin-top:16px;">' . $type . ' &middot; '
        . (int) $b['guests'] . ' guest' . ((int)$b['guests'] > 1 ? 's' : '')
        . (!empty($b['cot']) ? ' &middot; baby cot (complimentary)' : '') . '</div>
  </td></tr>
  <tr><td style="padding:30px 40px 0;">
    <div style="' . $label . 'border-bottom:2px solid ' . $green . ';padding-bottom:8px;">Your invoice</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;font-family:' . $body . ';">' . $rows . '</table>
    <div style="font-size:11px;color:' . $muted . ';">Charged to your card as <b>Upper View Samui</b>. All prices include taxes and fees.</div>
  </td></tr>
  <tr><td style="padding:30px 40px 0;">
    <div style="' . $label . 'border-bottom:1px solid #e4dcd2;padding-bottom:8px;">Your stay</div>
    <p style="font-size:14px;line-height:1.7;">A hillside boutique resort above Bophut with panoramic views across
    the Gulf toward Koh Phangan — an infinity-edge lap pool, a clubhouse lobby lounge,
    a strength-and-cardio gym and a peaceful north-coast setting.</p>
  </td></tr>
  <tr><td style="padding:16px 40px 34px;">
    <div style="' . $label . 'border-bottom:1px solid #e4dcd2;padding-bottom:8px;">Getting here</div>
    <p style="font-size:14px;line-height:1.7;">161/40 Moo 1, Bophut Hills Soi Pho Tha 1, Bo Phut,<br>
    Koh Samui, Surat Thani 84320, Thailand<br>
    15 minutes from Samui International Airport (USM).</p>
    <p style="font-size:13px;line-height:1.7;color:' . $muted . ';">Questions or special requests? Reply to this email or write to
    <a href="mailto:booking@upperviewsamui.com" style="color:' . $green . ';">booking@upperviewsamui.com</a>
    with your confirmation number.</p>
    <p style="font-size:12px;line-height:1.6;color:' . $muted . ';"><b style="color:' . $ink . ';">Cancellation policy:</b>
    free cancellation until 30 days before check-in; 50% refund until 7 days before check-in;
    non-refundable within 7 days of check-in or in case of no-show. Bookings made more than 7 days
    before check-in may be cancelled free within 24 hours of booking.
    <a href="' . $base . '/policies.html" style="color:' . $green . ';">Full cancellation &amp; refund policy</a>.</p>
  </td></tr>
  <tr><td style="background:' . $green . ';border-top:1px solid #68795f;padding:44px 40px 40px;text-align:center;">
    <img src="' . $base . '/brand/logo-lockup-cream.png" alt="Upper View &mdash; Boutique Hotel, Koh Samui"
      width="170" style="width:170px;height:auto;display:inline-block;border:0;opacity:.95;">
    <div style="margin-top:18px;"><a href="mailto:booking@upperviewsamui.com"
      style="font-family:' . $body . ';color:' . $fcream70 . ';font-size:13px;letter-spacing:.06em;text-decoration:none;">booking@upperviewsamui.com</a></div>
    <div style="margin-top:12px;font-family:' . $body . ';color:' . $fcream60 . ';font-size:11px;letter-spacing:.14em;">&copy; 2026 Upper View &middot; upperviewsamui.com</div>
  </td></tr>
</table>
</td></tr></table></body></html>';
}

/** Send guest confirmation + internal copies for a paid booking row. */
function uv_send_confirmation(array $b, string $provider = 'card'): void {
    $html = uv_confirmation_email_html($b);
    uv_mail_html((string) $b['email'],
        'Upper View Samui — reservation confirmed (' . $b['code'] . ')', $html);
    $nights = (int)((strtotime($b['checkout']) - strtotime($b['checkin'])) / 86400);
    uv_notify_all('Upper View: PAID booking ' . $b['code'],
        ((int)($b['rooms'] ?? 1) > 1 ? (int)$b['rooms'] . ' × ' : '') . uv_type_name($b['type']) . " | {$b['checkin']} -> {$b['checkout']} ($nights nights)"
        . " | {$b['guests']} guests" . (!empty($b['cot']) ? ' + baby cot' : '')
        . " | {$b['name']} | {$b['email']} | {$b['phone']}"
        . ' | ' . uv_fmt_thb((int) $b['amount']) . " THB PAID via $provider | code {$b['code']}"
        . (!empty($b['promo_code']) ? "\nPROMO CODE: {$b['promo_code']} — {$b['promo_note']}"
            . (!empty($b['discount']) ? ' (−' . uv_fmt_thb((int)$b['discount']) . ' THB, already off the total)' : '') : '')
        . "\n\nWritten to Beds24 — OTA calendars closed automatically.");
}
