# Carl — handoff for Phase 3

**Phase 1 is live at `https://www.reshiftmanager.com/carl/` and logging real
data.** This document was the scope for what came next: the Phase 2 remnants,
then Phase 3 in full — reminders, email, the watering model and NWS alerts.

> **Status, 2026-08-31: §3 and §4 are built.** 240 tests, 947 assertions,
> `--strict`, green from an empty database on both MySQL 8.0 and MariaDB
> 10.11. §9 below records what was built differently from what this
> document specified, and why, as §8 requires. The owner actions in §6 are
> still outstanding — §4.1 was built to work correctly while its mailbox does
> not exist, and to start sending the day it does.

It **extends** `docs/CARL-HANDOFF.md` rather than replacing it. That document
is still the authority on what Carl is, the screens, the data model and the
phasing; §11 (watering model) and §12 (reminders and digest) are the
specification for most of the work below and are not restated here. Where this
document adds something, it is because Phase 1 measured a fact the original
could only guess at, or because a decision has been made since.

`docs/hosting.md` and `docs/weather.md` remain the authority on the platform
and on weather ingestion, and still override everything here.

---

## 0. Read these first

1. `docs/CARL-HANDOFF.md` §11 and §12 — the watering model and the digest.
2. `docs/deploy.md` §0 — the Phase 0 spikes, now run on the real host. Several
   of those numbers change how Phase 3 should be built.
3. §1 below — what the original handoff got wrong, measured.

---

## 1. What Phase 1 measured that the scope could only assume

These are the facts worth having before writing code. Each was expensive to
learn or would have been expensive to get wrong.

### 1.1 The server clock is US Eastern. PHP is UTC.

`hosting.md` originally recorded "Server timezone UTC". That was a PHP reading,
and PHP cannot see the operating system's setting. **Cron runs in US Eastern**
(measured 2026-08-31 by a cron job running `/bin/date`, which printed `EDT`).

Consequences for Phase 3:

- Any cron you add is scheduled in Eastern. The weather job runs `15 5 * * *`
  for that reason — 05:15 Eastern is 04:15 in the tester's Central garden.
- **`bin/daily_digest.php` is immune to this and must stay that way.** §12
  specifies it runs *hourly* and sends to each user whose **own local time** is
  between 06:00 and 07:00. That is computed from `user.timezone` through
  `Clock::localHour()`, so the server's zone never enters into it. Schedule it
  `15 * * * *` and do not reason about Eastern anywhere inside it.
- `/status?key=` reports the OS zone, from `/etc/localtime`. Do not re-derive
  it; do not trust `date_default_timezone_get()`, which the bootstrap pins to
  UTC on every request.

### 1.2 Outbound HTTPS is open, and fast

All five hosts returned 200 from sh193 on 2026-08-31:

| Host | Time | Needed by |
| --- | --- | --- |
| `archive-api.open-meteo.com` | 459 ms | weather sync (live) |
| `api.open-meteo.com` | 463 ms | forecast (live) |
| `api.weather.gov` | 165 ms | **NWS alerts — Phase 3** |
| `api.zippopotam.us` | 122 ms | ZIP fallback (live) |
| `www.ncei.noaa.gov` | 528 ms | NCEI fallback (not built) |

`api.weather.gov` being open is what unblocks the alerts poll. It needs a
descriptive User-Agent; `config/app.php` already carries one.

### 1.3 The Open-Meteo exposure is the *hourly* limit, not the daily one

The original scope worried about the 10,000/day per-IP quota being shared with
strangers. Two corrections:

- The account has a **dedicated IP**, shared only with the owner's own
  projects. (Caveat in `deploy.md` §0.5: a cPanel dedicated IP is *inbound*, so
  outbound curl may still leave by the server's primary address. Untested, and
  nothing depends on it.)
- The limit actually hit during development was the **hourly** one, tripped by
  running the sync twice in one hour. That applies however private the IP is.

