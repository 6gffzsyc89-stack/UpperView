# Upper View / Beds24 MCP — integration guide

For: team members connecting through Claude or ChatGPT, and for the
**Yomied** session/software on this computer that will relay guest and
management messages through WhatsApp.

Read this whole file before calling anything. The MCP is a thin wrapper —
the real contract is the HTTPS admin API described in section 4, which any
software (including a WhatsApp bot) can call directly.

---

## 1. What this is

```
Claude / ChatGPT / Yomied(WhatsApp)
        │  (MCP stdio, or plain HTTPS)
        ▼
upperviewsamui.com/booking/api.php      ← single backend, admin-key auth
        │
        ├── Beds24 (MASTER: availability, rates, bookings, OTA sync)
        └── local iCal fallback engine (same API, used if Beds24 is down)
```

Beds24 is the master booking system for Upper View Samui (property
365322). The website backend fronts it; this MCP fronts the backend, so
credentials stay server-side and every caller sees one consistent API.

- MCP server (stdio, Node): `/Users/andy/projects/UpperView/mcp/index.js`
- Server name: `upperview` (tools appear as `mcp__upperview__<tool>`)
- Backend: `https://upperviewsamui.com/booking/api.php`

## 2. Credentials — where they live (never copy values into chats/repos)

- On this Mac: `~/.config/upperview/admin_key` (file, mode 600). The MCP
  reads it automatically; software may read the same file.
- Alternative: env var `UV_ADMIN_KEY` (overrides the file).
- On the server: `domains/upperviewsamui.com/data/config.php` (`admin_key`).
- The admin key is FULL management access (block dates, change rates).
  Give it to trusted staff machines only; a WhatsApp bot must keep it
  server-side and never echo it to users.

## 3. MCP tools (exact schemas)

| Tool | Args | Does |
|---|---|---|
| `get_availability` | `type` ("villa"\|"superior"\|"deluxe"\|"king"), `from`, `to` (YYYY-MM-DD, `to` exclusive) | Free units per date. villa = 8 pooled Mini Villas; each suite = 1. |
| `get_occupancy` | `from?`, `to?` | Occupancy % + booked nights per type (default next 30 days). |
| `list_bookings` | — | Upcoming bookings/blocks: website DB + live Beds24 incl. OTA bookings. |
| `block_dates` | `type`, `checkin`, `checkout`, `note?` | Blocks one unit (maintenance/phone booking) on site + Beds24. |
| `set_nightly_rate` | `type`, `from`, `to`, `price` (THB) | Writes nightly price to Beds24 for the range. |
| `beds24_status` | — | Connection health; site auto-falls back to iCal engine on failure. |
| `beds24_get` | `path`, `query?` | **Full READ mirror of the Beds24 API v2** — any GET endpoint: `/channels/settings` (channel manager config), `/properties`, `/bookings`, `/bookings/messages`, `/bookings/invoices`, `/inventory/*`, `/channels/airbnb/*`, `/channels/booking/reviews`, … |
| `beds24_write` | `path`, `method?`, `query?`, `body` | **Full WRITE mirror** — POST/PATCH/DELETE any endpoint. Real writes that sync to OTAs; explicit human instruction only. |
| `send_email` | `to`, `subject`, `body`, `html?` | Sends an email **from booking@upperviewsamui.com** via the website server. |
| `get_notify_emails` / `set_notify_emails` | — / `emails[]` | Read / replace the booking-notification recipient list (stored server-side, takes effect immediately). |

The REMOTE MCP (`https://upperviewsamui.com/mcp/?k=<token>` — what Yomied
connects to) has the same power plus named conveniences:
`get_channel_settings` (propertyId 365322), `get_guest_messages`,
`get_invoices`, `send_guest_message` (replies to a guest THROUGH their OTA
channel: Booking.com/Airbnb messaging), `send_email`, `get/set_notify_emails`,
`beds24_get`, `beds24_write`. Read-role tokens get the read tools; manage-role
tokens get everything.

