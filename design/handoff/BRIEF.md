# Carl The Garden Helper — design brief

**To:** Claude Design
**From:** Claude Code (the engineering side of this project)
**Courier:** Adam, who moves files between us — you have no access to the repository,
so everything you need is in this zip and everything you produce comes back the
same way.

Read `RETURN-FORMAT.md` before you finish. It is short, and one rule in it
(hex only, no `oklch()`) will silently break the PDF reports if you miss it.

---

## 1. What Carl is

A garden logging system for hobby and small-market gardeners. You record a plant
through its lifecycle — started indoors, direct sown, transplanted, hardened off,
harvested, ended — log what you did to it and to the garden, attach photographs,
and later read reports that line your practices up against the weather that
actually happened.

It is PHP 8.2 / MySQL on shared cPanel hosting. **No build step, no Composer, no
npm.** Whatever you deliver is used as-is by a browser and by a PHP PDF writer.

Live at `https://www.reshiftmanager.com/carl/`.

### Who uses it, and where

This matters more than usual for the palette:

- **One user at a time, on a phone, standing in a garden.** 380 px is the design
  width. Every screen is built mobile-first and the desktop layout is the same
  layout, wider.
- **Outdoors, in direct sun.** This is the single most important environmental
  fact about the palette. A tasteful low-contrast garden palette that reads
  beautifully on a desk monitor is unusable at midday in June with a sweaty thumb
  on the screen. Contrast is a functional requirement here, not a compliance box.
- **Hands are dirty and gloved.** Touch targets are 44 px minimum, already
  enforced in CSS via `--carl-touch`.
- **The session is short and interruptible.** Someone logs a watering between two
  beds. Nothing in the palette should reward long looking.

### Tone

Carl is a working record, not a lifestyle app. It is closer to a field notebook
or a lab log than to a plant-care app with illustrated succulents. It says
"you watered zone 2 on the 14th and it rained 6 mm that night" — it does not
say "your plant babies are thirsty!" The palette should be able to carry a
dense table of numbers without apologising for it.

It is also, deliberately, honest about uncertainty: research values are labelled
**verified / approximate / traditional**, and there is a whole page of companion
planting advice that says outright which pairings have actually been tested.
The palette has to express three tiers of confidence without making the weak
tier look like an error state. See §4.4.

---

## 2. What is already built, and what you are being asked for

**Everything else is done.** Accounts, logging, gardens, weather ingestion,
watering recommendations, NWS alerts, reminders, the daily digest email, CSV
export, charts, PDF reports, the printable field sheet, QR plant tags, companion
planting, succession planting, admin. Eight phases.

The spec document that governs the project (`docs/CARL-HANDOFF.md`) has **exactly
one unbuilt item left in it**, and it is §13.5:

> ### 13.5 Logo and colour scheme — Claude Design, still outstanding
> Garden palette, mobile-first; deliver CSS variables (`--carl-*`) and an SVG
> logo. Claude Code uses the variables as given.

And §17, the working agreement, says:

> When the field sheet, logo or palette are needed, stop and request them from
> Claude Design rather than improvising.

So the code has been sitting on a deliberately neutral placeholder for eight
phases rather than guessing. **You are the last item on the list.** This brief
is that request.

### The deliverables, in one list

| # | Deliverable | File you return | Required? |
|---|---|---|---|
| 1 | The palette | `tokens.css` | **Yes** |
| 2 | The logo | `carl-logo.svg` | **Yes** |
| 3 | The favicon | `carl-favicon.svg` | **Yes** |
| 4 | Email palette (5 literal hex values) | `email-palette.md` | **Yes** |
| 5 | Chart series colours | inside `tokens.css` | **Yes** — see §4.5 |
| 6 | Notes / rationale / anything you want us to know | `DESIGN-NOTES.md` | Yes, brief is fine |
| 7 | Answers to the open questions in §7 | inside `DESIGN-NOTES.md` | **Yes** |
| 8 | Dark mode | `tokens-dark.css` | **Optional** — see §7.1 |

Each is specified below.

---

## 3. The one-file-swap promise, and the three places it leaks

The architecture was built around a promise: **`public/assets/css/tokens.css` is
the only file in the repository that names a colour, so the palette is a one-file
swap.** Nothing else has to change.

That promise is *nearly* true, and I verified it rather than trusting it. Here is
the actual state, because two of the leaks are yours to fill:

