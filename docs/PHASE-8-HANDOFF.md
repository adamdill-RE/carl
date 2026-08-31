# Carl The Garden Helper — Phase 8 handoff

**Phase 7 is built and green.** Splitting a planting
(`docs/PLANTING-SPLIT-SPEC.md`) is in: moving part of a planting somewhere
else now makes a planting, descended from the first, and every feature that
reads a planting's single location keeps working because every planting still
has exactly one. `moved`, which had been in the event vocabulary since the
first design and implemented nowhere, finally means something.

The spec is marked built and carries a new §9: **five places where the build
diverged from it, and why.** Read that before reading the spec as a
description of the code.

What is left is the QR tag spec the split was sequenced ahead of, the Claude
Design item that has outlived four phases, ten owner actions, and what Phase 7
found and did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 7 annotated neither, and had no reason to:** the split touches no
   weather path and no platform constraint that is not already written down.
   It did lean on hosting §7 twice — once for the DDL/DML split of the
   migration, once for placeholder reuse — and both times the document was
   right and complete.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing and now
   carries a Phase 7. **There is nothing unbuilt left in it except §13.5.**
3. **`docs/PLANTING-SPLIT-SPEC.md`** — built, and §9 is the delta.
4. **`docs/QR-TAGS-SPEC.md`** — the next scope document, and the reason the
   split went first. §3 below is what changed for it.
5. **`docs/deploy.md`** — the runbook. §0 is every measurement taken. The
   "Redeploying" section gained a Phase 7 entry, and it is the most invasive
   migration this application has had: read it.
