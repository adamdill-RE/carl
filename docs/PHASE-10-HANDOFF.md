# Carl The Garden Helper — Phase 10 handoff

**Phase 9 is built and green.** Three refinements, and the third was a
decision before it was a feature: a tag code typed into a search box, a
Calendar, and a pest and disease catalogue that ships with Carl instead of
waiting for somebody to type one.

`CARL-HANDOFF.md` §14 carries a Phase 9 and `deploy.md` carries its deploy —
which has an unusual shape, because one of the two migrations reads a file
that is not code, and because the thing that file contains is prose about
living things and will be wrong about something.

What is left is the Claude Design item that has now outlived six phases, a
report whose column has existed since Phase 7 with nothing calling it, twelve
owner actions, and what Phase 9 found and did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 9 annotated neither.** It leaned on §7 twice — migrations are
   immutable once applied, which is the whole reason the catalogue is a seed
   file and not a migration, and a named placeholder cannot be reused under
   real prepares — and on §9 twice, once for the statement budget and once for
   the page-weight budget that sent `/pests` back to be rebuilt. All four
   times the document was right and complete.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing and now
   carries a Phase 9. **There is still nothing unbuilt in it except §13.5.**
3. **`db/migrations/022_pest_reference.sql`** — a migration that is mostly an
   argument. It is where the "should Carl ship a pest list at all" question
   was answered and why, and it is worth reading before touching any of the
   reference layer. `Carl\Research\PestCatalog` is the companion argument for
   what is in the catalogue and what deliberately is not.
4. **`docs/QR-TAGS-SPEC.md`** — built; §12 is the delta and §13 is what was
   deliberately left. **§7 is why the Phase 9 scan search is a typed code and
   not a camera**, and **§6.2 is why a code that is not yours behaves exactly
   like a code that does not exist.**
5. **`docs/deploy.md`** — the runbook. §0 is every measurement taken. The
   Phase 9 section is two migrations and one file that is not code, and it
   carries the **maintenance path for the catalogue**, which is the only way a
   corrected sentence reaches an installation that has already run 023.
