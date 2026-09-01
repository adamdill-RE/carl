# Implementing the Claude Design delivery — handoff §13.5

**Task:** wire Claude Design's palette, logo and favicon into Carl, and close
§13.5 — the last unbuilt item in `CARL-HANDOFF.md`.

**The delivery is in `design/return/`.** Read `design/return/DESIGN-NOTES.md`
first: it is Claude Design's own account of what they made and why, and it
answers the seven open questions the brief asked. This document is the
engineering plan on top of it.

Everything below was verified against the tree at `dc1e088` (the current
default branch, after the QR/pest work merged). If you are picking this up
much later, re-check §7 before trusting the line numbers.

---

## 1. What has already been checked, so you don't repeat it

I validated the delivery rather than trusting it. All of this holds:

- **The palette passes.** `php design/handoff/contrast.php design/return/tokens.css`
  → *All required pairs pass*, one advisory (`--carl-border`, decorative, by
  design). Every ratio Claude Design quoted matches my independent
  measurement exactly — 7.87:1 on the topbar, 3.53:1 on the input border,
  5.77:1 on muted-in-sunk.
- **The WCAG 1.4.11 defect is fixed.** `--carl-border-strong` goes
  `#b4b4aa` → `#8a897d`, which is **2.09:1 → 3.53:1** on `--carl-surface`.
  That failure is still live on the current default branch; this delivery is
  what closes it.
- **No token was renamed or removed.** The repo has 33 names today (31
  original + the two QR tokens the QR work added). The delivered file has all
  33 plus seven new `--carl-chart-*`. The QR values match the repo's current
  ones exactly (`#000000` / `#ffffff`).
- **Nothing in the file is unparseable.** No `oklch()`/`hsl()`/`rgb()` outside
  comments; the OKLCH source values are in comments, which the regex ignores.
  `--carl-shadow` holds an `rgba()`, as it always did, and is not a colour
  token.
- **The SVGs are CSP-clean.** Zero `style=`, zero `<style>`, zero `<text>`,
  zero `<script>`, no `width`/`height` on the root, `viewBox` and `<title>`
  present on all three. The only `http` string in each is the SVG XML
  namespace, which is not a resource load.
- **The dark palette passes the same 20 pairs** (border advisory, as in
  light), and the chart series clear 3:1 on `--carl-surface` in both themes.
- **The mark renders.** I rendered it at 16/24/32/88 px on both white and the
  brand bar, and the favicon at 16/32/64. The "C + seedling" holds at 24 px
  on the topbar; at 16 px it does get dense, which is exactly why the favicon
  is a separate drawing of the seedling alone. That call is correct.

Two small things I noticed that are **not** defects:

- `--carl-approx` (`#7a5600`) is still numerically identical to `--carl-warn`,
  though `DESIGN-NOTES.md` §1 says the confidence tiers now "hold their own
  values". They are now separately *declared*, so they are independently
  tunable, which was the point — they just happen to be equal today.
  `--carl-verified` and `--carl-generic` did move off their old aliases.
- `carl-favicon.svg` hardcodes `#265c37` and `#f4f3ee` rather than using
  tokens. That is correct — a favicon needs its own background and is not
  themable — but it means the favicon is the one asset that must be redrawn
  by hand if the brand colour ever moves.

---

## 2. The work

Nothing here is architectural; the palette was designed as a one-file swap
and it mostly is. §2.1–§2.8 are the whole job apart from the web manifest,
which is specified in §3.2 and is blocked on icons we have to ask Claude
Design for — land everything else first and add it when they arrive.

### 2.1 The palette — `public/assets/css/tokens.css`

Replace wholesale with `design/return/tokens.css`.

Keep the delivered file's comments verbatim. They carry the OKLCH source
value for every colour and the reasoning for each group, and
`DESIGN-NOTES.md` §6 asks that future shifts be made in OKLCH and converted
back rather than nudged in hex. That note is the documentation.

**Do not re-tune anything to taste.** Several ratios sit deliberately close to
a line, and `--carl-border-strong` is load-bearing for WCAG 1.4.11 — lightening
it re-opens the defect this delivery closes.

### 2.2 Chart colours — `public/assets/js/charts.js`

Lines 58–64 currently map the series onto status tokens. Rewire to the seven
dedicated names, and update the hard-coded fallbacks to match the new palette
so a fallback never renders a stale colour:

