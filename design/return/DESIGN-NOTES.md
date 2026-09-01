# Carl — design notes

Claude Design → Claude Code, 31 August 2026. Handoff §13.5.
Everything here is in `carl-design-return/`.

---

## What's in the zip

| File | Status |
|---|---|
| `tokens.css` | The palette. All 31 names kept, 9 added (7 chart + 2 QR). Hex only. |
| `carl-logo.svg` | Primary mark. **Monochrome, `currentColor`** — see below. |
| `carl-logo-lockup.svg` | Mark + wordmark, one colour, for login and the wizard. |
| `carl-favicon.svg` | Drawn at 16 px, own background, not a scaled logo. |
| `carl-favicon-32.png`, `carl-favicon-180.png` | Fallback + iOS home screen. |
| `email-palette.md` | The five hexes, with both-ground measurements. |
| `tokens-dark.css` | Dark mode. Optional to ship — my answer to §7.1 is yes. |
| `mockups/carl-palette-mockup.html` | Main menu and a plant page at 380 px, plus the marks at 16/24/88 px. Self-contained, open it in a browser. Review artefact, not a repo file. |

No `carl-logo-pdf.png` and no `carl-logo-mono.svg` — deliberate, see §3 and §2 below.

---

## Validator verdict

Pasted `tokens.css` into `validate/contrast-check.html`:

> **✓ Ready to ship** — 32 colour tokens parsed, 0 unparseable values,
> 0 missing names, all required contrast pairs pass, 1 advisory.

The advisory is the expected one: `--carl-border` on `--carl-surface` at
**1.53:1**. Decorative, and pushing it to 3:1 makes every card on the main
menu shout — I agree with your read and left it decorative, a hair darker
than the placeholder (1.46 → 1.53) so a card edge still exists in sun.

**The defect is fixed.** `--carl-border-strong` `#8a897d` on
`--carl-surface` is **3.53:1** (was 2.09), and 3.17:1 on `--carl-bg` for the
few controls that sit directly on the page. Every text input, select and
textarea now has a boundary that meets SC 1.4.11.

Two pairs the validator does not cover, which I measured anyway:
`.btn-danger` — white on `--carl-error` — is **7.99:1**; the derived
timeline dot (`--carl-border-strong` on `--carl-surface`) is the 3.53 above,
so a derived event is now visibly a dot rather than a smudge.

Self-check: `grep -c "style=" *.svg` → 0. `grep -c "<text" *.svg` → 0.
`grep -c "oklch\|hsl(\|rgb(\|color-mix" tokens.css` → 0 (the OKLCH source
values are in comments, which the parser ignores; `--carl-shadow` holds an
`rgba()` as it did before, and is on your NON_COLOUR list).

---

## 1. The palette, in one paragraph

Warm paper, ink-dark type, a deep forest brand, and everything pitched high.
The placeholder's instinct was right and I kept its shape; what I changed is
temperature and depth. The ground is `#f4f3ee` — a shade warmer and a shade
darker than `#f6f6f3`, so white cards read as *cards* outdoors instead of
dissolving into the page. Text went to `#191d19` and the brand to `#265c37`,
which puts white-on-brand at 7.87:1 (was 6.37) — the topbar is the one large
colour field on every screen and it is what your eye adapts to in sun, so it
does the most work. Status colours all land between 5.9 and 7.5:1 against
their soft fills. Nothing in the palette is at the 4.5:1 floor.

The three confidence tiers now hold **their own values** rather than aliasing
`--carl-primary`, `--carl-warn` and `--carl-text-muted`, so you can tune a
badge without moving the brand. They land close to where they were, which is
the point — the placeholder's answer was correct, it just wasn't independently
addressable. Grey for the weakest tier stays; `--carl-generic` `#5a6058` is a
green-grey rather than a neutral one, so it sits in the family instead of
looking like a disabled state.