6. **`docs/PHASE-9-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current; §4 gains twelve entries in §4 below
   and §7 gains eight in §7.
7. **§8 below is the working agreement.** Unchanged in substance; two
   additions.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 23 (`001`–`023`), 42 tables, all `utf8mb4_unicode_ci` — **Phase 9 added two, one DDL and one `.php`, and no new table** |
| Routes | 106 — **three new** |
| Source / views | 111 PHP classes (**+4**), 55 templates (**+2**) |
| Tests | **581 tests, 6,519 assertions**, green under `--strict` on MySQL 8.0 **and** MariaDB 10.11 |
| Client shell | 20.1 KB gzipped against a 150 KB budget — **up from 19.1, and all of it is CSS** |
| Cron jobs | six (`deploy.md` §7) — **unchanged** |
| Research template | version 2 — **unchanged**; a zip that imported yesterday imports today |
| Reference catalogue | **76 entries, 128 KB of CSV that is not code** |

Everything from Phases 1–8, plus:

- **A tag code typed into the search box on View Plants and Log Plant
  Activity**, landing on the screen you were already on. The spec rules out an
  in-app scanner and that stands — a phone camera reads a QR symbol from the
  lock screen better than anything that would fit in the budget. What a camera
  cannot do is put six characters into a box on a page you are already looking
  at, and that is the case this fills. A partial code narrows the list; a
  complete code jumps; a code that is not yours falls through to an ordinary
  search **in silence**.
- **`GET /calendar`** — a month grid of what was logged beside what is
  projected, filterable by plant, with the upcoming-actions table beneath it.
  `Carl\Planting\Calendar` is a pure calculator beside `Succession` and
  **computes nothing the digest does not**: same research values, same
  arithmetic, and it calls the digest's own `ReminderBuilder::dtmAnchor()` so
  there is exactly one writer for the rule that decides which end a
  days-to-maturity count starts from.
- **`GET /pests`** — seventy-six pest, disease and disorder entries that ship
  with Carl, in the shape extension pest notes have used for decades: what you
  will see, what it costs to ignore, what it is confused with, when to look,
  how to stop it happening, what to do without a spray, and only then what the
  spray is. **The chemistry is last because that is the order an IPM programme
  asks the questions in**, and it names active ingredients, never products and
  never rates.
- **The dropdown on the log form is no longer empty.** The mechanism —
  `ListRepository::seedForNewUser()` copying the global `pest` table into each
  account's list with `pest_id` as the join — has existed since Phase 1 and
  had nothing to copy, because `pest` is only ever written by a per-county
  research import that is still an owner action.
- **A treatment shelf**, twenty-one entries with "Watched, not treated" first,
  because the most common true answer to "what did you do about it" is
  nothing.

**Two migrations, and the second one reads a file.** 022 is pure DDL —
thirteen columns on `pest` (eleven nullable, plus two flags that default to 0)
and one index — and nothing on any hot path reads them, so a pending 022
leaves every screen working. 023 is DML and a `.php` file: it applies
`db/seed/pest_catalog.csv`, **adopts** the pest entries accounts typed for
themselves, inserts what is missing, and seeds the treatment shelf.
`.cpanel.yml` copies the whole of `db/`, so a normal deploy puts the seed file
there by itself — and running 023 without it fails loudly and writes nothing,
which is the good failure.

---

## 2. What Phase 9 measured that Phase 10 should not re-derive

### 2.1 The mechanism had been there since Phase 1, with nothing to copy

The question asked was "should we ship a list of pests rather than making it
fully self-serve", and the honest first answer was that the code already
believed we did. `user_list_item.pest_id` is a foreign key into a global
`pest` table, `seedForNewUser()` has copied that table into every new account
since Phase 1, and `pest` is written by exactly one thing: a per-county
research import that has never been run on the live installation.

So every account since Phase 1 has started with an empty dropdown and a free
text box, and **free text is what destroys the join**. "Aphids", "aphid",
"greenfly" and "Aphids (again)" are four pests to a database and one to a
gardener, and every multi-season question — did this work, is this getting
worse, does it always arrive in the third week of June — is answered through
that join or not at all.

**The transferable part: a feature that is 90% built and 0% used looks
identical to a feature that was never specified.** The gap was not in the
schema and not in the code; it was that the one thing which fills the table
was an owner action nobody had performed. Before designing a mechanism, check
whether the mechanism is already there and starved.

### 2.2 A page can be right and still be too heavy to be used

`/pests` was built as seventy-six full cards. Every entry was correct, the
whole catalogue was in one statement, and the browser's own find-in-page
searched all of it. It was **202 KB of HTML, 49 KB gzipped** — ten times the
entire client shell, for one page, on the connection somebody standing in a
garden actually has.

Rebuilt as a list that expands one entry via `?key=`, it is **57 KB raw,
11 KB gzipped**, with one extra statement only when somebody has actually
asked for a card. The `signs` line stays in the list on purpose: it is the
sentence people search this page by, and it is what turns a name somebody does
not recognise into one they do.

**Nothing in the suite would ever have caught this.** The asset budget check
measures the shell — CSS and JavaScript — and a rendered page is not in it.
The number came from measuring the page after the fact, which is a thing worth
doing on any screen that draws a whole table.

### 2.3 The gate is "is this one of yours", not "does this look like a code"

`PEPPER` and `GARDEN` are both six characters drawn entirely from the tag
alphabet. So is any word without I, L, O or U in it. A search box that decided
"this looks like a code" and stopped searching for plants would break the
plain search for a real word, and there is no clever pattern that fixes it —
the alphabet was chosen to be readable, which is exactly what makes it collide
with readable words.

What settles it is that the question is answerable cheaply: **one statement
asks whether this exact code is one of yours.** A hit jumps; a miss falls
through to an ordinary search and says nothing. That silence is not politeness
— it is spec §6.2, because a message distinguishing "no such code" from "not
your code" is an enumeration oracle for a tag that is photographable from the
pavement.

### 2.4 A test that pins a rendered count pins the clock as well

The Calendar's roll-up test asserted that a day with two waterings draws
`Watered ×2`. It passed in CI and it passes at nine in the evening. **It fails
at lunchtime**, because the test above it in the same file waters the whole
zone on the same day, and whether that third entry lands in the same grid cell
depends on the relationship between `gmdate('Y-m-d')` — which the fixture is
written with — and the account's local today, which is what the screen draws.

The invariant the test is actually about is that repeats **collapse into one
chip carrying a count**, however many there were. It now asserts that shape:
one `Watered` chip, with a count of at least two. The literal number was never
the point, and pinning it pinned two other things by accident — the clock, and
everything every earlier test in the file had written.

This is the same failure `PHASE-9-HANDOFF.md` §2.4 describes and it is worth
restating in its general form: **a fixture built on `gmdate()` and an
application built on the account's local today agree for nineteen hours a day.**

### 2.5 Editorial prose cannot live in a migration

The catalogue is seventy-six entries of writing about living things. It will
be wrong about something — a name, a look-alike, a control that is no longer
registered — and a correction must not need a schema version, because
migrations are immutable once applied (`hosting.md` §7).

So the catalogue is `db/seed/pest_catalog.csv`, the migration merely applies
it, and **the maintenance path is a button**: Admin → Research import →
"Re-apply and sync every account". It is idempotent, it is safe to press
twice, and pressing it is the only way a corrected sentence reaches an
installation that has already run 023.

The same reasoning applies to anything else Carl will one day want to ship
that is written rather than computed.

---

## 3. Phase 10

### 3.1 The palette and the logo (§13.5) — six phases old, and still the only spec item left

Unchanged in substance from Phase 9 §3.1. `public/assets/css/tokens.css` is
still the one-file swap and still the only file in the repository that names a
colour.

**Two variables in it remain off-limits**: `--carl-qr-ink` and
`--carl-qr-paper`, marked contrast-critical. A QR symbol has to be near-black
on near-white to scan, and a designer who tints the ink to brand green has
silently broken every tag in every garden — printed ones included, since the
PDF layer reads that file. `21_tags_test.php` asserts the warning is still
there.

**Phase 9 added no new palette token.** The severity badges on `/pests` are
`--carl-info`, `--carl-warn`, `--carl-error` and `--carl-accent` with their
existing soft backgrounds, so a palette swap carries them without anybody
touching the pest CSS. That is deliberate and worth preserving: severity is
not a fifth colour family, it is the four that already exist.

### 3.2 The whole-sowing report — a column with nothing calling it

Carried unchanged from Phase 9 §3.2, and it is now the most obviously
ready-to-build thing in the repository. `planting.root_planting_id` exists,
`PlantingRepository::wholeSowing()` reads it in one indexed statement, and
**nothing calls either except a test.**

"This tray produced 100 plants, 94 transplanted into three beds, 61 alive,
40 kg picked" is the report the column was put there for, and it is a page,
not a plumbing change.

Phase 9 sharpens it a third time. The Calendar draws a month per planting, so
a tray split five ways now has five plantings drawing five sets of projections
on the same grid, and the gardener has a screen that shows all of them and
still no screen that says what the sowing did.

### 3.3 Reminders: thirteen kinds, no pagination, and now a second reader

Carried from Phase 9 §3.3 with one addition. `DigestMessage::grouped()` orders
by priority and that is the whole of the triage. Nothing caps the count and
nothing rolls up "and six more waterings".

**The Calendar is the argument for fixing it, not against.** Phase 9 solved
exactly this problem on the grid — repeats collapse into one chip with a
count, a zone watering is one garden entry rather than one per plant, a pest
window draws its opening day rather than each of its ninety open days — and
the digest is now the only screen in the application that still says
everything at once. The roll-up rules are written down in
`Planting\Calendar`'s docblock and `23_calendar_test.php` pins them; the
digest can borrow the reasoning even though it cannot borrow the code.

### 3.4 What Recommendations still wants

Unchanged from Phase 9 §3.4, all four still true:

- Nothing displays `analysis.scope` on the answer itself.
- The per-day cap is per account, not per cost; `document_bytes` is stored per
  row, so a cost-weighted cap needs no migration.
- The answer text is never revisited — an analysis from March is on the page in
  September with only its date to say so.
- The document carries lineage and nothing tells the model what to do with it,
  and it is still untested against a split account.

### 3.5 A second region

Unchanged from Phase 9 §3.5. Everything in the research schema is
region-agnostic and exactly one region has ever been imported. A second county
is the cheapest way to find out what assumed one, and
`plantTypesForRegion()`'s overlay is the obvious suspect.

Phase 9 adds a second reason to want it. `/calendar` and `/pests` both degrade
to something honest without a researched county — the grid draws, the upcoming
table is thin and says so in words, and the catalogue is region-agnostic on
purpose — but **the pest windows on the calendar come from the research
layer**, so a second region is now the only way to see whether they generalise
past the county they were written for.

### 3.6 The catalogue's other half: nineteen columns, and a template version 3

Deliberate future work, not an oversight. The county research importer's
`pests.csv` still carries **seven columns** and the catalogue carries
nineteen, so a county dataset can only ever say something about
`description`, `signs`, `source` and `treatments`.

Widening it means `TEMPLATE_VERSION = 3` and a third entry in
`READABLE_TEMPLATE_VERSIONS`. That was not done in Phase 9 for one reason:
**a research zip that imported yesterday must import today**, and there are
zips sitting in an owner action queue (§5, item 9) that were built against
version 2. Raise the version when those have been imported, not before.

When it is raised, §4 item 15 below is the rule to preserve: the two writers
share six columns on last-writer-wins, and `source` moves alongside the text
it attributes.

### 3.7 The camera question, left open on purpose

The spec says no in-app scanner, ever (§7), and Phase 9 did not build one —
it built the typed-code path instead, which is the case a camera cannot serve.
**That is a reversible decision, and it is the owner's to make**, not a
technical one: the argument against is the 150 KB budget, the JavaScript, and
that the phone already does it better; the argument for is that a code has to
be read off a muddy stake and typed with one hand.

If it is ever wanted, `Controller::tagCodeJump()` is already the whole
back half — a scanner would only have to put six characters into the box that
is already there.

### 3.8 What Phase 9 left undone, deliberately

- **No photographs and no diagnostic key on `/pests`.** A wrong
  identification delivered confidently is worse than none. The screen shows
  what a thing looks like, what it is confused with, and what it costs, and
  leaves the identifying to the person who can see it. Photographs are also a
  licensing question, a storage question and a page-weight question, and §2.2
  is what the third one looks like.
- **The catalogue is not linked from the log form.** Somebody choosing "Pest
  or disease observed" gets the dropdown, not the eight paragraphs behind the
  entry they are choosing. A link per option is a bigger change to the busiest
  form in the application than it sounds, and the Lists screen already reaches
  every card.
- **`affects_categories` is a semicolon string, not a join table.** It is read
  in PHP, never in SQL, and the whole catalogue is one statement — so a join
  table would buy a query nothing asks. Revisit if "show me everything that
  attacks brassicas" ever needs to be a database question rather than an array
  one.
- **Nothing writes back.** An account cannot correct a catalogue entry, only
  add its own beside it. That is the right default for shared reference data
  and it is also why the re-apply button can be safe to press.

### 3.9 What Phase 8 left undone, deliberately

All still open, carried from Phase 9 §3.6:

- **The tag origin is a fourth place the site URL is written down.**
  `config/app.php` has `tags.origin`, and **four older sites still spell
  `https://www.reshiftmanager.com` inline** — one in `AdminController` (the
  invitation link) and three in `Reminders\Digest`. They should move to the
  key. They were left alone because changing what a live mail path builds is
  not a change to make alongside a new feature, and `07_mail_test.php` and
  `10_digest_test.php` are what would have to be re-read first.
