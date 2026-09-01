# Carl The Garden Helper — Phase 14 handoff

**Phase 13 is two asks about one screen, and the second one turned out to be
a data model that had been wrong since Phase 8.** "There is no way to add a
tag from a plant", and "the dropdowns should be in ascending order, not by
row and label" — and the second, followed properly, is not a sort order. It
is the question of what a person is holding when they open that list, which
is a different physical object in March than it is the following March.

The first ask was finished in an afternoon. The second took the rest of the
phase, because answering it honestly meant asking what a tag is *attached
to*, and the answer the code had — one tag, one planting — is wrong about a
tray of twenty-four cells whose plants go to three different beds in May.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 13 annotates neither.** It touched no weather code and no
   platform constraint; the one hosting fact it leaned on is §7 (emulation
   is off, so a named placeholder cannot be reused in one statement), which
   is why `bindCandidates()` binds one search term under three names.
2. **`docs/CARL-HANDOFF.md`** — the specification. **Phase 13 rewrites
   nothing in it and there is still nothing unbuilt in it.** The feature it
   changed is specified in `QR-TAGS-SPEC.md`, not here.
3. **`docs/QR-TAGS-SPEC.md`** — the tag spec, and the document this phase is
   really about. **§14 is new and is the whole of Phase 13.** Read §14.7
   first: it is the one place where the spec now contradicts an earlier
   decision of its own, and it says so.
4. **`docs/DESIGN-NOTES.md`** — the palette. **Phase 13 adds no colour and
   takes none**, for the fourth phase running. The stake grid is laid out
   with existing tokens and nothing else. §6 below is the one thing a
   designer might want to look at, and it is smaller than Phase 12's.
5. **`docs/PHASE-13-HANDOFF.md`** §4 (what must not regress) and §7 (where
   the bodies are buried). Both current in full; §4 gains ten entries in §4
   below and §7 gains five in §7. **Its §3.6 is an addendum written after
   the fact** and is the short version of this document.
6. **`docs/deploy.md`** — the runbook. **Phase 13 adds NO MIGRATION.** The
   deploy is a file copy: `cp -R public/.` and the sibling application
   directory, per §6.2. There is no cron change, no new file at the web
   root, no `.cpanel.yml` change, and no `/setup?key=` step. Three routes are
   added, all `POST`, all inside the existing router.
7. **§8 below is the working agreement**, unchanged.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | **24** (`001`–`024`), 42 tables — **Phase 13 added none** |
| Routes | **109** — Phase 13 added **three**, all POST |
| Source / views | 111 PHP classes (**+0**), **58** templates (**+1**) |
| Tests | **642 tests, 6,935 assertions**, green under `--strict` on MariaDB 10.11 and MySQL 8.0 |
| Static CI checks | 8, unchanged |
| Client shell | **34.1 KB** gzipped against 150 KB — up from 32.4, CSS and JS |

Three things, in the order a season meets them:

**A planting carries a stake per cell.** A tray of twenty-four is one
planting with twenty-four tags on it. This is the model change, and
everything else here is either a consequence of it or was blocked by its
absence.

**Stakes go on from the plant's end**, off a checkbox grid ordered by code,
with labels still on a sheet told apart from loose stakes out of a box — and
come off again from the same panel, one at a time or all at once.

**Stakes travel with the plants.** Six of the twenty-four go to bed two, and
the six stakes that went with them are rebound to the planting that is
actually in bed two, so scanning one there opens the right record.

---

## 2. What Phase 13 established that Phase 14 should not re-derive

### 2.1 Migration 021 was right about the wrong noun

`021_qr_tags.sql` says, in a comment block written with care:

> ONE TAG PER PLANTING IS CORRECT BY CONSTRUCTION, and nothing here has to
> enforce it as a rule. […] A planting is location-singular, so a tag names a
> group that is all in one place.

