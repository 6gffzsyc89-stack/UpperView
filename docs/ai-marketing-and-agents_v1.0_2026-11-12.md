# AI marketing & agents — strategy and spec — v1.0 (2026-11-12)

Scope: every property (Upper View, Sonata, and any added later). This file is
the canonical spec for AI discovery/booking marketing AND the agent
architecture (Yomied internal, guest agent external, Live Help, email triage,
sheet manual-fields). Mirrored by pointer in the Sonata repo; the
new-property playbook §12 binds it for new sites. Research base: Andy's
compiled AI direct-booking plan (12 Nov 2026, sourced); claims we acted on
were re-verified live where marked.

## A. Agent architecture (Andy's spec, binding for all properties)

1. **Yomied = the INTERNAL agent.** Staff/owner only. Uses the private admin
   MCP + Beds24 MCP. Owns: key generation/rotation (kiosk & calendar-image
   keys — never an admin-page button), WhatsApp calendar pictures, the
   vacancy optimiser ("Optimize for Vacancies" chart + "Do it" applying unit
   moves through Beds24), ops mini-apps inside the Yomied admin software.
   Guests never talk to Yomied.
2. **Guest Concierge = the EXTERNAL agent.** Linked directly on every
   property website. Answers inquiries (availability, rates, style, FAQs,
   directions, policies) grounded ONLY in a public guest MCP (§C). On open it
   auto-sends a **welcome message**: a greeting plus "Need live help? Tap
   Live Help" — the Live Help button opens the property's human channel.
3. **Live Help number.** Today hard-coded in each front end (UV:
   `CONFIG.whatsapp = '66620132003'` in site/i18n.js; Sonata equivalent).
   Spec: it becomes per-site config (`live_help_phone`, optional
   `live_help_wa`), read by the welcome message and all contact links. New
   properties MUST use config from day one; existing hard-codes migrate when
   the concierge widget ships.
4. **Email triage (Andy 2026-11-12).** Inbound guest email is classified by
   AI, which drafts an auto-reply. A human reviews and presses GO before
   anything sends. When draft quality is proven, per-category auto-send can
   be enabled (config flag per property, default OFF — human review stays
   the default until Andy flips it).
5. **Outbound email log → Yomied.** Every email the system sends (and later,
   approved replies) is logged (who, when, booking ref, subject, body) and
   queryable by Yomied, so staff can answer "what did we tell this guest?"
   and spot special requests.
6. **Manual data stays first-class.** The automated sheet carries a
   staff-editable green "Manual notes (yours — kept)" column in every month
   tab, preserved across every sync (guest corrects by WhatsApp / in person →
   staff type it straight into the sheet). Yomied/admin notes also flow into
   the sheet's Ref/Notes column automatically. Goal: the manual NikiStay
   workbook retires completely.
7. **Fields that NikiStay fills by hand are automated data** (names, party
   size, amounts, booking dates transcribed from portal extranets). They
   auto-fill from the Beds24 API the moment the trial lifts; commission
   computes from per-channel % in config. Until then they stay empty by
   design (Andy 2026-11-12).

## B. Discovery strategy (phased; free first)

**Phase 0 — AI crawler access (DONE for UV, 2026-11-12).** Neither site had
robots.txt (404, verified live). UV now serves an explicit allow for
OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot,
Perplexity-User, Googlebot, Bingbot + allow-all (site/robots.txt). Sonata:
same file via its session. When the Cloudflare proxy flips, re-verify over
the network — Cloudflare blocks AI crawlers by default on new zones and can
prepend a managed robots.txt; its bot settings must whitelist the same
agents.

**Phase 1 — free visibility (Andy + sessions).**
- Tripadvisor: claim/complete both listings (dominates Grok/Perplexity
  citations).
- Google Business Profile: complete, style words identical to site/portals
  ("contemporary", "Balinese", "bohemian luxury", budget band) — most AI
  Mode hotel links route through the Business Profile.
- Bing: verify both sites in Bing Webmaster Tools, submit sitemaps, enable
  IndexNow, claim Bing Places (ChatGPT search leans on Bing's index).
- Reddit: honest participation in Koh Samui threads only; never fake posts.
- Review recency is the KPI: the review kiosk is the engine — recent
  detailed reviews outweigh lifetime score.
- Answer pages on both sites: style, seasonal price range, airport/beach
  distances, pool/family/pet rules, cancellation — short answers first, FAQ
  + Hotel schema markup.
- Published direct-rate promise (and a public promo code once promos switch
  ON) so price-checking agents find the direct rate wins.

**Phase 2 — the Guest Concierge + public guest MCP (§A2, §C).**

**Phase 3 — assistant distribution.** Submit the guest MCP: Claude connector
directory; ChatGPT apps; Grok custom connector (available to paid users
immediately); Muse last (US/CA only). Google Hotel/free booking links via
Beds24 — BLOCKED-UNTIL-VERIFIED: Beds24's wiki requires Beds24 to BE the
site's booking engine, which it is not (our own engine books; Beds24 is the
channel manager). Ask Beds24 support whether our API-write pattern
qualifies; do not rely on it.

**Phase 4 — measurement.** Monthly scripted prompt runs across ChatGPT,
Claude, Grok, Perplexity, Gemini (who gets named, which sources cited);
Bing Webmaster AI report + Search Console; paid tracker only if the free
loop shows traction. Reality check kept from the research: conversational
agents are <1% of Booking Holdings room nights — the free discovery work
and the on-site concierge carry most of the value today.

## C. Public guest MCP (design)

Separate remote server, separate narrow token — NEVER the admin MCP.
Tools: `search_availability` (read), `get_quote` (read, all-in price,
style/price-band/photo fields so assistants can match "Balinese under X"),
`get_property_info` (read, FAQs/policies), `create_booking` (25-min expiring
hold — same engine rule as the website), `get_payment_link` (Stripe Checkout
URL; card never touches the chat). Rate-limited; no guest PII beyond the
booking itself; bookings auto-expire unpaid exactly like website holds so
abandoned chats never block portal inventory.

## D. Division of labour

- UpperView session: UV site work, shared-core, this spec, guest-MCP build.
- Sonata session: Sonata mirror of every item (robots.txt, links, widget
  when built).
- Yomied session: internal mini-apps (optimiser UI, email triage queue +
  GO button, email log viewer, key management). Coordinate via
  cross-session messages; this doc is the contract.
