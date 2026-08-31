# Carl The Garden Helper — Phase 5 handoff

**Phase 4 is built and green.** Reports, charts, PDFs and `/export/claude.json`
all work; `CARL-HANDOFF.md` §14 has nothing left in Phase 4 except §13.4, which
is blocked on Claude Design and is described in §6 below.

What is left is v2 — the Reports menu and Recommendations — plus a short list
of things Phase 4 found and did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   Both now carry one Phase 4 annotation each; see §2.1 and §2.4.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing, §17 the
   working agreement.
3. **`docs/deploy.md`** — the runbook. §0 is every measurement taken, and it
   gained two in Phase 4 (§0.7 and §0.8) that are the most reusable thing this
   phase produced.
4. **`docs/PHASE-4-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current, with two corrections noted in §2
   below.
5. **§8 below is the working agreement.** Unchanged in substance; one addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 15 (`001`–`015`), 35 tables, all `utf8mb4_unicode_ci` — **Phase 4 added none** |
| Routes | 72 (67 + 5) |
| Source / views | 82 PHP classes, 39 templates |
| Tests | **276 tests, 1,230 assertions**, green under `--strict` on MariaDB 10.11 |
| Client shell | 15.8 KB gzipped against a 150 KB budget (Chart.js excluded, by design) |
| Vendored | Chart.js 4.5.1 (70 KB gz, report pages only), FPDF 1.86 |

Everything from Phases 1–3, plus:

- **`/api/plant/{id}/series` and `/api/garden/{id}/series`** — the JSON a chart
  reads. One statement for weather, one for events, whatever the size.
- **Charts** on `/plants/{id}` and `/gardens/{id}`: temperature max/min band,
  rainfall bars, ET₀ line, events as markers, one weather series visible at a
  time, provisional days marked on every panel.
- **`/report/plant/{id}/pdf` and `/report/garden/{id}/pdf`** — FPDF documents
  carrying the research card, the event table, the charts the browser posted
  up, the photographs and the citations.
- **`/export/claude.json`** — one document per user, shaped for pasting into a
  Claude conversation. The v2 Recommendations bridge.

**Nothing in Phase 4 touched the database.** No migration, no schema change,
so the `deploy.md` "Redeploying" trap (code ahead of schema) does not apply to
this deploy. Check `/status?key=` immediately afterwards anyway.

---

## 2. What Phase 4 measured that Phase 5 should not re-derive

### 2.1 `memory_get_peak_usage()` cannot see GD

This is the most important thing in this file, and it is not about reports.

Measured 2026-08-31: five open 1920×1440 truecolour images move the process's
resident set by **53 MB** and move `memory_get_peak_usage(true)` by **nothing
at all.** libgd allocates its pixel buffers outside the Zend allocator.

Two consequences, pulling in opposite directions:

- `memory_limit` is enforced *by* that allocator, so decoded images do not
  count against it. Twenty open photographs will not raise "Allowed memory
  size exhausted".
- The host's own per-process ceiling still applies, and a shared host killing
  a process for exceeding one leaves no PHP error — the page just does not
  arrive.

So **any memory budget for code that touches GD must be checked against the
process**, and `hosting.md` §4's `memory_limit` row now says so.
`Carl\Support\ProcessMemory` reads `VmHWM`; `deploy.md` §0.7 has the numbers.

A second trap sits behind the first: resident memory is a high-water mark a
long-lived process never gives back, so a delta measured around one operation
inside a test suite that has already peaked higher reads **zero** however much
that operation used. The only honest per-operation figure comes from a fresh
process. `tests/measure_report.php` is that process and
`Harness::measureInChildProcess()` is how the suite runs it.

### 2.2 The PDF budget, measured

`CARL-HANDOFF.md` §13.2 asked for under 10 s and 64 MB on a 20-photo report.
Measured in a fresh process, three times, stable: **1.9 s and +16 MB
resident.** `deploy.md` §0.8 has the fixture and the breakdown. There is a
large margin; the thing that would eat it is opening more than one photograph
at a time, and the child-process test is what catches that.

### 2.3 FPDF is not one file

`PHASE-4-HANDOFF.md` §3.3 and `vendor/README.md` both said "FPDF, vendored as
one file at `vendor/fpdf/fpdf.php`". It cannot be: `fpdf.php` loads its *core*
font metrics from `font/helvetica.php` and its siblings at the first
`SetFont()`, and without them the first line of text throws. All fourteen
metric files ship (64 KB) plus `license.txt`. `vendor/README.md` is corrected
and says who corrected it.

### 2.4 One weather series at a time is right, and it means two as well as four

`weather.md` §7.3 says four overlaid weather variables are unreadable at
380 px. Built and looked at in a headless browser: it is right about *two*.
The ET₀ panel first carried rainfall as a dashed comparison line and read as a
picket fence the eye could not separate from the ET₀ curve. Removed; the
comparison it was for is one number in the totals table. `weather.md` carries
the annotation.

### 2.5 The inline-script rule *was* test-enforced; the CDN half was not

`PHASE-4-HANDOFF.md` §3.2 says "The style rule is test-enforced; the script
rule is not, and a violation is silent in the browser console." Half wrong:
`01_core_test.php` already asserted that no template carries an inline
`<script>` body or an `on*=` handler. What nothing checked was an off-site
`src` or `href` — a CDN, which is exactly what §3.2 was warning about. That
check now exists too, and it fired on the first thing it was pointed at (a
docblock in `partials/charts.php` that spelled out a script tag, which is a
false positive worth having over a check that is too clever to fire).

---

## 3. Phase 5

`CARL-HANDOFF.md` §14 lists v2 as: Reports menu; Recommendations (Claude
analysis); End Growing Season; crop rotation warnings by plant family; GDD
pest reminders; succession planting; companion planting reference.

Two of those are now cheap, and the order matters.

### 3.1 Recommendations, on `/export/claude.json`

The document is built and it is the bridge §13.3 describes. What is missing is
the round trip: a route that sends it to the Claude API and stores the reply
against the user, plus a screen that shows the reply with its date.

**This is the first third-party call Carl would make that is not weather or
mail, and the rule in `PHASE-3-HANDOFF.md` §5 is absolute: no third-party call
on the request path.** So it is a cron-driven job with a run table and a
bounded retry, exactly like `WeatherSync` and the mail outbox — a user asks
for an analysis, a row goes in a queue, the drain calls the API, the answer
appears on the next page load. `Carl\Mail\Outbox` is the pattern to copy;
`weather_sync_run` is the run table to copy.

Two things to decide before writing code:

- **Size.** A five-year account's document could be large. The API has a
  context limit and the document has no size bound. Either cap the date range
  the analysis covers, or summarise the weather into weekly rows before
  sending. Measure a real document first — `/export/claude.json` on the live
  account will say what "large" means here.
- **The key.** It belongs in `config/local.php` beside the mail credentials,
  and nothing in the repository may ever hold it.

### 3.2 The Reports menu

There are now four things a user can download and two report pages, and the
only way to reach any of them is from the plant or the garden. A Reports menu
is a screen with links and no new data access. Small, and it is what makes the
rest discoverable.

### 3.3 End Growing Season

Bulk-ends every living planting in a garden on one date. It is a batch write
over `EventRepository::recordBatch()`, which already exists and already
recomputes state per planting. The care needed is in the confirmation screen,
not the code: it is the one destructive action in the application.

### 3.4 Crop rotation warnings

`plant_family` is stored on every `plant_type` and `garden_row_id` on every
planting, so "this bed grew a Solanaceae last year" is one statement. The
warning belongs on the Start a New Plant form beside the row picker.

### 3.5 The tokenised set-password link

`PHASE-3-HANDOFF.md` §9.4 called this "the Phase 4 improvement to make here"
and Phase 4 did not make it. It is still the right change: a temporary
password in an inbox sits there until the account is first used, and a
tokenised link removes the password from the mail entirely. `Carl\Auth\TokenStore`
already exists for the unsubscribe token and is the thing to reuse.

---

## 4. What must not regress

Everything in `PHASE-4-HANDOFF.md` §4, all of which still holds, plus:

1. **The statement counts on the new endpoints.** `11_reports_test.php`
   asserts that a 200-day planting with 40 events costs the same three
   statements as a 2-day one with one event. Anything that adds a lookup
   inside those loops breaks it, and the test is the only thing that notices.
2. **The report budget test runs in a child process.** If it is ever
   "simplified" back into the parent it will pass unconditionally and mean
   nothing (§2.1).
3. **`/export/claude.json` is not formula-injection guarded.** The docblock
   and a test both say why. Running JSON values through `Csv::field()` would
   rewrite every negative number in the file.
4. **Chart.js stays at `public/assets/vendor/`.** Moving it under
   `assets/js/` fails the asset budget check for a reason that is not a real
   regression.
5. **`tokens.css` is still the only file that names a colour.** The charts
   read the tokens through `getComputedStyle`; the PDF reads them through
   `Carl\Support\Tokens`, which parses the same file. Both follow the palette
   swap of §13.5 automatically. Do not hard-code a colour in either.
6. **276 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

Unchanged from `PHASE-4-HANDOFF.md` §5, and none of it blocked Phase 4. In
priority order as they now stand:

1. **Rotate `cron_key`.** It was visible in a screenshot during the Phase 3
   session and it travels in URLs.
2. **Delete `diag_key`** from `config/local.php`; the `/diag` route should be
   shut.
3. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`. They are
   two extra full weather syncs a day against a shared quota.
4. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox, so the
   daily digest reaches a mailbox that gets read.
5. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports look
   clean.
6. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron. Nothing
   depends on the answer; it settles whether the Open-Meteo quota is the
   owner's alone.
7. Ask Ahosting whether `ea-php82-php-opcache` can be enabled.
8. Email Open-Meteo describing Carl (internal, unsold, no ads); keep the reply
   in `docs/`.

**One thing changed for the deploy:** `.cpanel.yml` no longer creates
`var/reports/`. Nothing writes there — the PDF is built in memory and sent,
never stored (`CARL-HANDOFF.md` §13.2, "streams the file; nothing kept"). The
directory on the server can be removed by hand or left to rot; nothing reads
it either way.

---

## 6. Claude Design outstanding

1. **Logo and palette.** `public/assets/css/tokens.css` is still the one-file
   swap, and Phase 4 made it carry more weight: the chart colours and the PDF
   colours are both read from it at runtime, so the swap now reaches the
   reports and the printed documents without touching any other file. The
   charts reuse existing semantic tokens (`--carl-error` for the high
   temperature, `--carl-info` for the low and for rain, `--carl-accent` for
   ET₀, `--carl-primary` for logged events) rather than introducing
   `--carl-chart-*` names — improvising a palette is what §17 says not to do.
   If Claude Design would rather the charts had their own names, that is a
   one-file change here too.
