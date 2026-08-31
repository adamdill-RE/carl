# Carl The Garden Helper — Phase 6 handoff

**Phase 5 is built and green.** The Reports menu, Recommendations, End Growing
Season, crop rotation warnings and the tokenised set-password link all work.
`CARL-HANDOFF.md` §14 has four of the seven v2 items struck through.

What is left is three v2 items nobody has scoped, one Claude Design blocker
that has now blocked two phases, and a short list of things Phase 5 found and
did not fix.

---

## 0. Read these first

1. **`docs/hosting.md`** and **`docs/weather.md`** — copied-in authorities on
   the platform and on weather. They override everything, this file included.
   **Phase 5 annotated neither**, which is worth knowing: it measured plenty
   (see §2) but nothing that contradicted either document, and the one
   platform fact it could not establish is in §5.3 rather than in hosting.md,
   because it is still unknown.
2. **`docs/CARL-HANDOFF.md`** — the specification. §14 is the phasing, §17 the
   working agreement.
3. **`docs/deploy.md`** — the runbook. §0 is every measurement taken, and
   Phase 5 added §0.9, which is the one that decided the shape of the whole
   Recommendations feature. §7.6 is the new owner action.
4. **`docs/PHASE-5-HANDOFF.md`** §4 (what must not regress) and §7 (where the
   bodies are buried). Both still current; §4 gains six entries in §4 below,
   and one line of §3.5 was wrong — see §2.3.
5. **§8 below is the working agreement.** Unchanged in substance; one
   addition.

---

## 1. What is built and deployed

| | |
| --- | --- |
| Migrations | 17 (`001`–`017`), 38 tables, all `utf8mb4_unicode_ci` — **Phase 5 added two** |
| Routes | 82 (72 + 10) |
| Source / views | 91 PHP classes, 43 templates |
| Tests | **335 tests, 1,495 assertions**, green under `--strict` on MySQL 8.0.46 **and** MariaDB 10.11.14 |
| Client shell | 17.3 KB gzipped against a 150 KB budget (Chart.js excluded, by design) |
| Cron jobs | **six**, not five (`deploy.md` §7) |

Everything from Phases 1–4, plus:

- **`/reports`** — the Reports menu. Links and no new data access, and it is
  now the seventh item on the main menu in place of Export, which it links to.
- **`/advice`** — Recommendations. A page queues an `analysis` row and
  returns; `bin/analysis_run.php` calls the Anthropic API hourly and stores
  the answer; the answer is on the page on the next load. **This is the only
  place in Carl that calls that API, and it is a cron job.**
- **`/gardens/{id}/end-season`** — End Growing Season. Ends every living
  planting in a garden on one date, behind a screen that names each one and
  asks for the words "end season" to be typed.
- **Crop rotation warnings** beside the row picker on Start a New Plant: what
  that bed grew and when, in the option's own text with JavaScript off, and as
  a live warning matched against the chosen plant's family with it on.
- **`/password/setup/{token}`** — the tokenised set-password link. The
  account-creation email no longer carries a password.

**Two migrations, both pure DDL, both new tables.** Unlike Phase 4, the
`deploy.md` "Redeploying" trap applies in full: between the deploy and
`/setup?key=`, `/advice` and creating a user both return 500. `deploy.md`
"The Phase 5 deploy DOES add migrations" says what order to do it in.

---

## 2. What Phase 5 measured that Phase 6 should not re-derive

### 2.1 A five-year account's export is 3.3 MB, and 93% of it is two sections

This is the most important thing in this file.

Phase 5 handoff §3.1 said to measure a real `/export/claude.json` before
deciding how to bound the analysis document. Measured 2026-08-31 on MySQL
8.0.46 against a synthetic five-year account — 150 plantings, 4,500 events,
1,826 days of weather:

| Section | Bytes | Share |
| --- | --- | --- |
| `plant_events` | 2,257,261 | 68% |
| `weather.days` | 820,079 | 25% |
| everything else | 228,948 | 7% |
| **Total** | **3,306,288** | **≈918,000 tokens** |

Two consequences, and the second is the one that is easy to miss:

- **It exceeds every model's context window**, and at Claude Opus 5's input
  rate one analysis of it would cost about $4.60 — per run, per user, for a
  document three quarters of which is four thousand rows saying "watered".
