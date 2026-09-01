# Carl The Garden Helper — Phase 13 handoff

**Phase 12 is three asks, and the third one turned out to be a bug in a
document nobody had disagreed with.** A link to the menu, a size on the log
form, and "make the charts more interactive" — and the third, read against
`weather.md` §7.3, was not a request for a feature so much as a request to
build what that section had said since before any chart existed.

The chain runs the other way from how it was asked. The size is what made the
charts possible: until Phase 13 the plant had almost no number of its own to
put on an axis, which is the honest reason nine phases of charts drew the
weather and reduced the plant to identical triangles.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 12 annotates `weather.md` §7.3**, for the second time (Phase 4 was
   the first). The annotation is not a correction: §7.3 was right, and the
   application had been wrong about it since Phase 4. §7.1 and §7.2 were both
   leaned on and both were complete — §7.1 decided that GDD is computed at
   read time and never stored, §7.2 decided that the correlation view uses a
   lagged window and that the lag is adjustable.
2. **`docs/CARL-HANDOFF.md`** — the specification. §4.2, §4.4, §13.1 and
   §13.2 are rewritten by this phase. **There is still nothing unbuilt in it.**
3. **`docs/DESIGN-NOTES.md`** — the palette. **Phase 12 adds no colour and
   takes none**, for the third phase running. The subject line borrows
   `--carl-chart-event`, which is already the token named for the plant's own
   record. §6 below is the one thing a designer might want to look at.
