# Carl The Garden Helper — Phase 9 handoff

**Phase 8 is built and green.** QR plant tags (`docs/QR-TAGS-SPEC.md`) are in,
5a and 5b both. A stake in the soil carries a code; a phone camera turns it
into that plant's logging screen; a watering costs two taps instead of six.

The spec is marked built and carries a new **§12: the seven places the build
diverged from it, and why**. Read that before reading the spec as a
description of the code. One of the seven is not a detail — **§2.2's
all-uppercase URL would have printed tags that 404**, and §12.4 is the whole
argument.

What is left is the Claude Design item that has now outlived five phases, a
report whose column has existed since Phase 7 with nothing calling it, ten
owner actions, and what Phase 8 found and did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 8 annotated neither.** It leaned on hosting §7 three times — the
   DDL/DML kind line, no placeholder reuse under real prepares, and no
   Composer — and on §5.1 once, for the fact that broke §2.2 of the QR spec.
   All four times the document was right and complete.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing and now
   carries a Phase 8. **There is nothing unbuilt left in it except §13.5.**
3. **`docs/QR-TAGS-SPEC.md`** — built, and **§12 is the delta, §13 is what was
   added beyond it and what was deliberately left.**
4. **`docs/deploy.md`** — the runbook. §0 is every measurement taken. The
   "Redeploying" section gained a Phase 8 entry with an unusual shape: the
   smallest migration in the project's history with the widest blast radius.
   Read it, and read the two steps after the migration — they are the only
   verification the label geometry gets.
