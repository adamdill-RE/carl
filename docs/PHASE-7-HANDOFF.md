# Carl The Garden Helper — Phase 7 handoff

**Phase 6 is built and green, and v2 is complete.** GDD pest reminders,
succession planting and the companion planting reference are in;
`CARL-HANDOFF.md` §14 has every v2 item struck through. The field-recording
sheet of §13.4, which had blocked Phase 4 and Phase 5, is built. The three
things Phase 6 handoff §3.5 wanted from Recommendations are done.

What is left is a scope document with nothing unbuilt in it, one Claude
Design item, ten owner actions, and a short list of things Phase 6 found and
did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 6 annotated neither.** It came close on weather.md §7.1, which
   gives GDD as a SQL window function and is right that GDD must not be
   stored — but it assumes one base temperature, and the base comes from the
   dataset and differs per pest. §2.2 below is the divergence and its reason;
   the section itself is unchanged and still correct about the thing it is
   about.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing, §17 the
   working agreement. **There is nothing unbuilt left in it except §13.5.**
3. **`docs/deploy.md`** — the runbook. §0 is every measurement taken; Phase 6
   added §0.10 (where the research bytes were) and §0.11 (the biofix). §5 is
   the owner list and it has grown to ten.
4. **`docs/PHASE-6-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current; §4 gains seven entries in §4 below.
5. **§8 below is the working agreement.** Unchanged in substance; one
   addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 18 (`001`–`018`), 39 tables, all `utf8mb4_unicode_ci` — **Phase 6 added one** |
| Routes | 87 (82 + 5) |
| Source / views | 96 PHP classes, 46 templates |
| Tests | **421 tests, 1,898 assertions**, green under `--strict` on MySQL 8.0 **and** MariaDB 10.11 |
| Client shell | 17.4 KB gzipped against a 150 KB budget |
| Cron jobs | six (`deploy.md` §7) — **unchanged** |
| Research template | **version 2** — `companions.csv` added; version 1 still imports |

Everything from Phases 1–5, plus:

- **A twelfth reminder kind, `pest_gdd`.** Accumulated heat rather than the
  calendar: the forecast extends the count past today so the reminder can
  arrive before the moths do. One statement for the whole digest batch, and
  **only** where the research carries a threshold to accumulate towards.
- **A thirteenth, `succession`.** A fortnight after a sowing, while the
  window is open and a round sown today would still ripen.
- **`/succession`** — the planner. Every sowing the research still allows in
  the next 60 days, each date a link into Start a New Plant with the crop and
  the date filled in.
- **`/companions`** — the companion planting reference, and a "Neighbours"
  block on every research card. Twenty pairings with a mechanism and a
  confidence each.
- **`/reports/field-sheet.pdf`** and
  **`/reports/garden/{id}/field-sheet.pdf`** — the field sheet, blank and
  prefilled. §13.4's last unbuilt item.
- **`/admin/analysis`** — what Recommendations has cost.
- **A scope on Recommendations** — season, one garden or one plant, using the
  column Phase 5 left for it. No migration.

**One migration, pure DDL, one new table.** The `deploy.md` "Redeploying"
trap applies: between the deploy and `/setup?key=`, `/companions` and every
plant page's research card return 500. `deploy.md` "The Phase 6 deploy adds
ONE migration" says what order to do it in.

---

## 2. What Phase 6 measured that Phase 7 should not re-derive

### 2.1 The research section was big because it repeated itself

Phase 6 handoff §3.5 called `research` "the section with the worst ratio of
bytes to signal" and said a trim would have to keep the citations and
confidences. Measured before cutting anything, on an account holding a
planting of every plant type in the catalogue: **51,288 bytes, 68% of a
74,893-byte document.**

The finding is one sentence: **twelve distinct citations were cited
ninety-nine times.** The section was not carrying a lot, it was carrying the
same twelve strings over and over. Add the explicit nulls, a
`dataset_version` identical on all ninety-nine rows, and two opaque join ids,
and 38% of it said nothing at all.

Four cuts, none of which removes information — sources into a map cited by
id, nulls dropped, the version stated once, region windows nested under their
plant. **51,288 → 34,379, a third off, with every citation and all 99
confidences asserted still present.** `deploy.md` §0.10 has the table.

The transferable part: **before optimising a document, measure where its
bytes are, not where you think they are.** The obvious candidate here was the
agronomic values, which are 62% of the section and were left completely
alone. The win was entirely in repetition and absence.

### 2.2 The biofix was fine, and the warning about it was backwards

`deploy.md` §0.11 has the table. Short version: the dataset's own note said
the Midwest threshold of 1000 DD50 would be wrong for central Texas "because
emergence is earlier" there. Seven years of Open-Meteo archive for Hillsboro
say 1000 DD50 from 01-01 lands **18 April to 6 May**, and AgriLife reports
central Texas emergence as "as early as April/May". They agree.

**The note feared the right fact and drew the wrong conclusion from it.**
Emergence in Texas *is* earlier in calendar terms — and that is precisely
what a degree-day model produces on its own. The threshold is the constant;
the date is the output. A note that says "the threshold is wrong because the
date is different" has the model backwards, and it cost two phases of
deferral.

Worth generalising: **a deferral with a reason attached is not the same as a
deferral with a correct reason attached.** This one was written down, carried
forward through three handoffs, and read as settled. It took an afternoon to
check.

### 2.3 A prop named for what it counts shadows the thing it counts

Not a Carl finding — it came out of the field-sheet design — but it is the
same shape as §2.2 of the Phase 6 handoff and worth keeping. In the design
format, a declared property and a computed render value share one namespace,
so a property named `lines` beside a computed array named `lines` renders
zero rows. The artboard is not broken, it is *empty*, and an empty ruled box
looks like a deliberate design.

The general form: **when two namespaces merge, a name that describes the
data in both is the one that collides.** `blockCount` → `blocks` is the fix
and it costs nothing to adopt up front.

### 2.4 The suite's assertion count depends on whether the database is fresh

**1,898 on a fresh database, and fewer on a re-run.** Seven assertions (as of
Phase 5; more now) are conditional on first-run state — a fixture that exists
only if nothing created it earlier. Nothing is wrong, but it means **an
assertion count is not a regression signal unless the database was dropped
first.** CI always drops it, so CI's number is the real one. Locally,
`DROP DATABASE` before believing a diff.

This cost a detour in Phase 6: a count that fell from 1,495 to 1,488 looked
exactly like a change that had quietly removed assertions, and was not.

### 2.5 Three existing guards each caught a real mistake

Worth recording because it is an argument for writing more of them:

- **`01_core_test.php`'s "the base path appears in one committed file"**
  caught a `= '/carl/'` default argument — a second place the deployment path
  lived, which would have been silently wrong on any install with a different
  base path.
- **`03_flow_test.php`'s smoke sweep** refuses to pass when a route has no
  substitution, so a new parameterised route cannot slip past unrendered. It
  then caught the route 500ing.
- **The CSP test** caught an inline `style=` attribute in a new template.

None of the three is about the feature it caught. All three are about the
class of mistake a new file makes.

---

## 3. Phase 7

**`CARL-HANDOFF.md` has nothing unbuilt in it except §13.5.** This is the
first phase that starts without a scope document to work from, so it is worth
being explicit that the next phase is a choice rather than a queue.

### 3.1 The palette and the logo (§13.5) — the only spec item left

`public/assets/css/tokens.css` is still the one-file swap and still the only
file in the repository that names a colour. Phase 6 added no colour to it and
two new templates that name none.

One thing changed: **the PDF layer now reads that file too.**
`Carl\Reports\Document` pulls the palette from it, and `Carl\Reports\FieldSheet`
deliberately does not — the field sheet is black on white by design (§2.3 of
this file's sibling reasoning, and the `FieldSheet` docblock). So a palette
swap moves the reports and leaves the field sheet alone, which is correct and
worth not "fixing".

### 3.2 What the companion reference should probably grow into

The table exists, the screen exists, and **nothing acts on either**, which is
the honest state given the evidence. Two things could change that without
overclaiming:

- **A bed-level warning.** Start a New Plant already warns about crop
  rotation beside the row picker, from `familyHistoryByRow()`. The same
  screen could say "there is garlic in this row and you are planting beans",
  which is a `bad` pairing. It would need the confidence shown beside it and
  it must not look like the rotation warning, which has real evidence behind
  it. Do not let a `generic` pairing produce a warning that reads as a fact.
- **More data.** Twenty pairings over eighteen categories is thin, and the
  `verified` four are the interesting ones. The trap-cropping literature has
  more that would qualify.

### 3.3 What Recommendations still wants

Phase 6 did the three things §3.5 listed. What is visible now that it is
running:

- **Nothing displays `analysis.scope` on the answer itself.** The history
  list badges it, but a stored answer read six months later does not say what
  it was about at the top. `covers.subject` is in the document; the page does
  not show it.
- **The per-day cap is per account, not per cost.** Three analyses of one
  plant cost a fraction of three of the season, and the cap does not know.
  `document_bytes` is stored per row, so a cost-weighted cap is available
  without a migration.
- **The answer text is never revisited.** `Analyst::prune()` drops `done`
  rows older than `analysis.retention_days` (365 by default) and
  `analysis_run` rows at 90, so nothing grows unbounded — that was checked,
  not assumed. What nothing does is notice that an answer has *aged*: an
  analysis from March is on the page in September with only its date to say
  so. A stale-answer marker is a line of template.

### 3.4 The reminder set is thirteen, and nothing paginates it

Thirteen kinds is enough that a gardener with a full garden in June can get a
digest with twenty items in it. `DigestMessage::grouped()` orders them by
priority and that is the whole of the triage. Nothing caps the count, nothing
rolls up "and six more waterings". Worth looking at before a fourteenth kind
rather than after.

### 3.5 A second region

Everything in the research schema is region-agnostic and exactly one region
has ever been imported. The importer's region handling, the "regions needing
research" queue and `region_scheme`/`region_code` have never been exercised
against two. A second county would be the cheapest way to find out what
assumed one — and `plantTypesForRegion()`'s overlay is the obvious suspect.

---

## 4. What must not regress

Everything in `PHASE-6-HANDOFF.md` §4, all of which still holds, plus:

1. **GDD arithmetic is in Fahrenheit.** `weather_daily` is Celsius because
   weather.md §6.3 says weather is stored SI; `gdd_base_f` and
   `gdd_threshold` are Fahrenheit because that is what the bulletins print.
   Mixing them accumulates 1.8× too slowly and the reminder is six weeks late
   every year without ever looking wrong. `15_gdd_test.php` lays down days
   whose Fahrenheit total is hand-checkable and asserts the reported number.
2. **A biofix reads backward.** `previousOccurrence()` is the mirror of
   `nextOccurrence()`; reading it forward puts the accumulation's start date
   in the future and the count never begins.
3. **The digest is ten statements for the whole batch, not per user.** Nine
   always plus a conditional tenth. `15_gdd_test.php` builds a batch of five
   and asserts the count did not move, and asserts the tenth is skipped where
   no pest carries a threshold.
4. **The analysis document is twelve statements, scoped or not.** A scope is
   a filter over rows already fetched. If it ever becomes a second query, the
   property in Phase 6 handoff §4.3 is gone.
5. **Every companion pairing carries a reason and a source.**
   `17_companions_test.php` asserts it over the whole table. A row that
   cannot say why is an assertion, and this is the subject where an assertion
   in Carl's voice does the most damage.
6. **A companion pair is stored once, lexically ordered, and read both
   ways.** The unique key is on an ordered pair, so without the normalisation
   two datasets stating opposite directions leave two rows free to disagree.
7. **The field sheet fits on one page and says when it did not.**
   AutoPageBreak is off, so content past the bottom is not pushed to page two
   — it is drawn off the paper and does not print, footer first.
   `18_fieldsheet_test.php` asserts the measured depth against the limit for
   gardens of 1 to 200 plants.
8. **A template version 1 research zip still imports.** The Phase 5 dataset
   is kept in the repository so that promise has a file behind it.
9. **421 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

In priority order. Items 1–3 have been outstanding since Phase 3.

1. **Rotate `cron_key`.** Visible in a Phase 3 screenshot, and it travels in
   URLs. Phase 6 added no route behind it.
2. **Delete `diag_key`** from `config/local.php`; the `/diag` route should be
   shut.
3. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`.
4. **Add the sixth cron job**, `bin/analysis_run.php` hourly at minute 40
   (`deploy.md` §7). Without it the queue never drains.
