<?php
/**
 * Upper View — transactional emails.
 * Guest confirmation: high-end hotel standard (Nobu-class): confirmation
 * number, stay summary, embedded invoice, property intro, arrival info.
 * Internal notification: plain text to the management list.
 */

require_once __DIR__ . '/lib.php';

function uv_notify_emails(): array {
    $cfg = uv_config();
    if (!empty($cfg['notify_emails']) && is_array($cfg['notify_emails'])) return $cfg['notify_emails'];
    return array('management@ableab.com', 'bookings@upperviewsamui.com', 'upperviewsamui@gmail.com');
}

function uv_mail_html(string $to, string $subject, string $html): void {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "From: Upper View Samui <bookings@upperviewsamui.com>\r\n"
             . "Reply-To: bookings@upperviewsamui.com";
    @mail($to, $subject, $html, $headers);
}

function uv_notify_all(string $subject, string $body): void {
    foreach (uv_notify_emails() as $to) {
        @mail($to, $subject, $body, 'From: bookings@upperviewsamui.com');
    }
}

function uv_fmt_thb(int $n): string { return '฿' . number_format($n); }

function uv_fmt_date_long(string $d): string { return date('l, F j, Y', strtotime($d)); }

/** Nobu-standard confirmation email with embedded invoice. */
function uv_confirmation_email_html(array $b): string {
    $cfg   = uv_config();
    $type  = uv_type_name($b['type']);
    $nights = (int)((strtotime($b['checkout']) - strtotime($b['checkin'])) / 86400);
    $total  = (int) $b['amount'];
    $nightly = $nights > 0 ? (int) round($total / $nights) : $total;
    $code  = htmlspecialchars($b['code']);
    $name  = htmlspecialchars($b['name'] ?: 'Guest');
    $ci    = uv_fmt_date_long($b['checkin']);
    $co    = uv_fmt_date_long($b['checkout']);
    $green = '#3D4C36'; $cream = '#F7F0E9'; $ink = '#2b2b26';

    $rows = '';
    $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e4dcd2;">'
        . $type . ' &middot; ' . $nights . ' night' . ($nights > 1 ? 's' : '')
        . '<br><span style="color:#8a8376;font-size:12px;">' . uv_fmt_thb($nightly)
        . ' per night &times; ' . $nights . '</span></td>'
        . '<td align="right" style="padding:10px 0;border-bottom:1px solid #e4dcd2;">'
        . uv_fmt_thb($total) . '</td></tr>';
    $rows .= '<tr><td style="padding:10px 0;color:#8a8376;font-size:12px;">Taxes and fees</td>'
        . '<td align="right" style="padding:10px 0;color:#8a8376;font-size:12px;">Included</td></tr>';
    $rows .= '<tr><td style="padding:14px 0;border-top:2px solid ' . $green . ';font-weight:bold;">Total paid</td>'
        . '<td align="right" style="padding:14px 0;border-top:2px solid ' . $green . ';font-weight:bold;">'
        . uv_fmt_thb($total) . ' THB</td></tr>';

    return '<!doctype html><html><body style="margin:0;padding:0;background:' . $cream . ';">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream . ';padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0"
  style="max-width:600px;width:100%;background:#ffffff;font-family:Georgia,\'Times New Roman\',serif;color:' . $ink . ';">
  <tr><td style="background:' . $green . ';padding:34px 40px;text-align:center;">
    <div style="color:' . $cream . ';font-size:22px;letter-spacing:.35em;">UPPER VIEW</div>
    <div style="color:#b9c0ae;font-size:10px;letter-spacing:.3em;margin-top:6px;">KOH SAMUI</div>
  </td></tr>
  <tr><td style="padding:40px 40px 10px;text-align:center;">
    <div style="font-size:11px;letter-spacing:.3em;color:#8a8376;">RESERVATION CONFIRMED</div>
    <div style="font-size:26px;margin-top:12px;">We look forward to welcoming you, ' . $name . '</div>
    <div style="font-size:12px;color:#8a8376;margin-top:14px;letter-spacing:.1em;">CONFIRMATION NUMBER</div>
    <div style="font-size:20px;letter-spacing:.2em;margin-top:4px;">' . $code . '</div>
  </td></tr>
  <tr><td style="padding:26px 40px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
      <tr>
        <td width="50%" style="padding:14px;background:' . $cream . ';">
          <div style="font-size:10px;letter-spacing:.25em;color:#8a8376;">CHECK-IN</div>
          <div style="margin-top:6px;">' . $ci . '</div>
          <div style="color:#8a8376;font-size:12px;">from 3:00 PM</div>
        </td>
        <td width="4"></td>
        <td width="50%" style="padding:14px;background:' . $cream . ';">
          <div style="font-size:10px;letter-spacing:.25em;color:#8a8376;">CHECK-OUT</div>
          <div style="margin-top:6px;">' . $co . '</div>
          <div style="color:#8a8376;font-size:12px;">by 12:00 PM</div>
        </td>
      </tr>
    </table>
    <div style="font-size:14px;margin-top:16px;">' . $type . ' &middot; '
        . (int) $b['guests'] . ' guest' . ((int)$b['guests'] > 1 ? 's' : '') . '</div>
  </td></tr>
  <tr><td style="padding:30px 40px 0;">
    <div style="font-size:11px;letter-spacing:.3em;color:#8a8376;border-bottom:2px solid ' . $green . ';padding-bottom:8px;">YOUR INVOICE</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">' . $rows . '</table>
    <div style="font-size:11px;color:#8a8376;">Charged to your card as <b>Upper View Samui</b>. All prices include taxes and fees.</div>
  </td></tr>
  <tr><td style="padding:30px 40px 0;">
    <div style="font-size:11px;letter-spacing:.3em;color:#8a8376;border-bottom:1px solid #e4dcd2;padding-bottom:8px;">YOUR STAY</div>
    <p style="font-size:14px;line-height:1.7;">A hillside boutique resort above Bophut with panoramic views across
    the Gulf toward Koh Phangan — an infinity-edge lap pool, a clubhouse lobby lounge,
    a strength-and-cardio gym and a peaceful north-coast setting.</p>
  </td></tr>
  <tr><td style="padding:16px 40px 34px;">
    <div style="font-size:11px;letter-spacing:.3em;color:#8a8376;border-bottom:1px solid #e4dcd2;padding-bottom:8px;">GETTING HERE</div>
    <p style="font-size:14px;line-height:1.7;">161/40 Moo 1, Bophut Hills Soi Pho Tha 1, Bo Phut,<br>
    Koh Samui, Surat Thani 84320, Thailand<br>
    15 minutes from Samui International Airport (USM).</p>
    <p style="font-size:13px;line-height:1.7;color:#8a8376;">Questions or special requests? Reply to this email or write to
    <a href="mailto:bookings@upperviewsamui.com" style="color:' . $green . ';">bookings@upperviewsamui.com</a>
    with your confirmation number.</p>
    <p style="font-size:12px;line-height:1.6;color:#8a8376;">Free cancellation until 3:00 PM (hotel time) one day before
    check-in; later cancellations are charged one night.</p>
  </td></tr>
  <tr><td style="background:' . $green . ';padding:18px 40px;text-align:center;color:#b9c0ae;font-size:11px;letter-spacing:.2em;">
    UPPER VIEW SAMUI &middot; UPPERVIEWSAMUI.COM
  </td></tr>
</table>
</td></tr></table></body></html>';
}

/** Send guest confirmation + internal copies for a paid booking row. */
function uv_send_confirmation(array $b): void {
    $html = uv_confirmation_email_html($b);
    uv_mail_html((string) $b['email'],
        'Upper View Samui — reservation confirmed (' . $b['code'] . ')', $html);
    $nights = (int)((strtotime($b['checkout']) - strtotime($b['checkin'])) / 86400);
    uv_notify_all('Upper View: PAID booking ' . $b['code'],
        uv_type_name($b['type']) . " | {$b['checkin']} -> {$b['checkout']} ($nights nights)"
        . " | {$b['guests']} guests | {$b['name']} | {$b['email']} | {$b['phone']}"
        . ' | ' . uv_fmt_thb((int) $b['amount']) . " THB paid via Airwallex | code {$b['code']}");
}
