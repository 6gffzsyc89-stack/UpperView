# Andy's open requests — tracker

Working log. Updated by Claude Code as items progress. (2026-11-03)

| # | Request | Status |
|---|---|---|
| 1 | MCP address: upperviewsamui.com/mcp or mcp.upperviewsamui.com | **DONE** — live at `https://upperviewsamui.com/mcp/?k=…` after CDN bot-shield lowered. Subdomain optional, not created (Hostinger API outage). |
| 2 | Booking.com photo cleanup (each suite keeps its 6) | **BLOCKED on Andy** — extranet session expired; tab sits at SMS verification. Room 3 selection must be redone after login, then Rooms 1 & 2. |
| 3 | Beds24 MCP not working | **DONE** — cause was Hostinger CDN bot verification; Andy set CDN security to low; verified end-to-end. README documents it for Yomied. |
| 4 | Airwallex: Pinkai payment activation approved? | **BLOCKED on Andy** — tab waits at "confirm in Airwallex app" 2FA. Application was "under review (1–2 business days)" at submission. |
| 5 | Hero video comparison / furnished aerial | **DONE** — desktop `ae92da70.mp4` + mobile `60386f15.mp4` live; Kling pinned to the July originals' first/last frames (furnished, stacks removed); QC frame-by-frame passed; comparisons in Andy's Downloads. No single merged MP4 (Andy's decision). |
| 6 | Listings consistent from Beds24 on all sites | OPEN — Beds24 control panel tab is at its login page; needs Andy's Beds24 login (or channel audit via MCP read tools where possible). |
| 7 | Airbnb Mini Villa #4 as template for villas 1–3, 5–8 | OPEN — template listing exists; other villa listings not yet created. Template itself must NEVER be modified. |
| 8 | Same listings/photos on Booking.com, Agoda, Trip.com (per-villa, room, building/facility photo sections) | OPEN — Agoda logged in (3 tabs); Booking.com needs SMS login; Trip.com no account tab yet. Prefer Beds24 as single source where supported. |
| 9 | Airbnb: single listing with pooled mini-villa inventory (via Beds24?) | **ANSWERED (confirmed from Beds24 wiki, 2026-11-03)** — YES, possible: Beds24's Airbnb connection "supports room types with a quantity of more than one", BUT (a) the Airbnb property type must NOT be "House"; (b) once qty>1 is ever sent the listing becomes multi-unit permanently (revert requires disconnecting it from Beds24); (c) a pooled listing takes ONE Airbnb search slot vs 8 slots for separate listings; (d) hotel-style listings are approved by Airbnb case-by-case. Alternative: 8 separate listings (template #4 duplicated) kept overbooking-safe with Beds24 Dependencies. AWAITING ANDY'S ARCHITECTURE DECISION before creating any listings. |
| 10 | Suite sizes 60/50/40 sqm on all platforms | IN PROGRESS — website first, then Airbnb/Agoda/Booking.com/Trip.com. |

Airbnb manual-change log lives in `docs/airbnb-changelog.md` (revert reference).
