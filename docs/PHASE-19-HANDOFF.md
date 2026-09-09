# Carl The Garden Helper — Phase 19 handoff

**Phase 18 fixed the three things the owner reported from the inbox and the
main menu**, and found under the third a checkbook that had been stepping
over waterings for fifteen phases. A temperature no longer reaches a
Fahrenheit household in Celsius; the "nothing logged" nudge sees a zone
watering, a photograph and a new planting as the activity they are; and the
water balance — on the garden page, the plant page, the PDF and the chart —
now includes what the gardener put down, by the emitter figures they typed
onto the zone for exactly this. No migration, no cron, no setup step, and
nothing new from the host.

The owner's three sentences are in §2, each with what was found and what was
built.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — the authorities. Phase
   18 annotates neither and adds no outbound call anywhere.
2. **`docs/CARL-HANDOFF.md`** — the specification. Phase 18 adds two bullets
   to §11 (the ledger, and the invalidation), rewrites the `inactivity` and
   `heat_watch` rows of the §12 table, adds a paragraph to §13.1 (the
   balance), and a Phase 18 entry in §14.
3. **`docs/PHASE-18-HANDOFF.md`** §3, §4, §5 — all still current. Nothing
   there is closed by this phase; Phase 18 was a bug phase.
4. **`docs/deploy.md`** — the runbook. Phase 18 adds nothing to run and one
   thing to check.
5. **§8 below is the working agreement**, unchanged, with one addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **27**, 46 tables — unchanged |
| Routes | **123** — unchanged |
| Source / views | 128 PHP classes (**+1**: `Carl\Weather\IrrigationLedger`), 62 templates |
| Tests | **718 tests, 7,561 assertions** (+6 tests), green under `--strict` on MariaDB 10.11 locally (PHP 8.4; CI is 8.2 on MySQL 8.0 and MariaDB) |
| Static CI checks | 8, unchanged, all green |
| Client shell | 43.1 KB gzipped (one label changed in `charts.js`) |
| Cron jobs | 7, unchanged |

**The heat watch is in the gardener's unit.** "Heat tomorrow: 97°F forecast",
not "36 C". §2.1.

**The nudge sees every kind of entry.** Plant events, garden events,
photographs, new plantings, and the later of the entry's own date and the
day it was written. §2.2.

**The balance includes the watering.** "Watering logged" and "Water balance
— rain plus watering, minus evapotranspiration" on both report pages and the
PDF; the chart's line is "Rain + watering − ET₀"; the series document
carries `watered` per day and in the totals. §2.3.

**A watering logged after its day's row exists is counted.** The write
invalidates the rows after the event's date and the same request walks them
forward again. The MOTD says "Logged today: 20 min on Zone B (about 0.3 in).
Counted from tomorrow." under a sentence that cannot yet contain it. §2.4.

Branch `claude/temp-units-watering-bugs-jbd1xw`, one commit.

---

## 2. What Phase 18 established that Phase 19 should not re-derive

### 2.1 One title printed the column, not the unit

Every temperature on a page goes through `Units::temperature()` and comes
out in the configured system (`config/app.php` `'units' => 'us'`), which is
weather.md §6.3's rule: store SI, convert once at display. The digest is
built by `ReminderBuilder`, which until Phase 18 was constructed with a
`Database` and nothing else, and the one rule that prints a number —
`heat_watch` — wrote `round($tmax) . ' C forecast'` straight from
`weather_forecast.temp_max_c`. In a Fahrenheit household "35 C" reads as a
mild day, which is the opposite of the reminder.

`ReminderBuilder` now takes a `Units` (optional, defaulting to US so the
tests and the older callers need not know), `Digest` passes
`$app->units()`, and the title reads "Heat tomorrow: 95°F forecast". The
threshold itself stays in Celsius (`HEAT_C = 35.0`) against the Celsius
column, as the model's does; only the sentence converts.

Everything else that prints a temperature was walked: the MOTD table, the
report totals and series, the GDD base on the chart, the PDF, the calendar,
the research card (soil temperatures, which arrive in °F from the research
tables and are shown as they arrive, research-template README "Units").
All go through `Units`. Two things deliberately still say Celsius and are
not user-facing in the owner's sense: the CSV export (`_c` columns, a
documented data contract, §13.3 and the note on the export page) and the
MCP tool payloads (`units: {temperature: C}`, read by Claude Code, which is
told the display units separately). §3.1 says what to do if the owner wants
the export changed too.

### 2.2 "Nothing logged" counted one table

The nudge's last-activity read was `MAX(event_date) FROM plant_event` per
user. A zone watering — which is how most watering is logged, and what the
one-tap timer logs — writes a `garden_event` and fans out to `plant_event`
only for the living plants in the zone's rows. A gardener watering the beds
every evening with nothing in a row yet, or watering a zone whose rows hold
no plantings, was told after seven days that nothing had been logged; and
an inactivity reminder is exactly the mail that makes a person stop
reading the rest.

