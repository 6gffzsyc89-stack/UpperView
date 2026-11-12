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
2. Sonata session returns its manifest + what each differing file diverges on
   (brand constants vs real logic).
3. Assemble the parameterized core (every brand literal → config), lint on the
   server's PHP, both sessions review.
4. Cutover, one site at a time, in a quiet window with Andy aware: server-side
   backup of the old `booking/` dir first; rollback = restore that dir.
5. Review kiosk and future features (vacancy optimiser UI, Yomied endpoints)
   land in the core once, flagged per-site via config toggles.

## Not changing

Data stays per-site (`data/config.php` + `bookings.sqlite` per property —
separate Stripe accounts, separate Beds24 properties, separate secrets).
Front-end sites remain their own (brand) codebases.