6. **`docs/PHASE-7-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current; §4 gains eight entries in §4 below
   and §7 gains five in §7.
7. **§8 below is the working agreement.** Unchanged in substance; one
   addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 20 (`001`–`020`), 39 tables, all `utf8mb4_unicode_ci` — **Phase 7 added two, and they are a pair** |
| Routes | 87 — **unchanged**; the split is a change to a form, not a screen |
| Source / views | 96 PHP classes, 46 templates — **unchanged in count** |
| Tests | **464 tests, 2,222 assertions**, green under `--strict` on MySQL 8.0 **and** MariaDB 10.11 |
| Client shell | 17.4 KB gzipped against a 150 KB budget — **unchanged** |
| Cron jobs | six (`deploy.md` §7) — **unchanged** |
| Research template | version 2 — **unchanged**; a zip that imported yesterday imports today |

Everything from Phases 1–6, plus:

- **A planting can be split.** Log Plant Activity → **Transplanted** → *how
  many?* `[6] of 94` → *where to?* → done. The six become a planting of their
  own, in the new bed, descended from the tray; the tray keeps ninety-four and
  stays where it was. **The word "split" appears nowhere in the interface.**
- **The same for Up-potted and Moved.** The three relocations share one block
  on the form and one code path, and `EventType::isRelocation()` is the list.
- **A twenty-second event type, `split_out`**, recorded on the parent with a
  negative delta and a link to the child. It is not attrition, and
  `EventType::isDispersal()` is what keeps the two apart by name rather than
  by sign.
- **`planting.ended_reason`.** A tray every plant of which was transplanted
  out is `ended` with `ended_reason = 'dispersed'`, and the page says "Fully
  moved out". Before this, `derive()` returned `state = ended` with
  `ended_at = null` — an inconsistent pair, and a UI that called a fully
  planted tray dead.
- **A survival rate that counts what died.** `quantity_lost` is attrition
  only; `PlantingState::survivalPercent()` is the one expression, shared by
  the plant page and the PDF, where there had quietly been two.
- **A lineage panel on both ends**, and a "moved out of another" badge in the
  plant list. A link, never a merged timeline.
- **Lineage in both exports** and in the analysis document, where it is free.

**Two migrations, and `deploy.md` "The Phase 7 deploy adds TWO migrations"
says why they cannot be one and why they must be run together.** This is the
most invasive migration the application has had: 019 adds four columns to
`planting` and one to `plant_event`, and between the deploy and `/setup?key=`
every plant page, the plant list, the log screen, both CSV exports and the
PDFs return 500.

---

## 2. What Phase 7 measured that Phase 8 should not re-derive

### 2.1 The spec's own reading of the code was right, and that is worth saying

`PLANTING-SPLIT-SPEC.md` §3 makes four claims from reading the code, and three
of them are the reason the change was small. All four held:

- **`quantity_live` is written in exactly one statement.** True, and it is
  still one: `quantity_lost` and `ended_reason` were added to the same
  `UPDATE` rather than getting writers of their own.
- **State and quantity are derived, not maintained.** True. A new event type
  with a negative delta flowed through the quantity arithmetic **with no
  change to it at all** — `derive()` already summed every non-null delta. The
  edit to `derive()` was entirely about recording *why* a planting reached
  zero.
- **`EventType::MOVED` is defined and implemented nowhere.** True. It cost one
  line in `actionsFor()` to become real.
- **44 files mention `planting`; 24 files and 72 references mention the
  quantities.** The change touched 9 source files, 4 views and 5 test files.

The transferable part is not "the spec was good". It is that **a spec written
by reading the code, with the line numbers in it, made a two-day change out of
one that looked like a week** — and that the one number it got wrong (§4.5's
"exactly one site today"; there were two) was the one it had not gone and
counted.

### 2.2 The riskiest edit was not where the risk was

§6 says the change is "riskier than it is big", and points at
`PlantingState::derive()`: the one function every other feature's correctness
rests on, changed in a way that passes every existing test because nothing
before it split anything.

That was right about the risk and wrong about the location. `derive()` took
twelve lines and behaved. **Every real problem was in the migration and the
fixtures:**

- The migration mixed DDL and DML and was rejected by a guard (§2.3).
- Five test fixtures inserted plantings with raw SQL, so a NOT NULL column
  with no default broke them — loudly, at the insert, naming the column.

Both are the same shape: **the new invariant was enforced at the schema, and
what it caught was every write path that was not the one writer.** That is the
argument for putting the invariant in the schema rather than in the
repository, and it is worth remembering the next time a column is tempting to
make nullable "so the fixtures still work".

The five fixtures now go through `PlantingRepository::insert()`, which is
better for a reason unrelated to this change: a fixture that writes a planting
the application could not write is a fixture that can drift from the
application.

### 2.3 The DDL/DML guard caught its first real mistake

`01_core_test.php`'s "no migration mixes DDL and DML" has been in the suite
since Phase 1 and had never fired. It fired on `019`, which was an
`ALTER TABLE` and a backfill `UPDATE` in one file — the obvious way to write
it, and unrollbackable on a host with no staging copy.

That is now four existing guards that have each caught a real mistake in a new
file (Phase 7 handoff §2.5 has the first three), and all four share a
property: **none of them is about the feature it caught.** They are about the
class of mistake a new file makes. The argument for writing more of them is
now four for four.

### 2.4 A cached derived column beats a subquery when two callers hold rows

`quantity_lost` could have been a correlated subquery in `LIST_SELECT` — no
extra statement, no new column, no migration. It is a column because **both
callers that need it hold a planting ROW and no events**: `PdfBuilder` and
`views/plants/show.php`. A subquery would have run once per row of every plant
list to answer a question two pages ask.

The rule that generalises: a derived value belongs in the query when the
caller is running a query anyway, and on the row when the caller has a row. It
is only safe on the row here because there was already exactly one writer of
the other derived quantities to add it to.

---

## 3. Phase 8

### 3.1 QR tags (`docs/QR-TAGS-SPEC.md`) — the sequenced next thing

The split spec's §7 is explicit that this comes second, and it gave two
reasons. Both are now cashed in:

- **§10 Q1 has dissolved.** "May twelve tags point at one planting?" A
  planting is location-singular by construction, so **one tag per planting is
  simply correct** and there is nothing to decide. There is no rule to invent
  for "a tag on a planting that later splits" either: the tag stays on the
  planting it was bound to, which is the ninety-four, and the six get their
  own.
- **The screen it wants is built.** "Scan a tag for the six you're moving" is
  one line on the relocation block of `views/plants/log.php`, next to the
  quantity — a field, not a section. The block is already shared by all three
  relocations, so the tag arrives on all three at once.

Read the QR spec against §9 of the split spec before starting: §9.4's truth
table is where a tag binding has to attach.

### 3.2 The palette and the logo (§13.5) — the only spec item left

Unchanged from Phase 7 §3.1. `public/assets/css/tokens.css` is still the
one-file swap and still the only file in the repository that names a colour.
**Phase 7 added no colour and no CSS**: the lineage panel, the badge and the
quantity field all reuse existing classes (`card`, `list`, `badge-muted`,
`field`, `help`).

The PDF layer reads that file too, and `Carl\Reports\FieldSheet` deliberately
does not — the field sheet is black on white by design. A palette swap moves
the reports and leaves the field sheet alone, which is correct and worth not
"fixing".

### 3.3 What the split left undone, deliberately

Each of these is a real gap with a reason, not an oversight:

- **No whole-sowing report.** `root_planting_id` exists and
  `PlantingRepository::wholeSowing()` reads it in one indexed statement, and
  **nothing calls it** except a test. "This tray produced 100 plants, 94
  transplanted into three beds, 61 alive, 40 kg picked" is the report the
  column was put there for, and it is a page, not a plumbing change.
- **No merge.** Two plantings cannot be recombined. Spec §8, and the reason
  stands: the inverse of a split is a much worse problem, because which
  parent's history wins is not answerable.
- **No individual plant identity.** Six plants in Bed A are a group of six.
  Spec §2.2 and §8.
- **The child inherits the parent's label**, so after a split there are two
  plantings called the same thing, told apart in the list only by the "moved
  out of another" badge. A gardener who splits a tray five ways gets five
  plantings called "Cherokee Purple". Letting the transplant form set a label
  for the child is three lines and was not in the spec; it is the first thing
  to do if anybody actually splits a tray five ways.
- **Nothing warns that a split will end the parent.** Moving the last plant
  out ends the tray, correctly and with the right reason, but the form does
  not say so first. It is not destructive — the plants are alive on the child
  — so no confirmation was added.

### 3.4 What Recommendations still wants

Unchanged from Phase 7 §3.3, all three still true, and Phase 7 added a fourth
thing worth knowing:

- **Nothing displays `analysis.scope` on the answer itself.** The history list
  badges it; a stored answer read six months later does not say what it was
  about at the top.
- **The per-day cap is per account, not per cost.** `document_bytes` is stored
  per row, so a cost-weighted cap is available without a migration.
- **The answer text is never revisited.** An analysis from March is on the
  page in September with only its date to say so.
- **The document now carries lineage, and nothing tells the model what to do
  with it.** `split_from` is in `plantings[]` and the `read_me` says the two
  are the same plants. Whether a recommendation actually reads a tray and its
  six children as one crop is untested, because nobody has run one against a
  split account.

### 3.5 The reminder set is thirteen, and nothing paginates it

Unchanged from Phase 7 §3.4. `DigestMessage::grouped()` orders by priority and
that is the whole of the triage. Nothing caps the count, nothing rolls up "and
six more waterings". **The split makes this slightly worse**: splitting a tray
four ways turns one watering reminder into four, because there are now four
plantings where there was one. Worth looking at before a fourteenth kind.

### 3.6 A second region

Unchanged from Phase 7 §3.5. Everything in the research schema is
region-agnostic and exactly one region has ever been imported. A second county
is the cheapest way to find out what assumed one, and
`plantTypesForRegion()`'s overlay is the obvious suspect.

---

## 4. What must not regress

Everything in `PHASE-7-HANDOFF.md` §4, all of which still holds, plus:

1. **A dispersal is not attrition, and the two are told apart by NAME.**
   `split_out` carries a negative `quantity_delta` exactly like `died` does.
   `EventType::isAttrition()` and `isDispersal()` are separate lists for that
   reason, and any code that decides "is this a loss?" from the sign of the
   delta is wrong. `20_split_test.php` asserts a tray that transplanted forty
   out still reads 100% survival.
2. **`quantity_live`, `quantity_lost`, `ended_at`, `ended_reason` and `state`
   are written by ONE statement.** The `UPDATE` in
   `PlantingRepository::recomputeState()`. Three caches kept by three writers
   is three ways to disagree, and the whole reason a new event type needed no
   arithmetic change is that there was one.
3. **`state = ended` and `ended_at IS NOT NULL` are a pair.** The bug §4.4 of
   the spec is about. Splitting every plant out of a tray used to produce
   `ended` with a null date; it now produces both, plus a reason.
4. **A null `ended_reason` means attrition.** Every planting that ended before
   migration 019 has one, and nothing could disperse then.
   `PlantingState::endedLabel(null)` returns "Ended", and any code that
   branches on `=== 'attrition'` rather than `=== 'dispersed'` gets the old
   rows wrong.
5. **`derive()` clamps a contradictory backdate to zero, and says so.** Split
   a tray empty on the 14th, then record twenty deaths on the 12th: the deltas
   sum below zero and the result is clamped, not negative and not an
   exception. The child is never retroactively resized. Stated in the
   docblock, asserted in `20_split_test.php` — an unstated clamp is a bug
   waiting to be "fixed" into a 500 for a gardener correcting their own
   records.
6. **`root_planting_id` is NOT NULL, and no row says 0.** 019 puts a
   placeholder in the rows it adds the column to and 020 replaces every one.
   `20_split_test.php` asserts that no planting anywhere says 0 and that every
   root names a real planting of the same account. A zero means a write path
   that does not go through `PlantingRepository::insert()`.
7. **The root is flattened as the chain is built.** A split of a split carries
   the ORIGINAL sowing, not its immediate parent, so "everything from this
   tray" stays one indexed read however deep it goes. `split()` copies the
   parent's `root_planting_id`, never the parent's id.
8. **A split is one transaction.** A child with no parent event is a planting
   that came from nowhere and took six plants with it; a parent event with no
   child is six plants that went nowhere. Neither is a state a gardener could
   unpick.
9. **The lineage is a link and the timelines are not merged.** Merging costs a
   statement per generation and breaks the assertions in `11_reports_test.php`
   that a 200-day planting costs the same three statements as a two-day one.
   `20_split_test.php` asserts the same planting costs the same before and
   after it has a child.
10. **The plant page spends no statement on lineage when there is none to
    show.** A planting that never split has
    `quantity_live + quantity_lost = quantity_initial`, because dispersal is
    the only other thing that takes plants off a row — so the question is
    answered from the row in hand.
11. **Everything in `PHASE-7-HANDOFF.md` §4** — GDD in Fahrenheit, the biofix
    reading backward, ten digest statements, twelve analysis statements, every
    companion pairing carrying a reason and a source, the ordered pair, the
    field sheet fitting one page, and a template version 1 zip still
    importing.
12. **464 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

Unchanged from Phase 7 except item 6, which now names two datasets. In
priority order; items 1–3 have been outstanding since Phase 3.

1. **Rotate `cron_key`.** Visible in a Phase 3 screenshot, and it travels in
   URLs. Phase 7 added no route behind it.
2. **Delete `diag_key`** from `config/local.php`; the `/diag` route should be
   shut.
3. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`.
4. **Add the sixth cron job**, `bin/analysis_run.php` hourly at minute 40
   (`deploy.md` §7). Without it the queue never drains.
