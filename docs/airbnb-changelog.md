# Airbnb manual-change log

Every manual change made to Airbnb listings is recorded here so it can be reverted.
Rule: **the Mini Villa #4 listing (1810117481404650858) is the template — it is never modified.**

| Date | Listing | Change | How to revert |
|---|---|---|---|
| — | — | (no changes made yet) | — |


## As-is snapshot before villa listing creation (2026-11-06, revert reference)

Airbnb account listings (all "Listed"), exactly as found before any new
listings were created:
1. Akasha: 2-BR Luxury Showcase Villa w/Stunning Pool — "Akasha (2BR stay)"
2. Brand New Unique Designer 2BR Sonata Pool Villa #2 — "Sonata Villa Two"
3. New Stunning Designer Lux 2BR Sonata Pool Villa #1 — "Sonata Villa One"
4. Sonata Villa #3 New Designer Luxury 3BR Pool Villa — "Sonata Villa Three"
5. Upper View Brand New Mini Villa#2 Stunning Seaview — "Upper View Villa 2"
6. Upper View Brand New Mini Villa#3 Stunning Seaview — "Upper View Villa 3"
7. Upper View Brand New Mini Villa#4 Stunning Seaview — "Upper View Villa 4" (TEMPLATE — NEVER modified)

CORRECTION 2026-11-06: id 1810117481404650858 is VILLA #2 (verified in its
listing editor: title "Upper View Brand New Mini Villa#2"), not the template.
Template #4's real listing id still to be captured. Villa 2 editor-verified
settings: ฿4,875/night (OTA low rate), max 2 guests, House, 2–365 nights,
auto-approve, 40% monthly discount.
8. Villa Akasha Massive Designer Luxury 3BR Pool w/BF — "Akasha 3-bedroom"

Upper View villas 1, 5, 6, 7, 8: DO NOT EXIST yet (to be created by
duplicating the template; any listing created after this snapshot can be
unlisted/deleted to revert to exactly the above).

Beds24 prices as found (price1 = doc OTA rate, verified to the satang):
Mini Villa 4875/6337.5/7000, Superior 4375/5687.5/6250,
Deluxe 4625/6012.5/6625, King 4375/5687.5/6250 (low/high/peak).
Website multiplier changed 0.90 -> 0.72 on 2026-11-06 (Andy's rates doc:
website must charge Direct = base -10%; Beds24 price1 holds OTA = base +25%).
Revert = set direct_multiplier back to 0.90 in data/config.php.