- **No scan log.** Spec §3.1. A row per scan is a write on every page view for
  a fact nothing yet reads; the event the user records *is* the trail.
- **The named-label queue is every bound tag**, not the ones that have not had
  a named label printed yet.
- **Retire is per sheet, not per tag.**
- **No in-app scanner, ever.** Spec §7; see §3.7 above.
- **Half of each label stock's geometry is derived rather than published.**
  `Carl\Domain\LabelStock` marks every number, and the registration sheet is
  what turns a derivation into a measurement. **This is an owner action (§5),
  not a code task.**

---

## 4. What must not regress

Everything in `PHASE-9-HANDOFF.md` §4, all of which still holds — the QR
matrices, one live binding per tag and per planting, undo deletes and release
closes, the bind list is untagged plants, `/t/{code}` costs two statements,
the tagging session costs no statement to detect, the field screen offers no
action needing a second answer, the two QR colour tokens are not palette,
label sheets are US Letter, a batch's stock comes from the batch row, tag
codes are strings, a stranger's code and a nonexistent code get the same 404,
and all of `PHASE-8-HANDOFF.md` §4 — plus:

1. **A tag code that is not yours falls through to an ordinary search, in
   silence.** No message, no hint, no different empty state. §2.3 above, and
   spec §6.2 is the reason. `22_scan_search_test.php` proves it by rendering
   the page twice — once with a stranger's code and once with a code that
   never existed — and comparing the two bodies byte for byte with the code
   substituted out.