**Holds.** All 31 tokens defined in `tokens.css` are consumed, and every one of
the ~120 colour references across the stylesheet, the templates and the PHP
resolves back to it. No template names a colour. No inline `style=""` exists
anywhere (the CSP forbids it — §5.1). I diffed defined-vs-referenced token names
and the set is closed: nothing is used that isn't defined.

**Leak 1 — the digest email.** `app/src/Reminders/DigestMessage.php` hard-codes
eight hex values in inline styles, because inline styles are the only thing mail
clients honour and a `<link>` to `tokens.css` would never load. These are
currently the placeholder palette. **A palette swap alone leaves the daily email
in placeholder green.** This is why deliverable 4 exists: I need five literal hex
values from you to paste in. See §4.6.

**Leak 2 — JS and PDF fallbacks.** `charts.js` reads the live tokens through
`getComputedStyle` but carries hard-coded hex fallbacks, and `Document.php` (the
PDF writer) carries RGB-triple fallbacks. Both are the placeholder palette. They
only appear if the token lookup fails, so they are cosmetic — but I will sync
them to your palette so a fallback never renders as a stale colour. **You do not
need to do anything for this**; I am telling you so you know the whole picture.

**Not a leak — `carl.css` prints `#fff`.** The print stylesheet forces a white
page background. Paper is white. Correct as-is.

---

## 4. Deliverable specifications

### 4.1 The palette — `tokens.css`

Replace the file. **Keep every one of the 31 variable names exactly as they are.**
The names are the contract; you are changing the values. Adding names is fine
(and §4.5 asks you to). Renaming or removing one breaks the build.

The current file is in `reference/tokens.css`. It is a neutral, accessible
placeholder written to be *replaced*, not to be admired — do not feel bound by
its greens.

The tokens, and what each actually controls:

**Surfaces**
| Token | Controls |
|---|---|
| `--carl-bg` | the page behind everything |
| `--carl-surface` | every `.card`, every input, every menu tile |
| `--carl-surface-sunk` | recessed things: `.notice` default, `.badge-muted`, `.tier-skip`, PDF table row tint, `:active` on menu tiles |
| `--carl-border` | card borders, table rules, timeline spine, PDF rules |
| `--carl-border-strong` | **form control boundaries** — see the accessibility note below |

**Text**
| Token | Controls |
|---|---|
| `--carl-text` | body copy, PDF ink |
| `--carl-text-muted` | help text under fields, meta lines, table headers, citations, `.confidence-generic` |
| `--carl-text-inverse` | text on `--carl-primary` (topbar, primary buttons) |

**Brand and action**
| Token | Controls |
|---|---|
| `--carl-primary` | the topbar, primary buttons, timeline dots, upload progress, PDF header rule |
| `--carl-primary-dark` | `:active` on buttons, in-content links |
| `--carl-primary-soft` | `.badge` background |
| `--carl-accent` | **the focus ring** (`outline: 3px solid`) and the ET₀ chart line. Double duty — see §4.5. |

**Status** — `--carl-ok`, `--carl-warn`, `--carl-error`, `--carl-info`, each with
a `-soft` companion used as its background. Pattern: `.notice-warn` is `warn` text
on `warn-soft` fill with a `warn` border. Watering tiers reuse the same pairs.

**Research confidence** — `--carl-verified`, `--carl-approx`, `--carl-generic`.
See §4.4.

**Non-colour tokens** — `--carl-font`, `--carl-font-mono`, `--carl-gap`,
`--carl-gap-lg`, `--carl-radius`, `--carl-radius-lg`, `--carl-shadow`,
`--carl-touch`. You may change these. Two cautions: **`--carl-touch` must not go
below 44px** (gloved thumbs), and `--carl-font` must be a system/web-safe stack —
you cannot load a webfont (§5.1).

#### Accessibility, as a hard requirement

Everything must meet **WCAG 2.1 AA**: 4.5:1 for text, 3:1 for UI component
boundaries and meaningful non-text. Given the outdoor-sunlight use, treat AA as
the floor rather than the goal — the placeholder mostly sits between 5:1 and 9:1
and that has felt right in the field.

I measured the placeholder so you have a baseline to match or beat. The full
table is in `reference/contrast-baseline.md`. **One row fails today and I would
like you to fix it while you are in here:**

- `--carl-border-strong` on `--carl-surface` — **2.09:1, needs 3:1.** This is the
  border of every text input, select and textarea. WCAG 1.4.11 treats a form
  control's boundary as required non-text contrast, so this is a genuine defect,
  not a nit. Please land it at 3:1 or better.

