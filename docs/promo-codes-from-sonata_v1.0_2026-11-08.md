# Promo codes and the promotion bar: how Sonata does it (for Upper View to match)

v1.0 · 2026-11-08 · written by the Sonata Samui session at Andy's request. Sonata's
implementation is live (Sonata CHANGELOG v2.7, commit 03b44fc in github.com/6gffzsyc89-stack/Sonata).
Andy asked that Upper View get the same: promo fields, discounts that change the rates when the
code is typed, and the promo + services carried onto the invoice and into Beds24 so staff and the
WhatsApp agent can see them. Nothing here has been changed in Upper View's code yet.

## Andy's rules
- Promotions are run by **our own website and its invoices, not by Beds24**. Beds24 only
  receives the resulting booking with the code and what it gives.
- Codes are **CAPITAL letters/digits** (e.g. `TRANSFER26`), set on the admin page.
- A promo either gives a **% discount** (shows in the rates as soon as the code is typed) or an
  **included service** (e.g. a free airport transfer).
- The **invoice must show everything**: the code and what it does.
- A **promotion bar at the top** like thehoxton.com: one line; still when it fits, scrolling when
  it is too long; shows the promotion text and its code.

## What Sonata built (files are in the Sonata repo, `site/` and `site/booking/`)
1. **Database** (`lib.php`): table `promos(code PK, title, kind percent|service, percent, service,
   valid_until, min_nights, banner, active, created_at, updated_at)`; bookings gained
   `promo_code, promo_note, gross, discount` (amount stays the NET price actually charged).
   Helpers: `sn_promo_code_clean`, `sn_promo_find(code, ci, co)` (inactive / expired / too few
   nights → reason), `sn_promo_note` ("10% off the room rate" or "Included: Airport transfer on
   arrival"), `sn_promo_discount(promo, gross)` (whole baht), `sn_promos_banner()`.
2. **API** (`api.php`): `GET ?action=promos` (public: code, title, note for the bar);
   `quote&promo=CODE` returns per room `gross`, `gross_nightly`, discounted `total`/`nightly`,
   plus `promo` or `promo_error`; `request` re-validates the code server-side, stores it on every
   row of the reservation and refuses a bad code with the reason.
3. **Admin** (`admin.php`): Promotions section: code (forced to capitals as you type), top-bar text,
   gives (included service / % discount), valid until, minimum nights, show in top bar; Pause,
   Show/Hide in top bar, Delete; a Promo column on the bookings table.
4. **Website** (`index.html`, `app.js`): `#promobar` above the fixed header (header moves down
   36 px while it shows); items "Text · Use code CODE"; JS measures the line and duplicates it
   with a CSS marquee only when it overflows; click → opens Reserve with the code filled in.
   Reserve step 1 has a "Promo code" field; results show the old nightly rate struck through and
   the new one; "Code X applied — …" note. New phrases in all ten languages.
5. **Checkout + invoice** (`pay.php`, `emails.php`): villa lines at the gross (direct) rate, then
   "Promo code X — what it gives" with −discount or "Included", then the total. Desk emails carry a
   "PROMO CODE:" line. Stripe line description and metadata include the code.
6. **Beds24** (`beds24.php` `b24_booking_payload`, written on confirmation): notes gain
   `PROMO: CODE — what it gives (−N THB on this villa)` and, for services,
   `SERVICE TO ARRANGE: …`; the guest's own message goes in `comments`; `infoItems:
   [{code:'PROMO', text:'CODE — what it gives'}]`; invoice lines: villa charge at the gross
   rate, a promo charge line (negative discount, or 0 for a service), and the payment when paid.
   (Before this, unpaid manual confirmations wrote no invoice lines.)

## For Upper View to do (in the Upper View session)
- Port the same pieces with `uv_` names. Upper View already has a "Promo code" input in the
  reserve panel (`#uv-promo`) that is not wired to anything yet, and room cards that can show a
  struck-through price.
- Upper View sells mini villas, studios and suites; apply the % to each room line the same way.
- Add the PROMO / SERVICE TO ARRANGE lines to `docs/BEDS24-MCP-README.md` §6 for Upper View
  bookings too (§6 now notes that Sonata uses them).
- Test with a hidden code (top bar off), then delete it, so no working test discount is left live.
