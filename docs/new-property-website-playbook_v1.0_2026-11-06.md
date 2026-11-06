# New property website playbook — v1.0 (2026-11-06)

How to stand up a complete direct-booking hotel website from zero, with
everything Andy's brands (Upper View Samui, Sonata Samui) learned and
implemented: live availability, accurate rates, payments, emails, admin,
AI-assistant access, and the verification discipline that keeps it honest.
Reference implementation: github.com/6gffzsyc89-stack/UpperView
(`site/` + `mcp/` + `docs/`). Detailed history: `docs/CHANGELOG.md`.

Standing rule: any architectural or backend change made on one brand's
site is messaged to the other brand sessions (e.g. Sonata Samui) so all
properties stay consistent, and is recorded in that repo's CHANGELOG.md.

---

## 1. Stack and hosting shape

- Static-first site (hand-written HTML/CSS/JS, no framework) + a small
  PHP booking backend in `/booking/`. Hostinger shared hosting.
- Deploy ONLY via SSH/scp to the webroot
  (`domains/<domain>/public_html/`). Lint with the SERVER's `php -l`
  (the Mac has no PHP; note the server CLI may be older than web PHP —
  avoid arrow functions if CLI is 7.2).
- Config + SQLite live OUTSIDE the webroot: `domains/<domain>/data/`
  (`config.php`, `bookings.sqlite`). Never in git, never web-readable.
- Git repo per property; GitHub remote; commit per change; CHANGELOG.md
  as the human-readable record. Git history is the changelog of record.
- i18n: one `i18n.js` with a key map per language (Upper View ships 10).
  EVERY new UI string gets all languages at introduction time.

## 2. Inventory model (get this right first)

- Define room types with real unit counts: pooled types (e.g. Mini
  Villa ×8) vs single-unit types (each suite ×1). Everything downstream
  (availability, multi-room logic, Beds24 room mapping) hangs off this.
- Occupancy: hard cap per unit, enforced server-side by REJECTION (never
  silently clamp), mirrored in the UI selector. Options like baby cots
  are explicit flags (DB column), limited to the types that offer them.
- Multi-room parties (W/Nobu pattern): rooms = ceil(guests / cap). The
  POOLED type offers "N × <type>" (price ×N, availability needs N free
  units every night); single-unit types show "Sleeps 2" guidance
  instead of a dead end. Server re-validates all of it.

## 3. Beds24 — the master calendar

- One Beds24 account, one property per brand, one room type per real
  type with correct quantity. The website NEVER keeps its own
  availability truth while Beds24 is enabled — it reads Beds24 live on
  every search (prices via calendar `price1` × direct multiplier, e.g.
  0.90) and falls back to a local iCal engine only if Beds24 is down.
- Auth: invite code → refresh token stored server-side (SQLite meta);
  access tokens cached ~24h. Setup script: `booking/beds24-setup.php`.
- Writes: ONLY these create Beds24 bookings (which is what closes OTA
  calendars everywhere): (a) payment webhooks on verified payment,
  (b) admin "Confirm + close OTAs", (c) admin/MCP date blocks. Unpaid
  website requests are deliberately NOT written to Beds24.
- Every website-written booking carries structured notes the AI
  assistant can read: `SOURCE: direct website <domain> — ref <code>`,
  `PAYMENT: PAID <amt> THB via <provider> (verified)` or
  `PAYMENT: NOT TAKEN — …`, `OTA CALENDARS: CLOSED automatically …`,
  plus roomQty for multi-unit and invoice charge+payment rows (second
  POST /bookings with `{id, invoiceItems}`) so payment shows on the
  money tab.
- CHANNEL CONNECTIONS ARE NOT AUTOMATIC: connecting Booking.com,
  Airbnb, Agoda, Trip.com to Beds24 happens in the Beds24 control panel
  with owner authorization on each OTA side. Until done, Beds24 holds
  only direct bookings and aggregates NOTHING — verify, never assume
  (see §9). The API exposes channel settings read/write only for
  iCal/Airbnb/VRBO (`/channels/settings`, Alpha).

## 4. Booking engine (`site/booking/`)

- `api.php` — public: `availability`, `quote` (per-type free units +
  live totals), `book` (creates a hold; routes to pay mode when a
  provider is live, else records an unpaid inquiry). Admin actions
  behind `k=` admin key (occupancy, bookings, block, rate, full Beds24
  GET/write mirror, send-email, notify-list). Accept a secondary admin
  key from meta (`admin_key2`) so the owner gets access without the
  primary key ever leaving the server.
- `lib.php` — config/db/meta helpers; schema with lazy ALTER-TABLE
  migrations (`cot`, `rooms` columns); `uv_notify_list()` resolution:
  meta override → config `notify_emails` → legacy single address.
- Booking statuses: pending (hold awaiting payment), inquiry (unpaid
  request), confirmed, cancelled, blocked. Holds collect guest details
  on the checkout page, not in the widget (Nobu flow).

## 5. Payments (Stripe active, Airwallex seam kept)