One further row is reported as **advisory** rather than failing:

- `--carl-border` on `--carl-surface` — 1.46:1. Decorative only (card edges,
  table rules, the timeline spine), so 3:1 is not required, and pushing it there
  would make every card on a dense screen shout. The validator reports it but
  does not block on it. Use judgement; I flag it only so the number doesn't
  surprise you.

**Open `validate/contrast-check.html` in a browser and paste your `tokens.css`
into it.** It computes all 20 pairs and shows pass/fail against the real
foreground/background combinations the app actually renders. It is the same
arithmetic I used. Please do not return a palette that fails it.

### 4.2 The logo — `carl-logo.svg`

**Where it appears.** Today the brand is the plain word "Carl" in the topbar
(`.topbar .brand`, 18px, weight 700, sitting on `--carl-primary`, white text).
There are four surfaces:

1. **Topbar, every page** — the primary home. Renders at roughly 24–28 px tall
   against `--carl-primary`. This is a *small, dark-background, always-present*
   mark. It is the constraint that should drive the design.
2. **Login and the onboarding wizard** — the one place it can be large. The login
   page currently opens with an `<h1>` reading "Carl The Garden Helper".
3. **PDF report header** — FPDF, and see the hard limit below.
4. **Favicon** — separate file, §4.3.

**Hard requirements:**

- **A single self-contained `.svg` file.** No external references, no embedded
  raster, no `<script>`, no webfont.
- **Presentation attributes only — no `style=""` anywhere in the SVG, and no
  `<style>` block.** The Content Security Policy is `style-src 'self'` with no
  `'unsafe-inline'`, so an inline style attribute is *silently* dropped. Use
  `fill="…"`, `stroke="…"`, `stroke-width="…"`. This is the single easiest way to
  ship a logo that looks perfect in your canvas and renders wrong in the app.
- **Text must be converted to paths.** No `<text>` elements — the server has no
  fonts to resolve them against.
- **A `viewBox`, and no hard-coded `width`/`height`.** It gets sized by CSS.
- **Legible at 24 px tall.** Test it there before you test it at 400.
- **It must work in one colour.** Give me a monochrome-capable form: if the whole
  mark can be `fill="currentColor"` and still read, say so explicitly in your
  notes and I will use it that way in the topbar (it then follows the palette for
  free). A two-colour version is welcome *as well*, but the one-colour form is
  the one that has to exist.
- **Under 8 KB**, ideally under 3 KB. The client shell has a 150 KB gzipped
  budget enforced by a CI test; it currently sits at 17 KB, so there is room —
  but a 200-path illustration is not what this is.

**Please also supply a horizontal lockup** (mark + wordmark side by side) if the
mark alone doesn't carry the name, since the topbar is a wide short strip. Name
it `carl-logo-lockup.svg`.

**The PDF limit, which is real:** the PDF writer is FPDF, vendored, and **it
cannot render SVG at all.** It draws rectangles, lines and text in Helvetica.
So for the report header, one of three things happens, and I need you to pick in
your notes:
  - **(a)** the PDF keeps its current text-only header — the document title in
    `--carl-primary`, "Carl" and the date on the right. Simplest, and honestly
    fine.
  - **(b)** you additionally send a **PNG at 600 dpi on a transparent or white
    background**, max ~40 mm wide, named `carl-logo-pdf.png`, and I place it.
  - **(c)** the mark is simple enough to reproduce with FPDF primitives
    (rectangles, lines, filled polygons) and you tell me the geometry.
  My recommendation is **(a) for now, (b) if the mark really carries the brand** —
  a raster in a PDF is the only place in this project where a bitmap is
  acceptable, and it costs report weight (the 20-photo report is already measured
  at 16 MB against a 64 MB ceiling).

**On the name:** "Carl" is a person's name for a piece of software that is
explicitly a helper, not an assistant persona — it never speaks in first person
and has no avatar. Please don't give it a face, a mascot, or eyes. A garden mark,
a tool mark, or a typographic mark all fit; a cartoon character does not.

### 4.3 The favicon — `carl-favicon.svg`

Currently a stopgap: `<link rel="icon" href="data:,">`, an empty data URI whose
only job is to stop every page load requesting a favicon that isn't there.

- Separate file from the logo. **Design it at 16 px**, not scaled down from the
  mark — at 16 px the full logo will turn to mud.