Every clause of that is true. **A planting is location-singular** — the
planting split was built (`PLANTING-SPLIT-SPEC.md`) precisely so it would
be. And the conclusion does not follow, because a tag is not attached to a
*location*. §1.1 of the tag spec says what it is attached to, in the row
that decides the physical design: *"Moves with the plant. The same physical
object goes into the ground at transplant."*

A planting is a **group** — twenty-four cells, a hundred carrots — and the
plants in it leave one at a time. The stake goes in the cell because the
cell is what moves. So the rule that fell out was:

> **One live binding per tag. As many tags as a planting wants.**

The first half is physics and was already enforced. The second half is what
Phase 8 had backwards: `bindTo()` closed the *planting's* other bindings as
well as the tag's, so putting a second stake in a tray silently pulled the
first one out. Nobody found out, because with one tag per planting there was
no screen that could put a second one on.

**This is the shape of the mistake to watch for, and it is the third
instance the project has recorded.** Phase 12 found a document quoted
correctly next to code that did not implement it (`weather.md` §7.3). This
is the other version: a comment that reasons correctly from a true premise
to a conclusion about a *different noun*, and is then enforced for five
phases. Re-read what the sentence is about, not only whether it is true.

### 2.2 The list changes shape between March and the following March

The ask was "order the dropdowns ascending instead of by row and label".
The reason it was asked is the reason it cannot be answered with a sort:

- **In March of season one**, every free code is a label on a sheet on the
  desk. The useful list is the sheet: *"row 3, column 1"*, tick it, peel it.
- **In March of season two**, most free codes are stakes in a box, pulled in
  October. Their sheet position is a fact about a piece of paper that no
  longer exists. The only way to find one is to read the six characters off
  the stake in your hand.

So `free()` returns **two lists, both ascending by code**: `sheet` (never
been on a plant — carries sheet, row and column) and `loose` (has been on a
plant before — carries nothing but the code). The predicate is an `EXISTS`
over the tag's bindings, which is the only physical fact Carl actually
knows. Sheet order survives where it earns its place, as a *label on each
row of a code-ordered list*, and as the "tick the next N in peeling order"
button.

**The first draft of §14 sorted everything by sheet position** and would
have been unusable in season two — the phase's own §14.3 records that,
because a design that is right in the first season and wrong in every one
after it is worth naming.

### 2.3 The scan was never the confirm

Spec §6.5 promised: *"Twelve scans, zero taps."* What Phase 8 shipped
rendered the **bind screen** on every scan of a free tag, session or no
session — so the strip named the next plant and then asked for a tap anyway.
The session cost one tap per plant and saved nothing but the list-pick.

It now binds on the scan. Where it goes:

1. the plant the session is **filling**, if it still has fewer stakes than
   plants — the tray you are working along, cell by cell; else
2. the next plant with no stake at all.

The fill target lives in the **PHP session** (the camera opens `/t/{code}`
in the browser that holds the login, so it is there), and is **re-read from
the database on every use**. That is what makes it safe: a target that
ended, or got its last stake by another route, simply stops being a target.
Nothing stale can be acted on, which is the same reason §6.5 gave for
computing the cursor instead of storing it.

**"Next plant" on the strip is the skip §6.5 declined to build**, and it
needed no table after all — because the only thing to forget is the single
plant being filled. A row of a hundred carrots gets one stake and a tap.

### 2.4 A quiet drop is worse than a refusal

Phase 8 bound the new-plant form's tag *after* the insert, best-effort, and
flashed "Plant recorded" either way. That was right when the only way a code
reached the form was a scan Carl had itself just called free. It is wrong
the moment a person can tick twenty-four deliberately: a stake goes in the
cell, Carl does not know about it, and you find out in July.

So the codes are checked **before the planting is written**, and a bad one
is a form error naming the code and the reason. And a batch is **all or
nothing** — twenty-four ticked with one stale is twenty-four stakes to check
against the screen if twenty-three went on, and one line to fix if none did.

