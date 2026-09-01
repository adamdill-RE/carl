# How to return the work

Read this before you finish. It is short, and the first rule is the one that
silently breaks things.

---

## The five rules that matter

1. **Hex only in `tokens.css`.** 3- or 6-digit, lowercase `--carl-` prefix,
   semicolon required. `oklch()`, `hsl()`, `rgb()`, `color-mix()` and colour
   names **do not parse**, and the PDF reports fall back to grey with no error.
   Design in any colour space you like; convert before you hand over. Keep the
   original values in a CSS comment if you want them preserved.
2. **No `style=""` and no `<style>` in any SVG.** Presentation attributes only
   (`fill=`, `stroke=`, `stroke-width=`). The CSP drops inline styles silently.
3. **Keep all 31 token names.** Add names freely; do not rename or remove.
4. **No `<text>` elements in SVG.** Convert type to paths.
5. **Plain files, no build artifacts.** No `.scss`, no `dist/`, no `node_modules`,
   no minification. What you write is what ships.

---

## Exact filenames

Return a folder named `carl-design-return/`, zipped as
**`carl-design-return.zip`**, with these names exactly — I match on them:

```
carl-design-return/
  tokens.css                 REQUIRED  the palette; replaces public/assets/css/tokens.css
  carl-logo.svg              REQUIRED  primary mark
  carl-favicon.svg           REQUIRED  designed at 16px, not a scaled logo
  email-palette.md           REQUIRED  the five literal hex values (BRIEF §4.6)
  DESIGN-NOTES.md            REQUIRED  rationale + answers to BRIEF §7

  carl-logo-lockup.svg       optional  horizontal mark + wordmark
  carl-logo-mono.svg         optional  if the one-colour form is a separate file
  carl-logo-pdf.png          optional  600 dpi, ≤40mm wide — only if you chose §4.2(b)
  carl-favicon-32.png        optional  raster fallback
  carl-favicon-180.png       optional  iOS home screen
  tokens-dark.css            optional  dark mode, same 31 names (BRIEF §7.1)
  mockups/                   optional  any screen mockups — PNG or self-contained HTML
```

If a required file is missing I can't apply the palette, so I'd rather have a
file with a note in it saying "not doing this, because…" than no file.

---

## Format of each file

### `tokens.css`

Same shape as the placeholder in `reference/tokens.css`: a single `:root` block,
one declaration per line, grouped with comments. Please keep the group comments —
they're how the next person navigates it.

Add a header comment naming what it is and when it was delivered. **Remove the
placeholder's "PLACEHOLDER" banner** — that file's whole purpose was to be
replaced, and leaving the banner on the real palette would be confusing in a
year.

If you add `--carl-chart-*` or `--carl-qr-*` tokens, give each group its own
comment block. For the QR pair specifically, carry the warning verbatim:

```css
/* Contrast-critical, NOT palette. Must not be themed: a tinted QR code
   silently fails to scan and nothing reports it. */
```

### `carl-logo.svg` / `carl-favicon.svg`

- Self-contained. No external refs, no embedded raster, no script.
- `viewBox` present; **no hard-coded `width`/`height`** (CSS sizes it).
- Presentation attributes only.
- Paths, not text.
- If the mark is meant to inherit the surrounding text colour, use
  `fill="currentColor"` and **say so in your notes** — that's the form I'll put
  in the topbar so it follows the palette for free.
- Include a `<title>` element for accessibility (that's markup, not style — it's
  allowed and wanted).

### `email-palette.md`

Just the table from BRIEF §4.6 with your values filled in, plus one line each on
why, and a note confirming you checked them against a dark mail client
background. Five hex values is the whole deliverable.

### `DESIGN-NOTES.md`

Free-form, but please cover:

- **The seven answers** to BRIEF §7 (dark mode, chart tokens, PDF header, favicon
  fallbacks, alt field sheet, and anything you think is wrong).
- **Which logo form is canonical** — mono, two-colour, or lockup — and where you
  intend each to be used.
- **Anything you changed beyond colour** — a `--carl-radius`, the font stack, a
  token you added.
- **Anything you couldn't do** and why. A known gap I can plan around beats a
  surprise.
- **Anything you'd want a future designer to know.** This file gets committed to
  the repo next to the handoff docs.

---

## Self-check before you zip

Please actually run these — each maps to a way this has broken before:

- [ ] Opened `validate/contrast-check.html` in a browser, pasted in my
      `tokens.css`, and it reports **&#10003; Ready to ship**. (Rows marked
      *advisory* are decorative and do not block; rows marked **FAIL** do.)
      Paste the verdict into `DESIGN-NOTES.md`.
- [ ] `grep -c "style=" *.svg` returns **0**.
- [ ] `grep -c "<text" *.svg` returns **0**.
- [ ] `grep -c "oklch\|hsl(\|rgb(\|color-mix" tokens.css` returns **0**.
- [ ] All 31 original token names still present. (The validator checks this too
      and names any that are missing.)
- [ ] The logo has been looked at **at 24 px tall on the brand colour**, not just
      at full size.
- [ ] The favicon has been looked at **at 16 px**.
- [ ] The five chart series are distinguishable in a deuteranopia simulation.
- [ ] No file in the zip is a build artifact, and nothing is minified.

---

## Getting it back to Claude Code

**Adam — this part is for you.**

Any one of these works; the first is easiest:

1. **Drop the zip in the repo.** Put `carl-design-return.zip` in the root of
   `/home/user/carl` on branch `claude/design-handoff-prompt-3xlubk` (or commit
   it anywhere on that branch) and tell me it's there. I'll unpack, verify, apply
   and delete the zip in the same commit so it doesn't linger as a binary in the
   history.
2. **Attach the zip to the conversation** and I'll read it from wherever it
   lands.
3. **Paste `tokens.css` inline** if the palette is all that comes back and the
   logo is running late. It's 60 lines — a paste is fine, and I can apply the
   palette without waiting on the mark. The SVGs are harder to paste reliably,
   so prefer a file for those.

**Tell me if anything is partial.** I'd rather apply a palette now and a logo
next week than sit on a complete package. The palette is the one that unblocks
the most: every screen, both report types and the charts move the moment
`tokens.css` lands.

**What I'll do when it arrives**, so you know what you're approving:

1. Verify the zip against the checklist above and re-run the contrast maths
   myself.
2. Replace `public/assets/css/tokens.css`.
3. Add the logo and favicon to `public/assets/img/`, wire the topbar brand, the
   login page and `<link rel="icon">` in `app/views/layout.php`.
4. Sync the stale fallbacks: the seven hexes in `charts.js` and the five RGB
   triples in `Reports/Document.php`.
5. Paste the five email hexes into `Reminders/DigestMessage.php`.
6. Rewire the chart series to `--carl-chart-*` if that's what came back.
7. Run the suite, the asset-budget check and the collation check; check the PDF
   actually picks up the new brand colour rather than falling back to grey.
8. Commit `DESIGN-NOTES.md` into `docs/` alongside the handoffs, mark §13.5
   built, and report back with what changed and anything that didn't fit.

I will not silently adjust your colours. If something fails contrast or doesn't
parse, I'll come back and ask rather than nudging a hex myself — the whole reason
this file exists is that §17 of the handoff says not to improvise a palette.
