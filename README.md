# Upper View Samui

Asset and reference repository for the **upperviewsamui.com** website project.

Upper View is a property development in Koh Samui, Thailand. This repo holds the
brand documentation and photography used to design and build the website, and
serves as the handoff package for design work.

## Contents

### `brand/`
| File | What it is |
|---|---|
| `UPPER-VIEW-Brand-Guideline-V3.pdf` | Primary brand guideline (v3) — logo usage, colors, typography |
| `UV-Identity-2023.pdf` | Brand identity document (2023) |
| `UPPER-VIEW-Signage-Legal.pdf` | Signage and legal documentation |

### `site/`
The production website (static HTML/CSS/JS — no build step). `index.html` +
`app.js` + `i18n.js` (10 languages and the editable config: WhatsApp number,
booking email, booking-engine URL, address). `site/video/` holds the 12
Higgsfield-generated hero clips (6 desktop 16:9, 6 mobile 9:16), and
`site/brand/` the extracted logo PNGs. Deploy by uploading the `site/`
folder as the web root.

### `site/photos/`
142 web-resolution photographs from the May 2026 shoot, named
`upper-view-001.jpg` through `upper-view-142.jpg`.

Filenames were normalized from the originals for web use: the original
`Upper View - May 2026 (N).jpg` becomes `upper-view-NNN.jpg` (zero-padded,
same number). Example: `Upper View - May 2026 (76).jpg` → `upper-view-076.jpg`.

### `site/booking/`
The direct-booking engine (PHP + SQLite, no framework). Beds24 is the master
calendar (API v2, account-wide token); Airwallex hosted payment page takes the
card payment; `emails.php` sends the branded confirmation. Secrets live on the
server in `domains/upperviewsamui.com/data/config.php` — **outside the webroot
and never in this repo**.

### `site/mcp/` — Beds24 MCP server (remote, Streamable HTTP)
A single multi-property MCP endpoint at `https://upperviewsamui.com/mcp/` that
any MCP client (Claude custom connector, ChatGPT developer-mode connector)
can use. Auth is a token in the URL (`?k=…`); tokens and their roles
(`read` / `manage`) are defined in the server's `data/config.php` under
`mcp_tokens` — ask Andy for a connection URL, they are not in the repo.
Tools: `account_status`, `list_properties`, `get_calendar`, `get_availability`,
`list_bookings`, plus `create_block` and `set_prices` for `manage` tokens.

**KNOWN BLOCKER (2026-11-03): Hostinger CDN bot protection.** The CDN in
front of upperviewsamui.com serves a "Bot Verification" reCAPTCHA page to
non-browser clients, which blocks all MCP connector traffic (and has been seen
blocking real browsers too). The origin server itself answers fine when the
CDN is bypassed. Fix options, in order of preference:
1. hPanel → Websites → upperviewsamui.com → Performance → CDN → Manage →
   Security → disable bot verification (or set the lowest level). There is no
   API for this setting; it must be done in hPanel.
2. Or create subdomain `mcp.upperviewsamui.com` with DNS pointing directly at
   the origin server (not the CDN) and serve `site/mcp/` there.

Until one of these is done, MCP calls return HTTP 403 with a
"Bot Verification" HTML page.

## Related

- Live domain: [upperviewsamui.com](https://upperviewsamui.com) (registered via Hostinger)
- Sister domain: upperviewthailand.com
- Deployment: `scp`/`ssh` to Hostinger shared hosting (see `deploy.sh`);
  git history is the changelog.