5. **`docs/PHASE-8-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current; §4 gains twelve entries in §4 below
   and §7 gains nine in §7.
6. **§8 below is the working agreement.** Unchanged in substance; two
   additions.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 21 (`001`–`021`), 42 tables, all `utf8mb4_unicode_ci` — **Phase 8 added one, and it is pure DDL** |
| Routes | 103 — **sixteen new**, and two of them are the same action under two spellings |
| Source / views | 107 PHP classes (**+11**), 53 templates (**+7**) |
| Tests | **517 tests, 2,885 assertions**, green under `--strict` on MySQL 8.0 **and** MariaDB 10.11 |
| Client shell | 19.1 KB gzipped against a 150 KB budget — **up from 17.4, and all 1.6 KB of it is CSS** |
| Cron jobs | six (`deploy.md` §7) — **unchanged** |
| Research template | version 2 — **unchanged**; a zip that imported yesterday imports today |

Everything from Phases 1–7, plus:

- **A tag is a reusable physical object**, bound to a planting for a period of
  time. Print blank codes in January at a desk; take one out of the box in
  April when a tray needs one; release it at the end of the season and use the
  same stake next year for something else. The binding history is kept, so an
  old photograph of a stake does not lie about what it was.
- **`GET /t/{code}` is the whole point.** Two statements, `Route::USER_ACCESS`,
  and it lands on a **field screen** — one row of large tap targets, one tap
  records one event dated today and comes back. Not `/plants/{id}`: that is
  the report page with charts, which is the right page at a desk and the wrong
  one in a garden.
- **A QR encoder written from the standard**, `app/src/Qr/` — 1,239 lines of
  which 652 are code, the rest being the reasoning: alphanumeric with a byte
  fallback, error levels M and Q, versions 1–4. No
  QR-image web service (a third-party call on a request path, and every plant
  URL in the account handed to a stranger) and no library (no Composer here).
- **Label sheets for two Avery stocks**, drawn as merged FPDF rectangles
  rather than an image: vector at any DPI, no GD, no temp file. A 100 mm
  calibration rule on every sheet, and a registration test sheet for plain
  paper.
- **Bulk tagging in twelve scans and zero taps.** Carl holds the cursor, names
  the next untagged plant, and the scan is the confirm.
- **Tagging a plant Carl already knows about** is the same act from the other
  end: a tag panel on every plant page, and a bind list of every *untagged*
  living plant rather than the recent ones.
- **End Growing Season offers to release the tags**, opt-in, because it is a
  physical claim about stakes you did or did not pull.

**One migration, and `deploy.md` says why its blast radius is not proportional
to its size.** 021 adds three tables and two columns on `user` — and those two
columns are read by `Auth::user()`, which runs on **every request**. Between
the deploy and `/setup?key=`, every signed-in page returns 500, not just the
tag screens.

---

## 2. What Phase 8 measured that Phase 9 should not re-derive

### 2.1 A spec can be right about the engineering and wrong about the platform

`QR-TAGS-SPEC.md` §2.2 is the best-argued section in any document here. It
derives, correctly, that an all-uppercase URL keeps the payload in QR
alphanumeric mode, which buys a version 3 symbol where lower case needs
version 4, and 0.649 mm modules against 0.585 on the same tag. Every number in
it is reproduced by `21_tags_test.php` from the code, not quoted.

Its conclusion is wrong, and the sentence that does it is **"DNS is
case-insensitive and we choose the path"**. Half of that path is a directory.
Carl is served from `public_html/carl/` and Apache maps URL paths onto
filesystem paths case-sensitively, so `/CARL/T/AB7K4M` is the web server's own
404 — the `.htaccess` inside `carl/` is never consulted and `index.php` never
runs.

**The transferable part is the shape of the mistake, not the fact.** The
reasoning was purely about encoding and the fact that broke it was in
`hosting.md` §5.1, which the spec's own header says to read first. A
specification that reasons impeccably inside one document is exactly the one
to check against the other two.

It cost nothing to absorb: `tags.uppercase_url` is off unless the mount point
cannot bite, the Tags screen shows what the current encoding costs, and the
lower-case symbol is still 2.3× ISO 18004's practical print floor.

### 2.2 An external oracle beats a fixture, and a decoder beats an encoder

§4.1 asks for module matrices captured from an independent implementation and
asserted bit for bit, "because a PHP decoder does not exist to round-trip
against". True of PHP; not true of the offline generator, which is a checked-in
Python script that nothing in the suite runs.

So `tests/fixtures/qr/generate.py` refuses to write a fixture unless **an
independent decoder reads the exact payload back out of the matrix**, *and* an
independent encoder agrees module for module. The decoder is the check that
actually says "this tag will scan"; it shares no table and no code path with
an encoder, where two encoders can agree on the same misreading of a standard.

It also found the limit of the encoder-comparison half: the reference
implementation appends a pad codeword ISO 18004 §7.4.10 does not call for, on
payloads whose bit stream ends flush on a byte. That changes a data codeword,
which changes every error-correction codeword in its block, which can change
which mask scores lowest — so on four of eleven payloads the two symbols
legitimately differ and only the decoder says anything. The script classifies
those rather than papering over them.

### 2.3 Three bugs, and all three were caught by the same kind of test

The suite found what review would not have:

- **A code that is six digits with no leading zero came back as an `int`.**
  Codes were deduplicated by using them as array keys, and PHP casts a
  canonical decimal key to an integer. About one code in twelve hundred, so a
  couple of times a sheet over a season. It reached the database intact and
  broke at the far end, where the tag on the stake is the one Carl cannot find.
- **A multi-row `INSERT` reused named placeholders.** Emulation is off, so
  those are real server-side prepares and a name cannot be reused within one
  statement (hosting §7). It fails at the moment of minting, on the one screen
  that mints.
- **An unbound tag had no `planting_id` key at all.** `SELECT p.*` over a LEFT
  JOIN gives every planting column as null — and `planting` has an `id`, not a
  `planting_id`, so "is this tag on something?" was an undefined-index notice
  rather than a null.

None is subtle in hindsight and none is visible by reading. What found all
three is a test that **exercises the write path with real data and reads it
back**, rather than asserting that a function returns what it was told to.

### 2.4 A green suite can be red for six hours a night

Three existing test files compared a date the application wrote in the
account's timezone against one the test computed with `gmdate()`. The accounts
are in `America/Chicago`, so between UTC midnight and local midnight those are
different days.

The suite passed every afternoon and failed every evening, and it was failing
on `a83d5e7` before Phase 8 touched anything — which is worth knowing, because
the obvious reading of four red tests on a new branch is that the branch did
it. **Establish that before fixing it**: a worktree at the previous commit and
a second database settled it in three minutes.

The invariant they were breaking is handoff §6, "the user's local today, never
the server's" — the one the whole application is careful about. The tests were
the only place it was not.

---

## 3. Phase 9

### 3.1 The palette and the logo (§13.5) — now the only spec item left, five phases old

Unchanged in substance from Phase 8 §3.2, and it is now the *only* thing in
`CARL-HANDOFF.md` that is not built. `public/assets/css/tokens.css` is still
the one-file swap and still the only file in the repository that names a
colour.

**Phase 8 added colour to it for the first time, and marked it un-swappable.**
`--carl-qr-ink` and `--carl-qr-paper` are in `tokens.css` because that rule is
worth keeping, and they carry a comment saying they are contrast-critical and
must not be themed. A QR symbol has to be near-black on near-white to scan;
a designer who tints the ink to brand green has silently broken every tag in
every garden — printed ones included, since the PDF layer reads that file —
and nothing will report it. `21_tags_test.php` asserts the warning is still
there, which is the most a test can do about it.

The rest of the Phase 8 UI added **no colour and no new palette token**: the
tag screens are `card`, `list`, `notice`, `btn`, `field`, `help`, `badge` and
`menu`, all of which already existed.

### 3.2 The whole-sowing report — a column with nothing calling it

Carried unchanged from Phase 8 §3.3, and it is now the most obviously
ready-to-build thing in the repository. `planting.root_planting_id` exists,
`PlantingRepository::wholeSowing()` reads it in one indexed statement, and
**nothing calls either except a test.**

"This tray produced 100 plants, 94 transplanted into three beds, 61 alive,
40 kg picked" is the report the column was put there for, and it is a page,
not a plumbing change.

Phase 8 makes it better and slightly more urgent: a tray that was split five
ways now has five tags on five stakes, and the gardener holding one of them
has no screen that says what the whole sowing did.

### 3.3 Reminders: thirteen kinds, no pagination, and tags make it worse again

Carried from Phase 8 §3.5 with one addition. `DigestMessage::grouped()` orders
by priority and that is the whole of the triage. Nothing caps the count and
nothing rolls up "and six more waterings".

The split made it worse by multiplying plantings. Tags do not multiply
plantings, but they change who is reading: somebody standing in a garden with
a phone, who has just been told by a scan exactly what one plant needs. The
digest is the screen that still says everything at once.

### 3.4 What Recommendations still wants

Unchanged from Phase 8 §3.4, all four still true:

- Nothing displays `analysis.scope` on the answer itself.
- The per-day cap is per account, not per cost; `document_bytes` is stored per
  row, so a cost-weighted cap needs no migration.
- The answer text is never revisited — an analysis from March is on the page in
  September with only its date to say so.
- The document carries lineage and nothing tells the model what to do with it,
  and it is still untested against a split account.

### 3.5 A second region

Unchanged from Phase 8 §3.6. Everything in the research schema is
region-agnostic and exactly one region has ever been imported. A second county
is the cheapest way to find out what assumed one, and
`plantTypesForRegion()`'s overlay is the obvious suspect.

### 3.6 What Phase 8 left undone, deliberately

Each is a real gap with a reason, not an oversight:

- **The tag origin is a fourth place the site URL is written down.**
  `config/app.php` gained `tags.origin`, and **four older sites still spell
  `https://www.reshiftmanager.com` inline** — one in `AdminController`
  (the invitation link) and three in `Reminders\Digest`. They should move to
  the key. They were left alone because changing what a live mail path builds
  is not a change to make alongside a new feature, and `07_mail_test.php` and
  `10_digest_test.php` are what would have to be re-read first.
