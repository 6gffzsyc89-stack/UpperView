# New property website playbook — v1.4 (2026-11-12)

Changelog:
- v1.4 (2026-11-12) — §12: AI agents, Live Help and email triage are
  requirements for every property, per Andy; canonical spec =
  docs/ai-marketing-and-agents_v1.0_2026-11-12.md.
- v1.3 (2026-11-12) — ONE BACKEND (CHANGELOG v4.0): every engine file now
  lives once in /home/u357356777/shared-core/ (uv/ + sonata/ profiles
  merging file-by-file into common/); webroot booking/ dirs hold 2-line
  stubs only. New §11 is the explicit recipe for adding a THIRD (or Nth)
  property on the shared core — this section is the authoritative answer
  to "how do we add a property", maintained in BOTH brand repos
  (UpperView + Sonata) by their respective Claude sessions.
- v1.2 (2026-11-07) — Beds24 inventory-rows gotcha (root cause of the
  366403 zero-availability mystery, found by the Sonata session): rooms
  created via the Beds24 API get NO inventory rows — availability reads
  false/0 although the panel shows Inventory 1, and API numAvail writes
  to missing rows return success and do nothing. Rooms created in the
  control panel are seeded ~2 years. Fix: panel → Calendar → Inventory
  cell → Apply to all rooms → end date → Save (creates rows, but max
  365 days per write; longer ranges silently truncate). Track the
  inventory horizon (e.g. config beds24.inventory_until) and re-extend
  before it rolls; past the horizon treat Beds24 as blind and let OTA
  feeds decide. Upper View's panel-created rooms run out 2028-11-01.
  Also corrected: Beds24 billing is pay-as-you-go daily fees from the
  account balance — there is no subscription to "activate"; channel
  sync unlocks when the free trial ends.
- v1.1 (2026-11-06) — reconciled with Sonata's build: distinct-unit
  combination model beside the pooled "N ×" model (§2); hold/details
  order relaxed to either sequence with server re-validation (§4);
  tri-state availability source `local | both | beds24` as the channel
  transition mode (§3, §10 step 8); one account-wide remote MCP across
  brands (§8); i18n launch scope per brand (§1).
- v1.0 (2026-11-06) — first issue.

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
- i18n: one `i18n.js` with a key map per language (Upper View ships 10;
  a brand may deliberately launch English-only, e.g. Sonata). Whatever
  languages a site has, EVERY new UI string ships in all of them at
  introduction time.

## 2. Inventory model (get this right first)

- Define room types with real unit counts: pooled types (e.g. Mini
  Villa ×8) vs single-unit types (each suite ×1). Everything downstream
  (availability, multi-room logic, Beds24 room mapping) hangs off this.
- Occupancy: hard cap per unit, enforced server-side by REJECTION (never
  silently clamp), mirrored in the UI selector. Options like baby cots
  are explicit flags (DB column), limited to the types that offer them.
- Multi-room parties (W/Nobu pattern) — two shapes, pick by inventory:
  (a) POOLED identical units (Upper View Mini Villa ×8): rooms =
  ceil(guests / cap), offer "N × <type>" (price ×N, availability needs
  N free units every night); other single-unit types show "Sleeps 2"
  guidance instead of a dead end. (b) DISTINCT single units with
  different capacities (Sonata: three villas sleeping 4/4/6): return
  minimal COMBINATIONS of units that sleep the party (one row per unit,
  grouped by a group_code; one checkout and one confirmation per group;
  one channel-manager booking per unit with a MULTI-VILLA note), with a
  hard party ceiling above which the flow hands to WhatsApp/staff.
  Either shape: the server re-validates all of it.

## 3. Beds24 — the master calendar

- One Beds24 account, one property per brand, one room type per real
  type with correct quantity. Availability truth is a tri-state
  `beds24.source` setting: `local` (OTA iCal feeds per unit — correct
  while channels are NOT yet connected), `both` (a date is free only if
  Beds24 AND the OTA feeds agree — the safe TRANSITION mode while
  channels come online), `beds24` (Beds24 alone once every channel is
  connected and verified). In `beds24` mode the site reads Beds24 live
  on every search (prices via calendar `price1` × direct multiplier,
  e.g. 0.90) and falls back to the local iCal engine only if Beds24 is
  down. Never run `beds24` mode before §10 step 8 passes.
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
  live totals), and a hold-creating request endpoint. Guest details may
  be collected BEFORE the hold (Sonata: in the booking sheet, request
  returns {mode:'pay', url} straight to a prefilled checkout) or AFTER
  it (Upper View: Nobu order, details on the checkout page) — either is
  fine as long as the checkout POST re-validates everything server-side
  and an unpaid inquiry is recorded when no provider is live. Admin actions
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
- ONE account-wide remote MCP serves ALL brands: the Beds24 token is
  account-level, so every property is reachable by id through the same
  endpoint (Upper View hosts it; Sonata has none of its own). A second
  brand's SITE backend (its own requests/enquiries/admin) is separate —
  give the assistant access via a token for that site's api.php or a
  thin additional MCP only when actually needed.
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
   aggregated: run `beds24.source` = `local` (OTA iCal feeds) or
   `both`, and use manual blocks for anything the feeds miss. Switch
   to `beds24` only after the verification passes. Known gotcha
   (SOLVED, see v1.2 changelog): API-created rooms have no inventory
   rows — seed them from the panel (Calendar → Inventory → Apply to all
   rooms, max 365 days/write) and track the inventory horizon.