2. **The scan search costs one extra statement, and only when the query could
   be a code.** A search for "tomato" makes no tag lookup at all;
   `TagRepository::isWellFormed()` is the gate and it touches no database.
3. **The calendar and the digest do not disagree.** They answer two questions
   off the same research values — the digest decides when to SPEAK, the
   calendar draws WHEN IT IS — and `Planting\Calendar` calls
   `ReminderBuilder::dtmAnchor()` rather than reimplementing it.
   `23_calendar_test.php` pins the harvest and hardening dates against the
   digest's own arithmetic, not against numbers typed into the test.
4. **`/calendar` costs seven statements end to end**, six of its own plus the
   one `Auth::user()` makes on every request, and three of the six are skipped
   entirely for an unresearched county. **The plant filter costs no statement
   at all**: the rows it filters and the options it offers are the same array.
5. **The calendar's noise budgets.** A zone watering is one garden entry, not
   one per plant it fanned out to; a pest window draws its opening day, not
   each of its ninety open days; identical chips in one cell roll up into one
   chip with a count. Each of these is a way of making a page unreadable while
   every individual entry on it is true.
6. **A plant filter never hides a garden-wide date.** Frost, sow-by, pest
   windows and zone waterings are about the garden and not about the tomato
   you filtered to; they are kept or dropped by their own tick box, which
   defaults to on. Filtering to one plant still shows the frost that will kill
   it.