- **§3.1 offered two bounds and neither alone was enough.** Capping the date
  range still leaves a heavy year at roughly 450 KB of event log; weekly
  weather rows leave 75% of the bytes untouched. The measurement is what said
  so. Guessing would have shipped one of them and a document five times too
  big.

`Carl\Analysis\Document` applies three: a 365-day window, weekly weather rows,
and per-planting event roll-ups with the narratives kept verbatim and capped
by count. Result on the same account: **140,510 bytes, ≈39,000 tokens, a
factor of 23.5, in 16 ms.** `deploy.md` §0.9 has the full table.

### 2.2 The statement count was 13, and one garden hid it

`Document::build()` costs **twelve statements, whatever the size of the
account.** It cost thirteen in the first cut, because `gardenSection()` read
the rows of each garden in a loop — and a test account with one garden reports
exactly the same number as a flat implementation would.

The fix was `rowsForGardens()`, which already existed. The thing worth keeping
is the shape of the bug: **an N+1 in a loop over something a test fixture has
one of is invisible to every measurement including the honest ones.**
`12_analysis_test.php` creates a second garden and asserts the count did not
move, which is the only reason it will stay fixed.

### 2.3 Phase 5 handoff §3.5 named the wrong class, and the right pattern

§3.5 says "`Carl\Auth\TokenStore` already exists for the unsubscribe token and
is the thing to reuse." Two things wrong with that sentence and one thing
right:

- The unsubscribe token is not in `TokenStore`. It is
  `user.email_unsubscribe_token`, a column on the account since migration 001.
- `TokenStore` holds login sessions, and `resolve()` **rotates** them on every
  use. Rotation is exactly wrong for an invitation: a link must work once and
  then never again, and a rotating one hands back a fresh credential every
  time it is opened.
- What is genuinely right is the *pattern*, and `Carl\Auth\InviteStore` is it:
  selector plus verifier, the selector indexed and useless alone, only a
  SHA-256 of the verifier stored, compared with `hash_equals`. A copy of
  `password_invite` is not a set of working links.

### 2.4 `TokenStore::pruneExpired()` had no caller, and had not since Phase 1

Found while wiring the invite sweep. `auth_token` had been accumulating
expired rows for three phases. Both sweeps now run from `Digest::run()`, which
is the hourly job every install has and the only scheduled thing on an account
with no shell. It is deliberately after the sending and swallows its own
failure: housekeeping must never be the reason a digest did not go out.

Worth generalising: **a public `prune`/`sweep`/`cleanup` method with no caller
looks exactly like one with a caller.** If Phase 6 adds a table that grows,
grep for who sweeps it before assuming somebody does.

### 2.5 A summary that does not say it is a summary gets read as a record

Not a measurement, a design finding, and it applies beyond this feature. The
analysis document carries a `read_me` block that says what has been summarised
and a `covers` block that says which dates are in it — because a reader handed
a plausible-looking document of a gardener's records will reason about it as
though it were all of them, and then say "you have never fertilised" about an
account whose fertilising was in year two.

---

## 3. Phase 6

`CARL-HANDOFF.md` §14 lists three v2 items Phase 5 did not reach, and §15
explains why each was deferred. None is blocked by anything Phase 5 built.

### 3.1 GDD pest reminders

§15 says: "Data is stored; Texas biofix needs validating first." That is still
the whole of it, and it is a research question rather than a code one — the
`pest` table carries GDD thresholds and `weather_daily` carries the
temperatures to accumulate them from, so the computation is a day's work once
somebody trusts the biofix dates. **Do the validation first**, or the reminder
fires on the wrong week and teaches people to dismiss the whole digest.

`ReminderBuilder` is where it goes; it already computes eleven kinds and the
twelfth is a `ReminderKind` constant and a method.

### 3.2 Succession planting

§15: "Planning features; the digest is the task list." The pieces are all
there now — `plant_region` has the planting windows, `plant_type` has the DTM,
and the crop rotation query of §3.4 shows how to ask what a bed is doing. What
is missing is a decision about what the screen IS: a planner that proposes
dates, or a reminder that says "you could sow another round of beans this
week". The second is much smaller and fits the digest that already exists.

### 3.3 Companion planting reference