5. **An Anthropic API key** in `config/local.php`, if Recommendations is
   wanted. Optional: with no key the feature queues and waits.
6. **Import the research datasets.** `research_US-48217_2026-08-31.1.zip` for
   the companion reference and the GDD threshold, and the 68-cultivar Hill
   County set `2026-08-31.2` that landed after Phase 6. Without the first,
   `/companions` is an empty page that explains itself and the GDD reminder
   runs off the unvalidated `approx` row.
7. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox.
8. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports look
   clean.
9. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron.
10. Ask Ahosting whether `ea-php82-php-opcache` can be enabled; email
    Open-Meteo describing Carl.

### 5.1 The one platform fact still not established

**Outbound HTTPS to `api.anthropic.com` has never been tried from sh193.**
Unchanged from Phase 7 §5.1 and Phase 6 §5.3 — Phase 7 added no third-party
call, so nothing moved. Phase 0 spike 1 proved five hosts reachable and this
is not one of them. Egress was open to all five, so there is no reason to
expect a block, but it has not been shown.

The first drain is the test and it is safe: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To settle
it without waiting for the hour, queue something and open
`/tasks/analysis-run?key=<cron_key>`.

**This has now been carried unchanged through three handoffs.** It is five
minutes of somebody's time and it is the only thing standing between
Recommendations and "known to work on this host". If Phase 8 does one owner
action, do this one.