9. Remote MCP tokens for the AI assistant; send the integration README.
10. CHANGELOG.md started; cross-brand sync message sent; tracker row
    opened for anything left with the owner.


## 11. Adding a property on the ONE BACKEND (shared-core) — explicit recipe

State as of 2026-11-12: engine code lives ONLY in
`/home/u357356777/shared-core/` — profiles `uv/` and `sonata/`, shared
superset growing in `common/` (network.php, sheet-ping.php merged; the
rest follows per docs/one-backend-plan.md). Webroot `booking/` dirs hold
two-line stubs. NEVER copy engine code into a webroot.

For a new property "X" at domain X.com (same Hostinger account):

1. **Hosting + data.** Add the domain to the account. Create
   `domains/X.com/data/` (OUTSIDE the webroot) with `config.php` from
   `config.sample.php`: brand name, domain, mail addresses, Beds24
   property/room ids, villa_model ('pool' like UV or 'individual' like
   Sonata), pricing source, feature toggles, 'min_nights' => 2.
2. **Beds24.** Create the property and its rooms IN THE CONTROL PANEL
   (never via API — API-created rooms get no inventory rows, §2 gotcha),
   seed inventory ~2 years, note the horizon in config, set minStay 2.
3. **Engine.** While the superset merge is incomplete: copy the CLOSEST
   profile (`uv/` for pooled identical units, `sonata/` for named villas
   with capacities/combos) to `shared-core/X/`, then point X at `common/`
   for every already-merged module via the 2-line shims (see
   `shared-core/uv/sheet-ping.php` for the pattern + the CORE_* adapter
   constant). Once the merge completes, this step collapses to "stubs +
   config only" — no engine copying at all.
4. **Stubs.** Generate one 2-line stub per engine .php into
   `domains/X.com/public_html/booking/` (template:
   `tools/stubs/uv-example-stub.php`): define X_SITE_DATA +
   X_SITE_WEBROOT, require the shared-core file. Generate LOCALLY, lint,
   ship via tar over ssh (never printf files over ssh — that broke 5
   live files once).
5. **Front end.** New brand site in the webroot (its own repo), plus the
   static `review/` kiosk page and `photos/`.
6. **Tokens + mirror.** Set meta cal_feed_token + cal_image_token; add a
   brand entry (feed URL, tab prefix, layout style) to the Apps Script
   "NikiStay live sheet sync" CFG.brands, run syncFeeds, and BUMP THE WEB
   APP DEPLOYMENT VERSION (Manage deployments → edit → New version —
   deployments pin code); store meta sheet_ping_url on the new site.
7. **Payments.** The brand's own Stripe (and/or Airwallex) account; keys
   via admin; webhook URL = https://X.com/booking/webhook-stripe.php;
   payment_provider set in admin.
8. **MCP + email + DNS.** Remote MCP entry per the account-wide pattern
   (§8); SPF/DKIM/DMARC; Cloudflare NS if adopted account-wide.
9. **Verify + record.** Run the §10 checklist + the endpoint battery
   (quote with real pricing, feed CSV, webhooks answer 400 to garbage,
   admin, calendar-image 403 without token, kiosk). Add the property to
   every session's standing rules; record in BOTH repos' CHANGELOG.md
   and message all brand sessions (standing rule, header of this doc).


## 12. AI agents, Live Help, email triage (required for every property)

Canonical spec: `docs/ai-marketing-and-agents_v1.0_2026-11-12.md` (mirrored
by pointer in every brand repo). Summary of what each property MUST have:
Yomied as the internal agent only (private admin MCP; owns keys, calendar
pictures, the vacancy optimiser, ops mini-apps); an external Guest Concierge
on the website grounded in the public guest MCP, whose auto welcome message
offers a Live Help button reading the number from per-site config
(live_help_phone — never hard-coded); AI email triage with human GO before
sending (auto-send is a per-property opt-in later); every outbound email
logged and queryable by Yomied; explicit AI-crawler robots.txt; the
automated sheet's green Manual notes column (survives every sync) so the
manual workbook can retire.
