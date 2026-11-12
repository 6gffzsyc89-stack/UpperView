# One backend — migration plan (approved by Andy, 2026-11-12)

Decision: a single shared booking engine serves every site; each website keeps
its own admin page and its own front end. "No possibility of code drift and
the ability to add more websites and properties."

## Mechanism (proven live 2026-11-12)

Both domains live under the same Hostinger account (u357356777), and web PHP
may `require` from the account home: a test file in `/home/u357356777/shared-core/`
was included successfully from the UV webroot. Therefore:

- `/home/u357356777/shared-core/` — the ONE engine: lib, api, beds24, calendar,
  units, pay, stripe/airwallex + webhooks, emails, calendar-feed/-image,
  sheet-ping, network, admin, review app. One copy, no per-site forks.
- `domains/<site>/public_html/booking/*.php` — two-line stubs only:
  `define('SITE_DATA', '/home/u357356777/domains/<site>/data'); require '/home/u357356777/shared-core/<file>.php';`
- `domains/<site>/data/config.php` — everything per-site: brand name, domain,
  mail addresses, Beds24 property/room ids and unit map, feature toggles,
  palette bits the engine needs. The engine reads ONLY from here for brand
  differences; adding a website = new data dir + stubs + Beds24 property.
- Admin remains a per-site PAGE (per Andy) — same shared admin.php, branded
  and scoped by the site's config.
- Git: the UpperView repo's `site/booking/` becomes the canonical core source;
  deploys go core → shared-core/. A parity line in each admin footer shows the
  core's version hash, so drift (which should now be impossible) is visible.

## Steps

1. DONE — mechanism test; UV file manifest (sha256, 21 engine files + review
   app) sent to the Sonata session.
2. DONE — Sonata's manifest + fork diff (2026-11-12): after normalising
   prefixes and brand strings, ONLY network.php is identical; the rest is real
   logic divergence (api 1020 differing lines, admin 709, pay 455, lib 406 …).
   The forks are two engines: UV = unit-pool villas + optimiser + fixed
   pricing; Sonata = individual villas with capacities/combos/group bookings +
   per-date rates table (Airbnb × 0.72) + a consolidated confirm.php.
   DECISION (both sessions): the core is the SUPERSET with per-site toggles —
   villa_model 'pool'|'individual', pricing 'fixed'|'table', features flagged —
   with Sonata's single-path confirm.php as the confirm spine and its styled
   emails as the shared baseline. Beds24 stays the inventory master; the core
   is the storefront engine on top of it. Stripe + Airwallex both live in the
   core; payment_provider remains a per-site admin choice with per-site keys.
   Andy 2026-11-12: our own engine code is kept permanently (git history +
   live repo) in case we ever leave Beds24 — so the core keeps ALL Beds24
   coupling isolated behind beds24.php + the OTA-feed layer, a seam another
   channel manager (or none) could replace without touching the engine.
3. Assemble the superset core in this repo under core/ (merge order smallest-
   first: sheet-ping, ical-export, calendar-feed, network-local, webhooks,
   calendar-image, then lib/api/pay/admin+confirm as a designed unification),
   lint on the server's PHP, Sonata session reviews each tranche.
4. Cutover, one site at a time, in a quiet window with Andy aware: server-side
   backup of the old `booking/` dir first; rollback = restore that dir.
5. Review kiosk and future features (vacancy optimiser UI, Yomied endpoints)
   land in the core once, flagged per-site via config toggles.

## Not changing

Data stays per-site (`data/config.php` + `bookings.sqlite` per property —
separate Stripe accounts, separate Beds24 properties, separate secrets).
Front-end sites remain their own (brand) codebases.