Non-colour tokens: unchanged, except `--carl-shadow`, which is now
`rgba(25, 29, 25, .10)` — very slightly stronger and warm-tinted, because a
cool grey shadow on a warm ground reads as dirt. `--carl-touch` is 44px.
`--carl-font` untouched: the system stack is already the right answer under
`default-src 'self'`, and I am not going to ask you to host a font for this.

---

## 2. The mark, and which form is canonical

**A "C" with a two-leaf seedling in its opening.** Monoline, one weight,
built from four paths. The C is the name; the seedling is the subject; the
opening in the C is what makes them one shape instead of two things next to
each other. No face, no eyes, no mascot.

**`carl-logo.svg` is canonical and it is monochrome.** Every path is
`currentColor`. Put it in the topbar and it inherits `--carl-text-inverse`
for free; put it on login and set `color: var(--carl-primary)`. It follows
the palette forever with no second file to keep in sync, so I did not ship a
separate `carl-logo-mono.svg` — the primary *is* the mono form. There is no
two-colour version and I don't think one earns its place: at 24 px on a solid
brand bar, a second colour is noise.

**`carl-logo-lockup.svg`** is mark + a monoline lowercase "carl" wordmark
drawn to the same stroke language, all paths, no `<text>`. Use it on login and
the onboarding wizard, at 180–240 px wide. **Do not use it in the topbar** —
keep the mark plus your existing HTML `.brand` text there. The HTML wordmark
stays crisp at any zoom, respects the user's text size, and is selectable;
an SVG wordmark at 18 px is worse on all three counts.

Sizes I actually looked at: 16, 24, 32, 88 px, and the lockup at 228 px. The
24 px topbar rendering is in the mockup, on the brand bar, not on white.

**`carl-favicon.svg`** is a separate drawing: the seedling only, no C,
thicker strokes, larger leaves, on a `#265c37` rounded square. The C does not
survive 16 px; the seedling does, and it is the half of the mark that carries
recognition. It brings its own background, so it works on light and dark tab
chrome. `viewBox="0 0 32 32"`, ~430 bytes.

Both SVGs: presentation attributes only, no `<style>`, no `style=""`, no
`<script>`, no `width`/`height`, `<title>` present, well under 1 KB each.

---

## 3. Answers to BRIEF §7

### 7.1 Dark mode — **yes, and it's in the zip**

`tokens-dark.css`, a single `@media (prefers-color-scheme: dark)` block over
the same names. Wire it by loading it after `tokens.css` and changing the meta
to `content="light dark"`.

The argument that convinced me is the one you made: evening waterings are a
real, frequent case, and a full-white 380 px screen at dusk is a flashbulb
followed by two minutes of ruined night vision — you then can't see the bed
you are standing in. That is a functional cost, not a taste one.

Two things to know before you wire it:

- **The topbar inverts its relationship.** In dark, `--carl-primary` is a
  *light* green and `--carl-text-inverse` is near-black, because a solid
  deep-green bar at night is just a black bar with a green cast. Everything
  that pairs `primary` with `text-inverse` keeps working; nothing else has to
  change. If any code assumes `text-inverse` is white, that's the one place
  it will show.
- **PDF and email stay light.** `Carl\Support\Tokens` reads `tokens.css`,
  not the dark file, so reports keep the light palette. That is correct —
  paper is white — and it's why I kept the dark file separate rather than
  folding the media query into `tokens.css` where the regex could scrape the
  dark values into the PDF.

QR ink and paper do **not** invert in the dark file. Carried the warning
comment verbatim.

It passes the validator on the same 20 pairs (border advisory, as in light).

### 7.2 Chart tokens — **dedicated names, please**

All seven added: `--carl-chart-hot`, `-cold`, `-water`, `-et0`, `-event`,
`-grid`, `-axis`. `--carl-accent` is now **only** the focus ring, and it is
deliberately off-palette magenta `#8e2c66` — a focus ring should never be
mistakable for a brand surface, a status, or a data series. That was the real
cost of the double duty: `--carl-accent` had been quietly compromised toward
"plausible data line", and a focus indicator has no business being tasteful.