**So: Phase 3 must not add Open-Meteo calls.** The watering model reads
`weather_daily` and `weather_forecast`, which the nightly job has already
filled. It is a computation, not a fetch. If you find yourself wanting a
forecast the sync did not store, extend the sync's variable list rather than
adding a second call site.

`HttpClient::isQuota()` recognises a quota by its reason text and declines to
retry it. Anything new that calls out should go through `HttpClient` and
inherit that.

### 1.4 The database is fast, and the cost is work, not round trips

| Measurement | Result |
| --- | --- |
| Round trip | 0.81 ms |
| 200-row upsert | 1.9 ms |
| 2,000-row upsert | 19.9 ms |

Two things follow. `time ≈ measured + statements × 0.81 ms` is a real budget —
a 20-statement render carries ~16 ms. And 2,000 rows costing 10× 200 rows means
the batching is already at the right size; raising chunk sizes buys nothing.

This matters for the digest, which is the first job that loops over users. See
§4.4.

### 1.5 Smaller things

- **cPanel 136's Server Information page has no clock.** Do not send anyone
  there for the server time.
- **`/usr/local/bin/ea-php82`** is the cron PHP binary, pinned deliberately —
  plain `php` follows the account's MultiPHP default and would silently move
  the job to a different PHP from the web SAPI.
- **`%` is special in a crontab** and becomes a newline. Escape it or avoid it.
- The Content-Security-Policy is `style-src 'self'` with no `unsafe-inline`.
  **An inline `style=""` attribute is silently refused** — the element renders
  unstyled and the only trace is a console message. Use a utility class in
  `carl.css`. A test enforces this; it will fail your build, which is the point.
- `vendor/` must never become empty in git. `.cpanel.yml` copies it, and a
  missing source path fails the task, which fails the whole deployment. A lint
  test enforces this too.

---

## 2. What is built and deployed

Phase 1 complete, plus most of Phase 2. 127 tests, 449 assertions, `--strict`;
CI green on MySQL 8.0 and MariaDB 10.11.

**Working end to end:** login with forced reset, onboarding with ZIP → county →
region, all three Start-a-New-Plant forms with the research card, Log Plant
Activity with every action the state allows (single and batched), View Plants
with timeline and photos, Build Garden, Garden Actions including the zone
watering fan-out, View Garden, Lists, all three admin functions, photo upload
with client resize and GD re-encode, and the nightly weather sync feeding the
MOTD matrix.

**Ahead of schedule** (listed as Phase 2 in the original handoff, already
built): batch actions with filters, hardening schedules and the countdown
display, `/status` extended with weather health, and backfill-on-backdate.

**The MOTD re-post rule is done.** `weather_location.forecast_hash` is written
by the sync and compared against the dismissal, so the box reappears when the
forecast changes materially, not only the next day.

---

## 3. Phase 2 remnants — do these first, they are small

### 3.1 The row occupancy hint is a stub — this is a real gap

`CARL-HANDOFF.md` §4.3 asks for a light hint on row selection: *"Row 3 already
has 4 living plants."* Not a block.

The repository method exists and is correct —
`GardenRepository::livingCountByRow()` — and feeds the garden report. **But the
plant form does not use it.** `PlantController::formData()` passes
`'occupancy' => []`, an empty stub, and `app/views/plants/placement.php`
mentions the hint only in a docblock comment. It was never wired up.

Wire it: pass the real map, render the count beside each row option or under
the select. One statement, already written.

### 3.2 CSV exports (`CARL-HANDOFF.md` §13.3)

`/export/plants.csv`, `/export/events.csv`, `/export/weather.csv`, for the
signed-in user's own data only.

- **Formula-injection guarded.** A cell beginning `=`, `+`, `-` or `@` must be
  neutralised, or the export executes on the machine of the person it is for
  (hosting §8.5). There is no helper for this yet; write one and test it.
- Streamed in 1,000-row chunks. `max_execution_time` is 30 s and these run
  under the web SAPI.
- Scope through the repository base class like everything else. An export that
  builds its own SQL is how a user-scoping bug gets in.

