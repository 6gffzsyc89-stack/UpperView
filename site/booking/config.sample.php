<?php
/**
 * SAMPLE config. The real file lives OUTSIDE the web root:
 *   domains/upperviewsamui.com/data/config.php   (never in git, never in public_html)
 * Copy this file there and fill in the values.
 */
return [
    'site_url' => 'https://upperviewsamui.com',
    'opening_date' => '2026-12-20',
    'notify_email' => 'andy@ableab.com',

    // Interchangeable units per room type.
    'inventory' => ['villa' => 8, 'studio' => 3],

    // Nightly rates in whole THB. NOT live until rates_confirmed is true.
    'currency' => 'THB',
    'rates' => ['villa' => 4900, 'studio' => 3200],   // PLACEHOLDER — Andy must confirm
    'rates_confirmed' => false,

    // Payment (adapter seam: stripe today, airwallex later).
    // Keys are created in Andy's own browser session, pasted here on the server
    // directly — never through chat.
    'stripe' => [
        'secret_key' => '',        // sk_live_... or sk_test_...
        'webhook_secret' => '',    // whsec_... for /booking/webhook.php
    ],

    // OTA iCal imports — one entry per unit per platform. Paste export URLs
    // from Airbnb (calendar > availability > connect calendar) and
    // Booking.com (extranet > calendar > sync).
    // unit: v1..v8 (villas) / s1..s3 (studios)
    'ical_feeds' => [
        // ['unit' => 'v1', 'type' => 'villa', 'source' => 'airbnb',  'url' => 'https://...'],
        // ['unit' => 'v1', 'type' => 'villa', 'source' => 'booking', 'url' => 'https://...'],
    ],
    'ota_refresh_seconds' => 300,

    // Random secrets (generate with: php -r "echo bin2hex(random_bytes(16));")
    'export_key' => 'CHANGE_ME',   // /booking/ical-export.php?type=villa&k=...
    'admin_key'  => 'CHANGE_ME',   // /booking/admin.php?k=...
];