The only one of the three with no data behind it. It needs a dataset in the
`research-template/` contract before it needs a screen, and the contract would
need a new file — which makes it the one v2 item that touches
`ResearchImporter` and the dataset version.

### 3.4 The per-garden prefilled field sheet

Still blocked on §13.4, still the only Phase 4 item not built, and now the
only thing blocking a whole section of `/reports`. See §6.

### 3.5 What Recommendations should probably grow next

Three things that were deliberately left out, in the order they are worth
doing:

- **A per-plant or per-garden analysis.** The `analysis` table has a `scope`
  column that only ever says `season`, precisely so this needs no migration.
  A gardener looking at one struggling bed does not want a review of the year.
- **Trim `research`.** It is 44,606 of the 140,510 bytes — a third of the
  document — for thirty plant types, and it is the section with the worst
  ratio of bytes to signal. It is whole because the citations and confidences
  in it are what stop the answer presenting a catalogue default as a local
  measurement; a trimmed version would have to keep those.
- **Show what it cost.** `input_tokens`, `output_tokens` and `document_bytes`
  are all stored per row and nothing displays them. An admin page that says
  what the month cost is twenty lines.

---

## 4. What must not regress

Everything in `PHASE-5-HANDOFF.md` §4, all of which still holds, plus:

1. **No third-party call on a request path.** Phase 5 added the first one that
   is neither weather nor mail, and it is a cron job. `12_analysis_test.php`
   asserts that `POST /advice` returns in under two seconds having called
   nothing — which is the only assertion in the suite that would notice
   somebody "simplifying" the queue away, because a page that called the API
   would still be correct, just slow and then one day down.
2. **The analysis document stays bounded.** The test asserts the ratio against
   a heavy fixture, not an absolute byte count, so it survives the fixture
   changing. One extra field per event does not look like anything until it is
   4,500 events long.
3. **Twelve statements, and a second garden does not make it thirteen.** §2.2.
4. **The API key lives only in `config/local.php`.** `config/app.php` carries
   the URL and the model, which are not secret. Nothing in the repository may
   ever hold the key.
5. **The account-creation email carries no password.** `14_invite_test.php`
   reads the queued body and asserts that nothing in it matches the shape of a
   temporary password. Putting one back looks like a convenience and is the
   exposure Phase 5 removed.
6. **The on-screen temporary password stays.** Phase 3 handoff §4.1: it works
   with no mailbox, it works when a message bounces, and it is the only path
   that works the first time an install is stood up. The link supplements it.
7. **End Growing Season keeps the typed confirmation and re-reads the batch.**
   A checkbox is one mis-tap on a phone, and the list on the screen can be a
   week old.
8. **335 tests green under `--strict` on both engines** before any push.

---

## 5. Owner actions outstanding

In priority order as they now stand. Items 1–3 have been outstanding since
Phase 3 and none of them has blocked anything yet, which is the reason they
are still here.

1. **Rotate `cron_key`.** It was visible in a screenshot during the Phase 3
   session and it travels in URLs. Phase 5 added a sixth route behind it
   (`/tasks/analysis-run`), which does not change the argument but does add
   one more thing a leaked key can start — and this one spends money.
2. **Delete `diag_key`** from `config/local.php`; the `/diag` route should be
   shut.
3. **Delete two stale cron rows** — the `15 6` duplicate weather sync and the
   `17 8` spike-3 `--verbose` job — and `carl-app/var/cron-test.log`. They are
   two extra full weather syncs a day against a shared quota.
4. **Add the sixth cron job**, `bin/analysis_run.php` hourly at minute 40
   (`deploy.md` §7). Without it the queue never drains and `/advice` says a
   request is on its way for ever.
5. **An Anthropic API key** in `config/local.php`, if Recommendations is
   wanted (`deploy.md` §7.6). Optional: with no key the feature queues and
   waits, which is a working state and the one it ships in.
6. **Add a cPanel forwarder** `carl@reshiftmanager.com` → a real inbox, so the
   daily digest reaches a mailbox that gets read.
7. **DMARC `p=none` → `p=quarantine`** once a few weeks of `rua=` reports look
   clean.
8. **Spike 0.5** — `curl -s https://api.ipify.org` from a cron. Nothing
   depends on the answer; it settles whether the Open-Meteo quota is the
   owner's alone.
