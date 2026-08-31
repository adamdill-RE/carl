# Carl The Garden Helper — Phase 4 handoff

**Phase 4 is reports and PDFs.** `CARL-HANDOFF.md` §14 scopes it as: Chart.js
plant and garden charts, FPDF plant and garden PDFs, the per-garden prefilled
field sheet, and `/export/claude.json`.

Phases 1 through 3 are built, deployed and running against real data at
<https://www.reshiftmanager.com/carl/>. Mail went live 2026-08-31 and
authenticates cleanly. Nothing in this phase is blocked on the owner.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
2. **`docs/CARL-HANDOFF.md`** §13 (reports, PDFs, exports) is the
   specification for this phase. §3 is the repository layout, §17 the working
   agreement.
3. **`docs/deploy.md`** — the runbook. §0 is every measurement taken on the
   live host; read it before assuming anything about the server.
4. **`docs/PHASE-3-HANDOFF.md`** §5 and §9 — what must not regress, and where
   the build deliberately departed from the spec.
5. **§8 below is the working agreement.** It has one rule Phase 3 earned the
   hard way.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 15 (`001`–`015`), 35 tables, all `utf8mb4_unicode_ci` |
| Routes | 67 |
| Source / views | 75 PHP classes, 38 templates |
| Tests | 247 tests, 962 assertions, green under `--strict` on MySQL 8.0 and MariaDB 10.11 |
| Client shell | 10.9 KB gzipped against a 150 KB budget |

Working end to end: accounts and forced password reset, onboarding, gardens
and rows, plantings of all three kinds with backdating, the full event log
with photos, lists, research import, the regions queue, nightly weather sync
(archive + forecast), the MOTD matrix, the FAO-56 watering model, NWS alert
polling, CSV exports, the mail outbox with SMTP and Brevo drivers, the daily
digest with all nine reminder kinds, One-Click unsubscribe, and today's items
on the main menu.

Five cron entries are live and correct (`deploy.md` §7). The cron clock is US
Eastern; PHP is pinned to UTC.

---

## 2. What Phase 3 measured that Phase 4 should not re-derive

### 2.1 Mail authenticates, and Brevo is not needed

Measured 2026-08-31 against a real Gmail account with `driver = smtp`:

```
dkim=pass  header.i=@reshiftmanager.com header.s=default
spf=pass   designates 152.160.193.193 as permitted sender
dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=reshiftmanager.com
```

All three, and **aligned** — DKIM signs `d=reshiftmanager.com`, the envelope
sender and `header.from` are the same domain, so DMARC passes on either leg.
`deploy.md` §7.5 carries the full header trail.

The `api` (Brevo) driver is built, tested and unused. Leave it that way unless
a volume ceiling or a filtering incident forces it: a second sending identity
means merging SPF records, and a domain with two SPF records has none.

### 2.2 Certificate verification works; never turn it off

`SmtpMailer` runs with `verify_peer` and `verify_peer_name` on and connects to
`mail.reshiftmanager.com:465` (implicit TLS) without complaint. AutoSSL covers
the subdomain. If a future renewal ever misses it, the fix is to re-run
AutoSSL or set `'host' => '<server hostname>'` — **never** to disable
verification. That connection carries the mailbox password on every send.

### 2.3 Two IPs, and which is which

The account's application traffic leaves from `152.160.208.75`. Mail leaves
from `152.160.193.193`, which is `sh193.sameservers.com` — the server's own
address, the one the PTR and the alternate HELO match.

`deploy.md` §0.5 originally named `.208.75` as the *server's* shared address
and built its Open-Meteo quota conclusion on that. The header trail shows
otherwise. The section is corrected; the one-line `curl https://api.ipify.org`
that settles it is still outstanding and still depends on nothing.

### 2.4 The error page lies if you let it

The Phase 3 deploy took the site down for as long as it took to notice, and
the error page made it worse: a 500 rendered "There is nothing at that
address" under a headline saying the server broke. Whoever is diagnosing acts
on the sentence, not the headline, and goes looking for a routing fault.

Fixed, and an admin-only hint now names the actual cause for a missing table.
**The lesson is the deploy order, and it is in `deploy.md` under "Redeploying":
check `/status?key=` immediately after every deploy.** The deploy copies code
and never touches the database, so between the two the code is ahead of the
schema and every page touching a new table is a 500. The main menu is one of
those pages.

Phase 4 adds no migrations if the shape below holds. If you add one, this is
the trap.

