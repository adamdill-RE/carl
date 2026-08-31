# Carl — splitting a planting

**Status: proposal.** Written 2026-08-31, out of the QR tag work, which ran
into this as §10 Q1 and could not answer it. Read `docs/hosting.md` first; it
overrides this file.

---

## 1. The problem, stated exactly

A `planting` is a **group with one location**. It carries
`quantity_initial` / `quantity_live`, and it carries `garden_id`,
`garden_row_id` and `container_id` — one of each, not a list.

So a hundred tomatoes started in one tray are one row, and that row can only
ever be in one place. In reality they go to two gardens and five rows over
three weekends, six at a time. Today Carl cannot say that. It cannot even say
"I transplanted six of them", because the transplant event moves the *planting*
and the planting is all hundred.

This is not a reporting inconvenience. Five features read the planting's single
location as a fact: the weather series, the watering model, row occupancy, the
zone fan-out, and the (unbuilt) crop-rotation warning. **A model where a
planting is in five places at once breaks all five.**

---

## 2. Three models, and why two of them are wrong

### 2.1 Rejected — one planting, many locations

Move location into `planting_placement(planting_id, garden_row_id, quantity,
from_date, to_date)`. A planting spans five rows and Carl knows the quantities.

**This is the one that looks right and is not.** Everything downstream assumes
a planting has *a* location:

- `/api/plant/{id}/series` draws one weather series. Two gardens is two series,
  and the chart has nowhere to put the second.
- `WateringModel` computes a checkbook per garden and per container. A planting
  spanning both has two answers and the MOTD shows one number.
- Row occupancy and the zone fan-out are per row and would each need to know
  the fraction of a planting that is theirs.

You would be adding a quantity-weighted join to five features to avoid adding a
row to one table.

### 2.2 Rejected — a row per physical plant

`plant` under `planting`, a hundred rows for a hundred tomatoes. Maximum
fidelity.

It immediately reintroduces the group it removed: nobody logs watering a
hundred times, so every event UI grows a multi-select and every event write
becomes a fan-out. `plant_event` is already batched per planting
(`EventRepository::recordBatch()`); this would batch it again, one level down.
The fidelity buys nothing Carl asks for — no feature in the handoff is about an
*individual* plant's history.

### 2.3 Chosen — a split makes a real planting

Moving a subset to a different location **creates a planting**, descended from
the first. Six tomatoes into Bed A is a new `planting` row with
`quantity_initial = 6`, in Bed A, whose parent is the tray of a hundred, now
holding ninety-four.

Every planting keeps exactly one location, so all five features above keep
working untouched — and become *correct*, where today they quietly average over
plants that are not where the row says they are.

---

## 3. What the code says, measured

Four facts found by reading it, and three of them make this much smaller than
it looks.

1. **`quantity_live` is written in exactly one statement**, the `UPDATE` in
   `PlantingRepository::recomputeState()` (line ~190). There is no incremental
   arithmetic anywhere else to keep consistent.
2. **State and quantity are derived, not maintained.** `PlantingState::derive()`
   is a pure function of `(start_method, start_date, quantity_initial, events)`
   and is re-run over the whole log after every insert, edit or delete —
   deliberately, so that backdating works (handoff §5.3). A new event type with
   a negative `quantity_delta` therefore flows through the quantity arithmetic
   **with no change to it at all**: `derive()` already sums every non-null
   delta.
3. **`EventType::MOVED` is defined and implemented nowhere.** It is in the
   vocabulary and its label is "Moved", and no controller, repository or view
   references it. The original design left the hook and never used it.
4. **Reach:** 44 files mention `planting`; 24 files and 72 references mention
   `quantity_initial` / `quantity_live`. The state machine's tests are
   `03_flow_test.php` (833 lines) and `06_backfill_test.php` (193), and the
   report endpoints' statement-count assertions are in `11_reports_test.php`
   (656).

---

## 4. The design

### 4.1 The user never sees the word "split"

**The split and the transplant are the same act.** The user's sentence is "I
transplanted six of them", not "I split the planting and then transplanted the
child".

So: Log Plant Activity → **Transplanted** → *how many?* `[6] of 94` → *where
to?* → done. Carl splits behind it. Same for **Up-potted** and for **Moved**,
which is finally implemented (§3, fact 3).

Everything else needs no split at all, and must not get one. Watering,
fertilising, dying, culling and yielding all apply to a quantity **within one
location**; the plants do not go anywhere. The rule is one line:

> A split happens when, and only when, a subset moves to a *different*
> location.

### 4.2 Schema — migration `017_planting_split.sql`

```
planting.split_from_id      INT UNSIGNED NULL   FK planting(id)
planting.root_planting_id   INT UNSIGNED NOT NULL   -- self when never split
planting.ended_reason       ENUM('attrition','dispersed') NULL
KEY (split_from_id), KEY (root_planting_id)
```

Backfill is `root_planting_id = id` for every existing row, and nulls
elsewhere — so **the change is a no-op for anyone who never splits.** That is
the main de-risking property of this design and it should be asserted by a
test.

`root_planting_id` exists so "everything descended from this sowing" is one
indexed statement rather than a recursive walk. `split_from_id` alone would
force the walk, and the report endpoints have statement-count tests that a walk
would break (Phase 5 handoff §4.1).

### 4.3 Event vocabulary

One addition: `split_out`, recorded **on the parent**, `quantity_delta = -k`,
carrying the child's id. The child's own first event is the physical one —
`transplanted`, `up_potted` or `moved` — because that is what happened to it.

`split_out` carries a negative delta but **is not attrition**, and that
distinction has to be made explicit:

```
EventType::isAttrition()   germination_failed, died, culled     -- unchanged
EventType::isDispersal()   split_out                            -- new
```

### 4.4 The one bug this introduces, and where

`PlantingState::derive()` ends with:

```php
if ($live === 0) {
    $state = self::ENDED;
}
```

— unconditional, while `ended_at` is set only when the zeroing event was
attrition. Split every plant out of a tray and the parent comes back
**`state = ended` with `ended_at = null`**, an inconsistent pair, and the UI
calls a tray that was fully transplanted "ended", which reads as dead.

The fix is `ended_reason`, not a new state. Adding a value to the `state` ENUM
would touch every `switch` on state and every label map; a nullable reason
column changes the sentence the UI prints and nothing else. A planting whose
last plant left by dispersal is ended with `ended_reason = 'dispersed'`, and
the plant page says "fully transplanted out" with links to where they went.

**This is the highest-risk edit in the change** — twenty lines in the function
that every other feature's correctness rests on.

### 4.5 Survival and germination rates

`PdfBuilder` computes `$live / $initial` as a survival percentage. After a
split that is wrong for the parent: six plants leaving looks identical to six
plants dying.

Rates must be computed as `quantity_initial + SUM(attrition deltas only)`.
There is exactly one site today, which is the moment to make it a helper on
`EventType` or `PlantingState` rather than a second expression that can drift
from the first.

### 4.6 Lineage in the UI — link, do not merge

The child's history before the split is the parent's. **Do not merge the two
timelines.** Show a line at the head of the child's history:

> Split from *Cherokee Purple* (tray of 100) on 14 Apr — see its earlier history

Merging means walking the ancestor chain on every plant page and every series
request, which costs a statement per generation and breaks the assertions in
`11_reports_test.php` that a 200-day planting costs the same three statements
as a two-day one. The link costs nothing, and it is also more honest: those
events happened to the tray, not to these six.

`root_planting_id` is there for when a whole-sowing report is wanted; that is a
later feature and a single indexed query, not a reason to merge now.

### 4.7 Backdating a contradiction

Carl backdates everything, so this will happen: you split six out on 14 April,
then on 20 April you record that twenty died on 12 April — before the split.
The parent now had 80, not 100, and 6 of them are gone.

**The child is never retroactively resized.** The six physically moved; no
later bookkeeping un-moves them. The parent's `derive()` sums the deltas and
`derive()` already clamps `$live < 0` to zero, so a contradictory backdate
degrades to zero rather than to a negative or an exception. Say so in the
docblock, and test it — an unstated clamp is a bug waiting to be "fixed".

---

## 5. The blast radius

| Where | What | Size |
| --- | --- | --- |
| `db/migrations/017` | three columns, two indexes, a backfill | trivial |
| `Domain\EventType` | `split_out`, `isDispersal()` | trivial |
| `Domain\PlantingState` | the dispersal branch of §4.4 | **small code, highest risk** |
| `Repo\PlantingRepository` | `split()` — insert child, insert parent event, recompute both, in one transaction | ~80 lines |
| `Controller\LogController` | quantity + destination on transplant / up-pot / move; batch logging over a partial quantity | **the largest piece** |
| `views/plants/*` | the transplant form, a lineage panel, a lineage badge in the list | moderate |
| `Reports\PdfBuilder` | the rate fix of §4.5 | trivial |
| `Controller\ExportController` | lineage columns in CSV and `claude.json` | small |
| `Reminders`, `Weather\WateringModel`, `Repo\GardenRepository` | **no change** — they read a planting's single location, which still exists | none |
| `tests/` | split, split a split, split everything out, attrition after a split, backdate before a split, delete a split, and the report statement counts re-run | **comparable to the code** |

The three that need no change are the point of choosing §2.3.

---

## 6. How big a lift

**Smaller than it looks, and riskier than it is big.**

Small because of §3: the quantity arithmetic needs no edit at all, there is one
writer of `quantity_live`, and the state machine is a pure function with a test
suite already pointed at it.

Risky because `PlantingState::derive()` is the one piece of logic every other
feature's correctness rests on, and the change to it is the kind that passes
every existing test — nothing today splits anything — while being wrong for a
case no test covers yet.

Call it **a phase in its own right, of roughly Phase 4's size**: a couple of
days of model and repository, a couple more of the transplant UI, and a test
sweep as large as the code. It is not a task that fits inside another feature.

The honest cost driver is not the split. It is that **the transplant form stops
being a form about a plant and becomes a form about a quantity of a plant**,
and every other partial-quantity action has to be looked at once in that light.

---

## 7. Sequencing: this before the QR tags

The QR spec's §10 Q1 asks whether twelve tags may point at one planting. **If
splits exist, the question dissolves** — a planting is location-singular by
construction, one tag per planting is simply correct, and there is nothing to
decide.

Build QR first and you pay twice:

- Tag semantics get designed against a unit that is about to change shape, and
  the rules for "a tag on a planting that later splits" have to be invented and
  then thrown away.
- The natural moment to bind a tag to the six plants you are moving is **the
  transplant itself** — which is exactly the screen this change rebuilds. Doing
  the split second means opening that screen twice.

So: **splits, then QR.** And when QR lands, the transplant flow gains one line —
"scan a tag for the six you're moving" — instead of a section.

---

## 8. What this does not do

- **No individual plant identity.** Six plants in Bed A are still a group of
  six. If "the third one from the left died" ever matters, that is §2.2 and a
  different proposal.
- **No merge.** Two plantings cannot be recombined. Nothing asks for it, and
  the inverse of a split is a much worse problem: which parent's history wins.
- **No change to anyone who never splits.** §4.2.
