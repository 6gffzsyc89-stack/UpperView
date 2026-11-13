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

## v2.5 — 2026-11-06 · Live OTA availability via Airbnb iCal union
Availability transition mode live: uv_avail() intersects Beds24 with the
local iCal engine when feeds are configured (most pessimistic source wins).
Airbnb export links harvested for villas 2/3/4 and wired into the server
config (tokened URLs live only in data/config.php, never in git); 14 real
busy ranges imported, website villa counts verified against them. Jan 1–4
manual blocks kept deliberately (suites/Booking.com/Trip bookings are not
feed-visible yet; over-blocking is safe, under-blocking overbooks). Remove
feeds + manual blocks once Beds24 channel sync is live and verified.
Technique credit: Sonata session (12-feed union).

## v2.6 — 2026-11-07 · Beds24 inventory-rows gotcha documented
Sonata session found the root cause of API-created rooms reading zero
availability: no inventory rows (panel rooms get ~2 years seeded; API
numAvail writes to missing rows no-op with success). Upper View's rooms
are seeded until 2028-11-01 — re-extend from the panel before then
(365-day max per write). Billing corrected: pay-as-you-go daily fees,
no subscription to activate; channel sync unlocks at trial end.

## v2.7 — 2026-11-07 · Unified cancellation policy (OTA "Firm" terms)
Three divergent policies were in circulation (checkout: free until 1 day
before; email: 14d/50%/7d-100%; Airbnb: Firm). Unified on the confirmed
Airbnb-set Firm terms (full refund >=30d before check-in, 50% >=7d, none
after, 24h free-cancel grace for bookings made >7d out; 28+ night stays
stricter). Checkout now shows a bold stay-specific summary with computed
dates + link; new standalone /policies.html carries the full policy
(index.html untouched); confirmation email matches and links it.
Booking.com's own wording still unverified (extranet behind WAF) — to be
reconciled when reachable.

## v2.8 — 2026-11-08 · Card amounts fixed (Stripe ×100, Airwallex pass-through)
Made from the Sonata Samui session, on Andy's explicit authority (the Upper View
session was not running). Found while comparing the two checkouts.
- **Problem:** bookings store the price in WHOLE BAHT. `api.php` `book`
  stores the quote total, e.g. 14800, and the emails format it as baht. But
  `stripe.php` passed that number as Stripe `unit_amount`, which for THB is in
  satang. `airwallex.php` divided it by 100 before sending major units. Either
  provider would have charged 1/100 of the price, e.g. ฿148 for a ฿14,800 stay.
- **Fix:** `stripe.php` sends `amount × 100`; `airwallex.php` sends `amount`
  unchanged. The header comment that claimed bookings store satang is
  corrected. The legacy `uv_checkout_create` path in `api.php`/`lib.php` already
  multiplied by 100 and is unchanged.
- **Deployed** over SSH to `domains/upperviewsamui.com/public_html/booking/`;
  live files matched the repo before upload. Backups are
  `domains/upperviewsamui.com/data/{stripe,airwallex}.php.bak-2026-11-08`.
  Site and booking API verified responding afterwards.
- No real charge was made to test this. Rates are still unconfirmed, so no card
  is charged until Andy confirms them. Check the first live payment amount in
  the Stripe dashboard.

## v2.9 — 2026-11-08 · Admin payments panel (from the Sonata session)
Made from the Sonata Samui session on Andy's authority ("same change should be reflected on
the Upper View website"). Andy saved a Stripe key on Sonata's admin and nothing showed it had
worked; Upper View's admin had the same weakness.
- `booking/admin.php`: a green/amber bar at the top, "Online card payments: ON/OFF"; the Payments
  section shows badges (key saved with its last 4 characters, Stripe accepts the key — checked
  live and read-only, re-checked hourly — provider in effect, card payments ON/OFF) and a
  "Test Stripe connection" button (lists one Checkout Session; charges nothing). Every form now
  redirects back to its own section with a green/red message (post / redirect / get), so a
  refresh never re-submits.
- Verified live: Upper View's saved restricted key (ending …gqR8) is accepted by Stripe; Stripe
  is the provider in effect; card payments ON.