5. **An Anthropic API key** in `config/local.php`, if Recommendations is
   wanted. Optional: with no key the feature queues and waits.
6. **Import the Phase 6 dataset** (`research_US-48217_2026-08-31.1.zip`).
   Without it `/companions` is an empty page that explains itself and the GDD
   reminder runs off the unvalidated `approx` row.
7. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox.
8. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports
   look clean.
9. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron.
10. Ask Ahosting whether `ea-php82-php-opcache` can be enabled; email
    Open-Meteo describing Carl.

### 5.1 The one platform fact still not established

**Outbound HTTPS to `api.anthropic.com` has never been tried from sh193.**
Unchanged from Phase 6 §5.3 — Phase 6 added no third-party call, so nothing
moved. Phase 0 spike 1 proved five hosts reachable and this is not one of
them. Egress was open to all five, so there is no reason to expect a block,
but it has not been shown.

The first drain is the test and it is safe: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To
settle it without waiting for the hour, queue something and open
`/tasks/analysis-run?key=<cron_key>`.

---

## 6. Claude Design outstanding

Down from three items to two, and the remaining one is the original one.

1. **Logo and palette (§13.5).** `public/assets/css/tokens.css` is the
   one-file swap and carries the chart colours and the PDF colours as well as
   the pages. Phase 6 added three templates and one CSS utility class
   (`input.narrow`); none names a colour.
