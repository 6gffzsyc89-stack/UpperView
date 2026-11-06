# Stripe direct-booking playbook (replicate on another hotel site)

Written 2026-11-06 after Stripe went LIVE on upperviewsamui.com, for reuse on
sonatasamui.com (and any future property site). The reference implementation
lives in this repo: `site/booking/`. Verified end-to-end on Upper View:
hold → checkout session (cs_live_…) → hosted Stripe page 200 → webhook
API-verified → Beds24 confirmed booking + OTA calendars closed + emails.

## Architecture (copy this shape)

```
site booking widget (index.html/app.js)
  → api.php?action=book         creates a 'pending' hold with live price
  → pay.php?code=…&sig=…        Nobu-style checkout page (guest details)
      POST → st_checkout_for()  creates/reuses a Stripe Checkout Session
      → redirect to checkout.stripe.com (hosted page; card never touches us)
  → webhook-stripe.php          checkout.session.completed:
      re-fetch session from Stripe API with the secret key (NEVER trust the
      webhook payload), require payment_status=paid
      → booking confirmed → b24_create_booking() (Beds24 = closes OTA
        calendars everywhere) → branded confirmation email + notify list
```

Key files to copy/adapt from this repo (`site/booking/`):
- `stripe.php` — adapter: st_secret(), st_enabled(), uv_pay_provider(),
  st_checkout_for(), st_session_status(), st_booking_paid().
- `webhook-stripe.php` — API-verified webhook (no signing secret needed).
- `pay.php` — checkout page; routes by uv_pay_provider() (stripe/airwallex).
- `admin.php` — Payments section: paste-key form (meta `stripe_secret_key`),
  provider switch; accepts a secondary admin key (meta `admin_key2`).
- `lib.php` — uv_notify_list()/uv_notify() (multi-recipient, envelope
  sender `-f booking@<domain>` for SPF/DKIM alignment), meta table helpers.
- `emails.php` — branded confirmation email + uv_notify_all().

## Decisions that matter (learned the hard way)

1. **Hosted Stripe Checkout, not embedded** — zero card data on our server,
   no PCI scope, works with a RESTRICTED key.
2. **Restricted key, minimum scope** — create in Stripe dashboard:
   Developers → API keys → Create restricted key, name `<site>-website`,
   permission **Checkout Sessions: Write** only. The key NEVER passes
   through chat: the owner pastes it into the admin page Payments form
   (stored in SQLite meta, outside webroot config also works).
3. **Webhook WITHOUT signing secret** — register endpoint
   `https://<domain>/booking/webhook-stripe.php` for
   `checkout.session.completed` (+ `async_payment_succeeded`). The handler
   treats the payload only as a hint: it re-fetches the session from the
   Stripe API and requires `payment_status === 'paid'`. Forged posts do
   nothing.
4. **THB has NO minor-unit trap in practice but code stores satang** —
   bookings store minor units; Stripe THB unit_amount is satang (pass
   through); Airwallex wants MAJOR units (divide by 100). Keep the adapter
   seam: `uv_pay_provider()` returns 'stripe'|'airwallex' from config
   `payment_provider` or meta, so switching provider is one click.
5. **statement_descriptor_suffix** 'UPPER VIEW' (retry without suffix if
   Stripe rejects it for the account/currency).
6. **Unpaid requests NEVER write to the channel manager** — only the
   payment webhook (automatic) or an explicit admin "Confirm + close OTAs"
   click (manual) creates the Beds24 booking that closes OTA calendars.
7. **Beds24 booking carries structured notes** so AI assistants can read:
   `SOURCE: direct website <domain> — ref <code>` /
   `PAYMENT: PAID <amt> THB via Stripe (verified online payment)` or
   `PAYMENT: NOT TAKEN — …` / `OTA CALENDARS: CLOSED automatically …` —
   plus invoice charge+payment rows via a second POST /bookings with
   `{id, invoiceItems:[{type:'charge'…},{type:'payment'…}]}`.
8. **Occupancy cap enforced server-side** (reject, don't clamp) and
   mirrored in the UI dropdown.
9. **Email deliverability**: every `mail()` call needs 5th arg
   `-f booking@<domain>` (envelope sender) + `From:` header on the same
   domain, with the domain's Hostinger SPF/DKIM/DMARC records in place —
   otherwise Gmail junks/drops. Notification recipients come from
   `uv_notify_list()`: meta `notify_emails` (set via admin API) → config
   `notify_emails` → legacy `notify_email`.

## Owner steps (the only manual parts)

1. Stripe dashboard → Developers → Webhooks → Add endpoint:
   `https://<domain>/booking/webhook-stripe.php`, event
   `checkout.session.completed` (+ async_payment_succeeded). No signing
   secret needs storing.
2. Stripe dashboard → API keys → Create restricted key
   (`<site>-website`, Checkout Sessions: Write) → Reveal → paste into
   `https://<domain>/booking/admin.php?k=<admin key>` → Payments →
   Save Stripe key (this also sets provider=stripe).

## Verification checklist (what "works" means — run after wiring)

```bash
# 1. hold routes to pay mode with a live price
curl -s -X POST 'https://<domain>/booking/api.php?action=book' \
  -H 'Content-Type: application/json' \
  -d '{"type":"<type>","checkin":"<future>","checkout":"<future+2>","guests":2}'
# expect {"mode":"pay","code":…,"url":…,"total":…}

# 2. guest details POST returns a cs_live_… checkout_url; curl it → HTTP 200
# 3. webhook endpoint: POST garbage → 400 "bad body" (reachable, rejects junk)
# 4. admin-beds24-status → enabled:true, token stored
# 5. CANCEL the test hold in admin afterwards
```

Full payment path (webhook → Beds24 → emails) is exercised by the first
real guest payment; the webhook logic is identical to the Upper View one
that was code-reviewed and API-verified.

## Fallback

Airwallex adapter remains in place; when its account is approved, one click
("Use Airwallex" in admin → Payments) switches providers. Keys for it go in
`data/config.php` `airwallex` block.