2. **The static field-recording sheet** (§13.4). Still the only thing blocking
   the per-garden prefilled sheet, which is the one Phase 4 item not built.
   `Carl\Reports\Document` is the FPDF layer it will use, and it already has
   headings, fact blocks, tables that repeat their header across a page break,
   and a photo grid.
3. **The PDF report layout.** Phase 4 shipped a serviceable default rather
   than wait: A4, 15 mm margins, a running header, "page n of m", section
   headings with a rule, label/value fact blocks, and charts capped at 82 mm
   so two fit on a page. `deploy.md` §0.8 says what it produces. Anything
   Claude Design wants to change is inside `Carl\Reports\Document`.

---

## 7. Where the bodies are buried

Everything in `PHASE-4-HANDOFF.md` §7 still applies. Phase 4 added five.

- **PHP method names are case-insensitive, so a subclass collides with FPDF.**
  `Carl\Reports\Document::line()` was a fatal error at class-declaration time,
  because FPDF has a public `Line()`. It is `hairline()` now. If you add a
  method to that class, check it against `get_class_methods('FPDF')` first.
- **FPDF's core fonts speak Windows-1252, not UTF-8.** Every string goes
  through `Document::t()`. Without it a degree sign or a curly apostrophe —
  both of which Carl writes — is mojibake, silently and only in the PDF.
  `iconv` is *not* in hosting §4's list of present extensions; `mbstring` is.
- **A `display:none` canvas has no pixels.** `toDataURL()` on one returns a
  blank image, so a hidden chart would go into the PDF as an empty rectangle.
  The inactive chart panels are laid out and hidden with `visibility` instead,
  which is why `.chart-panel` is absolutely positioned rather than hidden.
- **A chart canvas is shaped by the phone it was drawn on.** At 380 px it is
  about 3:4, and printed at the full 180 mm column that is 132 mm tall — one
  chart per page with two thirds of each page blank. `Document::CHART_MAX_HEIGHT`
  caps it at 82 mm and centres what is left. Found by printing one.
- **A test that measures its own process's memory measures nothing.** See
  §2.1. This is the one most likely to be re-broken by someone tidying up.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8 and
`PHASE-4-HANDOFF.md` §8, all of which earned their place again this phase. One
more:

- **Measure the thing the constraint is on, not the thing that is easy to
  measure.** `memory_get_peak_usage()` is right there, it returns a number
  that looks like memory, and for a report full of photographs it is off by a
  factor of three and blind to the part that matters. The budget in §13.2 is
  about a process; the number had to come from a process. Every one of the
  Phase 4 measurements that turned out to be worth recording was one where the
  obvious instrument was measuring something adjacent to the question.