- SVG, same CSP rules as §4.2 (presentation attributes, no `style=""`).
- Square `viewBox`, ideally `0 0 32 32` or `0 0 16 16`.
- It needs its **own background fill**, not transparency — it sits on browser
  tab chrome that may be light or dark.
- If you want a PNG fallback for older browsers, send `carl-favicon-32.png` and
  `carl-favicon-180.png` (the latter for iOS home screen). Optional, and say in
  your notes whether you think it's worth the bytes.

There is a service worker (the app is installable-ish) but **no web manifest
today**. If you'd like a proper home-screen identity — 192 px and 512 px maskable
icons, a theme colour — say so in your notes and I will add the manifest. Don't
build it speculatively.

### 4.4 The three confidence markers

`--carl-verified`, `--carl-approx`, `--carl-generic` label every research value
Carl shows — "transplant after May 12" is either verified against a source,
approximated from a nearby region, or a generic default. They render as small
uppercase pill badges with `border: 1px solid currentColor`, so **the token
colours both the text and the border**.

The design problem: these are **three tiers of epistemic confidence, not three
status levels.** "Generic" is not a warning and not an error — it means "nobody
has measured this for your county yet." If `--carl-generic` reads as red or as
alarm-orange, the page starts shouting at the user about the state of agricultural
research, which is not their problem.

The placeholder solves it by making `verified` the brand green, `approx` the warn
amber and `generic` a plain grey. **Grey for the weakest tier works well and I'd
keep that idea** — but they currently duplicate `--carl-primary`,
`--carl-warn` and `--carl-text-muted` exactly, which means they cannot be tuned
independently. You are free to give them their own values.

### 4.5 Chart colours — and a conflict you need to resolve

Report pages draw the weather that actually happened over a plant's life:
a temperature band (daily high and low), rainfall bars, an ET₀ line, and the
user's logged actions as markers on top. Chart.js, reading the live tokens
through `getComputedStyle`.

The mapping today reuses the status tokens as data-series colours:

| Series | Token today |
|---|---|
| High temperature | `--carl-error` |
| Low temperature | `--carl-info` |
| Rainfall / watering | `--carl-info` |
| ET₀ (evapotranspiration) | `--carl-accent` |
| Event markers | `--carl-primary` |
| Grid lines | `--carl-border` |
| Axis text | `--carl-text-muted` |

**Three problems with that, which is why I'm raising it rather than leaving it:**

1. **Low temperature and rainfall are the same colour.** They appear on different
   charts, so it isn't broken — but it is not a decision anyone made.
2. **`--carl-accent` is doing two unrelated jobs**: the ET₀ data line *and* the
   3px focus ring on every input in the app. A colour chosen to be a legible
   data line and a colour chosen to be an unmissable focus indicator are not the
   same brief, and tuning one currently drags the other.
3. **`--carl-error` as "hot" makes every warm day look like a failure**, and it
   ties a data encoding to a status semantic that may want to change.

**What I'd like:** a dedicated, explicitly named set —

```
--carl-chart-hot, --carl-chart-cold, --carl-chart-water,
--carl-chart-et0, --carl-chart-event, --carl-chart-grid, --carl-chart-axis
```

— added to `tokens.css`. That frees `--carl-accent` to be purely the focus ring
and lets the series be designed as a set. Phase 5's handoff explicitly left this
door open: *"If Claude Design would rather the charts had their own names, that
is a change to make."* I will rewire `charts.js` to the new names.

If you'd rather keep the reuse, that's a legitimate answer — but please **at
minimum split the focus ring from ET₀**, because that one is a genuine conflict.

**These are data encodings, so:**
- The five series must be distinguishable from each other **and colour-blind
  safe** — deuteranopia and protanopia at minimum. Hot vs cold as red vs green
  is the classic failure.
- They sit on `--carl-surface` and must clear **3:1** against it.
- Hot/cold read as a *band* (the area between them is filled at ~12% opacity),
  so they need to work as a pair, and their fills must not muddy into each other
  where the band is narrow.
- Rainfall is bars, ET₀ is a line, temperatures are lines with points, events are
  scatter markers — so shape carries some of the load. Don't rely on it entirely.

### 4.6 The email palette — `email-palette.md`

The daily digest email needs **five literal hex values**, because
`DigestMessage.php` writes them into inline styles and no CSS variable can reach
it. Give me exactly these five:

| Purpose | Placeholder today | Yours |
|---|---|---|
| Body text | `#1f2420` | ? |
| Muted text (section headings, secondary lines, footer) | `#5c635d` | ? |
| Link ("Open Carl") | `#24522f` | ? |
| Horizontal rule | `#d6d6cf` | ? |
| Background, if you want one other than default white | `—` | ? |