The read is now one statement over four tables — `plant_event`,
`garden_event`, `photo`, `planting` — taking the later of the entry's own
date and `DATE(created_at)`, because logging last week's sowing this
morning is somebody keeping the record. Four IN lists with four prefixes,
since a placeholder cannot be bound twice in one statement (hosting §7).
The one-nudge-then-silence rule is untouched.

The test fixture had to change with it: an event inserted with `created_at
= UTC_TIMESTAMP()` now reads as written today, so the fixtures date their
`created_at` alongside their `event_date`. That is the right reading of the
fixture — a ten-day-old sowing logged ten days ago — and a fixture that
wanted the wall clock was the bug the rule had.

### 2.3 "Water balance" was rain minus ET0, with the watering left out

The garden page and the plant page said "Water balance — rain minus
evapotranspiration", the PDF said the same, and the chart's line was "Rain
minus ET₀": `weather_daily.water_balance_mm`, the archive's own column, with
every watering the gardener had logged left out. It is the one number a
person checks against the emitter figures they typed onto the zone in Phase
14, and it could not agree with them because it had never read them.

Phase 18 moves the reading of "what did that logged watering put down" out
of `WateringModel::irrigationByDate()` into **`Carl\Weather\IrrigationLedger`**,
the one writer of it, and gives it to everything that asks:

- **One statement per subject.** `forGarden()`, `forContainer()`,
  `forPlanting()` and `loggedOn()` are one UNION each: the garden's events
  (each one application of the bed) and the plant events with no
  `source_garden_event_id` (each a hand watering), in one column shape, with
  the zone's emitter figures, the event's or the zone's method and its flow
  rate, and the garden's dimensions riding along on the row. `byDate()`
  applies the rule the model always had: applications add up, hand
  waterings on one day count once at the deepest. `depthOf()` is the
  zone-before-method decision, and every assumption is in the basis text.
- **The model reads the ledger** (`ledgerRows()`), in one statement where it
  spent two, and the reason text, `irrigation_mm` and the refill minutes are
  unchanged to the hundredth — `08_watering_test.php` pins 6.0 mm for a hose
  and 8.15 mm for Zone B as before.
- **`Series` takes the ledger** as a sixth constructor argument and spends
  one more statement on it — four, where the tests pinned three: "one for
  the planting, one for the weather, one for the events, one for the water
  put down". Each day carries `watered`; `balance` is rain plus watering,
  minus ET0, and stays null on a day the archive has no balance for (a hose
  does not make a missing day whole); the totals carry `watered` and the
  same balance. The plant's spine carries `watered` too.
- **A single plant's ledger is the other way up:** the zone waterings that
  reached it, found through the derived row the fan-out wrote, plus its own
  hand waterings. `11_reports_test.php` waters a zone for an hour and reads
  0.32 in on the garden AND on the plant in the zone's row, once each.

### 2.4 The checkbook never looked back

Under §2.3 was the older fault. `WateringModel::resume()` starts the walk
the day after the newest stored row, and the nightly run at 05:45 computes
today's row from yesterday's watering. So a watering done yesterday and
logged this morning — the commonest way a watering gets logged — landed on
a day whose row already existed, and was never counted: the walk had
passed it. `08_watering_test.php` had been deleting rows by hand to see the
emitters counted, and the comment beside the delete said why without
saying that it was a bug.

Two things, layered:

- **`EventRepository::invalidateWatering()`**: writing a `watered` or
  `mulched` event (the two types the model reads) deletes this place's
  stored rows with `for_date` after the event's date — one statement, and
  for a plant event the place is found through the planting in the same
  statement. An event dated today deletes nothing: today's row is the
  balance at the start of today (§11), and tonight's run counts a watering
  done today.
- **`WateringModel::refresh()`**, called by the five paths that log one (the
  plant log, single and batch; the garden actions form; the field screen's
  one-tap; the timer's own "log it when done", from the page and from the
  cron): `run($userId, recordRun: false)` on the same request. The walk
  resumes from the newest row still standing, so it rebuilds exactly the
  invalidated span, on a handful of statements, and leaves no
  `weather_sync_run` row — one per logged watering would bury the nightly
  one on `/status`. It never throws: the write already happened, and a
  refresh that fails is repeated tonight from whatever is stored. "Never
  computed at render" still holds: this is a write path.

And on the MOTD, under a sentence that by construction cannot contain this
morning's watering, one line from the ledger: **"Logged today: 20 min on
Zone B (about 0.3 in). Counted from tomorrow."** — in the gardener's units,
which the reason text itself is not (§3.2).

---

## 3. Phase 19 — what is left

Everything in `PHASE-18-HANDOFF.md` §3 still stands. Phase 18 adds three.

### 3.1 The CSV export is SI, on purpose, and the page says so in words

`docs/CARL-HANDOFF.md` §13.3 and weather.md §6.3 make the export the
archive's own columns — `temp_max_c`, `precip_mm` — and the export page
explains it. If the owner reads the sheet in Excel and wants it in °F and
inches, the change is a second pair of columns or a `?units=display`
switch, not a rename: analysis and thresholds read the SI columns.

