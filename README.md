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

## Related

- Live domain: [upperviewsamui.com](https://upperviewsamui.com) (registered via Hostinger)
- Sister domain: upperviewthailand.com