- **No scan log.** Spec §3.1, and the reason stands: a row per scan is a write
  on every page view for a fact nothing yet reads. The event the user records
  *is* the trail. Revisit if "when did I last walk this bed" turns out to be a
  question worth answering — and note that the field screen's "Lately" block
  is the cheap half of that answer already.
- **The named-label queue is every bound tag**, not the ones that have not had
  a named label printed yet. Tracking "printed" is a column and a write path
  for a case the start-at-position control already covers.
- **Retire is per sheet, not per tag.** The sheet is the thing that actually
  goes missing; a single ruined stake is thrown away and its code sits unbound
  in the pool, which is the correct state for it.
- **No in-app scanner, ever.** Spec §7. The phone's camera reads QR codes from
  the lock screen, it is better at it than anything that could be vendored
  inside the 150 KB budget, and it needs no JavaScript.
- **Half of each label stock's geometry is derived rather than published.**
  `Carl\Domain\LabelStock` marks every number, and the registration sheet is
  what turns a derivation into a measurement. **This is an owner action (§5),
  not a code task** — but if a sheet comes back misregistered, the fix is one
  edit to one class, no migration, and every batch already minted re-renders
  correctly.

---

## 4. What must not regress

Everything in `PHASE-8-HANDOFF.md` §4, all of which still holds, plus:

1. **The QR module matrices are exact.** `21_tags_test.php` asserts eleven
   matrices row by row against `tests/fixtures/qr/matrices.json`. A change to
   the encoder that alters one module is a change that alters a fixture, and
   the fixture may only be regenerated by `generate.py`, which re-runs the
   decoder round trip. **Do not hand-edit `matrices.json`.**
2. **One live binding per tag, and one per planting.** `unbound_at IS NULL` is
   the live row, and `TagRepository::bindTo()` closes both sides in one
   transaction before opening the new one. Two live tags on one plant is Carl
   telling a gardener the stake in their hand is something it is not.
3. **Undo DELETES and release CLOSES, and the difference is the point.** An
   undone scan must leave no trace: a closed binding would read forever after
   as "this tag was on that plant for four seconds", which is a lie about a
   physical object. A release is a true fact and is kept.
4. **The bind list is UNTAGGED plants; recency is only the sort.** Spec §6.4,
   and the spec's own first draft got this wrong. A recency filter hides the
   May tomato you are standing in front of. Nothing is filtered out.
5. **`/t/{code}` costs two statements.** Spec §6.3 allows three. It is a page
   hit forty times in one walk around a garden against a database on separate
   hardware, and `21_tags_test.php` pins the number on the repositories
   directly — never through `Client`, which builds a fresh `App` per request
   and would count zero.
6. **The tagging session costs no statement to detect.**
   `tagging_started_at` rides on the user row `Auth::user()` already selects.
   The cursor costs three more, which is why it is on the tag screens and the
   strip on every other page is the flag alone.
7. **The field screen offers no action that needs a second answer**, and the
   POST refuses one even if the form is forged. A default guessed on the
   user's behalf writes a number nobody said, into a log everything else is
   derived from.
8. **`--carl-qr-ink` and `--carl-qr-paper` are not palette.** §3.1 above.
9. **Label sheets are US Letter and every stock fits the page.**
   `SetAutoPageBreak(false)` means geometry past the paper does not error and
   does not spill to page two — it prints off the bottom and the labels are
   simply not there. `LabelStock::fitsPage()` is asserted for every stock.
10. **A batch's stock comes from the batch row, never from the user's current
    preference.** It is what makes a reprint identical to the first print, and
    the moment it matters is exactly the moment somebody has switched stocks
    and is holding a half-used sheet of the old one.
11. **Tag codes are strings.** §2.3 above; `21_tags_test.php` pins it with
    `gettype()`, not with a truthiness check.