### 3.2 The reason text is in millimetres

"Root zone about 40% full; deficit 15 mm of an allowed 25; you watered
about 8 mm" is in SI whatever the account's units, because the sentence is
written by the model in the unit the checkbook is kept in. Every other
depth on the page goes through `Units::rain()`. The owner has not asked;
the "Logged today" line does convert, so the two now sit one above the
other in different units. Converting the sentence means the model taking a
`Units`, which is a small change with a long test file behind it.

### 3.3 A watering with no duration puts down nothing

`WaterMethod::depth()` returns 0 mm for a watering logged without minutes,
and says so in the basis. The plant page's quick log has no duration field
on its one-tap path (the tag field screen), so a watering from a stake is
activity (§2.2) but no water (§2.3). A default — ten minutes, stated — is
the obvious answer, and a design question about what a tap means.

---

## 4. What must not regress

Everything in `PHASE-18-HANDOFF.md` §4 still applies. Phase 18 adds six.

1. **`ReminderBuilder` prints temperatures through `Units`.** The threshold
   stays Celsius against the Celsius column; the title converts. `10` pins
   "Heat tomorrow: 97°F forecast".
2. **Last activity is four tables and the later of two dates.** Putting
   `MAX(event_date) FROM plant_event` back re-sends the nudge to everyone
   who waters a zone. `10` pins a zone watering with nothing in the rows,
   and an old event written yesterday.
3. **`IrrigationLedger` is the only reader of logged waterings as depths.**
   A second copy of the UNION — in the model, in a report — is the drift
   §2.3 removed. `08` pins the model's numbers unchanged through it; `11`
   pins the report's.
4. **The series costs four statements**, and the fourth is the ledger.
   `11`, `20` and `26` pin it. A ledger that cost one per zone or one per
   day would pass every other test.
5. **A `watered` or `mulched` write invalidates the rows after its date, and
   the request refreshes them without a run row.** `08` pins 4.07 mm on the
   day after a backdated watering with no hand-delete, the row for today
   present again, and `weather_sync_run` unchanged.
6. **An event dated today invalidates nothing.** Today's row is the balance
   at the start of today. `08` pins today's `irrigation_mm` and `deficit_mm`
   unchanged after a watering logged for today, and the "Logged today" line.

---

## 5. Owner actions outstanding

The lists in `PHASE-18-HANDOFF.md` §5 and its predecessors stand. Phase 18
adds two.

1. **Log yesterday's watering this morning**, on a zone with emitter figures,
   and open the main menu. The garden's sentence should now say "you watered
   about … mm" and a fuller root zone, without waiting for the cron. Then
   open the garden page: "Watering logged" should carry the depth in inches
   and "Water balance" should have moved by it.
2. **Read the next digest's heat line**, if the week gives one, and the next
   nudge, if there is one — there should not be, on a week with any entry.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-18-HANDOFF.md` §6. Phase 18 adds one row to two
tables and one line to the MOTD, in the existing classes.

---

## 7. Where the bodies are buried

Everything in `PHASE-18-HANDOFF.md` §7 still applies. Phase 18 adds five.

- **`IrrigationLedger::rows()` rewrites every placeholder per UNION half**
  (`:from_g`, `:from_p`) with a regex, longest names first, because a name
  cannot be bound twice in one statement with emulation off (hosting §7).
  A new parameter that is a prefix of another must be added with that in
  mind; the sort is what keeps `:from` out of `:from_g`.
- **The ledger orders applications before hand waterings on the same day**
  (`ORDER BY event_date, kind`), so the first basis a day records is the
  zone's — the wording the model always used.
- **`invalidateWatering()` for a plant event finds the place with two
  subselects on `planting`**, `garden_id = (SELECT …) OR container_id =
  (SELECT …)`; `= NULL` is never true, so a container planting deletes only
  container rows.
- **`WateringModel::refresh()` swallows everything.** Deliberately: the
  event is written, and nothing about a logged watering should fail because
  its recommendation could not be rebuilt on the spot.
- **The digest tests' fixtures date `created_at`.** A fixture that leaves
  it at `UTC_TIMESTAMP()` is an entry written today, and the rule now says
  so.

---

## 8. Working agreement

Unchanged from `PHASE-18-HANDOFF.md` §8, including every earlier phase's
addition. One addition, from §2.4:

> A test that deletes rows by hand to make a fixture work is describing a
> bug. Before writing the delete, ask what the code would have to do for
> the test not to need it, and put that in the code or in §3.

And the Phase 10 test, answered "no" once more — this time for the number
on the report page that a gardener with a drip line would check first:

> **Would anybody find out?**

Not from the suite, which proved rain minus ET0 was rain minus ET0. Not
from the model, which counted the watering correctly on the nights it saw
one. The owner found out by watering, logging it, and reading the page.