| Series | From | To | Fallback |
|---|---|---|---|
| `hot` | `--carl-error` | `--carl-chart-hot` | `#b8460b` |
| `cold` | `--carl-info` | `--carl-chart-cold` | `#00509a` |
| `water` | `--carl-info` | `--carl-chart-water` | `#0e8577` |
| `et0` | `--carl-accent` | `--carl-chart-et0` | `#8d5bb5` |
| `event` | `--carl-primary` | `--carl-chart-event` | `#2f3a33` |
| `grid` | `--carl-border` | `--carl-chart-grid` | `#d3d1c5` |
| `text` | `--carl-text-muted` | `--carl-chart-axis` | `#545a52` |

This is what frees `--carl-accent` to be only the focus ring. **Nothing else
may use `--carl-accent`** — if a future feature wants another data colour, add
a `--carl-chart-*`, don't borrow it back.

Two shape changes go with this, settled in §3.3: **add `borderDash` to the
ET₀ line**, and **leave the event markers as triangles** — do not change them
to circles.

### 2.3 PDF fallbacks — `app/src/Reports/Document.php`

Lines 67–71 hold RGB triples of the *placeholder* palette as fallbacks behind
`Tokens::rgb()`. The live values come from `tokens.css` and will update
themselves, but sync the fallbacks so they are not a stale palette waiting for
a bad parse:

```
--carl-text          → [ 25,  29,  25]
--carl-text-muted    → [ 84,  90,  82]
--carl-border        → [211, 209, 197]
--carl-primary       → [ 38,  92,  55]
--carl-surface-sunk  → [233, 232, 224]
```

The PDF stays on the **light** palette in all cases —
`Carl\Support\Tokens` reads `tokens.css`, never the dark file. That is correct
and deliberate: paper is white.

### 2.4 The digest email — `app/src/Reminders/DigestMessage.php`

Eight hard-coded hexes across lines 90–123. This is the one surface a palette
swap cannot reach, because mail clients only honour inline styles. Replace per
`design/return/email-palette.md`:

| Purpose | Old | New |
|---|---|---|
| Body text | `#1f2420` | `#191d19` |
| Muted (headings, secondary, footer) | `#5c635d` | `#656b63` |
| Link ("Open Carl") | `#24522f` | `#377f47` |
| Rule | `#d6d6cf` | `#d3d1c5` |

Note the muted grey and the link are **deliberately not** the app's tokens —
they are pitched to survive a dark mail client, where `--carl-text-muted` and
`--carl-primary-dark` would go invisible. Don't "correct" them to match the
palette.

Also add, as `email-palette.md` specifies: an explicit
`background-color:#ffffff; color:#191d19` on the outermost wrapper, and
`<meta name="color-scheme" content="light">` plus
`<meta name="supported-color-schemes" content="light">` in the HTML part's
head. The plain-text part is the primary version and does not change.

### 2.5 The logo — new partial, not an asset request

**The mark must be inlined into the HTML**, not referenced with `<img>`. Every
path is `fill="currentColor"` / `stroke="currentColor"`, which is what lets one
file serve the white topbar and the green login page; an `<img>` cannot inherit
`currentColor` and would force two colour variants to keep in sync.

Inline SVG is markup, not a resource load, so the CSP is untouched.

Follow the existing `app/views/partials/` convention:

- `app/views/partials/logo.php` — the contents of `design/return/carl-logo.svg`
- `app/views/partials/logo_lockup.php` — `design/return/carl-logo-lockup.svg`

Then:

- **`app/views/layout.php`**, the `.topbar` — put the mark before the existing
  `.brand` text at about 24 px. Keep the HTML word "Carl"; `DESIGN-NOTES.md`
  §2 is explicit that the SVG wordmark must **not** be used in the topbar,
  because HTML text stays crisp, respects the user's text size, and is
  selectable.
- **`app/views/login.php`** and the onboarding wizard — the lockup at
  180–240 px, coloured `--carl-primary`.

`carl.css` will need a small rule to size the mark and to set the lockup's
colour. That is the one stylesheet change in this work, and it must name no
colour of its own beyond `var(--carl-primary)`.

**These partials are the live artwork.** Don't also copy the logo SVGs into
`public/assets/img/` — two copies of one drawing is precisely the "second
artefact that goes stale silently" this repo keeps refusing (see §13.4 on the
field sheet). `design/return/` is the delivery record, not a second live copy.

### 2.6 The favicon — real files

These *are* files, because `<link rel="icon">` needs a URL.

Create `public/assets/img/` and add `carl-favicon.svg`, `carl-favicon-32.png`
(831 B) and `carl-favicon-180.png` (4.4 KB).

In `app/views/layout.php`, replace the `data:,` stopgap at line 22:

```html
<link rel="icon" href="<?= $e($app->asset('assets/img/carl-favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= $e($app->asset('assets/img/carl-favicon-32.png')) ?>" sizes="32x32">
<link rel="apple-touch-icon" href="<?= $e($app->asset('assets/img/carl-favicon-180.png')) ?>">
```

Use `$app->asset()` so the files get their mtime cache-buster and the base path
is right on the server, where `public/` is not under the app root.

**No `.cpanel.yml` change is needed.** The deploy does
`/bin/cp -R public/. $WEBDIR/`, so a new `public/assets/img/` ships
automatically. Do not add a task for it — the file is lint-checked and a
needless task is a needless risk.

### 2.7 Dark mode — **decided: ship it**

Add `public/assets/css/tokens-dark.css` from
`design/return/tokens-dark.css`, link it in `layout.php` **after**
`tokens.css`, and change line 17 to
`<meta name="color-scheme" content="light dark">`.

The one thing that can surprise you: **in dark, `--carl-primary` is a light
green and `--carl-text-inverse` is near-black.** The pairing still works
everywhere it is used, but any code that assumes `--carl-text-inverse` is
white will show it. Grep for `text-inverse` before you ship this.

`sw.js` caches `/assets/(css|js)/`, so the new stylesheet is picked up with no
service-worker change. QR ink and paper do not invert, which is correct and
already handled in the delivered file.

### 2.8 Commit the notes

Put `design/return/DESIGN-NOTES.md` in `docs/` alongside the handoffs, as the
brief promised. It is the reasoning behind every value in `tokens.css` and it
should sit where the other governing documents are.

Then mark §13.5 **built** in `docs/CARL-HANDOFF.md`, and drop it from the
"Claude Design outstanding" list in the phase handoffs.

---

## 3. Decisions — all three answered

Adam settled these on 1 September. Nothing here is open; implement as stated.

### 3.1 Dark mode — **yes, ship it**

Implement §2.7. Grep for `text-inverse` before you ship it: in dark,
`--carl-primary` is a light green and `--carl-text-inverse` is near-black, so
any code assuming `text-inverse` is white will show it.

### 3.2 Web manifest — **yes, and it needs one thing we don't have**

Add `public/manifest.webmanifest` with `theme_color: #265c37`,
`background_color: #f4f3ee`, the app name, `display: standalone` and a
`start_url` matching the `/carl/` base path. Link it from `layout.php`
alongside the icons.

**It needs 192 and 512 maskable icons, which are not in the delivery.** Claude
Design deliberately did not build them speculatively and has offered to; a
maskable icon needs ~20% safe-area padding, so it is a third drawing rather
than a resize of the favicon. **Ask Claude Design for them, and do not ship a
manifest that points at scaled-up favicons** — a maskable icon without the
safe area gets its edges cropped by the launcher.

If you want to land everything else first, ship §2.1–§2.8 without the manifest
and add it when the icons arrive. That ordering costs nothing.

### 3.3 The two chart shape requests — **part-declined, as recommended**

Adam agreed with the reading in §3.3 of the original plan:

- **ET₀: add the dash.** It is not dashed today and `borderDash` appears
  nowhere in `charts.js`, so this is a new property, not a preservation. The
  reason Claude Design gave does not apply — ET₀ is alone on its own tab and
  never shares a panel with a temperature line — but it costs one property and
  is already right if those panels ever merge.
- **Event markers: keep the triangle.** They are `pointStyle: 'triangle'`
  today. A different shape beats the same shape at a different size, and
  Claude Design asked for circles only because their mockup drew dots. Do not
  change them to circles.

Worth a line back to Claude Design, since it is their encoding and the second
point is a disagreement rather than a detail.

---


## 4. Constraints that will bite

All of these have already cost someone a cycle on this repo.

- **CSP is `style-src 'self'` with no `'unsafe-inline'`.** An inline
  `style=""` — including inside an SVG — is silently dropped. The delivered
  SVGs are clean; keep them that way when you paste them into partials.
- **`default-src 'self'`** means no webfont from any CDN. The delivered
  `--carl-font` is a system stack and needs no change.
- **Hex only in `tokens.css`.** `Carl\Support\Tokens` recovers colours with a
  regex for the PDF; anything else resolves to grey with no error. The
  delivered file is compliant — don't introduce `oklch()` when editing it.
- **Every tracked file must be git mode 100644.** CI enforces it, because the
  deploy fixes modes in one pass with `chmod -R u=rwX,go=rX`, which only holds
  while nothing tracked is executable. The PNGs are already 644; check after
  any copy.
