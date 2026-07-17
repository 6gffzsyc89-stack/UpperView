# Design Brief: Upper View Samui — upperviewsamui.com

**Project:** Full website design for Upper View, a boutique hotel in Koh Samui, Thailand. The live domain will be upperviewsamui.com. **Mobile-first is non-negotiable — the site's primary job is converting mobile visitors into bookings.**

## Design direction

Two reference brands, each contributing something specific:

- **W Hotels** (whotels.com) — the typography and color palette direction. Study their current live site rather than working from memory.
- **Nobu Hotels** (nobuhotels.com) — the interaction model: a **persistent "Reserve Now" button** that stays visible at all times as the user scrolls, plus their smooth, cinematic scroll transitions and animation feel. Study their current live site for how the persistent CTA behaves on mobile.

**Important hierarchy:** the Upper View brand guidelines (in this repo) are the source of truth for logo usage and brand identity. W Hotels is a *mood reference* for type and color. Where the brand guideline explicitly conflicts with the W Hotels-inspired direction, flag the conflict and ask Andy rather than silently choosing.

## Assets — this repo

- `brand/UPPER-VIEW-Brand-Guideline-V3.pdf` — primary brand guideline (logo, colors, typography rules)
- `brand/UV-Identity-2023.pdf` — brand identity document
- `brand/UPPER-VIEW-Signage-Legal.pdf` — signage/legal document
- **One of these PDFs contains the hotel's address in both English and Thai — use both on the site** (contact section and footer).
- `assets/photos/` — 142 web-resolution photos from the May 2026 shoot (`upper-view-001.jpg` through `upper-view-142.jpg`). These are the design references and the site's image library.

## Hero video

The site opens with a full-screen hero video. Andy is generating it with Higgsfield (AI video generation), so the design should include a proper hero video slot with mobile and desktop aspect ratios specified. The video will emphasize, in order: **the view, the room types, the facilities** — with perfectly smooth transitions in the style of the Nobu Hotels site. Design the hero assuming this video exists; specify the ideal resolution, aspect ratios, and duration so the video can be generated to fit.

## Property facts (site content)

**Accommodation:**
- **8 Mini Villas** — 50 m² each, and each has its own **50 m² private rooftop deck**
- **3 Studio Rooms** — 40 to 60 m², located in the main building

**Facilities:**
- Full gym (main building)
- Spa, **by appointment** (main building)
- **Stomping Grounds** — the hotel's café, serving trendy French-Thai fusion café food: cruffles, pancakes, salads, juices, and smoothies

## Feature specifications (verified against the live reference sites, 2026-07-17)

### 1. Persistent Reserve button + booking panel (mimic Nobu exactly)

Verified live on nobuhotels.com:

- **Desktop:** "RESERVE" button fixed top-right of the header, visible at all times. Clicking it slides a panel in **from the right edge** (covering roughly the right third of the screen, page content remains visible behind it). Panel heading: "Reserve Today". Fields, stacked vertically with thin underline styling: Location (hotel picker — **omit for Upper View, single property**), Arrival (check-in date), Departure (check-out date), Rooms & Guests, Promo code. Full-width black "CHECK AVAILABILITY" button at the bottom.
- **Mobile:** the Reserve button becomes a **full-width sticky bar pinned to the bottom of the viewport**, persisting over all content while scrolling. This is the single most important conversion element on the site.
- **On submit:** Nobu opens its booking engine in a **new tab** (reservations.nobuhotels.com — a branded skin of the SynXis/Sabre booking engine, passing hotel code, chain code, dates, and guests as URL parameters).
- **For Upper View:** build the same panel UI. The Check Availability handoff target depends on which booking engine/channel manager the hotel uses — **open question for Andy** (e.g. SiteMinder, Cloudbeds, Little Hotelier, Beds24, or direct-inquiry-only). Design the panel so the handoff is a swappable link/integration.

### 2. Persistent "Need Help?" contact tab (Locke-style, wired to email + WhatsApp)

Reference: lockeliving.com uses a **vertical tab fixed to the right edge of the viewport** that persists while scrolling (as of 2026-07-17 their desktop tab is labeled "Sign up & save" — replicate the pattern, not the label).

For Upper View:

- A vertical **"Need Help?"** tab fixed to the right edge, persistent during scroll, on every page.
- Clicking it opens a small panel with two contact options:
  1. **Email** — mailto link or short inquiry form
  2. **WhatsApp** — opens WhatsApp with a pre-filled booking inquiry aimed at the hotel's WhatsApp number
- **WhatsApp deep link format (official click-to-chat):** `https://wa.me/<number>?text=<url-encoded message>` — number in international format, digits only (no +, spaces, or dashes). On a phone this launches the WhatsApp app; on desktop it opens WhatsApp Web. Example: `https://wa.me/66XXXXXXXXX?text=Hello%20Upper%20View!%20I%27d%20like%20to%20inquire%20about%20a%20booking.`
- **Andy will supply the WhatsApp number** — build it as an easily editable config value.

## Image generation (Higgsfield — you drive it)

You have direct access to Higgsfield via its MCP connector. Use it to generate any imagery the design needs beyond the photo library: the hero video, ambience/lifestyle fill shots, textures, and backgrounds.

Rules:
- The 142 real photos remain the primary library for anything depicting the actual property — rooms, villas, views, the café. **Never use AI-generated images to represent real rooms or spaces guests will book** — that misrepresents the product. AI imagery is for mood, ambience, texture, and the hero video only.
- **Do not pull images from W Hotels, Nobu, Locke, or any other site** — emulate their layout, typography feel, and interaction patterns only; never their photos, logos, or trademarked elements.
- Higgsfield generation costs credits. Confirm with Andy before any large batch (more than a handful of images, or any video generation).

## Practical notes for studying the reference sites

- Reference sites: https://www.nobuhotels.com, W Hotels (https://w-hotels.marriott.com), and https://www.lockeliving.com
- All three show cookie-consent banners on first load that block interaction. Decline/close them (use the X or a "decline" option rather than Accept) — the sites work fine afterward.

## Priorities, in order

1. Mobile booking conversion — persistent Reserve Now button, frictionless path to booking
2. Brand fidelity per the guideline PDFs
3. W Hotels-caliber typography and color, Nobu-caliber motion and transitions
4. Showcase the view, the rooms, and the facilities through the photo library and hero video