The series start from the Okabe–Ito colour-blind-safe set and are tuned until
each clears 3:1 on `--carl-surface` (5.36 / 8.03 / 4.53 / 4.89 / 11.84:1):

- **hot** `#b8460b` orange, not red. A warm day is not a failure, and the
  encoding is no longer welded to `--carl-error`.
- **cold** `#00509a` deep blue and **water** `#0e8577` teal — no longer the
  same colour, which fixes the coincidence you flagged.
- **et0** `#8d5bb5` light violet, off the temperature axis entirely, and
  deliberately the lightest of the four data colours.
- **event** `#2f3a33` near-ink, so markers read as annotation on top of data
  rather than as a sixth series.

Deuteranopia/protanopia: I ran all five through a deuteranope and a protanope
simulation and measured the separations, rather than eyeballing them. The
closest pair under either is **42 units apart in sRGB** (cold/et0 under
protanopia); everything else is 45–90. My first pass had et0 as a mauve
`#9c4a7d`, which measured **10** against water under deuteranopia — visually
fine to me, indistinguishable to a deuteranope. That's the change: et0 is now
a light violet, pitched lighter than every other series, because under both
simulations lightness is what survives when hue does not.

**Two requests on the chart side**, since shape is carrying part of the load:
keep ET₀ **dashed** (it's the only line that isn't a temperature, and the dash
is what tells a red-green-blind reader it isn't one), and keep event markers as
filled circles distinct in size from the temperature line points.

One deliberate non-coincidence to be aware of: the *watering tier* badge stays
amber (`--carl-warn`) while the rainfall *series* is teal. That's right — the
tier encodes urgency, the series encodes quantity, and they never appear in the
same visual field.

### 7.3 PDF header — **(a), text-only**

Keep it. The document title in `--carl-primary`, "Carl" and the date on the
right. The mark's job is the topbar and login; a report already says Carl in
words in its header and its footer, and a 600 dpi PNG buys recognition nobody
needs on a document the user generated themselves — against real cost on a
16 MB report and a raster you'd have to regenerate every time the brand moves.
So no `carl-logo-pdf.png` in the zip. Ask if you disagree and I'll cut one.

Option (c) is technically available — the seedling is two filled polygons and a
line — but hand-transcribing bézier curves into FPDF primitives is a
maintenance liability for a header nobody will look at.

### 7.4 PNG favicon fallbacks and a manifest — **PNGs yes, manifest yes if you want it**

`carl-favicon-32.png` and `carl-favicon-180.png` are in the zip. 32 is a
few hundred bytes and covers browsers that don't take SVG icons; 180 is the
iOS home-screen icon, which **only** comes as PNG — and given the app is
installable-ish and used one-handed in a garden, a home-screen launcher is a
genuinely used affordance here. That's cheap and I'd ship both:

```html
<link rel="icon" href="/carl/assets/img/carl-favicon.svg" type="image/svg+xml">
<link rel="icon" href="/carl/assets/img/carl-favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/carl/assets/img/carl-favicon-180.png">
```

**On the manifest: yes, add it, and I'll send 192/512 maskable icons when you
do.** I didn't build them speculatively, per your note. Theme colour should be
`#265c37` and background colour `#f4f3ee`. Note that a maskable icon needs
~20% safe-area padding, so it's a third drawing, not a resize — say the word.

### 7.5 The alternate ledger sheet — **drop it**

I looked at `AltLedger.dc.html` again with fresh eyes and I don't think it
should be built. Its own comment states the trade honestly: it buys twice the
lines per page and costs the tick-and-move-on speed of a watering round. But
the reason the sheet exists at all is the round — one person, one pass, gloves
on, marking what they did as they go. Writing "watered" nineteen times in
longhand is the thing the tick grid was designed to remove. The gardener who
logs many small varied things is real, but they are also the gardener who has
their phone out, because varied things need narrative and photos, and the app
is better at both than any paper row is.

Also: two sheets is a choice at the printer, and a choice at the printer is a
support question forever. Keep `Main` and `Prefilled`, which cover blank and
per-garden. If someone asks for the ledger twice, build it then.

### 7.6 Things I think are wrong

Small, and none of them are palette:

1. **`.confidence-generic` renders the word "traditional".** The token, the
   class and the label are three different words for one tier. Not a bug, but
   the next person will lose ten minutes to it. Worth a comment in `carl.css`
   at minimum.
2. **`.notice` default and `.badge-muted` both sit on `--carl-surface-sunk`
   with a `--carl-border` edge**, which means a neutral notice and a muted
   badge are the same object at different sizes. Fine as-is; just be aware that
   any future tuning of `surface-sunk` moves both.
3. **The topbar as a solid brand bar is right — keep it.** You asked. On a
   phone in sun it's a fixed orientation cue and the only place the brand
   exists; a white bar with green text would save nothing and cost that.
4. **`.timeline .derived::before` used to be invisible** — `border-strong` at
   2.09:1 on white is not a dot. It's 3.53:1 now, so derived events are
   legitimately visible for the first time. Might be worth a glance to check
   that's what you want, since it's a visual change you didn't ask for and
   didn't cause.
5. **The main menu is where the palette will actually be judged.** Six tiles,
   a MOTD, a weather table, tiers, reminders and possibly an NWS alert all at
   once. I mocked it at 380 px with a frost advisory firing (worst case) — see
   `mockups/`. It holds, but it is dense, and if anything ever gets added to
   that screen the first thing to break will be the notice stack, not the
   colours.

---

## 4. Anything I changed beyond colour

- `--carl-shadow`: `rgba(0,0,0,.08)` → `rgba(25, 29, 25, .10)`. Warm-tinted
  and marginally stronger.
- Nine tokens added: seven `--carl-chart-*`, two `--carl-qr-*`. The QR pair is
  `#000000`/`#ffffff` and carries your warning comment verbatim.
- Nothing else. No renames, no removals, no font change, no geometry change,
  no `carl.css` edits.

## 5. Things I couldn't do

- **I could not test in actual sunlight.** The palette is designed for it and
  the arithmetic supports it, but the one test that matters is you or Adam
  standing in a garden at noon with the main menu open. If anything is too
  quiet out there it will be `--carl-border` (advisory, 1.53:1) and
  `--carl-text-muted` on `--carl-surface-sunk` (5.77:1) — both are one step
  from darker and neither would disturb anything else.
- **I could not test the digest email in real clients.** The five hexes are
  measured against four representative backgrounds, not rendered in Gmail
  dark mode. If it does come back wrong, the value to move is the link.
- **I did not build 192/512 maskable icons** — waiting on your call about the
  manifest (§7.4).

## 6. For whoever picks this up next

- **The comments in `tokens.css` are the documentation.** Each group says what
  it paints and each colour carries its OKLCH source value. If you need to
  shift the palette, shift it in OKLCH — hold chroma and lightness, move hue —
  and convert back to hex before you save. Do not nudge hex by eye; the ratios
  in this file are deliberate and several sit close to the line.
- **Three tokens are not palette and must not be themed:** `--carl-qr-ink`,
  `--carl-qr-paper`, and `--carl-border-strong`, which is load-bearing for
  WCAG 1.4.11 and not a decorative grey. Lightening it re-opens the defect
  this delivery closed.
- **The field sheet reads no tokens and that is correct.** Pure black on white,
  no grey, because a 600 dpi mono laser halftones a grey rule into a broken
  line. If you ever "fix" it to use the palette, you will have broken it.
- **`--carl-accent` is the focus ring and nothing else now.** If a future
  feature wants a sixth data colour, add `--carl-chart-*`; don't borrow it back.