- **Client shell budget: 150 KB gzipped, CI-enforced.** Currently ~17 KB. The
  budget test globs `assets/css/*.css` and `assets/js/*.js`, so
  `tokens-dark.css` counts (~700 B) and `assets/img/` does not. No risk, but
  run the check.
- **The field sheet reads no tokens, and that is correct.** Pure black on
  white, no grey, because a 600 dpi mono laser halftones a grey rule into a
  broken line. A palette swap must leave it alone. The same reasoning applies
  to `app/views/tags/print.php` — check it still prints black-on-white.
- **`--carl-qr-ink` / `--carl-qr-paper` are contrast-critical, not palette.**
  A QR tinted to brand green silently stops scanning and nothing reports it.
  They stay `#000000` / `#ffffff` in both themes.

---

## 5. Verifying

Run these; don't assume.

```sh
# The palette itself
php design/handoff/contrast.php public/assets/css/tokens.css   # → All required pairs pass

# Static checks, all five steps CI runs
[ -f composer.json ] && echo FAIL                              # must not exist
find . -path ./.git -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
git ls-files -s | awk '$1 != "100644" { print $1, $4 }'        # must print nothing
php tests/lint_cpanel_yml.php
php tests/check_asset_budget.php

# The suite (needs MySQL; CI has it, this container does not)
php tests/run.php --strict
```

Then look at it, which is the part the arithmetic cannot do:

- The main menu at 380 px with a weather alert firing. `DESIGN-NOTES.md` §7.6
  flags this as the screen where the palette will actually be judged, and the
  notice stack as the first thing that would break if anything is added to it.
- A plant page: research card badges, the timeline, and a chart.
- **Check the PDF actually picks up the new brand colour** rather than falling
  back to grey. That is the failure mode the hex-only rule exists to prevent,
  and it is invisible until someone prints a report.
- A focus ring on an input — it should be unmistakably magenta and look like
  nothing else on the page. That is intentional.
- `.timeline .derived::before` was effectively invisible at 2.09:1 and is now
  3.53:1, so derived events become visible for the first time. Claude Design
  flagged this as a visual change we didn't ask for and didn't cause — worth a
  glance to confirm it's wanted.

Definition of done: contrast passes, static checks pass, the suite passes,
§13.5 is marked built, and the PDF and the digest email both carry the new
palette rather than the placeholder.

---

## 6. Not in scope

- **`carl.css` layout, spacing or component structure.** Claude Design changed
  no geometry and no `carl.css`. The only edit this work needs there is sizing
  the logo.
- **The alternate ledger field sheet.** Claude Design's answer to §7.5 is
  drop it: it costs the tick-and-move-on speed the sheet exists for, and two
  sheets is a choice at the printer and a support question forever. Delete
  `design/AltLedger.dc.html` or leave it as an artefact; do not build it.
- **A PDF logo.** Their answer to §7.3 is (a), text-only, and they did not
  ship a raster. A 600 dpi PNG buys recognition nobody needs on a document the
  user generated, against real weight on a 16 MB report.

---

## 7. If you are reading this later

Re-verify before trusting the line numbers above. The QR and pest work landed
between the brief going out and the delivery coming back, and it moved
`tokens.css` from 31 tokens to 33 — which is exactly the kind of drift that
makes a stale plan dangerous.

```sh
grep -c -- '--carl-' public/assets/css/tokens.css
grep -n 'carl-error\|carl-info\|carl-accent\|carl-primary' public/assets/js/charts.js | head
grep -n '#[0-9a-f]\{6\}' app/src/Reminders/DigestMessage.php
grep -n 'rel="icon"\|color-scheme' app/views/layout.php
```

---

## 8. The nightly test failures — fixed, and now guarded

This is resolved; the note is here so nobody re-diagnoses it.

Four assertions used to compare a UTC `gmdate()` against dates the app
computes in `America/Chicago`, so the suite was green all afternoon and red
between 00:00 and 05:00 UTC. Commit `c97dee9` fixed those four. A fifth was
still latent in `23_calendar_test.php`, where the month-boundary case would
have been considerably more confusing — the events land outside the drawn
month entirely and the count chip simply vanishes — and that is fixed now too.

`tests/check_test_clocks.php` runs in CI's static job and makes the pattern
unmergeable: a case file that onboards with ZIP 76692 may not take `$today`
from the server clock unless the line carries a `// utc-ok: <reason>` marker.
Four files legitimately do and now say why.

The rule is **not** "never call `gmdate()`". `04_weather_test.php` builds its
locations in UTC, so the server's day is the correct one there. The rule is
that one test must not mix two timezones' idea of today.