---

## 3. Phase 4

Do these in order. Each is independently shippable, and the first two carry
all the risk.

### 3.1 The series endpoint (`CARL-HANDOFF.md` §13.1)

`/api/plant/{id}/series` returning JSON: weather for the planting's covered
dates plus its events, in **one statement for weather and one for events**.
User-scoped through the repository base class like everything else — a series
endpoint that leaks another account's plant is the same bug as a page that
does, and the base class is where that is enforced, not here.

Mark provisional days. The attribution line is generated from `source_model`
(`weather.md` §10), not hard-coded — NCEI rows may be mixed in later and the
credit has to stay honest.

Statement count is a test, not a hope. `tests/cases/10_digest_test.php` has
the pattern: assert that twenty of a thing cost about as many statements as
one.

### 3.2 Charts (`CARL-HANDOFF.md` §13.1)

Chart.js, vendored as **one file at `public/assets/vendor/chart.umd.js`** —
that exact path. It is outside the `assets/js/*.js` glob that
`tests/check_asset_budget.php` measures, which is deliberate: the 150 KB
budget is the *shell*, and a library loaded only on report pages is not the
shell. Put it in `assets/js/` and you will fail the budget check for a reason
that is not a real regression.

On a plant: temp max/min band, rainfall bars, ET₀ line, events as markers.
**One weather series visible at a time on mobile**, toggled — 380 px is the
design width, and it is the width to check the result at.

`script-src 'self'` and `style-src 'self'`. No inline `<script>`, no inline
`style=`, no CDN. The style rule is test-enforced; the script rule is not, and
a violation is silent in the browser console.

### 3.3 PDF reports (`CARL-HANDOFF.md` §13.2)

FPDF, vendored as one file at `vendor/fpdf/fpdf.php`. The deploy already
copies `vendor/` (`.cpanel.yml`); `vendor/README.md` is the placeholder
keeping the directory alive.

"Download PDF" posts the visible chart canvases as PNG data URLs to
`/report/plant/{id}/pdf`. The document carries the research card, the event
table, the charts, photos (GD-downscaled to 800 px, **max 20 per report**, and
say so in the document when it truncates), and citations.

**The budget is the constraint that shapes this, and it is tight:**

| | |
| --- | --- |
| `max_execution_time` | 30 s |
| `memory_limit` | 128M |
| `post_max_size` | 8M — the chart PNGs travel *up* through this |
| `upload_max_filesize` | 2M |

§13.2 sets the target at under 10 s and 64 MB on a 20-photo report. **Measure
it; do not assume it.** GD holds a decoded image in memory at roughly
width × height × 4 bytes, so twenty source photos are the risk, not the PDF.
Downscale and free each one before touching the next.

`Response::binary()` and `Response::streamed()` both exist and the CSV export
uses the latter. §13.2 says stream it and keep nothing — which means
`var/reports/` is currently a directory the deploy creates and no code uses.
Either use it or drop it from `.cpanel.yml`; do not leave it as decoration.

### 3.4 The prefilled field sheet (`CARL-HANDOFF.md` §13.4)

Per-garden, via FPDF, using the same layout as the static sheet: rows and
living plants listed. **Blocked on Claude Design** — the static
`public/assets/field-sheet.pdf` does not exist yet, and the layout to mirror
comes with it. Everything else in §3.3 can ship first.

### 3.5 `/export/claude.json` (`CARL-HANDOFF.md` §13.3)

One JSON document per user: plantings, events, gardens, weather for the
covered dates, and the research values in force, shaped for pasting into a
Claude conversation. This is the bridge to the v2 "Recommendations" feature.

It sits beside the three CSV exports in `ExportController`, and inherits their
rules: user-scoped, keyset-paginated, chunked. It does **not** inherit the
formula-injection guard — that is a spreadsheet concern, and `Csv::field()`
would corrupt JSON values. Note in a comment why it is absent, so the next
reader does not "fix" it.

Size is the open question. A user with five years of weather and a few hundred
plantings could produce something large enough to matter against `memory_limit`.
Build the document incrementally rather than assembling an array and calling
`json_encode` on it once.

---

## 4. What must not regress

Everything in `PHASE-3-HANDOFF.md` §5, and specifically:

1. **User scoping.** Every repository extends the base class that enforces
   `user_id`; it throws if constructed without one. New endpoints go through
   it. The isolation tests in `03_flow_test.php` are the proof, not the code
   review.