2. ~~**The static field-recording sheet (§13.4).**~~ **Built, Phase 6** —
   designed, then implemented as `Carl\Reports\FieldSheet`. The design canvas
   carries a fourth artboard, a ledger-style alternate that was not built; if
   a gardener prefers writing the action as a word to ticking a box, it is
   there.
3. **The PDF report layout.** Unchanged; anything Claude Design wants to
   change is inside `Carl\Reports\Document`, and the field sheet is
   deliberately not in that file.

---

## 7. Where the bodies are buried

Everything in `PHASE-6-HANDOFF.md` §7 still applies. Phase 6 added seven.

- **`Request::intInput()` reads `$_POST`, not the query string.**
  `/succession` is the only form in Carl that submits with GET, so a schedule
  can be linked to and reloaded, and `intInput()` there returns the default
  silently — which looks exactly like a reader who changed nothing rather
  than like a bug.
- **FPDF declares a public `Footer()`, and PHP method names are
  case-insensitive.** A private `footer()` on a subclass is a fatal
  "access level must be public" at class-load time. `Header()` is the same.
- **`PlantingRepository::livingInGarden()` returns ids and quantities, not
  names.** It exists for the zone-watering fan-out. Anything wanting to print
  a plant wants `listWithDetail(['garden_id' => …, 'living' => true])`; the
  smoke sweep caught this as a 500.
