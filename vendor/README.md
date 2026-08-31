# Vendored third-party code

Nothing installs packages on this host — there is no Composer and no build
step (hosting §3) — so any third-party library ships as a file in here and is
loaded directly.

This directory is deliberately not empty. Git does not track empty
directories, and `.cpanel.yml` copies `vendor/` on every deploy; a missing
source path fails that task, and a failed task fails the whole deployment
rather than just skipping a step (hosting §6.2).

## What is allowed here

Handoff §17: Chart.js, FPDF, and a mailer. **Ask before adding anything else.**

| Library | Status | Where |
| --- | --- | --- |
| FPDF 1.86 | **Vendored, Phase 4** | `vendor/fpdf/fpdf.php` **plus `vendor/fpdf/font/`** — see below |
| PHPMailer (single-file build) | Not needed. `Carl\Mail\SmtpMailer` was written by hand and authenticates cleanly (`deploy.md` §7.5) | — |
| Chart.js 4.5.1 | **Vendored, Phase 4** | `public/assets/vendor/chart.umd.js` — it is a browser asset, so it is **not** in this directory |

## FPDF is not one file

**Corrected by Claude Code, Phase 4, 2026-08-31.** The row above used to say
"FPDF (single file)", and `PHASE-4-HANDOFF.md` §3.3 says the same. It is not
true: `fpdf.php` loads its *core* font metrics from `font/helvetica.php` and
its siblings at the first `SetFont()`, so a lone `fpdf.php` throws "Could not
include font definition file" on the first line of text it is asked to draw.

All fourteen metric files ship, not only the four Helvetica ones the reports
use today. They are 64 KB in total, and pruning them would leave a trap for
whoever next reaches for `SetFont('Courier')` — a 500 on one route, at
runtime, with nothing else broken.

`license.txt` ships with them: FPDF's licence is permissive and unconditional,
but a vendored library without its licence beside it is a question somebody
has to answer later.

## Chart.js

Vendored from the published UMD build at 4.5.1, unmodified except for the
trailing `//# sourceMappingURL=chart.umd.js.map` comment, which was stripped:
the map is not shipped, so the reference was a guaranteed 404 in anyone's
devtools.

It is **not** under `public/assets/js/`, which is deliberate:
`tests/check_asset_budget.php` measures that glob against the 150 KB client
shell budget, and Chart.js is 70 KB gzipped of library loaded on two report
pages. Moving it there fails the budget check for a reason that is not a real
regression.

No date adapter is vendored with it. Chart.js needs one for a *time* axis; the
report charts use a category axis with dates the server has already formatted,
which needs none. Handoff §17 says to ask before vendoring anything beyond
Chart.js, FPDF and a mailer, and an adapter would be a fourth.

Every file added here must be git mode `100644` like everything else, or the
deploy's single-pass `chmod -R u=rwX,go=rX` stops being correct (hosting §6.2
step 5). CI asserts that.