---

## 6. Claude Design outstanding

Down to one item, and it is the original one.

1. **Logo and palette (§13.5).** `public/assets/css/tokens.css` is the
   one-file swap and carries the chart colours and the PDF colours as well as
   the pages. Phase 7 added three UI blocks and **no colour and no CSS at
   all** — the lineage panel, the "moved out of another" badge and the
   quantity field are `card`, `list`, `badge-muted`, `field` and `help`, all
   of which already existed.
2. ~~**The static field-recording sheet (§13.4).**~~ **Built, Phase 6.** The
   design canvas carries a fourth artboard, a ledger-style alternate that was
   not built; if a gardener prefers writing the action as a word to ticking a
   box, it is there.
3. **The PDF report layout.** Unchanged; anything Claude Design wants to
   change is inside `Carl\Reports\Document`, and the field sheet is
   deliberately not in that file.

---

## 7. Where the bodies are buried

Everything in `PHASE-7-HANDOFF.md` §7 still applies. Phase 7 added five.

- **`ALTER TABLE` plus a backfill `UPDATE` is a mixed migration, and the suite
  refuses it.** MySQL commits implicitly on DDL, so the file cannot be rolled
  back. The fix is two files and a note in `deploy.md` that they are a pair;
  the trap is that the single file *works* when you run it, and only
  `01_core_test.php` says otherwise.