7. **`pest` is global, read-only to accounts, and `ReferenceRepository`
   deliberately does not extend `Repository`.** The base class's mandatory
   user scoping is the right default everywhere else and wrong here; that is a
   decision recorded in the class, not an omission.
8. **An account's own pest entry has a NULL `pest_id` and is never touched.**
   Not by 023, not by the re-apply button, not by a county import. It is the
   only signal that separates what somebody typed from what Carl shipped.
9. **023 adopts before it inserts.** An account that had already typed
   "Aphids" gets its row joined to the catalogue entry rather than a second
   row beside it. Reversing those two steps gives every long-standing account
   a duplicate list.
10. **The catalogue and a county research import share six columns on
    last-writer-wins, and `source` moves with the text it attributes.** Either
    writer may be the most recent one and both are idempotent; what must never
    happen is a description from one and a citation from the other.
11. **`treatments` is left exactly as found.** It is where a county dataset
    says something local, and the catalogue has its own columns for the
    general answer.
12. **`/pests` is a list that expands one entry.** §2.2. Drawing all
    seventy-six cards is a correct page that nobody in a garden can load.
13. **The chemical column names active ingredients, never products and never
    rates**, and the label notice stays on the page. Which products are legal
    on which crop differs by state and the label on the bottle is the legal
    authority; `24_pests_test.php` asserts the notice is on the page and that
    no entry's chemical column names a rate.
14. **The IPM section order.** Identification, damage, look-alikes,
    monitoring, prevention, biological and cultural control, and chemistry
    last. Putting the spray first is a different document with a different
    effect on the person reading it.