- Deployed over SSH (only this file). The live file matched the repo before upload; backup
  `domains/upperviewsamui.com/data/admin.php.bak-2026-11-08`.
- Also added: `docs/promo-codes-from-sonata_v1.0_2026-11-08.md` (how Sonata built promo codes
  and the promotion bar, for Upper View to match) and BEDS24-MCP-README §6a (how the WhatsApp
  agent reads PROMO / SERVICE TO ARRANGE lines). No promo code is live on Upper View yet.

## v3.0 — 2026-11-08 · One admin network with Sonata (from the Sonata session)
Made from the Sonata Samui session on Andy's authority ("if you change the payment method in one
place … change it in all places … record the change and let the Upper View session know").
- New `site/booking/network.php` (IDENTICAL to Sonata's — keep the copies in sync) and
  `site/booking/network-local.php` (Upper View's adapter); `api.php?action=network` handles calls
  signed with a shared secret kept only in each site's database (meta `network_key`, set on the
  server; never in git or chat); calls expire after 5 minutes.
- `admin.php`: links to Sonata's admin at the top (15-minute signed link, no key pasting); the
  Payments section shows a table of every property (method in use, Stripe key, Airwallex, card
  payments) with Stripe / Airwallex / Email requests buttons per property or for all at once;
  a new **Booking emails** section shows and edits `notify_emails` for one or both properties.
  The old "Use Stripe / Use Airwallex" buttons are replaced by the table.
- `stripe.php` `uv_pay_provider()`: `payment_provider = manual` now really means "email requests,
  no card payments" (before, it fell through to Stripe whenever a key existed).
- Promo codes are still not built here; Sonata's admin shows Upper View as "promo codes not built
  there yet". To join: implement them (spec `docs/promo-codes-from-sonata_v1.0_2026-11-08.md`), add
  `net_local_promo()` to `network-local.php` and `'promos'` to its `features`.
- Deployed over SSH (5 files); live files matched the repo before upload; database backed up as
  `data/bookings.sqlite.bak-2026-11-08-network`. Previous versions are in git (commit 38541d5).

## v3.1 — 2026-11-11 · Admin calendar, no double bookings, WhatsApp calendar picture
Ported from Sonata v3.0 (Andy: "update Upper View's admin to match").
- **Admin calendar** (`booking/calendar.php`): one month at a time (◀ ▶, "This
  month"), one row per room type, stays drawn as bars coloured by source
  (Airbnb/Booking.com/Agoda/Trip/Expedia/Vrbo/direct/request/blocked) with
  badges; click a bar → side panel with the full booking loaded live from
  Beds24 (guest, channel, price, commission, messages, notes, invoice);
  Calendar/List switch and room filter (remembered per browser). Upper View
  adaptation: the Mini Villa row is a POOL of 8 units, so overlapping bars
  are normal there — "double booking" is capacity-aware (nights where summed
  stay weight, incl. Beds24 roomQty and our `rooms`, exceeds the unit count);
  suites (capacity 1) behave exactly like Sonata's villas.
- **No double bookings — ever** (`uv_clash_nights` in beds24.php: Beds24 +
  freshly pulled OTA feeds + our bookings, excluding the booking's own row):
  checked (a) in pay.php before any payment starts (409, clear message,
  nothing charged), (b) in all three webhooks at confirm time — if the nights
  were taken while the guest paid, status becomes `clash`, nothing is written
  to Beds24, the desk gets an URGENT email and the guest's checkout page
  explains (payment safe, contact today, refund or move), (c) on the admin
  Confirm button (refuses with the clashing nights). Local engine fixed on
  the way: multi-villa bookings now count `rooms` units per night (was 1).
- **WhatsApp calendar picture** (`booking/calendar-image.php`, PNG 1080px,
  Lato/OFL in booking/fonts/): `?t=<token>&m=YYYY-MM&v=all|villa|superior|
  deluxe|king&view=cal|list&who=guest|staff`. Guest = booked/free only, with
  "N free" per night on the villa pool; staff = colours + guest names. Token
  made in admin → Calendar; stored only in the site DB; 60 pictures/10 min
  per IP. Verified live: all six modes render, bad token 403.
- **Dedupe gotcha (both brands)**: Beds24 GET /bookings returns an EMPTY
  apiReference even for bookings created with one, so matching our rows to
  Beds24 copies by reference silently fails → the same stay showed twice and
  raised a false "double booking". Fix (second pass, exact-match idea from the
  Sonata session, which applied it as its v3.2): our UV code is read back from
  the Beds24 notes line we write on every booking ("… ref UVxxxxxx" and the
  older "Direct website booking UVxxxxxx" — the list endpoint DOES return
  notes); remaining fallback for note-less manual blocks: same type +
  identical dates + (both blocks, matching channel ref, or same guest name).
- iCal SUMMARY now stored (`ota_busy.summary`, lazy migration) so feed
  entries read "Airbnb guest" vs "Closed on Airbnb"; `uv_rate_limit()` added.
- Cloudflare (Sonata v3.0 item 4): both domains are in Andy's Cloudflare
  account, switch lives in Sonata's admin, covers upperviewsamui.com — no
  Upper View code needed, nothing changes until the nameserver move.

## v3.2 — 2026-11-11 · Pending-booking rule, promo codes (OFF by default), data reconciliation
- **Owner rule: unpaid requests are PENDING, never close dates.** The local
  engine stopped counting 'inquiry' rows; Beds24 'inquiry'/'request' statuses
  draw in the pending colour (dashed) on the admin calendar; the three stale
  'new' Beds24 bookings written from unpaid website requests (Mini Villa pool,
  Dec 24–26 / Jan 7–12 / Jan 21–26) were converted to 'inquiry' with an
  explanatory note — their dates are released. Confirm + take payment happens
  manually (admin page, Beds24, or the WhatsApp assistant via the MCP).
- **Data reconciliation vs the managers' Google Sheets (NikiStay):** after a
  fresh feed pull the website's availability and calendar match the UPPERVIEW
  RESERVATIONS sheet night-for-night on all three Airbnb villas for Dec/Jan —
  unit mapping v2/v3/v4 → listings confirmed correct. Earlier divergence was
  stale feed data (feeds refresh on site use) plus bookings made ~Nov 7 that
  had not been pulled. Beds24 still holds no OTA bookings (channels trial-
  locked), so the sheet's counterpart for OTA stays is the iCal layer.
- **Promo codes (ported from Sonata v2.7/v2.8, Andy's go 2026-11-11), feature
  SWITCHED OFF by default:** `promos` table + helpers in lib.php (new codes
  start inactive; bar only shows active banner promos — none exist, so the
  site looks unchanged); api.php `?action=promos`, promo-aware quote
  (gross/discount/net, struck-through rates) and book (code re-validated,
  stored on the booking; amount = net charged); checkout + confirmation email
  show the room at the gross rate and the promo's own line; Beds24 gets
  PROMO/SERVICE TO ARRANGE notes, a PROMO info item and invoice lines (charge
  at gross, promo at −discount, payment at net; invoice lines now also written
  for unpaid manual confirmations); Stripe description + metadata carry the
  code; admin Promotions section (multi-property via the network — Sonata's
  admin can now manage Upper View codes too, net_local_promo + 'promos'
  feature); Hoxton-style #promobar + reserve-flow promo field wired
  (#uv-promo existed, unwired), phrases in all ten languages. Tested live
  with a hidden 10% code end-to-end (quote 13,689→12,320; checkout shows
  −฿1,229; DB row net/gross/discount correct), then the test booking was
  cancelled and the code deleted — nothing promotional is live.
- BEDS24-MCP-README §6a updated (Upper View promo lines live) and §6b added:
  guest-care info-item convention (ALLERGY / OCCASION / REQUEST / PICKUP /
  TASK) so requests, allergies and birthdays live on the Beds24 booking,
  readable by staff and the WhatsApp assistant — the managers' sheets remain
  a human double-check, not the master.

## v3.3 — 2026-11-11 · Pending pipeline everywhere, sheet sync, live CSV feed, guest reviews
- **Sheet reconciliation finished (Dec 2026 → Apr 2027):** the managers'
  UPPERVIEW RESERVATIONS sheet now matches the live system on every month.
  Two gaps the sheets exposed were closed with manual blocks (local + Beds24
  96018102/96018103): the Superior Booking.com stay's Dec 26–31 nights (the
  old block started Jan 1) and a Deluxe Booking.com stay Feb 11–13 — both
  invisible to the website until Beds24's Booking.com channel connects.
- **Pending requests recorded in Beds24 from every channel:** website unpaid
  requests now ALSO write a Beds24 'inquiry' (non-blocking, PENDING notes,
  REQUEST info item); admin got a "Record a pending request" form for email/
  phone/walk-in requests; b24_create_booking updates the SAME Beds24 row when
  a pending request is later confirmed (meta b24id_<code>), never duplicates.
  Verified live: inquiry 96018236 did not reduce the pool, confirm patched
  the same id, then the test was cancelled.
- **Live CSV feed** (`booking/calendar-feed.php?t=<token>`): one row per stay
  across all channels incl. PENDING, for the Google Sheets mirror via
  =IMPORTDATA(...) — Google refreshes it on its own. Same token as the
  WhatsApp picture link.
- **Guest reviews** (`/review/`, Andy's spec 2026-11-11): iPad-first kiosk —
  staff tap the guest (token mode lists recent Beds24 stays), 5 huge stars,
  staff-roster portrait tiles ("The whole team" + per-person; roster and the
  whole step togglable in admin → Guest reviews), a few words, then the
  finished review is EMAILED to the guest ready to paste, catered to where
  they booked (Airbnb / Booking.com instructions, Google for direct). Desk
  notification flags STAFF CREDITED for salary bonuses. Mock roster seeded
  with 6 Higgsfield portraits in white UPPER VIEW polos (replace via admin
  photo upload). Verified live end-to-end; test review deleted.
- **Thank-you comp (togglable, OFF by default):** staff-only kiosk panel
  lists the stay's DRINK:/MEAL: charges from the Beds24 invoice; comp =
  min(selected charges, admin limit) written as a negative invoice line; one
  comp per stay. COMPLIANCE (verified 2026-11-11): Airbnb and Booking.com
  prohibit incentives in exchange for reviews, so the comp is a
  staff-discretion hospitality gesture, never conditional on posting;
  emailing guests their own words to post themselves is allowed. Beds24 has
  a Booking.com review mirror endpoint (/channels/booking/reviews — answers
  once the channel is connected).

## v3.4 — 2026-11-11 · Two-night minimum stay (matching the portals)
Andy's rule: minimum 2 nights everywhere. (1) Beds24: minStay 2 written to
all four rooms for the whole seeded horizon (today → 2028-11-01, two writes
per room for the 365-day cap) and read back = 2 on every spot check — so the
rule will flow to every connected channel. (2) Website server-side: quote and
book both refuse ranges under 2 nights (400 "Minimum stay is 2 nights.").
(3) Website calendar: a checkout tap less than 2 nights after check-in is
refused with the message in all ten languages (p_min2); the Check button
double-checks. (4) Server config min_nights=2 (backed up first) so the admin
calendar ambers gaps shorter than the minimum. Verified live: 1-night quote
and book 400, 2-night quote returns rooms. Sonata asked to confirm its side
(its Beds24 rooms were created with minStay 2; its site uses CONFIG.minNights).

## v3.5 — 2026-11-11 · Live Google Sheets mirror + low-privilege feed token
calendar-feed.php now takes its OWN token (meta cal_feed_token): the token
sits inside a link-shared Google Sheet's IMPORTDATA formula where any viewer
can read it, so it unlocks NOTHING but the CSV (verified: 403 on the kiosk
guest list). The shared mirror sheet "NikiStay — LIVE Reservations (Upper
View + Sonata)" is live under ableabmanagement@gmail.com, anyone-with-link
viewer: UPPER VIEW tab imports the feed (verified: 16 stays, dates formatted,
pendings/blocks/Airbnb/Booking.com all present), SONATA tab awaits that
session's feed deploy. Google refreshes IMPORTDATA on its own (~hourly).

## v3.6 — 2026-11-11 · Instant sheet pings, kiosk locked to staff-picked guests
- **Near-real-time sheet mirror:** `sheet-ping.php` (uv_sheet_ping) fires a
  non-blocking call to the Apps Script web app (meta sheet_ping_url) after
  every booking-state change — pay hold, pending request, webhook confirm,
  admin block/cancel/confirm/request/refresh — so the shared Google Sheet
  re-pulls both feeds within seconds of an event; Google-side script
  "NikiStay live sheet sync" created under ableabmanagement@gmail.com
  (authorization + trigger pending Andy's one-time Allow). Sonata's feed is
  live in the SONATA tab.
- **Review kiosk, Andy's correction:** in kiosk mode the manual fields are
  gone — staff tap the guest, the details come from the booking and are not
  editable; the booking's email is never shown or edited (a note says the
  finished review goes there); only the optional EXTRA email on the final
  step is typed. Guests at home (no kiosk key) keep the manual form.
- **MOCK BOOKING UVMOCK01** (Beds24 96025641, clearly labelled, PAST dates
  Nov 6–10 so it can never block availability or reach the sheet feed) seeded
  with two labelled DRINK/MEAL invoice lines to demo the kiosk and comps.
- Beds24 support request filled in the help centre (convert funded trial,
  owner 181740, both properties); the final Submit click is Andy's.

## v3.7 — 2026-11-11 · Physical villa units: per-unit calendar + assignment optimiser
Andy's rule: the pool must display and manage the 8 PHYSICAL villas (Airbnb
sells each villa as its own listing; Booking.com-style bookings are placed by
us for optimal occupancy).
- Beds24 room 752258 already models this (units 1..8 "Villa 1".."Villa 8",
  per-booking allocation) — assignments live on the Beds24 booking (unitId),
  never in a side table.
- **Admin calendar** now shows Villa 1–8 as individual rows (+ an Unallocated
  lane), unit-aware dedupe (a pending request or another villa's stay no
  longer hides a feed event — fixed a bug that hid a real Airbnb stay), and a
  per-unit double-ASSIGNMENT alert plus the whole-pool overbook check.
- **units.php**: uv_unit_stays (Beds24 unitId + Airbnb feeds v2/v3/v4 =
  locked units), uv_assign_units — standard hotel room-assignment heuristics:
  pin Airbnb/in-house/<48h arrivals; assign flexible stays arrival-first,
  best-fit (back-to-back rewarded = same-day turnover with the 12:00→15:00
  cleaning window, capped per day via config max_turnovers_per_day default 4;
  orphan gaps shorter than the 2-night minimum heavily penalised; fill broken
  units before long open runs). uv_assign_apply writes unitIds to Beds24;
  uv_best_unit auto-places each new paid pool booking at write time.
- **Admin "Villa assignments" section**: preview proposed moves (guest,
  dates, current → proposed, reason) and one-click apply.
- Data fixes while verifying: the three manual Jan villa over-blocks had
  auto-landed on wrong units (fixed to 2/3/4), then — feeds now verified
  villa-for-villa against the managers' sheet — the three VILLA blocks were
  cancelled as redundant duplicates (they phantom-blocked a sellable night);
  the SUITE block stays (no feed covers suites). Per-villa free-night counts
  and pool availability for Jan 1–4 now match the manual sheet exactly.
- calendar-feed.php: new Unit column (Villa N) + refreshes the Airbnb feeds
  on every pull, so portal changes reach the sheet on their own.

## v3.8 — 2026-11-12 · Review page rebrand + 10 languages; mirror live end-to-end
- Review kiosk: real brand logo header (site palette), uppercase Jost
  headings, and a 10-language selector (EN/TH/简/繁/FR/ES/RU/DE/PL/HE with
  RTL) covering every guest-facing string; staff/comp panel stays English.
- Sheet mirror fully live: Apps Script web app deployed ("Anyone" access by
  Andy), sheet_ping_url stored on BOTH servers, test pings verified; grids +
  flat tabs rebuild on every booking event and on the 10-minute backstop.
- Daily sheet-vs-manual verification: identical on everything the manual
  sheet records, with the mirror AHEAD on a new Airbnb stay (Villa 3,
  Dec 24–30) — and a lingering "pending" row exposed a small bug, now fixed:
  abandoned checkout holds (>25 min) are expired in the calendar/feed path
  too, not only in the availability engine. Suite-naming mismatch flagged to
  settle with the managers (their Superior 1 / Superior 2 / Deluxe 3 vs our
  Superior / Deluxe / King).
- Beds24 verification email drafted (listing links for both brands); Sonata
  parity check complete — full parity, one documented horizon gap.

## v3.9 — 2026-11-12 · True replica mirror tabs; admin "Booking calendar"; language auto-detect
- The mirror sheet was rebuilt as a TRUE REPLICA of the managers' manual
  workbooks (Andy: a data mirror in a different layout is not a copy; the
  manual sheets will be retired). Per-month tabs for both brands — UV NOV
  2026…UV APR 2027 in the UPPERVIEW manual's layout (orange banner, blue
  weekday headers, tall room rows: Superior/Deluxe/King Suite + Villa # 1–8
  + unassigned) and SON Nov 2026…SON Apr 2027 in the Sonata/AKASHA manual's
  layout (AKASHA row kept, cyan Villa 1–3) — each with the manual's guest
  detail table (Source logos via favicons, Check-in/out, Villa Type, Nights)
  where Date Booked / Rate / Commission stay EMPTY until Beds24 leaves the
  trial. The old two GRID tabs are deleted; flat tabs remain.
- Apps Script hardening: Sheets applies merges lazily, so a failed merge of
  overlapping bars surfaced LATER and silently discarded queued writes (SON
  Dec lost Villa 3 + its detail table). Bars are now clipped to free days
  (first booking wins the shared day), every month flushes explicitly, and
  errors land in a SYNC LOG tab instead of vanishing.
- Admin: "Calendar" → "Booking calendar", with two links beneath it — the
  live automatic sheet and the manual NikiStay sheet. WhatsApp picture link
  UI removed (that is Yomied's own function; the key-rotation action remains
  as the kiosk key). Sonata asked to mirror all of this.
- Site: browser-language auto-detect on first visit (Sonata's detectLang
  rules: navigator.languages walked in order, zh-Hant variants → 繁, iw →
  he; a saved menu pick always wins and is the only thing saved) — app.js
  v=mn3.
- Cloudflare nameserver move EXECUTED for both domains via the Hostinger
  API (adelaide/yoxall.ns.cloudflare.com, propagation pending); proxy
  flip awaits Andy's direct yes in the Sonata session.

## v3.9.1 — 2026-11-12 · Sold-out room types no longer shown
Nobu's engine (verified live by the Sonata session) omits rooms that are
unavailable or too small for the party rather than greying them out. Upper
View now does the same: renderResults drops them (suites for 3+ guests
included — the Mini Villa card already scales to the party), with an
"Unavailable — Change dates" note when nothing is left. app.js v=mn4.

## v3.9.2 — 2026-11-12 · Kiosk-key management out of admin
The create/rotate button for cal_image_token (review kiosk + calendar
pictures) is removed from admin entirely — Andy: key generation is Yomied's
job via the Beds24 MCP. Admin only DISPLAYS the kiosk link while a key
exists; with none it says Yomied generates it.

## v3.9.3 — 2026-11-12 · One-backend migration opened
Andy approved a single shared engine for all sites. Cross-webroot include
proven live (/home/u357356777/shared-core/ reachable from the UV webroot);
docs/one-backend-plan.md records the mechanism, steps and rollback; UV's
21-file engine manifest sent to the Sonata session for the fork diff. Per-site
admin PAGES stay (Andy's call) — served by the one core, branded via each
site's data/config.php.

## v4.0 — 2026-11-12 · ONE BACKEND: both sites cut over to the shared core
Andy: "Go build and deploy the unified backend system." Done, live, verified:
- /home/u357356777/shared-core/ now holds every line of engine code:
  uv/ (this repo's engine), sonata/ (Sonata's engine, path-patched the same
  way), common/ (the merged superset, growing file by file). BOTH webroots'
  booking/ dirs are now 2-line stubs that define the site's data dir +
  webroot and require the shared file — zero logic in any webroot.
- Path seams for shared-core mode: uv_data_dir()/sn_data_dir() honour
  UV_SITE_DATA / SN_SITE_DATA from the stubs (webroot-relative fallbacks
  kept); admin staff-photo dir and Sonata's pay.php photo check use
  *_SITE_WEBROOT. Deployed as a no-op first, then cut over.
- First merged common modules: network.php (was already byte-identical) and
  sheet-ping.php (superset: Sonata's once-per-request guard adopted), with
  2-line profile shims defining the adapter (CORE_META_GET → uv_/sn_meta_get).
  This shim+adapter pattern is how every further file merges.
- Verified live on BOTH brands after cutover: real quotes with live pricing
  (UV ฿4,563/night villa; Sonata rates_confirmed + Stripe active), promos,
  calendar feeds, admin pages, webhooks answering 400 to garbage (not 500),
  calendar-image auth intact. Rollback copies: ~/backups/{uv,sonata}-booking-
  pre-core-20261112 (moved OUT of the webroots).
- Incident during rollout, caught and fixed within minutes: a malformed
  server-side stub write briefly broke 5 UV endpoint files (stripe, units,
  webhook×3); restored from the backup immediately, stubs redeployed via
  tar (binary-safe), everything re-verified. Payment webhooks were stubs of
  identical code before any real traffic window.
- Repo: site/common/ (merged modules) + shims in site/booking/;
  tools/deploy-core.sh deploys engine → shared-core (webroots get stubs
  only, never engine files). Sonata profile deploys from the Sonata repo.

## v4.1 — 2026-11-12 · AI marketing & agents spec; sheet manual fields; robots.txt
- New canonical spec docs/ai-marketing-and-agents_v1.0_2026-11-12.md +
  playbook v1.4 §12 (binding for every property): Yomied = internal agent
  only; external Guest Concierge on each website with auto welcome message +
  Live Help button from per-site config (live_help_phone, replacing the
  hard-coded number); AI email triage with human GO before send (auto-send
  per-property opt-in later); all outbound email logged for Yomied; public
  guest MCP design (narrow token, 25-min expiring holds, Stripe Checkout).
- Discovery phase 0 done: UV serves an explicit AI-crawler robots.txt
  (OAI-SearchBot/ChatGPT-User/Claude-SearchBot/Claude-User/Perplexity*/
  Googlebot/Bingbot; both sites previously 404'd). Sonata mirroring.
  Cloudflare-flip caveat recorded (default AI-crawler blocking).
- Mirror sheet: every month tab gains column 15 "Manual notes (yours —
  kept)" — staff corrections typed into the sheet survive every rebuild
  (harvest-by-key before clear, re-apply after; verified by writing a test
  note and syncing twice). Web app bumped to deployment Version 3 (same
  URL) so instant pings carry the preserving code — the prior version would
  have wiped notes. Steps toward retiring the manual workbook; the
  still-empty columns (Date Booked / Rate / Commission for OTA rows) fill
  automatically when the Beds24 trial lifts.

## v4.1.1 — 2026-11-12 · The link now OPENS on the manual view (3rd ask — done)
The mirror's default tab (what the share link lands on with no gid) is now
the CURRENT MONTH's manual-style grid (UV NOV 2026 today): month tabs lead,
raw data tabs (UPPER VIEW / SONATA) and SYNC LOG are pushed to the end as
bottom tabs. Verified by opening the plain share link in a fresh tab. Web
app bumped to deployment Version 4 (same URL) so booking pings keep this
order instead of re-shuffling it.

## v4.2 — 2026-11-13 · Yomied tools: vacancy optimiser + outbound-email log (MCP v1.2.0)
Contract negotiated with the now-online Yomied session ("Yomied 8 Newest",
assistant Sora). optimize_preview / optimize_apply with planId + stateHash:
DO IT can never act on a stale picture (apply refuses if any booking changed
since preview; 24h expiry; idempotent; actor audit-logged + provenance meta
per booking). Pins are law (Airbnb-locked / in-house / <48h / multi-villa /
portal entries; respectPins:false refused). Before/after vacancy metrics.
Outbound email: every engine/notify/MCP send now carries a generated
Message-ID and lands in the sent_emails table; new list_sent_emails tool
joins exactly against Yomied's IMAP ingestion of booking@ (email-triage GO
lives wholly in Yomied; sends route through our send_email so the log stays
the single record). units.php gains uv_stay_pin_reason / uv_assign_state_hash
/ uv_assign_metrics. All verified live including refusal + idempotency paths.