- **`AFTER <column>` can name a column added in the same `ALTER`.** Both
  engines process `ADD COLUMN` clauses in order, so
  `ADD COLUMN a ..., ADD COLUMN b ... AFTER a` is fine. Worth knowing before
  splitting an `ALTER` into four to get the column order you wanted.
- **A child planting inherits its parent's label, so `WHERE label = ...`
  finds two rows after a split — and the child has the higher id.** A fixture
  that reads back "the planting I just made" by label and `ORDER BY id DESC`
  silently reads the child. This cost a confusing test failure that looked
  exactly like the `ended_at`/`state` bug the change was fixing.
- **`Series::coveredRange()` ends at *yesterday*, not today.** A planting put
  in the ground today has `from > to` and the weather read is skipped
  entirely, so it costs two statements where an older planting costs three.
  Any test that pins an absolute statement count has to use a planting that
  has been in the ground at least a day, or it pins the wrong number for the
  wrong reason.
- **`Client` builds a fresh `App` per request, with its own `Database`.**
  So `$app->db()->statementCount()` does not move across `$client->get()`, and
  a test that tries to count statements through the client counts zero and
  passes for the wrong reason. Statement counts are measured on repositories
  and services directly, everywhere in this suite, and that is why.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8,
`PHASE-4-HANDOFF.md` §8, `PHASE-5-HANDOFF.md` §8, `PHASE-6-HANDOFF.md` §8 and
`PHASE-7-HANDOFF.md` §8 — including "a deferral's stated reason is a claim,
not a fact", which Phase 7 had no occasion to use and which stands. One more:

- **Put a new invariant in the schema, and let it break the fixtures.**
  `root_planting_id` was made `NOT NULL` with no usable default, and the first
  thing that happened was five test fixtures failing at the `INSERT` with the
  column named in the error. That was the feature working. The tempting fix —
  a default, or a nullable column, so the fixtures keep running — would have
  moved the failure from the write that was wrong to a report six months later
  that quietly returned fewer rows than it should. A constraint that only the
  application enforces is a convention; a constraint the database enforces is
  an invariant, and the difference shows up as either a loud failure now or a
  silent one later.
