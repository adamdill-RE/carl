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

| Library | Arrives in | Goes where |
| --- | --- | --- |
| FPDF (single file) | Phase 4, for plant and garden PDFs | `vendor/fpdf/fpdf.php` |
| PHPMailer (single-file build) | Phase 3, only if hand-rolling SMTP proves brittle | `vendor/phpmailer/` |
| Chart.js (one file) | Phase 4 | `public/assets/vendor/chart.umd.js` — it is a browser asset, so it is **not** in this directory |

Every file added here must be git mode `100644` like everything else, or the
deploy's single-pass `chmod -R u=rwX,go=rX` stops being correct (hosting §6.2
step 5). CI asserts that.