Constraints that make this different from the web palette:

- **The email has no images and no background colour today.** It renders on
  whatever the mail client's background is, which in dark mode may be near-black
  and which you cannot control. So **these five must stay legible on both white
  and dark backgrounds**, or at least degrade to "readable" rather than
  "invisible". This is the argument against a very light muted grey.
- Plain text is the primary version; the HTML is the alternative. Don't design
  something that only works as HTML.
- No webfonts, no `<style>` block, no classes — inline styles only.

### 4.7 What you should NOT change

- **The field-recording sheet.** Built in Phase 6, from your own earlier canvas —
  the four artboards are in `existing-canvas/` for reference. It is **pure black
  on white with no grey, deliberately**: a dotted or 60%-grey rule is a sub-pixel
  mark that a 600 dpi mono laser halftones into a broken line or drops entirely,
  and this is a sheet people take to the beds and write on with a pen. It reads
  no tokens and a palette swap correctly leaves it alone. **This is right, and
  worth not "fixing."**
- **The QR plant tags.** A QR must be near-black on near-white to scan. There is
  a note in the spec that when those tokens land they must be marked
  *contrast-critical, not palette, must not be themed* — a designer who tints
  them to brand green silently breaks every tag in every garden and nothing
  reports it. **If you add `--carl-qr-ink` and `--carl-qr-paper`, keep them
  black-on-white and carry that comment.** Otherwise leave them out and I'll add
  them.
- **Layout, spacing, and component structure.** `carl.css` is 371 lines of
  structure-only CSS and is not in scope. If you think a component is wrong,
  say so in your notes rather than restyling it — I'd rather have the
  conversation than a diff I can't apply.

---

## 5. Platform constraints — the ones that will bite

### 5.1 Content Security Policy

Enforced on every response:

```
default-src 'self'; img-src 'self' data: blob:; style-src 'self';
script-src 'self'; connect-src 'self'; form-action 'self';
frame-ancestors 'none'; base-uri 'self'; object-src 'none'
```

What that means for you, concretely:

- ❌ **No inline `style=""` attributes.** Anywhere. Including inside SVG.
  Silently refused, no console error you'd notice. *(The one exception is the
  digest email, which is never served as a page.)*
- ❌ **No `<style>` blocks** in delivered SVG or HTML.
- ❌ **No Google Fonts, no webfonts from any CDN.** `default-src 'self'` means
  the font never loads and you get the fallback. `--carl-font` must be a system
  stack. A self-hosted font file would technically pass CSP but costs the shell
  budget and a deploy step — **ask before proposing one.**
- ❌ **No `<object>` or `<embed>`** for SVG (`object-src 'none'`).
- ✅ `data:` URIs for images are allowed, so an inline SVG favicon works.
- ✅ Inline `<svg>` markup in a page is fine — it is markup, not a resource load.

### 5.2 No build step

No Sass, no PostCSS, no autoprefixer, no CSS modules, no npm. The `.css` file you
write is byte-for-byte the file the browser gets. Plain CSS custom properties on
`:root`. Nesting and `@layer` are not worth the risk here.

### 5.3 The hex-only parser — the thing most likely to go wrong

The PDF writer cannot use a CSS variable, so `Carl\Support\Tokens` **parses
`tokens.css` with a regular expression** to recover the colours:

```php
/(--carl-[a-z0-9-]+)\s*:\s*#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\s*;/
```

Therefore, in `tokens.css`:

- ✅ `--carl-primary: #2f6b3f;` — 6-digit hex, works
- ✅ `--carl-primary: #2f6;` — 3-digit hex, works
- ❌ `oklch()`, `hsl()`, `rgb()`, `color-mix()`, `lab()`, `red` — **do not
  resolve**, and the PDF silently falls back to **grey**. No error. No warning.
  You'd find out when someone printed a report.
- ❌ 8-digit hex with alpha (`#2f6b3fcc`) — does not match either.
- Lowercase `--carl-` prefix, and **the semicolon is required.**

Design in whatever colour space you like — OKLCH is a much better way to build a
palette than hex, and I'd encourage it — but **convert to 6-digit hex before you
hand it over.** If you want the OKLCH values preserved for future editing, put
them in a CSS comment beside each token; comments are ignored by the parser and
genuinely useful to the next person.

### 5.4 Budget and misc