12. **A code that is not yours and a code that does not exist get the SAME
    404.** Spec §6.2. A tag on a stake in a front garden is photographable
    from the pavement, and anything that told the two apart would let a
    stranger enumerate which codes are real.
13. **Everything in `PHASE-8-HANDOFF.md` §4** — a dispersal is not attrition,
    one writer for the derived quantities, `state = ended` and `ended_at` are
    a pair, a null `ended_reason` means attrition, the backdate clamp,
    `root_planting_id` is never 0, the root is flattened, a split is one
    transaction, lineage is a link, GDD in Fahrenheit, the biofix reading
    backward, ten digest statements, twelve analysis statements, the field
    sheet fitting one page, and a template version 1 zip still importing.
14. **517 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

Twelve. Items 4–6 have been outstanding since Phase 3; items 1–3 are new and
are the ones that decide whether a hundred stakes are worth buying.

1. **Print one tag sheet and scan it.** `deploy.md`, the Phase 8 section,
   steps 1–4: mint one sheet, print its **registration test on plain paper**,
   hold it against a real label sheet up to a window, then print the real one
   and scan a tag. Ten minutes. **This is the only verification the derived
   half of the label geometry gets**, and it costs a sheet of paper against a
   wasted sheet of polyester.
2. **Decide `tags.uppercase_url`.** Open
   `https://www.reshiftmanager.com/CARL/`. A Carl page means set it `true` and
   reprint; a 404 means leave it off. Two minutes, and §12.4 of the QR spec is
   why it is a question at all.
3. **QR spec §1.7, before buying a hundred stakes.** Make five tags: one in
   full sun, one half-buried in wet soil, one under grow lights, one on a car
   dashboard, one indoors as a control. Scan all five weekly for four weeks.
   The arithmetic in §2.3 is not the acceptance criterion; this is.
4. **Rotate `cron_key`.** Visible in a Phase 3 screenshot, and it travels in
   URLs. Phase 8 added no route behind it.
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
   County set `2026-08-31.2`. Without the first, `/companions` is an empty
   page that explains itself and the GDD reminder runs off the unvalidated
   `approx` row.
10. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox.
11. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports
    look clean.
12. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron. And ask
    Ahosting whether `ea-php82-php-opcache` can be enabled; email Open-Meteo
    describing Carl.

### 5.1 The one platform fact still not established

**Outbound HTTPS to `api.anthropic.com` has never been tried from sh193.**
Unchanged from Phase 8 §5.1, Phase 7 §5.1 and Phase 6 §5.3 — Phase 8 added no
third-party call either, and deliberately: the QR encoder exists precisely so
that printing a tag does not become one. Phase 0 spike 1 proved five hosts
reachable and this is not one of them.

The first drain is the test and it is safe: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To settle
it without waiting for the hour, queue something and open
`/tasks/analysis-run?key=<cron_key>`.

**This has now been carried unchanged through four handoffs.** It is five
minutes of somebody's time and it is the only thing standing between
Recommendations and "known to work on this host". If Phase 9 does one owner
action that is not about tags, do this one.

---

## 6. Claude Design outstanding

Down to one item and a note, and the item is the original one.

1. **Logo and palette (§13.5).** `public/assets/css/tokens.css` is the
   one-file swap and carries the chart colours and the PDF colours as well as
   the pages. **Two variables in it are off-limits** — `--carl-qr-ink` and
   `--carl-qr-paper`, marked contrast-critical, for the reason in §3.1.
2. **The PDF report layout.** Unchanged; anything Claude Design wants to
   change is inside `Carl\Reports\Document`. Two files are deliberately NOT in
   that layer and should not be pulled into it: `Carl\Reports\FieldSheet`
   (black on white by design) and `Carl\Reports\LabelSheet` (US Letter, zero
   margins, and a symbol whose colours are not a design decision).
3. ~~**The static field-recording sheet (§13.4).**~~ **Built, Phase 6.**

---