9. Ask Ahosting whether `ea-php82-php-opcache` can be enabled.
10. Email Open-Meteo describing Carl (internal, unsold, no ads); keep the
    reply in `docs/`.

### 5.3 The one platform fact Phase 5 could not establish

**Outbound HTTPS to `api.anthropic.com` has never been tried from sh193.**
Phase 0 spike 1 proved five hosts reachable and this is not one of them.
Egress was open to all five, so there is no reason to expect a block — but it
has not been shown, and that is why it is here rather than in `hosting.md`.

The first drain is the test and it is a safe one: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To settle
it without waiting for the hour, queue something and open
`/tasks/analysis-run?key=<cron_key>`.

---

## 6. Claude Design outstanding

Unchanged from `PHASE-5-HANDOFF.md` §6 in substance. What has changed is that
the field sheet now blocks a visible, named section of a screen rather than an
absence nobody could see:

1. **Logo and palette.** `public/assets/css/tokens.css` is still the one-file
   swap, and it still carries the chart colours and the PDF colours as well as
   the pages. Phase 5 added two rules to `carl.css` (`.advice`,
   `.rotation-warn`) and neither names a colour.
2. **The static field-recording sheet** (§13.4). `/reports` now has a "Print"
   section whose entire content is a paragraph explaining that the sheet does
   not exist yet. That is the honest state and it is also a standing
   advertisement for the gap. `Carl\Reports\Document` is the FPDF layer it
   will use.
3. **The PDF report layout.** Unchanged; anything Claude Design wants to
   change is inside `Carl\Reports\Document`.

---

## 7. Where the bodies are buried

Everything in `PHASE-5-HANDOFF.md` §7 still applies. Phase 5 added six.

- **A fresh `Client` in a test does not mean a fresh session.** `$_SESSION` is
  a superglobal and the test harness runs every case file in one process, so
  `new Client($root)` inherits whoever the previous FILE left signed in — and
  `AuthController::login()` short-circuits for an already-signed-in request.
  The symptom is a 404 on an admin route in the full suite and a pass in
  isolation. `forgetCookies()` is what resets it, despite the name.
- **PDO cannot reuse a named placeholder with emulation off**, and the
  weekly-weather statement needs `:from` twice — once in the `WHERE` and once
  inside the `GROUP BY` expression. It is `:from` and `:from_group`. This is
  in hosting §7 and it still catches people, because the failure is
  `SQLSTATE[HY093] Invalid parameter number` and nothing points at which
  parameter.
- **`weather_daily.water_balance_mm` is a generated column.** Any fixture that
  inserts it fails with MySQL error 1906, which reads as a warning and is
  fatal. Insert `precip_mm` and `et0_mm` and let the column do its job.
- **An N+1 over gardens is invisible with one garden.** §2.2.
- **A model asked for plain text writes Markdown anyway.** `Prose` strips
  `**bold**`, `## headings` and `` `code` `` because asking is not receiving,
  and a stray `**` on a page is a cosmetic bug that gets reported as a real
  one. It does *not* render Markdown: it returns typed blocks the template
  escapes, so there is no path by which the API's output becomes markup.
- **A row stuck in `sending` is a process that was killed.** The web SAPI's
  30 s ceiling and a shared host's own limits both end a process without
  leaving a PHP error behind (hosting §4), so the row is the only evidence.
  The lease is what turns that into a retry with a bound rather than a stuck
  queue — and the reclaim counts an attempt, because a request that kills the
  process every time must eventually stop being retried.

---

## 8. Working agreement

`CARL-HANDOFF.md` §17, plus the additions in `PHASE-3-HANDOFF.md` §8,
`PHASE-4-HANDOFF.md` §8 and `PHASE-5-HANDOFF.md` §8, all of which earned their
place again this phase. One more:

- **When a handoff says "measure a real one first", the measurement is
  allowed to change the plan.** §3.1 of the Phase 5 handoff offered two ways
  to bound the analysis document and the measurement said that neither was
  enough on its own — which is a thing a handoff written before the
  measurement could not have known, and exactly what it was asking to be
  found out. The temptation is to treat the two options as the scope and pick
  one. Take the measurement as the authority over the sentence that asked for
  it, and write down what it said, so the next phase inherits the number
  rather than the guess.