### 3.3 Verify backfill-on-backdate on the live install

The code is built and unit-tested, but §14 asks for it verified against a real
plant started 60 days ago. On the live install: start a plant backdated 60
days, confirm `weather_location.backfill_from` moves back, wait for the nightly
run, confirm the archive fetches the gap in year-chunks and the plant report's
weather section fills in.

### 3.4 Field sheet — blocked on Claude Design

`CARL-HANDOFF.md` §13.4. Nothing to build until the PDF exists; then it goes in
`public/assets/field-sheet.pdf` and the app links to it.

---

## 4. Phase 3

`CARL-HANDOFF.md` §11 and §12 are the specification. What follows is the
implementation guidance Phase 1 earned, in the order the work should happen.

### 4.1 Mail, and the owner action that blocks it

**Blocked until the owner completes `CARL-HANDOFF.md` §12.1** — create
`carl@reshiftmanager.com`, install SPF and DKIM, add DMARC, and put the SMTP
credentials in `config/local.php`. Then run spike 4: one SMTP-AUTH send and one
Brevo API send, and record which lands in a Gmail inbox.

Build regardless, in this order:

1. **`email_outbox` table** (`CARL-HANDOFF.md` §5.8). Nothing sends inline in a
   request — the same discipline weather follows, for the same reason: a
   third-party outage must not be able to make a page slow or 500.
2. **`Mailer` interface**, two implementations chosen in `config/local.php`:
   `SmtpMailer` (stream_socket_client + openssl, no Composer) and `ApiMailer`
   (Brevo over curl, through `HttpClient`).
3. **A cron that drains the outbox** with bounded retries. Log attempts and
   `last_error` on the row.

Both drivers are spiked before either is relied on. Until the mailbox exists,
admin user creation keeps showing the temporary password on screen — that path
already works and should not be removed, only supplemented.

### 4.2 The watering model (`CARL-HANDOFF.md` §11)

Everything it needs is already in the database.

- `SoilType` (`app/src/Domain/SoilType.php`) already carries the TAW and MAD
  numbers from §11, per soil. The Build Garden screen already collects
  `garden.soil_type` using the same list, so the model and the form cannot
  drift apart.
- Kc curves and stage lengths are on `plant_type`, imported from the research
  zip.
- `weather_daily.et0_mm`, `precip_mm`, `precip_hours` are populated;
  `water_balance_mm` is a VIRTUAL column giving `precip − et0` for free.

Build it as `bin/weather_sync.php --recommend`, run by the same cron *after*
the weather step, writing `watering_recommendation`. **Never computed at
render** — the MOTD and the digest read the stored row.

Two implementation notes:

- The daily balance `D = clamp(D_prev + ET0×Kc − rain_eff − irrigation, 0, TAW)`
  is a recursion. Store `deficit_mm` on each row and read yesterday's back;
  do not try to recompute the whole season each night.
- `irrigation_applied` comes from logged watering. Watering events that came
  from a garden zone carry `source_garden_event_id` — **count those once**, not
  once as a garden event and again as the fanned-out plant events.

### 4.3 NWS alerts (`CARL-HANDOFF.md` §8.4)

`bin/alerts_poll.php`, every 3 hours. `api.weather.gov` is confirmed reachable
at 165 ms. Store only the event classes §8.4 lists. `weather_alert` already
exists (migration 009) and the MOTD already renders active alerts — the table
and the display are done, the poller is not.

Go through `HttpClient` so the bounded-retry and quota handling come for free,
and write a `weather_sync_run` row with `kind='alerts'` every time, success or
failure, like the other two.

### 4.4 Reminders and the daily digest (`CARL-HANDOFF.md` §12)

The eleven reminder kinds are specified in §12's table. Notes:

- **Hourly cron, per-user local time.** See §1.1. `15 * * * *`.
- **Silence is the default.** Queue an email only when there is something to
  say. An empty digest trains people to ignore a full one.
- **Deduplicate on the unique key** `(user_id, planting_id, kind, due_date)`.
  Let the database enforce it rather than reading first (hosting §7).