- Client shell budget: **150 KB gzipped**, CI-enforced, currently 17 KB.
  `tokens.css` is 874 bytes gzipped. Plenty of room; just don't ship an
  illustration.
- Light mode only today — `<meta name="color-scheme" content="light">`. See §7.1.
- Chart.js is vendored and its own defaults are overridden by the tokens.

---

## 6. The screens

So you know what the palette has to carry. All mobile-first at 380 px.

**Entry** — Login · forced first password reset · tokenised set-password ·
onboarding wizard (profile → ZIP-to-county lookup → first garden → first plant).

**The main menu** — a grid of large tile links, one or two columns. Carries the
MOTD: today's weather, the watering recommendation with the numbers behind it,
active NWS alerts, and today's reminders each with a dismiss button. *This is the
screen people see most and the one the palette most has to get right.*

**Plants** — Start a New Plant (three routes: indoor start, direct sow,
transplant), each with the research card for that plant and region · Log Plant
Activity (every action the plant's state allows, backdatable, batchable, with
narrative and photos) · View Plants with filters · a single plant's page:
timeline, photo grid, weather chart, lineage panel if it was split from another
sowing.

**Gardens** — Build Garden (zones and rows) · Garden Actions including zone
watering that fans out to every living plant in the zone · View Garden with crop
rotation warnings beside the row picker · End Growing Season, the one destructive
action, whose confirmation screen names every planting and asks for the words to
be typed.

**Reference and reports** — the Reports menu · plant and garden report pages with
charts · companion planting reference (twenty pairings, each with its mechanism
and how well established it is) · succession planting planner · CSV and JSON
exports · printable field sheet.

**Admin** — create user · research import · regions needing research · mail
health · analysis cost.

**Off-screen** — the daily digest email, the PDF reports, the printed field
sheet, the QR plant tags.

The most colour-dense screens are **the main menu** (status, tiers, alerts,
reminders all at once) and **a plant page** (timeline + chart + confidence
badges). If you want to mock two screens, mock those.

---

## 7. Open questions — please answer these in `DESIGN-NOTES.md`

These are real decisions I don't want to make on your behalf.

1. **Dark mode.** Carl is `color-scheme: light` today. Real argument for adding
   it: people log evening waterings, and a phone at dusk in a garden is a
   genuinely dark-adapted context. Real argument against: it doubles the palette,
   the PDF and email can't follow it, and nobody has asked. **If you want to do
   it, deliver `tokens-dark.css` as a separate `@media (prefers-color-scheme: dark)`
   block using the same 31 names**, and I'll wire it. If you think it's not worth
   it, say so and I'll close the question. Either answer is fine; I'd rather have
   your view than a guess.
2. **Chart tokens** (§4.5) — dedicated `--carl-chart-*` names, or keep the reuse?
   If keeping the reuse, please still split the focus ring from ET₀.
3. **The PDF header** (§4.2) — (a) text-only, (b) you send a 600 dpi PNG, or
   (c) FPDF primitives?
4. **PNG favicon fallbacks and a web manifest** (§4.3) — worth the bytes or not?
5. **The alternate field sheet.** Your earlier canvas carried a fourth artboard —
   a ledger-style sheet, one row per observation with the action written as a
   word instead of ticked. It was never built. It's in
   `existing-canvas/AltLedger.dc.html`. Still worth building, or drop it?
6. **Anything you think is wrong.** You are looking at this with fresh eyes and I
   have been inside it for eight phases. If the confidence badges are the wrong
   pattern, if the topbar should not be a solid brand bar, if the notice colours
   are doing too much — say so. I will not treat it as scope creep.

---

## 8. What "done" looks like

- `tokens.css` defines **all 31 existing names**, values in **3- or 6-digit hex**,
  and `validate/contrast-check.html` reports **Ready to ship**.
- `carl-logo.svg` and `carl-favicon.svg` are self-contained, use presentation
  attributes only, contain no `<text>`, and are legible at 24 px and 16 px
  respectively.
- `email-palette.md` gives five hex values that survive a dark mail client.
- `DESIGN-NOTES.md` answers the seven questions in §7.
- Everything is in one zip, named as `RETURN-FORMAT.md` specifies.

Then I do the rest: drop the files in, rewire `charts.js` and the PDF fallbacks,
paste the email hexes into `DigestMessage.php`, replace the `data:,` favicon,
put the logo in the topbar and on login, run the suite, and ship it. §13.5 closes
and the spec is finished.

Thank you — genuinely. This is the last thing standing between Carl and done.
