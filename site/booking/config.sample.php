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

    // Payment (adapter seam). Active provider: 'stripe' or 'airwallex'.
    // May also live in the meta table ('payment_provider') — set from admin.php.
    'payment_provider' => 'stripe',
    // Keys are created in Andy's own browser session and pasted either here on
    // the server directly or into admin.php's Payments form — never through chat.
    'stripe' => [
        'secret_key' => '',        // sk_live_... / rk_live_... (or meta 'stripe_secret_key')
    ],
    'airwallex' => [
        'client_id' => '', 'api_key' => '', 'webhook_secret' => '', 'env' => 'prod',
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