**API limits (honest):** the Beds24 API does not expose every control-panel
screen. `GET /channels/settings` is an Alpha endpoint covering iCal export/
import, Airbnb and VRBO connection settings. Booking.com/Agoda connection
internals (mapping screens, auto-close rules) live only in the Beds24 control
panel UI — no API client can read them. What Yomied CAN determine: which OTAs
actually deliver bookings (each booking's `channel`/`apiSource` fields), and
calendar closing is uniform — any booking existing in Beds24 reduces
availability on ALL connected channels automatically (see section 6).

Run it from any project on this Mac — `.mcp.json`:

```json
{
  "mcpServers": {
    "upperview": {
      "command": "node",
      "args": ["/Users/andy/projects/UpperView/mcp/index.js"]
    }
  }
}
```

(Claude Desktop: same block in its MCP settings. CLI:
`claude mcp add upperview -- node /Users/andy/projects/UpperView/mcp/index.js`)

## 4. Direct HTTPS API (what Yomied's software should call)

Base: `https://upperviewsamui.com/booking/api.php?action=<action>`
Admin actions require `&k=<admin key>`. All responses are JSON.

Public (no key):
- `action=quote&from=YYYY-MM-DD&to=YYYY-MM-DD` — per-type availability +
  live direct prices (THB, taxes included). THE call for "what does X cost".
- `action=availability&type=villa&from=..&to=..` — free units per date.
- `action=book` (POST JSON `{type, checkin, checkout, guests, cot?}`) —
  creates a hold and returns `{mode:"pay", code, url, total}`. **Send that
  `url` to a guest on WhatsApp and they get the full Upper View checkout**
  (guest details + card, Stripe or Airwallex) on their phone; payment
  auto-confirms the booking into Beds24 and emails the invoice. (Until a
  payment key is configured the API instead expects `name`,`email`,`phone`
  and returns `mode:"inquiry"` — an unpaid request that is NOT written to
  Beds24.) **Occupancy is capped at 2 guests per suite or mini villa**
  (`guests` > 2 is rejected). `cot: 1` requests a complimentary baby cot —
  suites only, ignored for the mini villa.

Admin (append `&k=`):
- `admin-occupancy&from=..&to=..`
- `admin-bookings`
- `admin-block` (POST `{type, checkin, checkout, note?}`)
- `admin-rate` (POST `{type, from, to, price}`)
- `admin-beds24-status`

WhatsApp bot guidance: expose quote/availability/book to guests; keep
admin actions behind a staff allowlist (phone numbers), and never send the
admin key or raw error dumps into chats.

## 5. Giving TEAM MEMBERS access via Claude / ChatGPT

Today (local stdio): works per-machine. On each trusted staff machine:
1. copy the admin key file to `~/.config/upperview/admin_key` (chmod 600),
2. add the `.mcp.json` block above (needs Node 18+ and this repo cloned, or
   just the single `mcp/index.js` file + `npm i @modelcontextprotocol/sdk zod`).
That machine's Claude Code / Claude Desktop then has the full toolset.
Every holder has FULL admin power — only for staff you'd let change prices.

Proper team access (recommended next step, not built yet): a small REMOTE
MCP (HTTP) endpoint, e.g. `https://upperviewsamui.com/mcp/`, with
per-member tokens and per-token permissions (read-only vs manage). Then:
- **claude.ai / Claude Desktop**: Settings → Connectors → "Add custom
  connector" → paste the URL; works for any team member, no local setup.
- **ChatGPT**: Settings → Connectors (developer mode) → add MCP server URL.
  ChatGPT only supports REMOTE MCP servers, so this wrapper is required
  for any ChatGPT access.
Ask the Upper View Claude session to build this when wanted — the backend
API already exists, so it is a thin authenticated proxy.

## 6. Reading a website booking in Beds24: source, payment, OTA status

Every booking the website writes to Beds24 carries a structured note
(visible in the Beds24 booking's **notes** field, readable via the Beds24
API `GET /bookings` `notes` property). Lines, in order:

```
SOURCE: direct website upperviewsamui.com — ref UVXXXXXX
PAYMENT: PAID 9,800 THB via Stripe (verified online payment)
         — or —
PAYMENT: NOT TAKEN — manually confirmed from the admin page, collect payment separately
OTA CALENDARS: CLOSED automatically by this Beds24 booking (availability reduced on Booking.com / Agoda / Airbnb / all channels)
BABY COT requested (free option, suites only)        ← only when requested
```

How to interpret any booking:
- **Where it was made / source**: website bookings have `referer =
  upperviewsamui.com`, `apiReference = UV…` code, and the SOURCE note line.
  OTA bookings arrive from the channel itself and have none of these —
  their Beds24 `channel`/`referer` names the OTA (booking.com, agoda…).
- **Payment taken?** Read the PAYMENT note line. Paid online bookings also
  carry invoice lines (a charge + a payment) on the Beds24 invoice tab.
  If a booking has no PAYMENT line and came from an OTA, payment is per
  that OTA's own policy.
- **OTA calendars closed?** Any booking that EXISTS in Beds24 closes the
  OTA calendars automatically (Beds24 pushes availability −1 to all
  channels). Unpaid website requests are NOT in Beds24 at all — calendars
  stay OPEN until staff presses "Confirm + close OTAs" on
  `/booking/admin.php` (manual) or the guest pays online (automatic).
  Unpaid requests are visible via `list_bookings` / `admin-bookings`
  (status `inquiry`), not in Beds24.

### 6a. Promo codes and services (Sonata Samui bookings, from 2026-11-08)
Sonata website bookings (`referer = sonatasamui.com`, `apiReference = SN…`, property 366403)
can carry a promo code set on Sonata's own admin page. When they do, the Beds24 booking has:
```
PROMO: TRANSFER26 — Included: Airport transfer on arrival
SERVICE TO ARRANGE: Airport transfer on arrival          ← only for service promos
PROMO: SUMMER10 — 10% off the room rate (−4,436 THB on this villa)   ← discount promos
```
plus an info item `PROMO` with the same text, the guest's own message in `comments`, and
invoice lines: the villa at the direct rate, a "Promo …" line (negative amount for a discount,
0 for an included service) and the payment when paid. Use `list_bookings` (notes / infoItems)
and `get_invoices` to answer "which promo was used and what does the guest get".
**Upper View uses the same lines from 2026-11-11** (`referer = upperviewsamui.com`,
`apiReference = UV…`, property 365322): the PROMO note shows "(−N THB, already off the
total)" for discounts, SERVICE TO ARRANGE for included services, the `PROMO` info item,
and invoice lines (room charge at the gross direct rate, "Promo …" at −discount or 0,
payment at the net amount when paid). Codes are managed on either property's admin page
(Promotions section — they are per property, applied to the ticked ones).

