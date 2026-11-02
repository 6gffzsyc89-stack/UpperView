# Upper View Samui — Master Room Content
One master copy for all portals (Booking.com, Airbnb, Agoda, Trip.com) and the
website reservation flow. Beds24 syncs rates/availability/bookings only;
descriptions and photos are applied per portal FROM THIS FILE.
Website is authoritative for sizes/positioning; Airbnb/Booking.com wording is
the authoritative marketing copy (per Andy, 2026-11-02).

## Room types (4) — mirror of Beds24
| Type | Beds24 room | Size | Guests | Bed | Notes |
|---|---|---|---|---|---|
| Mini Villa (x8, "Mini Villa 1..8") | 752258 (qty 8) | 40 m² + private 40 m² rooftop deck | 2 | 1 king | identical photo set for all 8 |
| Superior Suite with Sea View | 752259 | 50 m² | 2 | 1 king | main house, medium suite |
| Deluxe Suite with Sea View | 752304 | 40 m² | 2 | 1 king | main house, smallest suite |
| King Suite with Sea View | 752305 | 60 m² | 2 | 1 king | main house, largest suite |

Suites: king bed, living-room set, main house with lobby lounge + restaurant
one flight down, direct pool access down the stairs. NO kitchenette.

## Authoritative mini-villa copy (from Airbnb listing, verbatim)
Title pattern: "Upper View Brand New Mini Villa#N Stunning Seaview"

> Wake up above Koh Samui with panoramic views across the Gulf toward Koh
> Phangan in your own mini-villa with unobstructed sea views. Includes access
> to clubhouse lobby lounge, pool, gym.
> ♥ Private 40 sqm rooftop deck w/ sun loungers
> ♥ Brand new 40 sqm mini villa
> ♥ Bathtub facing the sea
> ♥ Shared infinity-edge lap pool w/ panoramic views
> ♥ Brand-new strength and cardio gym
> ♥ Clubhouse building w/ spacious lobby lounge
> ♥ Peaceful north-coast hillside setting
> ♥ twice-weekly housekeeping included

Address: Bophut Hills Soi Pho Tha 1, Bo Phut, Surat Thani 84320.
Airbnb settings seen: 2–365 nights, min 2 nights, 2 days advance notice,
auto-approve, ฿4,875/night (low), 40% monthly discount, 2 guests.

## Portal state (2026-11-02)
### Booking.com (hotel_id 17342990, SATU IN SAMUI CO.,LTD group login)
- Public room names: Superior/Deluxe/King Suite with Sea View, Bungalow.
- Extranet internal rooms: Upperview Room 1 (1734299001=Superior),
  Upperview Room 3 (1734299002=Deluxe), Upperview Room 2 (1734299003=King),
  Upperview Villa 4 (1734299004=Bungalow).
- !! Every room has "Number of this type: 1" — Bungalow must become 8
  (awaiting Andy's go-ahead; changes live inventory).
- Photo gallery: 42 photos, ALL main-house/suite shots, 32 untagged,
  1 unit without photos. NO mini-villa photos -> must add from the 142.
- Upcoming bookings handled manually by staff: Thorpe Rebecca (Room 1,
  26 Dec–4 Jan), Busisiwe Mabuza (Room 3, 9–11 Jan).

### Airbnb (co-host Niki Stay, owner Pui)
- Upper View listings: Mini Villa #2 (1810117481404650858), #3, #4 only.
  Each individual, 75 photos, full description (above).
- Missing: Villa #1, #5–#8, and all three suites. (Decision needed: add as
  individual listings or wait for Beds24 Airbnb API connection.)

### Agoda (property 98939846, in review 1–3 days)
- 4 room types created w/ correct sizes 50/40/60/40 + rates.
- 42 photos auto-imported from Booking.com (all main house).
  TO DO when content unlocks: remove lobby/plant photo (SW corner shot),
  add villa photos, per-room photo sets, descriptions from this file,
  mini villa units numbered 1–8, close dates before 2026-12-20,
  DSA form (Andy), bank payout (Airwallex details).

### Website (authoritative for sizes/positioning)
- Reservation flow room photos to use per-suite groups below.

## Photo grouping (from the 142 originals in site/photos/)
Villa set (identical for Mini Villa 1–8): exteriors 068–072, interiors/bamboo
ceiling 079–098 selects, evening 106–111, rooftop deck 073–078, 132–134.
Website villa picks: 106, 072, 133, 131.

Suite groups (PROPOSED — Andy to confirm):
- Warm-wood suite w/ pantry + day bed (photos 037–050) -> Superior (50 m²).
- Gray-marble suite w/ balcony bathtub (photos 051–067) -> King (60 m²).
- Deluxe (40 m²): remaining main-house room shots (subset of 037–050 group,
  TBD — photographer bulk groups to be split with Andy's help).
- Booking.com gallery photo-id -> local-file matching: TBD (42 ids recorded in
  session; match via download+hash when organizing uploads).

## Payments / checkout (in progress)
- Airwallex (Crown Faith HK, logged in): create restricted API key ->
  server config; descriptor must show "Upper View Samui" prominently.
- Checkout mirrors Nobu (desktop separate page, mobile has own flow):
  rooms grouped by rate, card fields on page, Price Details panel
  (room line, nights, taxes & fees, total incl. taxes), policies text,
  acknowledgement checkboxes, CONFIRM BOOKING.
- Confirmation email: Nobu-standard, high-end, embedded invoice (room,
  dates, nightly breakdown, taxes, total), property description, arrival
  instructions, booking code.
- Fallback engine: local iCal + Airwallex (config switch beds24.source).