## 7. Where the bodies are buried

Everything in `PHASE-8-HANDOFF.md` §7 still applies. Phase 8 added nine, and
the first four are all one lesson: **the loud failure is the good version.**

- **`Repository::bind()` is the base class's parameter-binding helper.** A
  repository method named `bind()` is a fatal at class load, naming both
  signatures. It cost thirty seconds because PHP refused to load the file;
  had it been `bind(int, int)` on a class that did not extend `Repository`, it
  would have been a scoping bug found in a season.
- **A multi-row `INSERT` cannot reuse a named placeholder.** Emulation is off
  (hosting §7), so `:user_id` in twenty-four tuples is `SQLSTATE[HY093]
  Invalid parameter number`. Every value in every tuple needs its own name —
  `Repository::inClause()` is the existing precedent and says so.
- **PHP casts a canonical decimal string array key to an integer.**
  `$seen['123456'] = true; array_keys($seen)` gives `[123456]`, an int.
  `'012345'` stays a string, because a leading zero is not canonical. Anything
  that deduplicates identifiers by using them as array keys will hand back
  integers for the numeric-looking ones.
- **`SELECT p.*` over a LEFT JOIN gives you the columns `p` HAS.** `planting`
  has an `id`, so there is no `planting_id` key to be null — there is no key.
  Select the foreign key from the side that owns it.
- **`$_SESSION` outlives a `Client` in the test suite**, which runs every file
  in one PHP process. A file whose predecessor finished signed in starts
  signed in as that user, and `AuthController` *silently* declines to log in
  when somebody already is — it redirects to the menu. Every screen then
  renders for the wrong account and every scoped lookup 404s. **The file
  passes alone and fails in the suite**, which is the worst shape a failure
  has. `$client->forgetCookies()` before the login is the fix and thirteen
  other files in the suite already do it.
- **A CSS text decoration cannot be removed by a descendant.** `text-decoration:
  none` on a span inside an underlined button does nothing; the underline is
  drawn across the whole subtree. Move the decoration onto the child that
  should have it.
- **Apache maps URL paths onto filesystem paths case-sensitively**, so the
  mount segment of a URL is a directory name and not ours to case as we like.
  §2.1, and the reason `tags.uppercase_url` exists.
- **ISO 18004 §7.8 has two defensible readings and encoders disagree.** Scoring
  a mask candidate with the format information stamped, or without it, picks a
  different mask on a good fraction of payloads. **Both produce symbols that
  decode** — the score only picks the one least likely to trouble a scanner —
  so this is never a correctness bug, and it is exactly why a fixture test has
  to be told which convention it is pinning. `Encoder::chooseMask()` is the
  docblock.
- **`Carl\Reports\Document` is A4 and every Avery template is Letter.**
  Rendering a Letter layout onto A4 puts every column ~3 mm off and drops the
  bottom row off the page, and **nothing errors**. `LabelSheet` is a sibling,
  not a subclass, and `Document::MARGIN` and `WIDTH` are consts used in twenty
  places that all assume a text column — do not try to parameterise them.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8 through
`PHASE-8-HANDOFF.md` §8 — including "put a new invariant in the schema, and
let it break the fixtures", which Phase 8 used twice and which stands. Two
more:

- **A specification is right about what it reasoned over.** `QR-TAGS-SPEC.md`
  §2.2 is a page of correct arithmetic resting on one sentence about Apache,
  and the arithmetic was never the risk. Before building a section, check its
  *premises* against the other governing documents rather than its
  *conclusions* against itself — and when a spec's own header says "read
  hosting.md first; it overrides this file", that is the instruction being
  described.

- **Establish whose failure it is before fixing it.** Four tests were red on
  the branch and the obvious reading was that the branch did it. A worktree at
  the previous commit and a second database said otherwise in three minutes,
  and that changed the fix from "make my change compatible" to "these
  assertions have been wrong since they were written". The cheap experiment is
  the one that tells you which problem you have.