- Hosted Stripe Checkout — card data never touches the server, works
  with a RESTRICTED key (Checkout Sessions: Write only). The owner
  creates the key in the Stripe dashboard and pastes it into the admin
  page Payments form (stored in meta) — keys NEVER pass through chat.
- Webhook `checkout.session.completed`: treat the payload as a hint
  only; re-fetch the session from the Stripe API and require
  `payment_status === 'paid'`. No signing secret needed; forged posts
  do nothing. On verified payment: confirm booking → write Beds24
  (closes OTA calendars) → confirmation + notify emails.
- Units: decide per site and keep the adapter honest — Stripe THB wants
  satang (minor units), Airwallex wants whole THB (major). Upper View
  stores satang (Stripe pass-through, Airwallex ÷100); Sonata stores
  whole THB (Stripe ×100, Airwallex pass-through). Document the choice.
- `uv_pay_provider()` reads config AND meta so the public API reports
  the truth; provider switch is one admin click (Airwallex fallback on
  its approval). statement_descriptor_suffix with retry-without-suffix.

## 6. Email (deliverability is a feature)

- Mailbox `booking@<domain>` must EXIST (Hostinger hPanel), with the
  domain's SPF + DKIM + DMARC records live.
- Every PHP `mail()` call sets the envelope sender: 5th argument
  `-f booking@<domain>`, plus a same-domain `From:` header — without
  this Gmail junks or drops (learned the hard way).
- Notifications go to the full recipient LIST (uv_notify_list), never a
  single address. Guest confirmation is a branded HTML email in site
  colors with an embedded invoice, confirmation number, arrival info
  and cancellation policy.
- The unpaid-request notification states calendar truth: "OTA calendars
  are still OPEN — confirm in admin or wait for online payment."

## 7. Admin page (`booking/admin.php`)

Key-protected single page: bookings table (guests, +cot, N× rooms,
status, amount), "Confirm + close OTAs" (writes Beds24), Cancel (local —
cancel in Beds24 separately until wired), Block dates (writes local AND
Beds24, reports the Beds24 block id or a loud failure), Payments
(paste key, provider switch), live Beds24 all-channel bookings table
(the master system's own view, next 12 months).

## 8. AI-assistant access (MCP × 2)

- Local stdio MCP (`mcp/index.js`, Node) for Claude sessions on trusted
  machines; admin key from `~/.config/<brand>/admin_key`.
- Remote MCP (Streamable HTTP, `site/mcp/index.php`) at
  `https://<domain>/mcp/?k=<token>` for Yomied/ChatGPT/team: per-member
  tokens in config `mcp_tokens` with read vs manage roles. Tools:
  availability/calendar/bookings/occupancy reads; create_block,
  set_prices; full API mirror beds24_get / beds24_write; guest
  messaging (get_guest_messages / send_guest_message through the OTA
  channel); send_email as booking@<domain>; get/set_notify_emails.
  Every write tool's description says: explicit human instruction only.
- Document the honest API limits in the integration README (e.g.
  Booking.com/Agoda connection screens are Beds24 control-panel-only).

## 9. Verification discipline (why the system stays accurate)

- CONFIRM, DO NOT ASSUME — the project's one law. Verify every claim
  with live data: query the public endpoint AND the master system and
  compare, before telling the owner anything is "connected".
- After every deploy: server `php -l` on each changed file, then a live
  end-to-end probe (create a hold with a clearly-labelled TEST name →
  check routing/price/DB row → CANCEL it). Payments: drive to a real
  `cs_live_…` Checkout session URL (HTTP 200) but never pay.
- Rejection paths are tested live too (e.g. POST 4 guests → expect the
  exact error).
- UIs of external dashboards change: never describe them from memory;
  search current docs first (global rule).
- The server-state check that catches silent config gaps: print
  stripe_key/provider/meta state via `php -r` over SSH rather than
  trusting what was set up previously.

## 10. Launch checklist (new property, in order)

1. Repo + GitHub remote; copy `site/` shape; adapt brand, rooms, i18n.
2. Hostinger: domain, SSH key, `data/` outside webroot with
   `config.php` (from config.sample.php: inventory, opening date,
   notify_emails list, admin/export keys, mcp_tokens).
3. hPanel: create `booking@<domain>` mailbox; confirm SPF/DKIM/DMARC.
4. Beds24: create the property, rooms with real quantities, prices;
   invite code → beds24-setup.php; room_map in config.
5. Deploy, lint, run §9 probes (availability, quote, book, reject >cap).
6. Stripe: owner registers webhook endpoint + creates restricted key →
   pastes into admin Payments. Verify hold → cs_live session → webhook
   400-on-garbage.
7. Owner confirms nightly rates + cancellation policy string.
8. Beds24 control panel: connect each OTA channel (owner authorizes);
   then VERIFY an OTA test booking appears in Beds24 and reduces
   website availability — until this passes, availability is NOT
   aggregated and manual blocks are required.
9. Remote MCP tokens for the AI assistant; send the integration README.
10. CHANGELOG.md started; cross-brand sync message sent; tracker row
    opened for anything left with the owner.
