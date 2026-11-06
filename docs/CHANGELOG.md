# Upper View Samui — system change log

The full, detailed record of the booking system's evolution. Git history
(local repo + github.com/6gffzsyc89-stack/UpperView) remains the
authoritative line-by-line record; this file is the human-readable account:
every milestone version, what changed, and why. Entries ascend; each carries
its version and the actual date it shipped. Maintained by Claude Code —
every architectural or backend change lands here AND is messaged to the
Sonata Samui session for cross-brand consistency (standing rule, 2026-11-06).

---

## v0.1 — 2026-07-17 · Site born from the design project
Brand documents, May 2026 photography, design brief, booking-panel and
Need Help feature specs; production website implemented from the Claude
Design handoff (4db4885). WhatsApp inquiry flow as the only booking path.

## v0.2 — 2026-07-18 → 2026-07-20 · Visual build-out
Spa imagery (Higgsfield, matched to Sonata reference), Need Help panel
polish (X + Escape), mobile full-width galleries, photo-anchored hero
scenes, curated room galleries, performance .htaccess.

## v0.3 — 2026-10-11 → 2026-11-01 · Content truth pass
Combined room sliders; opening notices (hotel late Dec 2026, Stomping
Grounds/Spa March 2027, auto-expiring); villa specs corrected (40 m² +
40 m² deck); swipe affordances (counter, peek, hint).

## v1.0 — 2026-11-01 · Booking engine
`booking/` engine v1 (543983c): live availability, Nobu-style calendar,
OTA iCal import seam, SQLite + config OUTSIDE the webroot, admin page,
booking codes, notification emails. Four real room types: Mini Villa ×8
pooled, Superior/Deluxe/King Suite ×1 each (dc81454).

## v1.1 — 2026-11-02 · Beds24 becomes the master system
e9b8761: availability served live from the Beds24 API (property 365322),
bookings written to Beds24, local iCal engine demoted to automatic
fallback. Local MCP server (`mcp/index.js`) so Claude/Yomied manage the
hotel through one authenticated backend. Nobu-style reservation flow
(dates → rooms → details).

## v1.2 — 2026-11-02 → 2026-11-03 · Checkout + remote MCP
Nobu-style checkout page (pay.php) with Airwallex drop-in, HMAC webhook,
branded invoice/confirmation email in site colors (c9b7249, 8dcf6de);
cancellation policy 14d free / 50% / 7d 100% (3aba2dc). Remote MCP
(Streamable HTTP) at upperviewsamui.com/mcp/ with per-member tokens and
read/manage roles (5c0cc69) — this is what Yomied connects to. Graded
per-suite photo sets from the one real suite (254ee86).

## v1.3 — 2026-11-04 · Hero videos + OTA photo parity
Furnished aerial heroes, Kling pinned to the July originals' first/last
frames, desktop + mobile (bf3312c, fc1a741). Booking.com: 12 photos per
suite, ID-verified (fd3f3b7). Requests tracker established (3b33bed).

## v2.0 — 2026-11-05 · Stripe live as active payment provider
5cbc121: Stripe Checkout (hosted page) adapter `stripe.php`; API-verified
webhook `webhook-stripe.php` (payload never trusted — session re-fetched,
payment_status=paid required, NO signing secret stored); provider switch
in pay.php (`uv_pay_provider()`: config/meta, one-click fallback to
Airwallex on its approval); admin.php Payments form so the owner pastes
the restricted key without it ever passing through chat. Secondary admin
key (meta `admin_key2`) for owner access (bd95760). Key pasted and
verified end-to-end 2026-11-06: hold → live cs_live Checkout session →
webhook 400-on-garbage → Beds24 write path.

## v2.1 — 2026-11-06 · Booking-policy fixes (occupancy, emails, OTA close)
9a452e6, after a real 4-guest mini-villa request exposed three faults:
- Occupancy: hard cap 2 guests per suite/villa, rejected server-side,
  mirrored in the UI; baby cot checkbox (suites only, complimentary,
  10 languages), stored in a new `cot` column.
- Emails: `uv_notify()` now mails the full `notify_emails` list
  (management@ableab.com, booking@upperviewsamui.com,
  upperviewsamui@gmail.com) — previously only the legacy single address.
- OTA close policy: unpaid website requests NO LONGER write to Beds24
  (calendars stay open); admin button renamed "Confirm + close OTAs"
  pushes manually; Stripe/Airwallex webhooks push automatically on
  payment. Beds24 bookings carry structured notes (SOURCE / PAYMENT /
  OTA CALENDARS / BABY COT) + invoice charge/payment rows so Yomied can
  read source, payment status and calendar state per booking.

## v2.2 — 2026-11-06 · Full Beds24 mirror for Yomied + deliverability
0f720a1: remote MCP v1.1.0 — beds24_get/beds24_write mirror the ENTIRE
Beds24 API v2; named tools get_channel_settings, get_guest_messages,
send_guest_message (replies through the booking's OTA channel),
get_invoices, send_email (as booking@upperviewsamui.com),
get/set_notify_emails. Verified end-to-end by the Yomied session (16
tools; ping strict_types 500 fixed, a09880a). Deliverability: every
mail() call sets envelope sender `-f booking@upperviewsamui.com` so
SPF/DKIM/DMARC align (Gmail was junking mail without it). Documented
API limit: Booking.com/Agoda connection screens are control-panel-only.

## v2.3 — 2026-11-06 · Help touchpoints + admin truth
a4eef7d: "Still have questions? Email or WhatsApp us" — ghost button in
the Reserve panel (opens the Need Help panel over the booking flow) and
a direct-link line under the checkout CTA (mailto + wa.me prefilled with
the booking code), 10 languages. f4755bf: admin Block form now writes
Beds24 too (a local-only block closed nothing); empty iCal "OTA busy"
table replaced by a live Beds24 all-channel bookings table. dfd014f:
availability endpoint reports the meta-aware payment provider.

## v2.4 — 2026-11-06 · Multi-room parties (W/Nobu pattern)
a5abe21: parties of 3–6 are offered "N × Mini Villa" (rooms =
ceil(guests/2), price × N, availability requires N free units nightly,
Beds24 roomQty=N + MULTI-UNIT note); suites stay capped at 2 with a
"Sleeps 2" pointer to the villas; server-side validation authoritative;
`rooms` column through DB, checkout, admin, emails. Same day: Jan 1–4
2027 OTA bookings (Suite 1 + villas 2/3/4) manually blocked in Beds24
(95788234/35/37/38) pending channel connections — Beds24↔OTA channel
wiring is the known open gap (Beds24 holds no OTA bookings yet).
