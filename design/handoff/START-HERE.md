# Carl — design handoff package

**Read `BRIEF.md` first.** It is the actual request. Everything else here is
supporting material.

---

## What this is

Carl The Garden Helper is a finished PHP garden-logging application with exactly
one unbuilt item left in its specification: **the logo and the colour palette**,
which have been a Claude Design deliverable since Phase 1 and which the
engineering side has deliberately not improvised for eight phases.

You have no access to the repository, so this zip carries everything: the brief,
the files you need to see, the four artboards from your earlier work on this
project, and a validator that tells you whether what you have made will actually
work before you hand it back.

## Order to read in

| # | File | Why |
|---|---|---|
| 1 | **`BRIEF.md`** | The request. Background, the eight deliverables, every constraint, and seven open questions to answer. |
| 2 | **`RETURN-FORMAT.md`** | Exact filenames and formats to hand back. Short. One rule in it (hex only) silently breaks the PDF reports if missed. |
| 3 | `reference/contrast-baseline.md` | The current palette measured against every real foreground/background pair. One row fails — a genuine accessibility defect you are asked to fix — and one is advisory. |
| 4 | `reference/tokens.css` | The file you are replacing. 31 token names — that set is the contract. |
| 5 | `reference/carl.css` | 371 lines of structure-only CSS. Every component that consumes a token, so you can see what each one actually paints. |

## The rest

```
validate/
  contrast-check.html      Open in a browser, paste your tokens.css, get
                           pass/fail on all 20 real colour pairs plus a check
                           that no token is missing and nothing is unparseable.
                           Please run this before you hand anything back.

return-template/
  tokens.css               Fill-in-the-blank version of the palette, with each
                           token annotated with what it actually paints.
                           Starting from this is easier than starting blank.

reference/
  tokens.css               The placeholder you are replacing.
  carl.css                 Structure-only stylesheet — every token consumer.
  contrast-baseline.md     Measured contrast of the placeholder.
  layout.php.txt           Page chrome: topbar, brand, the stopgap favicon.
  Tokens.php.txt           The PHP regex parser. Read the docblock — it explains
                           why the palette must be hex.
  DigestMessage.php.txt    The digest email, and the eight hard-coded hexes a
                           palette swap alone would leave stale.
  charts.js-colour-block.txt   How chart series pull colour from the tokens.
  Document.php-colour-block.txt  How the PDF writer pulls colour from the tokens.

existing-canvas/
  canvas.json              Your earlier canvas manifest for this project.
  Main.dc.html             Field sheet — blank              } built in Phase 6,
  GardenActions.dc.html    Garden actions sheet — blank     } shipped as an FPDF
  Prefilled.dc.html        Field sheet — prefilled per garden } generator
  AltLedger.dc.html        Alternate ledger sheet — never built; BRIEF §7.5 asks
                           whether it still should be.
```

## The three-line version

Deliver `tokens.css` (31 hex values, passes the validator), `carl-logo.svg` and
`carl-favicon.svg` (self-contained, presentation attributes only — no `style=""`,
the CSP drops it silently), five literal hex values for the email, and a notes
file answering the seven questions in BRIEF §7.

Zip it as `carl-design-return.zip` and hand it back to Adam.

Partial is fine — the palette alone unblocks every screen, both report types and
the charts.