15. **The seed file has to reach the server before 023 runs**, and 023 must
    keep failing loudly and writing nothing when it has not.
16. **581 tests green under `--strict` on both engines** before any push. The
    assertion count is not stable to the unit — see §7 — so the test count is
    the number to watch.

---

## 5. Owner actions outstanding

Twelve, unchanged from Phase 9 — **none has been performed**. Items 4–6 have
now been outstanding since Phase 3, and items 1–3 still decide whether a
hundred stakes are worth buying.

1. **Print one tag sheet and scan it.** `deploy.md`, the Phase 8 section,
   steps 1–4: mint one sheet, print its **registration test on plain paper**,
   hold it against a real label sheet up to a window, then print the real one
   and scan a tag. Ten minutes. **This is the only verification the derived
   half of the label geometry gets.**
2. **Decide `tags.uppercase_url`.** Open
   `https://www.reshiftmanager.com/CARL/`. A Carl page means set it `true` and
   reprint; a 404 means leave it off. Two minutes, and §12.4 of the QR spec is
   why it is a question at all.
3. **QR spec §1.7, before buying a hundred stakes.** Make five tags: one in
   full sun, one half-buried in wet soil, one under grow lights, one on a car
   dashboard, one indoors as a control. Scan all five weekly for four weeks.
4. **Rotate `cron_key`.** Visible in a Phase 3 screenshot, and it travels in
   URLs. Phase 9 added no route behind it.
5. **Delete `diag_key`** from `config/local.php`; the `/diag` route should be
   shut.
6. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`.
7. **Add the sixth cron job**, `bin/analysis_run.php` hourly at minute 40
   (`deploy.md` §7). Without it the analysis queue never drains.
8. **An Anthropic API key** in `config/local.php`, if Recommendations is
   wanted. Optional: with no key the feature queues and waits.
9. **Import the research datasets.** `research_US-48217_2026-08-31.1.zip` for
   the companion reference and the GDD threshold, and the 68-cultivar Hill
   County set `2026-08-31.2`. **Phase 9 changed what this is worth**: the pest
   dropdown is no longer empty without it, so the import is now about
   companions, the GDD threshold, the calendar's frost dates and planting
   windows, and the local half of the pest text — not about whether the pest
   feature works at all. Do it before raising the research template version
   (§3.6).
10. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox.
11. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports
    look clean.
12. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron. And ask
    Ahosting whether `ea-php82-php-opcache` can be enabled; email Open-Meteo
    describing Carl.

Not an outstanding action, but worth knowing it exists: **Admin → Research
import → "Re-apply and sync every account"** is the button that pushes a
corrected `pest_catalog.csv` out to every account. It is needed only after the
CSV is edited, it is idempotent, and it is safe to press twice.

### 5.1 The one platform fact still not established

**Outbound HTTPS to `api.anthropic.com` has never been tried from sh193.**
Unchanged from Phase 9 §5.1, Phase 8 §5.1, Phase 7 §5.1 and Phase 6 §5.3.
Phase 9 added no third-party call either — the pest catalogue ships in the
repository precisely so that reading about aphids is not a network request to
somebody else's server. Phase 0 spike 1 proved five hosts reachable and this
is not one of them.

The first drain is the test and it is safe: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To settle
it without waiting for the hour, queue something and open
`/tasks/analysis-run?key=<cron_key>`.

**This has now been carried unchanged through five handoffs.** It is five
minutes of somebody's time and it is the only thing standing between
Recommendations and "known to work on this host". If Phase 10 does one owner
action, do this one.

---

## 6. Claude Design outstanding

Down to one item and a note, and the item is the original one.

1. **Logo and palette (§13.5).** `public/assets/css/tokens.css` is the
   one-file swap and carries the chart colours and the PDF colours as well as
   the pages. **Two variables in it are off-limits** — `--carl-qr-ink` and
   `--carl-qr-paper`, marked contrast-critical, for the reason in §3.1. Phase
   9 added no token, so the surface to be swapped has not grown: the calendar
   grid, the severity badges and the pest cards are all existing tokens and
   existing components.
2. **The PDF report layout.** Unchanged; anything Claude Design wants to
   change is inside `Carl\Reports\Document`. Two files are deliberately NOT in
   that layer and should not be pulled into it: `Carl\Reports\FieldSheet`
   (black on white by design) and `Carl\Reports\LabelSheet` (US Letter, zero
   margins, and a symbol whose colours are not a design decision).
3. ~~**The static field-recording sheet (§13.4).**~~ **Built, Phase 6.**

---

## 7. Where the bodies are buried

Everything in `PHASE-9-HANDOFF.md` §7 still applies — `Repository::bind()`,
the multi-row `INSERT` that cannot reuse a named placeholder, PHP casting a
canonical decimal array key to an int, `SELECT p.*` over a LEFT JOIN giving
the columns `p` has, `$_SESSION` outliving a `Client`, a CSS text decoration
that a descendant cannot remove, Apache's case-sensitive path mapping, ISO
18004 §7.8's two defensible mask-scoring readings, and `Reports\Document`
being A4 while every Avery template is Letter. Phase 9 adds eight.

- **`Controller::choice()` reads a POSTED field.** A GET filter that borrows it
  silently validates the wrong superglobal and every value falls back to the
  default. `PestController` makes the same check inline and says why.
- **`Request::query()` returns the default when the value is an array.** A
  `plant_id[]` filter reads as "absent", so the calendar's filter would
  silently be empty. `queryList()` and `queryIntList()` exist for that and are
  the ones to reach for on any repeated query parameter.
- **An unchecked checkbox is indistinguishable from a form that was never
  submitted.** Both send nothing. A hidden marker field is the only thing that
  tells them apart, and without it a default that means "on" flips itself the
  first time somebody uses any other filter on the form.
- **A test that asserts a rendered count also asserts everything every earlier
  test in the file wrote, and the clock.** §2.4. Assert the shape — one chip,
  carrying a count — and let the number be whatever the fixture adds up to.
- **The suite's assertion count is not deterministic.** It drifts by about ten
  between runs on identical code, and it did so before Phase 9. An exact
  assertion count in a document is a snapshot, not an invariant; the test
  count is the number that means something.
- **`Response` exposes `body` as a readonly property, not a `body()` method,
  and a redirect is 303.** Both cost a test-writing cycle each time somebody
  guesses.
- **`EventRepository::recordGardenEvent()` takes seven parameters and returns
  an array.** `(int $gardenId, string $eventType, string $eventDate, array
  $data = [], array $rowIds = [], ?int $waterZoneId = null, bool
  $fanOutToPlants = false)` returning `['event_id' => int, 'fanout' => int]`,
  and the fan-out is off by default. A test that asserts a fan-out happened
  must also put the plantings in a row the garden event reaches — plantings in
  the indoor garden fan out to nothing, and the assertion measures nothing
  while appearing to pass.
- **This repository's CI posts check runs, not commit statuses.** A status
  query returns `state: pending, total_count: 0` forever, on a run that is
  green. The check runs are the authoritative signal. The default branch is
  also **not** `main` — it is
  `claude/carl-garden-helper-phase-one-he3fyp`, and a pull request opened
  against `main` is rejected with a validation error that does not say why.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8 through
`PHASE-9-HANDOFF.md` §8 — including "check a specification's premises against
the other governing documents rather than its conclusions against itself", and
"establish whose failure it is before fixing it", both of which Phase 9 used.
Two more:

- **Measure the page, not just the query.** `/pests` was one statement, fully
  indexed, correct, and 202 KB. Every budget in `hosting.md` §9 that the code
  respects is about the database, and the one it broke is about the person
  waiting for the page. When a screen draws a whole table, render it once and
  look at the size before deciding it is finished.

- **When something must be correctable without a schema version, it is a file
  and there is a button.** Migrations are immutable, so anything that will
  need editing — prose, editorial judgement, a list of living things — cannot
  live in one. Ship it as a seed file, make the migration apply it, and give
  the maintenance path a name somebody can find in an admin screen. §2.5.