2. **No third-party call on the request path.** Weather, alerts and mail are
   all cron-driven with browser twins guarded by `cron_key`. A chart endpoint
   that fetches anything is a regression.
3. **CSP.** `style-src 'self'`, `script-src 'self'`. No inline anything.
4. **The asset budget.** 150 KB gzipped for the shell. Vendored report
   libraries live in `assets/vendor/`.
5. **Statement counts.** Anything that loops over rows gets a test asserting
   the count does not grow with them.
6. **Migrations are immutable once applied,** numbered, and pure-DDL or
   pure-DML, never mixed.
7. **The temporary-password-on-screen path.** It works with no mailbox, when a
   message bounces, and when standing up a fresh install. Mail supplements it;
   it never replaces it.
8. **247 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

None block Phase 4.

1. **Rotate `cron_key`.** It was visible in a screenshot during the Phase 3
   session and it travels in URLs.
2. **Delete `diag_key`** from `config/local.php`. The Phase 0 spikes are
   recorded in `deploy.md` §0; the `/diag` route should be shut.
3. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`. They are
   two extra full weather syncs a day against a shared quota.
4. **Add a cPanel forwarder** `carl@reshiftmanager.com` → the owner's real
   inbox, so the daily digest reaches a mailbox that gets read.
5. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports look
   clean. They arrive at `carl@reshiftmanager.com`.
6. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron. Does it print
   `152.160.208.75`? If so the Open-Meteo quota is the owner's alone. Nothing
   depends on the answer.
7. Ask Ahosting whether `ea-php82-php-opcache` can be enabled. If it is,
   `opcache.validate_timestamps` becomes a deploy concern.
8. Email Open-Meteo describing Carl (internal, unsold, no ads); keep the reply
   in `docs/`.

---

## 6. Claude Design outstanding

1. **Logo and palette.** `public/assets/css/tokens.css` is a neutral
   placeholder defining exactly the `--carl-*` names to deliver. It is the
   only file in the repository that names a colour, so the palette is a
   one-file swap.
2. **The static field-recording sheet** (§13.4). Blocks §3.4 above.
3. **The PDF report layout** — Phase 4 wants it, and §3.3 will ship a
   serviceable default without it rather than wait.

---

## 7. Where the bodies are buried

Things that cost real time in Phases 1–3 and would cost it again:

- **The router reads a constraint as everything up to the first `}`.** So
  `{token:[0-9a-f]{64}}` silently truncates. Use `+` and enforce length in the
  lookup.
- **MySQL permits any number of NULLs in a UNIQUE index.** This shaped
  `subject_key` and `place_key`; it will shape anything similar.
- **A `STORED` generated column cannot carry `ON DELETE CASCADE` under MySQL
  8** (error 1215). `check_collation.php` asserts there are none.
- **`config/` exists twice on the account.** The running app reads
  `/home/reshiftmanager/carl-app/config/local.php` and never the git
  checkout's. The Mail admin page prints the path for this reason.
- **`local.php` is hand-edited on a host with no shell to lint it.** A stray
  character is a parse error and every page 500s. The bootstrap catches that
  case and prints the file and line without echoing a value.
- **`%` is special in a crontab** — it becomes a newline.
- **Controller action names collide with `Controller`'s accessors.** `events()`
  did; the export actions are `plantsCsv`/`eventsCsv`/`weatherCsv` because of
  it.
- **The test harness's `--strict` mode must honour `@`.** It did not, and every
  deliberate `@unlink` became a failure.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the two additions in `PHASE-3-HANDOFF.md` §8, plus
one more that Phase 3 earned:

- **Run it, do not just build it.** Five Phase 1 bugs and three Phase 3 bugs
  were invisible to the test suite and to code review. Each was found by
  running the thing — a live sync, a headless browser at 380 px, a dry-run
  deploy into a sandbox laid out like the account, a real send read at the
  receiving end.
- **When a documented fact turns out to be wrong, correct the document and say
  who corrected it.** `hosting.md` and `weather.md` are copied-in authorities;
  annotate, do not silently rewrite.
- **A measurement that contradicts an assumption is worth more than the
  feature it came from.** The mail test's real value was not that mail works.
  It was the two IPs in the headers, which showed `deploy.md` §0.5 would have
  led a reader to the wrong conclusion from the right number. Look at what the
  evidence says beyond the question you asked it.
- **Ask before vendoring** anything beyond Chart.js, FPDF and a mailer.