- **Watch the statement count.** This is the first job that loops over users,
  and §1.4's arithmetic applies. Compute reminders for all due users in
  set-based queries where you can rather than N queries per user. It runs under
  CLI so there is no 30 s ceiling, but the browser fallback route inherits one.
- Frost-date reminders are suppressed for users whose region is not researched,
  with the one-line explanation §9.4 requires. `User::hasRegion()` exists.
- `user.email_unsubscribe_token` already exists. The unsubscribe route is
  tokenised, and the digest carries `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers.

### 4.5 Today's items on the main menu

`CARL-HANDOFF.md` §4.2 wants the day's countdowns and reminders on the main
menu, showing the same content as the digest. Read the `reminder` table; do not
recompute at render.

---

## 5. What must not regress

- **No third-party call on the request path.** Weather, alerts and mail are all
  cron-only. The one exception already in the codebase is the Zippopotam ZIP
  lookup at onboarding, bounded to once per unknown ZIP for the life of the
  install.
- **User scoping stays in the repository base class**, not in individual
  queries.
- **Every migration** numbered, immutable once applied, idempotent, and pure
  DDL or pure DML — never mixed. The test suite asserts this.
- **Every form** carries CSRF, validates server-side, and defaults dates to the
  user's local today while accepting the past.
- **No inline `style=` or inline `<script>`.** See §1.5.
- **Ask before vendoring** anything beyond Chart.js, FPDF and a mailer.

---

## 6. Owner actions outstanding

1. **`CARL-HANDOFF.md` §12.1** — the mailbox, SPF, DKIM, DMARC, and the SMTP
   credentials in `config/local.php`. **This blocks all of §4.1.**
2. Spike 4 — one SMTP send, one Brevo send, note which lands in Gmail.
3. Spike 0.5 — `curl -s https://api.ipify.org` from a cron, to settle which IP
   Open-Meteo sees. Nothing depends on it.
4. Ask Ahosting whether `ea-php82-php-opcache` can be enabled. If it is,
   `opcache.validate_timestamps` becomes a deploy concern.
5. Email Open-Meteo describing Carl (internal, unsold, no ads); keep the reply
   in `docs/`.

## 7. Claude Design outstanding

1. **Logo and palette.** `public/assets/css/tokens.css` is a neutral
   placeholder defining exactly the `--carl-*` names to deliver. It is the only
   file in the repository that names a colour, so the palette is a one-file
   swap.
2. **The field-recording sheet** (§13.4), which blocks §3.4.
3. Phase 4 will want the PDF report layout.

---

## 8. Working agreement

Unchanged from `CARL-HANDOFF.md` §17, with two additions Phase 1 earned:

- **Run it, do not just build it.** Five of the bugs found in Phase 1 were
  invisible to the test suite and to code review: a quota reply being retried,
  forecast rows overwriting archive rows, a dropdown listing every plant twice,
  every inline style being silently refused, and assets losing their
  cache-busting stamp on the server layout only. Each was found by running the
  thing — a live sync, a headless browser, a dry-run deploy into a sandbox laid
  out like the account.
- **When a documented fact turns out to be wrong, correct the document and say
  who corrected it.** It happened twice in Phase 1, both times because a
  reading described PHP rather than the platform. `hosting.md` and `weather.md`
  are copied-in authorities; annotate them, do not silently rewrite them.

---

## 9. What was built differently, and why

§8 asks that a documented fact found to be wrong be corrected rather than
silently worked around. Six things.

### 9.1 `reminder`'s unique key, and `watering_recommendation`'s

`CARL-HANDOFF.md` §5.8 gives `reminder` a unique key of
`(user_id, planting_id, kind, due_date)` and `watering_recommendation` one of
`(garden_id, for_date)`.

Neither can be built as written. `planting_id` is NULL on five of the eleven
reminder kinds, `garden_id` is NULL for every container, and **MySQL permits
any number of NULLs in a unique index** — so both keys would have enforced
nothing on exactly the rows that need them. Every watering reminder would have
been written again on every run.