**This is what found the bug older than the tags.** `create()` rendered its
errors as `formData() + ['errors' => $errors]`; `formData()` carries an
empty `errors` key of its own, and PHP's array union keeps the **left**
value. Every server-side validation error on Start a New Plant, since Phase
1, was rendered as the form coming back untouched. The browser's own
`required` caught the common cases first, so it never surfaced — the tag was
the first check a browser cannot make. §14.9 of the spec, and
`27_tag_desk_test.php` now asserts the plain case ("Choose a plant category
and type." appears on the page).

**The Phase 10 test — "would anybody find out?" — answers "no" here twice**,
once for five phases and once for thirteen.

### 2.5 The counts are guides, and the UI has to say so

`quantity_live` is what the log says. The tray is what the gardener sees.
They disagree constantly and neither is wrong, so nothing refuses a stake on
a count:

- The bind screen lists plants with **no stake** first, then plants with
  **fewer stakes than plants**, then — under the fold, with a sentence
  saying why — plants that already have one each. A snapped stake needs
  replacing and that plant is "full".
- A row of a hundred carrots sits in the "wants" list forever with one
  stake. That is correct and it is why "Next plant" exists.

The rule the code enforces is only the physical one: a tag is in one place.

---

## 3. Phase 14 — what is left

Everything from `PHASE-13-HANDOFF.md` §3 is carried unchanged and is not
reproduced: the PDF not containing the chart you built (§3.1), the per-crop
GDD base (§3.2), a dedicated `--carl-chart-subject` (§3.3), cross-plant
comparison (§3.4), and a measurement that cannot be corrected (§3.5) — plus
everything §3 of that document carried forward from Phase 12: the
whole-sowing report (**still the oldest unbuilt thing in the project**),
reminder pagination and roll-up, the four Recommendations items, a second
region, the catalogue's other half and template version 3, the
no-JavaScript upload path, the `capture` heuristic, and the two field tests.
Phase 13 touched none of them.

Five are new, and the first two are the ones the model change created.

### 3.1 A tag's own history is kept and never shown

`qr_tag_binding` keeps closed rows on purpose. Migration 021 says why:
*"this tag was Cherokee Purple in 2026 and Provider beans in 2027 is a fact
about a real object."* **`unbound_at IS NOT NULL` does not appear anywhere
in `app/`.** Nothing renders it.

That was a curiosity when stakes were bound once and released at the end of
the season. It is a gap now: §14.3 makes "a loose stake out of a box" a
first-class citizen of the UI, so the question *"what was this one on last
year?"* is one a person will now actually ask, holding the stake, on the
screen the scan already lands on. The data is there, it is one statement,
and the bind screen is where it goes.

### 3.2 A tray of twenty-four gets twenty-four identical named labels

`TagController::namedQueue()` walks every batch and takes every tag that has
a plant on it, then prints one named label each (spec §5b). With one tag per
planting that produced one label per plant. With a stake per cell it
produces **twenty-four labels all reading "Tomato · Cherokee Purple · 12
Mar"**, which is not wrong exactly — they go on twenty-four stakes in one
tray — but it is a sheet and a half of polyester for one planting, printed
without warning, and the screen says nothing about it.

Decide what a named label means for a group. The honest options are a count
on the queue screen before you spend the stock, a "one per planting"
default, or the numbering the physical tray would want ("1 of 24"), which is
§3.4 below. **It is also an N+1**: one statement per batch, which was
tolerable at a handful of sheets and is the thing that grows.

### 3.3 Three list reads truncate in silence

`untagged()` is `LIMIT 200`, `bindCandidates()` `LIMIT 300`, `inUse()`
`LIMIT 500`. All three predate this phase in spirit and none of them says
anything when it clips. An account past the limit gets a bind screen that
quietly does not contain the plant they are standing in front of — which is
precisely the failure §6.4 exists to prevent, arriving by a different road.

The limits are defensible; the silence is not. A count and a "narrow it with
the search box" line costs nothing and turns a wrong list into a short one.

### 3.4 Which cell a stake was in is not stored

Deliberate, and recorded in §14.11: twenty-four stakes on a tray are
twenty-four ways to open one record, and nothing reads a cell number. The
split is where a subset gets its own record and the stakes follow.

It is worth revisiting only if a real use appears — a numbered named label
(§3.2), or "the one in cell 14 died" as something other than a narrative.
**Do not build it speculatively**: it is a column, a form field on every
bind path, and a migration, to serve a question nobody has asked yet.

### 3.5 The batch log form cannot move stakes

`/log/batch` offers no "which stakes went with them", on purpose: each
planting decides for itself whether it splits, so a single tick list across
several plantings would be answering for all of them. The single-plant form
covers the case that actually happens (one tray, one transplanting session).
If a batch transplant of several trays turns out to be real, it needs a tick
list **per planting**, not one shared one.

---

## 4. What must not regress

Everything in `PHASE-13-HANDOFF.md` §4 still applies, and everything in
`PHASE-9-HANDOFF.md` §4 **except 4.2 and 4.4, which §14.7 of the spec
withdraws and replaces**. Phase 13 adds ten.

1. **One live binding per TAG; a planting takes as many as it wants.**
   §2.1. `bindTo()` closes the tag's bindings and **not** the planting's.
   Restoring that one line is a one-word edit that looks like symmetry and
   silently pulls the first stake out of every tray.
2. **The free list is ordered by CODE, and sheet position is only carried
   for codes that have never been on a plant.** §2.2. Sorting the whole list
   by sheet position again is correct in season one and unusable in season
   two, which is exactly the kind of bug that ships.
3. **Chosen codes are validated before the planting is written, and a batch
   is all or nothing.** §2.4. A partial bind is stakes in cells that Carl
   half knows about.
4. **`create()` puts `errors` on the LEFT of the array union.** §2.4. The
   right-hand side is silently discarded and the form comes back looking
   untouched. Thirteen phases went by.
5. **The session's fill target is re-read from the database on every use,
   never trusted from the session.** §2.3. A trusted pointer attaches a stake
   to a plant that ended a week ago.
6. **`LIST_SELECT` gets `tag_count` and `tag_codes` from correlated
   subqueries, never a join.** A join returns the tray once per stake, so
   View Plants would list a twenty-four-stake tray twenty-four times. This
   is the same trap `untagged()` documented for closed bindings, met again
   with live ones. `22_scan_search_test.php` counts the rows.
7. **`moveTags()` moves only stakes that are actually on the parent
   planting.** It re-reads them and ignores anything else in the list, so a
   stale or forged form cannot pull a stake off another plant.
8. **`retireTag()` refuses a bound tag in the SQL `WHERE`, not only in the
   controller.** A plant page that names a stake which is in the bin is the
   thing this prevents.
9. **`/t/{code}` for a bound tag still costs two statements.** Spec §6.3,
   `PHASE-9-HANDOFF.md` §4.5, and `21_tags_test.php` pins it on the
   repositories directly. Phase 13 added nothing to the field screen.
10. **The stake grid is checkboxes and works with JavaScript off.** The
    "tick the next N in peeling order" button and the running count are
    enhancements that reveal themselves (`hidden` until wired). A grid that
    needs script to submit is a grid that fails in a potting shed.

---

## 5. Owner actions outstanding

**Thirteen, and one of them is now discharged.**

Twelve are unchanged and none has been performed; the list is in
`PHASE-10-HANDOFF.md` §5. **§5.1 is still the one platform fact not
established**: outbound HTTPS to `api.anthropic.com` has never been tried
from sh193, carried unchanged through Phases 6–13.

**The thirteenth — Phase 12's migration step — is discharged by this
phase's deploy being a file copy**, but only in the sense that Phase 13 adds
nothing to it. `024_plant_size.sql` still has to run, and **nothing in Phase
12's code works before it does**. If the live site has not had
`/setup?key=` opened since Phase 12, do that first and Phase 13 comes along
for free.

**And the walk is still outstanding**, now with a third thing on it. Take a
phone to a plant and:

1. scan the tag, press Take a photo, and look at which way up it comes out
   (Phase 12);
2. measure the plant, log it, and see whether the growth curve is a curve or
   two points and a straight line (Phase 12);
3. **start a tagging session at a tray of twelve and scan twelve stakes into
   it without touching the screen** (Phase 13). This is the only test of
   §2.3 that matters, because everything about it is timing and the feel of
   a full page load between scans. If it is worse than scan-pick-scan-pick,
   the fill target is the thing to question first.

---

## 6. Claude Design outstanding

**One, and it is smaller than Phase 12's.**

Phase 13 adds no colour and no token. The stake grid (`.tag-grid`,
`.tag-cell`) is `auto-fill` at a 118 px minimum with the existing touch
target and mono face, and it is checked at 380 px in light and dark.

What a designer might want to decide is **what twenty-four ticked
checkboxes should look like when they are a physical tray**. The grid is
honest and slightly relentless; a tray has rows and columns, and the sheet
the labels came off has rows and columns, and neither is currently drawn as
one. It is the first screen in Carl where the layout could mirror a physical
object the user is holding, and it does not. That is a deliberate hold —
nothing was going to be designed on a hunch at the end of a phase — not an
oversight.

Phase 12's question is still open and is unchanged: whether the chart
subject deserves a `--carl-chart-subject` of its own.

---

## 7. Where the bodies are buried

Everything in `PHASE-13-HANDOFF.md` §7 still applies. Phase 13 adds five.

- **A split child inherits its parent's `label`.** `PlantingRepository::
  split()` copies it deliberately (a moved-out six of a tray called "Tray Of
  Twelve" is still that tray's plants). So *"the newest planting called Tray
  Of Twelve"* is the **child** from the moment a split happens, and a
  fixture that re-looks-up a planting by label after a transplant is
  silently testing a different row. `27_tag_desk_test.php` keeps the id in a
  variable for exactly this reason; it cost a confusing failure to find.
- **`GROUP_CONCAT` truncates at a different length on the two engines.**
  MySQL 8.0 — production — defaults `group_concat_max_len` to **1024
  bytes**, about 146 codes; MariaDB 10.11, which the suite is usually run
  against locally, defaults to **1 MB**. `tag_codes` is only *displayed*
  when a planting has one or two stakes, so nothing renders a truncated
  value today. Render it for a large planting and it will be right in CI on
  one engine, right in local dev, and quietly clipped on the live site.
- **The tagging session's cursor sorts by `start_date DESC, id DESC`.** Two
  plantings sown on the same day resolve by id, so a fixture that sows "the
  next one" *before* "the one being filled" gets them the other way round.
  Two tests in `27_tag_desk_test.php` were written the wrong way round first
  and read as a broken session rather than a fixture ordering.
- **A flash is consumed by the next GET.** A test that fetches a page to
  check state and *then* asserts on the flash message has already thrown it
  away. Assert the flash on the first read after the POST.
- **`Controller::redirect()` takes a third argument now — a fragment.** It
  is how the plant page's tag forms come back to `#tag` instead of the top
  of a long report. It is appended after the query string and is not
  escaped, because every caller passes a literal; if one ever passes user
  input, that is the moment it needs escaping.

---

## 8. Working agreement

Unchanged from `PHASE-13-HANDOFF.md` §8, including Phase 12's addition. One
addition, from §2.1:

> A comment can reason validly, from a premise that is true, to a rule that
> is wrong — because the premise and the conclusion are about different
> nouns. "A planting is location-singular" is true and does not imply "one
> tag per planting", because a tag is attached to a plant that moves, not to
> a location. Check what the sentence is *about*, not only whether it is
> true.

And the Phase 10 test, which this phase answered "no" to twice — once for a
rule that had been wrong for five phases, and once for a form that had been
swallowing its own error messages for thirteen:

> **Would anybody find out?**

A silent rule and a silent form both survive every test that does not
specifically go looking, and they both fail in the only place that matters,
which is a garden in July with a stake in your hand.