- **A `sc-if` between a grid and its cells collapses the grid.** In the
  design format, cells emitted from a repeat need every wrapper between them
  and the grid to be `display: contents`. Flex rows cannot fail that way, and
  every table on the field-sheet canvas is one for that reason.
- **A label whose `font-size` is on a `<span>` inside the block is laid out
  by the BLOCK's strut.** A 9px span in a 16px div costs an 18px line, not a
  10px one — which is how a print layout that measures correctly on paper
  clips on the page. Put the size on the element that forms the line box.
- **A dotted or 60%-grey hairline is not a hairline on a mono laser.** At 600
  dpi it halftones into a broken line or drops entirely. Every rule Carl
  prints is solid black, and the one fill is a solid bar with knocked-out
  text.
- **`Config::int()` falls back to its default for anything non-numeric,
  including an array.** The analysis price table is therefore read with
  `get()` and cast at the call site. Reading a nested array through `int()`
  does not error and does not warn — it returns the default, which is the
  same shape of silent-wrong-number the cost page's null-versus-zero rule
  exists to avoid.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8,
`PHASE-4-HANDOFF.md` §8, `PHASE-5-HANDOFF.md` §8 and `PHASE-6-HANDOFF.md` §8,
all of which earned their place again. One more:

- **A deferral's stated reason is a claim, not a fact — check it before
  inheriting it.** "Texas biofix needs validating first" was written in Phase
  1, carried through three handoffs, and read every time as a known
  blocker. It took an afternoon of arithmetic to find that the reason given
  was backwards: the threshold was right, and the thing the note called a
  problem was the model working. Two phases of deferral rested on one
  sentence nobody had checked. When a handoff hands you a reason, the
  cheapest useful thing you can do with it is try to falsify it — and when it
  survives, write down what you did, so the next phase inherits a check
  rather than a sentence.