4. **`docs/PHASE-12-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both current in full; §4 gains five entries in §4 below
   and §7 gains four in §7.
5. **`docs/deploy.md`** — the runbook. **Phase 12 adds ONE MIGRATION**, which
   is the first since Phase 9 and the only thing here that is not a file copy.
   `024_plant_size.sql` is a single `ALTER TABLE` on `plant_event`: two
   nullable columns, one ENUM value, one index. It is `kind=ddl`, it applies
   in 35 ms on a table of this size, and it is idempotent in the sense the
   migrator needs (it runs once, and a second `bin/migrate.php` reports "up to
   date"). There is no cron change, no route change, no new file at the web
   root and no `.cpanel.yml` change.
6. **§8 below is the working agreement**, unchanged.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **24** (`001`–`024`), 42 tables — **Phase 12 added one** |
| Routes | 106 — **none added** |
| Source / views | 111 PHP classes (**+0**), 57 templates (**+0**) |
| Tests | **619 tests, 6,786 assertions**, green under `--strict` on MariaDB 10.11 |
| Static CI checks | 8, unchanged |
| Client shell | **32.4 KB** gzipped against 150 KB — up from 26.4, CSS and JS |

Three things, in the order they depend on each other:

**A labelled way back to the menu, on every signed-in screen.** The top bar is
sticky, so it is one tap from any scroll position on any page.

**A size on Log Plant Activity — height, diameter, or both.** On every action,
not only on the new `Measured` one.

**The plant is now the subject of its own charts**, with the weather behind it,
and a reader can build their own pair from two pickers or open a scatter that
puts one measurement against the weather in the days before it.

---

## 2. What Phase 12 established that Phase 13 should not re-derive

### 2.1 The application had `weather.md` §7.3 backwards for nine phases

§7.3 opens: *"Weather is context, not the subject. On a plant-performance
chart it belongs as a muted background band or a secondary axis, never
competing with the performance line for attention."*

What was built in Phase 4 and shipped unchanged through Phase 11 was three
**weather** panels — temperature, rainfall, ET₀ — with every logged event
drawn as an identical black triangle sitting on the weather line. The subject
was the context and the context was the subject.

**Nobody had disagreed with the document.** It was quoted correctly in the
charts partial, in `charts.js` and in `11_reports_test.php`, and every quotation
was of the *second* half of the paragraph — the "one weather series at a time
on mobile" rule, which the implementation obeyed scrupulously. The first half
was read as describing a chart the project did not have.

And it was right that the project did not have one, which is the part worth
carrying forward: **there was nothing to make the subject.** A plant's whole
numeric record was a harvest weight and a count, both of them a handful of
points a season. Height and diameter (§2.2) are what turned the plant into
something with a curve, and the chart rework is downstream of the form change
even though it was asked for as a separate thing.

The lesson is the Phase 11 one pointed at a *specification* rather than at a
comment: **a document can be quoted accurately, in the right file, next to the
right code, and still not be implemented** — because the half that was quoted
is the half the code already did.

### 2.2 A size is not an action

The obvious build is an event type called `measured` with two fields on it.
That is half of what was built and it is the half that gets used least.

The gardener's sentence is *"watered it, it's fourteen inches now."* One visit,
one thing to write down. An implementation where recording the size means
submitting the form a second time is an implementation where the size stops
being recorded within a fortnight, and nothing reports that either — the column
just stays null.

So the two boxes sit **outside the per-action fieldsets**, beside the notes and
the photos, and `LogController::eventData()` reads them for every event. This
is not novel: `narrative` has always been universal, and `duration_min` has
always been *read* universally even though the form only shows it for a
watering. `Measured` still exists, for the visit where measuring was the
errand — the same way `note` exists even though every event can carry a
narrative — and it is the one action that is refused when the boxes are empty,
because an empty `Measured` records that somebody went and looked at a plant.

**One place it is deliberately absent: a batch.** The same number written
against twenty plants is nineteen measurements nobody took. `EventType::
SINGLE_PLANT_ONLY` is a named list of one, the form withholds the action and
the boxes, and `LogController::batch()` refuses the action — both, because
either alone leaves a hole, and a form that offers what the handler rejects is
worse than either.

### 2.3 The unit is the part that goes wrong silently

Four units are offered (in/ft/cm/m) and one is stored (millimetres, per
`weather.md` §6.3 — store SI, convert once in `Units`). Every failure in that
sentence is silent:

- A unit the form never offered stores **nothing** rather than falling back to
  inches. A fallback would put a number twenty-five times too small in the
  column, and there is no way to tell it afterwards from a plant that really is
  that small.
- The bound (a hundred metres) is checked **after** the conversion, because
  that is the only point at which the four units are comparable. "100" is
  absurd in metres and ordinary in centimetres.
- `Units::size()` deliberately does **not** follow `weight()`'s oz-to-lb
  switch. A weight is never plotted; a size is mostly plotted, and a chart axis
  cannot change units halfway up. A six-foot tomato reads `72.0 in`.

### 2.4 Two spines, and why the second one exists

`Series` now emits a `plant` block: the subject's own numbers plus the weather
projected alongside them, on a spine that is the **union** of the weather days
and the days the plant has numbers on.

The union is not tidiness. `coveredRange()` ends at **yesterday** for a living
plant, because today is not over and the archive holds no observation for it —
which is correct, and has been since Phase 4. Drawn on the weather's dates
alone, the growth chart silently drops the measurement the gardener took an
hour ago. That is the single most likely moment for somebody to open the chart.

`days` is left exactly as it was, because `days_held` and `days_missing` mean
"how much weather is there" to the page, the PDF and the tests that count them.
Two spines is the price of not changing what those numbers mean.

### 2.5 Five more columns and a hundred derived numbers, and no more statements

Everything the chart needs is derived from rows already in hand.
`seriesMarkers()` gained five columns — adding columns to a `SELECT` is not
adding a statement — and the GDD accumulation, the cumulative harvest and the
weather projection are all loops over arrays the assembler already holds.
`26_chart_layers_test.php` asserts the count is still three, because "it looks
like it does not query anything" is not a measurement.

### 2.6 A time-series overlay does not show correlation

Two rising lines read as agreement whatever they are. The overlay is worth
having and it is not the thing that was asked for: *"show temp and yield
correlation"* is a question about whether the two move together, and the chart
that answers it is a scatter.

So "Compare" is one point per harvest against the weather over the days
**leading up to it**, with the lag adjustable (§7.2: *"different responses have
different memories — heat stress shows in days, water stress in weeks"*).
Pearson's r is printed, with n, and with a sentence saying that a handful of
points under a season that is itself moving is a shape to look at rather than a
result. **Printing r without that sentence would be the dishonest version of
this feature**, because the number looks like a finding and a garden season
cannot produce one.

---

## 3. Phase 13 — what is left

Nothing here is spec. Everything from `PHASE-12-HANDOFF.md` §3 is carried
unchanged and is not reproduced: the whole-sowing report (**still the oldest
unbuilt thing in the project**), reminder pagination and roll-up, the four
Recommendations items, a second region, the catalogue's other half and template
version 3, the no-JavaScript upload path, the `capture` heuristic, and the two
field tests in its §3.3. Phase 12 touched none of them.

Five are new.

### 3.1 The PDF does not contain the chart you built

"Download PDF" posts the three weather canvases, as it has since Phase 4, and
they are drawn hidden for exactly that reason. A reader who has just built
"Height against growing degree days" and pressed Download gets temperature,
rainfall and ET₀.

The fix is small and has one real decision in it: `PdfBuilder` lays out chart
images in order with no captions, so an ad-hoc chart would arrive in the report
with nothing saying what it is. Adding `chart_build` means adding a caption
field to the POST and a caption to the layout, and the caption is untrusted
text that reaches FPDF.

### 3.2 A per-crop GDD base belongs in the research tables

`Series::GDD_BASE_C` is 10 °C, the warm-season default, and `weather.md` §7.1
is explicit that one stored base is wrong for every crop it was not chosen for.
The compliant version of shipping it anyway is to print it — the axis says
"GDD, base 50 °F" — but the honest version is a `gdd_base_f` column on
`plant_type`, which is where `pest_reference` already keeps one. That is a
research-data change (a column, an importer field, a validator rule and a value
per crop), not a code change, and it is the thing that would make the GDD layer
mean something specific.

### 3.3 A dedicated `--carl-chart-subject`

The subject line borrows `--carl-chart-event` and the second subject series
borrows `--carl-primary`. Both are defensible — `--carl-chart-event` is
literally the token for the plant's own record — and neither was designed for
the job. §6 below.

### 3.4 Cross-plant comparison

Every chart in the application is about one subject. "This year's tomatoes
against last year's" and "the bed that got mulched against the one that did
not" are the questions a second season makes askable, and the series endpoint
takes one id.

### 3.5 A measurement cannot be corrected

The log is append-only, so a height typed as 140 when it should have been 14 is
on the chart forever, and it rescales every other point on it to a flat line.
The hundred-metre bound catches the unit-left-on-metres case and nothing
catches a typo inside the plausible range. Nothing in Carl can edit or delete
an event today — this is the first field where that stings, because it is the
first one that is *plotted*.

### 3.6 Addendum, written after the handoff: tags over a whole season

Not in the list above because it was not on it. Reviewing this document
against the tag screens turned up two things, one small and one not.

The small one: `QR-TAGS-SPEC.md` §5.2 — *"at the foot of Start a New Plant,
and on any plant's page: assign a tag"* — had shipped in Phase 8 as a link to
the pool screen.

The one that is not small: **Phase 8 let a planting carry one tag**, and a
planting is a tray of twenty-four cells whose plants go to three beds in May.
The second stake in a tray silently pulled the first one off, and nothing
moved with the plants at transplant. Migration 021's "one tag per planting is
correct by construction" was true of the *location* and wrong about the
*group*.

Both are built, on the branch this note is on, and documented as
**`QR-TAGS-SPEC.md` §14**: a stake per cell; a checkbox grid of free codes by
code, with labels still on a sheet told apart from loose stakes; a tagging
session that really binds on scan and fills a tray before moving on; the
stakes travelling with a split; a directory of which stakes are on which
plant; retiring one code without its sheet. `27_tag_desk_test.php` walks a
season through it. **Routes: 109 (+3). No migration. Statements on the field
screen: unchanged.** `PHASE-9-HANDOFF.md` §4.2 and §4.4 are amended by §14.7.

One §4-grade finding fell out of it, and it is older than the tags:
`PlantController::create()` rendered its validation errors with
`formData() + ['errors' => $errors]`, and PHP's array union keeps the left
value, so every server-side error on the new-plant form since Phase 1 came
back as an untouched form. The browser's `required` hid it. §14.9 has the
detail; the union is now the other way round and the plain case is asserted.

---

## 4. What must not regress

Everything in `PHASE-12-HANDOFF.md` §4 still applies. Phase 12 adds five.

1. **The size boxes stay outside the per-action fieldsets, and stay off the
   batch form.** §2.2. Moving them inside the `measured` fieldset is a
   one-line edit that looks like tidying up, passes every test that does not
   post a size with a watering, and quietly halves how often the field is
   filled in. Putting them on the batch form writes one gardener's measurement
   to twenty plants.
2. **An unrecognised size unit stores null, never inches.** §2.3, and
   `25_size_test.php` asserts it. A default here is a number twenty-five times
   too small with nothing to distinguish it from a real one.
3. **`Series::subject()` spends no statement.** §2.5. It is a loop over rows
   the assembler already holds, and the thing that breaks it is one helpful
   lookup inside that loop. `26_chart_layers_test.php` counts.
4. **Only a `yielded` row's `count_qty` is a harvest, and only a `watered`
   row's `duration_min` is a watering.** `count_qty` also carries how many
   germinated, died and were culled. A yield line that sums culled seedlings
   is wrong in a flattering direction, which is the direction nobody checks.
5. **The three PDF canvases keep being drawn even though nothing tabs to
   them.** They look like dead panels. Removing them is a PDF with no pictures
   in it, and no error anywhere: `chart_temp` simply arrives empty and
   `ReportController::readCharts()` skips it, which is the behaviour it has for
   a genuinely broken canvas.

---

## 5. Owner actions outstanding

**Thirteen.** Twelve are unchanged and none has been performed; the list is in
`PHASE-10-HANDOFF.md` §5.

**§5.1 is still the one platform fact not established**: outbound HTTPS to
`api.anthropic.com` has never been tried from sh193, carried unchanged through
Phases 6–12.

**The thirteenth is new and it is a deploy step.** Phase 12 is the first phase
since 9 to add a migration, so the deploy is no longer a file copy: after
`cp -R public/.`, open `/setup?key=` and let `024_plant_size.sql` run
(`docs/deploy.md`; there is no shell on the account, hosting §3). It is one
`ALTER TABLE` on `plant_event`, it adds two nullable columns and one ENUM
value, and it is safe to re-run — the migrator will simply report "up to date".
**Nothing in Phase 12's code works before it runs**, and what a page does
without it is a 500 on the log form, not a missing feature.

**And the walk is still outstanding**, from `PHASE-12-HANDOFF.md` §5: take a
phone to a plant, scan the tag, press Take a photo, and look at which way up it
comes out. Phase 12 adds a second thing to do on the same walk — measure the
plant, log it, and see whether the growth curve on its report is a curve or two
points and a straight line, which is the only test of whether anybody actually
fills this field in.

---

## 6. Claude Design outstanding

**One, and it is small.**

Phase 12 adds no colour. The chart series it introduces borrow two existing
tokens: the subject line is `--carl-chart-event` and the second subject series
(diameter, drawn beside height) is `--carl-primary`. Both were chosen because
they are already right rather than because they were designed for this, and
both are checked at 380 px in light and dark.

What a designer might want to decide is whether **the subject deserves a token
of its own** — a `--carl-chart-subject` and perhaps a `--carl-chart-subject-2`
— now that a plant's own line is the most important mark on the report and is
currently painted in the colour named for its logged actions. The rest of the
design work in this phase is structural, not chromatic: the weather is drawn
muted at 45% and 30% alpha behind the subject, on a right-hand axis with no
grid of its own, and that is `weather.md` §7.3's "muted background band"
carried out rather than a palette decision.

---

## 7. Where the bodies are buried

Everything in `PHASE-12-HANDOFF.md` §7 still applies. Phase 12 adds four.

- **`weather_location` is keyed by the place, not by the account.** Every
  fixture in the suite onboards with ZIP 76692, so they all share one location
  row and whatever weather any of them inserted. That is fine for "is there
  weather" and useless for "is a day of 30 °C over 10 °C exactly ten
  degree-days": the first draft of `26_chart_layers_test.php` used
  `INSERT IGNORE` and asserted another test file's numbers, giving 403.5 where
  300 was expected. It now makes a location of its own, at unique coordinates
  (`uq_coords` refuses a second row at one point on earth) and **inactive**, so
  no sync fixture picks it up.
- **GDD must be accumulated in the base's own degrees.** The days have already
  been converted to the account's display units by the time `subject()` runs,
  so it converts one back rather than carrying a second copy of every
  temperature. A Fahrenheit degree is not a Celsius degree, and accumulating
  one against a Celsius base gives a number 1.8× off that looks completely
  plausible — this is the same trap `ReminderBuilder::gddCrossing()` has a
  paragraph about, met again in a different file.
- **Chart.js `order` is inverted.** A *lower* order draws in *front*. Getting
  it backwards puts the muted weather band over the subject line, which is
  precisely the thing §7.3 forbids, and it looks like a z-index bug rather than
  like a wrong constant. It also controls legend order, which is why "Logged"
  used to appear first in the legend.
- **A subject series is sparse and a weather series is not.** Event markers
  used to sit *on* the series at the day's value, which worked when the series
  was the weather and had a value every day. A plant is measured eight times a
  season, so a marker placed on the height line appears only where there is
  already a dot and every watering, fertilising and treatment is silently
  dropped. They are now a rug along the floor of the subject's axis. The same
  sparseness is why `yield_cumulative` needs an explicit point-radius mask: it
  carries a value every day of the season, and a point on each of ninety days
  turns a step line into a caterpillar.

---

## 8. Working agreement

Unchanged from `PHASE-12-HANDOFF.md` §8. One addition, from §2.1:

> A document can be quoted accurately, in the right file, next to the right
> code, and still not be implemented — because the half that was quoted is the
> half the code already did. Re-read the whole paragraph, not the sentence the
> comment cites.

And the Phase 10 test, restated for the one place in this phase where it bit
hardest. The question is not "is this likely to be broken". It is:

> **Would anybody find out?**

A field the form asks for at the wrong moment does not fail. It stays null, on
every plant, in every season, and the chart it feeds is two points and a
straight line — which reads as a plant that did not grow rather than as a
feature nobody could be bothered to use.