Both tables carry an extra NOT NULL column that the index is built on instead:
`reminder.subject_key` (`p:123`, `pt:7`, `pest:4`, or `-`) and
`watering_recommendation.place_key` (`g:12` or `c:7`). The foreign keys are
still there for the cascade; the key column is what the database can actually
enforce. Same meaning, in a column an index can hold.

### 9.2 `/admin/mail-test` is admin-guarded, not key-guarded

`CARL-HANDOFF.md` §12.1 step 7 says `/admin/mail-test?key=`. A key-guarded
route that mails an address from the query string is an open relay to anyone
who ever sees the key — and a key travels in a URL, so it has been through a
browser address bar and the account's raw access log (`deploy.md` §8 makes the
same point about `setup_key`).

It is `Route::ADMIN_ACCESS` instead, and the destination is fixed to the
signed-in admin's own address. It also **queues rather than sends**, because
§5 forbids a third-party call on the request path; the drain sends it, and the
page shows the outcome on the next load.

### 9.3 The migration 009 alert key was wrong

`weather_alert` had `UNIQUE KEY (nws_id)`. Two users a few ZIPs apart share a
county and get the same alert id from `api.weather.gov`, so the second
upsert moved the one row from the first user's location to the second's and
one of them silently stopped seeing a freeze warning that was genuinely over
their garden.

Migration 014 replaces it with `(location_id, nws_id)`. 009 is unchanged —
migrations are immutable once applied (hosting §7).

### 9.4 The temporary password still goes to a screen, and now also to a mailbox

§4.1 says the on-screen path should be supplemented, not removed, and it is.
A temporary password in an inbox is a real exposure: it sits there until the
account is first used. It is bounded by `must_reset_password`, so the window
is one sign-in long.

**The better answer is a tokenised set-password link**, which removes the
password from the mail entirely. That is a change to the auth flow larger than
§4.10 asks for, and it is the Phase 4 improvement to make here.

### 9.5 One CSRF exemption, for One-Click unsubscribe

`Route::TOKEN_ACCESS` is new: no login, and no CSRF token, because the
credential is in the path. There is exactly one route with it, the RFC 8058
One-Click unsubscribe, which a mail client POSTs with no session and no page
having been rendered — and which Gmail and Outlook now expect of bulk mail.

It is safe only because of what the route can do: turn one person's own email
off. A forged request achieves precisely what the link it forged was for. The
constant's docblock says not to reuse it, and nothing does.

### 9.6 Two rules Section 12 left to judgement

- **`start_seeds_by`** is scoped to the types the region marks
  `recommended`. Every type in the catalog would be a wall of text nobody
  reads, and "recommended" is the research's own answer to what is worth
  growing there.
- **Irrigation depth from a logged watering** counts each `garden_event` as
  one application, and all directly-logged plant waterings on a day as one
  more (the deepest of them). Watering six plants in a bed by hand is one
  irrigation of the bed, not six. Every assumption the model makes about
  depth is stated in the reason text, which is the part §11 is emphatic
  about.

---

## 10. Still outstanding

Nothing in §3 or §4 is unbuilt. What remains is not code:

1. **§6.1, the mailbox.** Until it exists, mail queues and waits. `/status`
   and `/admin/mail-test` both say so in as many words. Nothing is lost and
   nothing sends twice when the credentials arrive.
2. **§6.2, spike 4.** `/admin/mail-test` queues a message with whichever
   driver is configured; switch `mail.driver` in `config/local.php`, queue one
   of each, and note which lands in a Gmail inbox with `spf=pass dkim=pass`.
3. **§3.3's live half.** The chain is tested end to end against a stub;
   `deploy.md` §7 now carries the checklist for seeing it once against the
   real archive.
4. **§6.3, §6.4, §6.5** — unchanged, and nothing depends on them.
5. **§7, Claude Design.** `tokens.css` is still the one-file palette swap, and
   §3.4's field sheet is still blocked on the PDF.