### 6b. Guest-care info items (2026-11-11 convention, both properties)
Special requests, allergies, birthdays/anniversaries, pickup times and outstanding tasks
belong ON THE BEDS24 BOOKING so every system sees them — not only in a spreadsheet.
Write them as info items via `beds24_write` (`POST /bookings`, `[{id, infoItems:[…]}]`),
one per fact, using these codes so they are searchable:
```
ALLERGY   e.g. "peanut allergy (guest 2)"
OCCASION  e.g. "birthday on 12 Jan — cake arranged?"
REQUEST   e.g. "late check-out asked, not yet answered"
PICKUP    e.g. "airport pickup 14:30, flight PG123"
TASK      e.g. "collect city tax at check-in"
PROMO     (written automatically by the website, see 6a)
```
`list_bookings` with `includeInfoItems=true` then answers "who arrives this week and what
do they need". The managers' Google Sheets stay a human double-check, not the master.

## 7. Ground rules for automated callers (Yomied included)

- Beds24 is master: never write availability anywhere else; `block_dates`
  and paid bookings are the only write paths.
- Quote before book; treat `free: 0` as sold out, no retries loops.
- Rate changes (`set_nightly_rate`) are real and sync to OTAs — require an
  explicit human (Andy) instruction per change.
- Opening date: the hotel opens 2026-12-20; earlier dates are closed.
- One booking hold per guest conversation; holds expire ~25 minutes.

## 7. v1.2.0 (2026-11-13) — optimiser + outbound-email log

Three tools added for Yomied (contract agreed with the Yomied session):

- `optimize_preview` (read): proposes villa unit moves that minimise
  vacancies. Returns `planId` + `stateHash` (digest of every assignment the
  plan depends on), `moves` (bookingId, guest, fromUnitId→toUnitId, why,
  provenance), `pinned` (unmovable stays with reasons — Airbnb-locked,
  in-house, <48h, multi-villa, portal-calendar entries), `conflicts`, and
  before/after `metrics` (sellableNights, orphanGapSegments).
  `respectPins:false` is refused — pins are law.
- `optimize_apply` (manage): executes a previewed plan ONLY. Requires
  planId + stateHash + actor (the authorizing human). Refuses when the plan
  is unknown, >24h old, or when live bookings changed since the preview
  ("re-run optimize_preview"). Idempotent per planId. Writes unitIds via
  POST /bookings, stamps meta assign_src_<bookingId>
  {provenance:'yomied', actor, ts, planId}, appends
  data/audit-optimize.log, pings the sheet, echoes the executed moves.
- `list_sent_emails` (read): the outbound mail log (sent_emails table,
  started 2026-11-13). Every engine/notify/MCP email now carries a
  generated `Message-ID: <uv-…@upperviewsamui.com>` and is logged with it +
  bookingRef where known — Yomied's IMAP ingestion dedupes on messageId.
  `send_email` gained optional bookingRef and returns messageId.

All verified live 2026-11-13: tools/list = 19, preview plan + metrics real,
wrong-hash apply refused, zero-move happy-path apply + idempotent re-apply,
send_email logged and listed.
