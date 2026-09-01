# Deploying Carl

Everything here is dictated by `hosting.md`. The account has no shell, so
every administrative action is either a cPanel page or a key-guarded route.

---

## 0. The Phase 0 spikes — run, and passed

Handoff §14 lists five spikes. The first could have killed the weather
feature. **It passed.** Run on sh193 on 2026-08-31 through the `/diag` route,
which existed only while `diag_key` was configured and has since been closed.

| # | Spike | Result on sh193, 2026-08-31 |
| --- | --- | --- |
| 1 | Outbound HTTPS to the five hosts the app talks to | **Pass.** All five HTTP 200. Egress is open; no rescope needed |
| 2 | PHP CLI binary path for cron | **Pass.** All five candidates exist and are executable |
| 3 | A real cron execution writing a `weather_sync_run` row | **Pass.** Ran 2026-08-31, 28 rows in 1.0 s. Also revealed the OS timezone — see §0.6 |
| 4 | SMTP-AUTH send, and one Brevo API send | **SMTP half passed**, 2026-08-31: `spf=pass dkim=pass dmarc=pass`, both aligned, to a Gmail account. Brevo not attempted — nothing found that needs it (§7.5) |
| 5 | Upsert timing and RTT to the database host | **Pass.** RTT 0.81 ms; 200 rows 1.9 ms, 2,000 rows 19.9 ms |

### 0.1 Outbound HTTPS — the blocking spike

This is the one weather.md §11 says to run before anything else, because a
filtered egress would have made the whole feature impossible as designed.

| Host | Status | Time | What it proves |
| --- | --- | --- | --- |
| `archive-api.open-meteo.com` | 200 | 459 ms | Historical weather. The primary source |
| `api.open-meteo.com` | 200 | 463 ms | Forecast and `past_days` |
| `api.weather.gov` | 200 | 165 ms | NWS alerts (Phase 3) |
| `api.zippopotam.us` | 200 | 122 ms | ZIP fallback for codes the Census file misses |
| `www.ncei.noaa.gov` | 200 | 528 ms | The NCEI escape hatch of weather.md §9 |

Two details worth keeping. Open-Meteo resolved 76692 to elevation 183 m and
`America/Chicago` at `utc_offset_seconds: -18000`, which is the timezone Carl
derives independently from the state and longitude — the two agree.
Zippopotam named the place "Whitney", which is the city name the Census ZCTA
files do not carry; that is exactly the gap the fallback exists to fill.

All five are comfortably faster than from the development container, where
the same calls took 750–850 ms. The nightly job needs single-digit calls, so
there is a very large margin here.

### 0.2 PHP CLI — every candidate exists

| Path | |
| --- | --- |
| `/usr/local/bin/php` | exists, executable |
| `/usr/local/bin/ea-php82` | exists, executable — **use this one**, see §7 |
| `/opt/alt/php82/usr/bin/php` | exists, executable |
| `/usr/bin/php` | exists, executable |
| `/usr/local/bin/lsphp` | exists, executable — this is the binary serving web requests, not a cron target |

weather.md §3.1 marked this Unverified and listed three plausible paths. All
three exist, so the curl fallback is not needed. Which to pick is not
arbitrary — see §7.

### 0.3 Database timing — the arithmetic that shapes every query

| Measurement | Result |
| --- | --- |
| Round trip, 10 × `SELECT 1` | **0.81 ms each** |
| 200-row upsert | 1.9 ms |
| 2,000-row upsert | 19.9 ms |

This confirms hosting §9's "RTT to the DB host is under 1 ms" against the real
host, and makes its arithmetic concrete: `time ≈ measured + statements × 0.81 ms`.
A 20-statement page render carries about 16 ms of round trips.

It also validates the two chunk sizes already in the code. Research imports
and weather sync upsert 200 rows per statement; the ZCTA migration uses 2,000,
so its 17 statements cost about 340 ms of database time — which is why loading
33,791 ZIP rows from a browser finishes inside `max_execution_time` with room
to spare rather than by luck.

Note that 2,000 rows cost 10× the time of 200 rather than being free — the
cost here is real work, not round trips. Raising the research chunk size would
buy nothing.

### 0.6 The server clock is US Eastern, not UTC

Measured 2026-08-31 by a cron job running `/bin/date`:

```
Mon Aug 31 09:27:01 EDT 2026
```

`hosting.md` recorded "Server timezone UTC" and `date.timezone UTC`. The
second is right and the first was a PHP reading — PHP cannot see the OS
setting. **Cron runs in US Eastern.** That is corrected in `hosting.md` §1
and §4.

The consequence is entirely in the schedule, and it is why §7 says `15 5`
rather than `15 9`:

| Schedule | Server (Eastern) | Garden (Central) | UTC |
| --- | --- | --- | --- |
| `15 9 * * *` | 09:15 | **08:15 — mid-morning** | 13:15 / 14:15 |
| `15 5 * * *` | 05:15 | **04:15 — before anyone is up** | 09:15 / 10:15 |

Eastern and Central observe DST on the same dates, so the gap between them is
always exactly one hour. A fixed server-local hour therefore holds its
gardener-local time all year, and only drifts against UTC — which nothing
cares about.

### 0.5 The Open-Meteo quota — whose traffic shares it

weather.md §8.1 assumes the account is on the server's shared IP and that the
10,000/day per-IP limit is therefore shared with strangers. **The account has
a dedicated IP**, shared only with the owner's own projects (owner, 2026-08-31).

That is not quite the end of it. A cPanel dedicated IP is an **inbound**
address — what DNS points at and what the vhost binds. **Outbound** requests
that PHP opens with curl leave by the server's routing table, which on a stock
cPanel box is the server's primary IP, not the account's. So Open-Meteo may
still be counting this account's calls alongside every other account on sh193.

Untested, and worth one line to settle. Add it to the one-off cron in §7:

```
/usr/bin/curl -s https://api.ipify.org
```

- Prints **the account's dedicated IP** → the quota is genuinely private, and
  the only traffic against it is RESM, RERM and Carl.
- Prints **the server's primary IP, or any other address** → outbound is
  leaving by the shared address and weather.md §8.1 stands as written.

**Evidence from the mail test, 2026-08-31, and it points the other way to
what this section originally guessed.** The received headers of the §7.5 test
carry two hops with two different addresses: PHP's socket reached Exim from
`152.160.208.75`, and Exim then delivered to Google from `152.160.193.193`,
which is `sh193.sameservers.com` — the server's own primary address, the one
the PTR and the alternate HELO match.

So `152.160.208.75` is **not** the server's primary, which is what this
section had assumed when it named that address as the "leaving by the shared
address" outcome. Two addresses, and the server's own is the other one. On an
account the owner reports as having a dedicated IP, `152.160.208.75` is
almost certainly it — which would make the Open-Meteo quota private after
all.

Short of proof, because the destination of that connection was on the same
box and the kernel may pick a source address differently for a route that
leaves the building. The `curl https://api.ipify.org` line still settles it,
and now has a sharper question to answer: **does it print `152.160.208.75`?**
If it does, the quota is the owner's alone.

Either answer leaves the design alone. Carl needs single-digit calls a day
against a 10,000/day ceiling, so the daily limit was never the exposure — the
**hourly** one is, and it was hit during development simply by running the
sync twice in an hour. The client recognises a quota by its reason text and
declines to retry it, weather never touches the request path, failures are
silent to the user and loud on `/status`, and the NCEI fallback exists. None
of that is worth removing for a limit this comfortable.

### 0.4 What was measured off-host beforehand

From a development container on the same day. It proved the request shapes
were right; the table above is what proves the host allows them.

- Every variable in `OpenMeteoClient::ARCHIVE_DAILY` present, all seventeen
  daily arrays the same length as `time`.
- Forecast with `forecast_days=7&past_days=7`: all nine daily arrays and all
  four hourly soil arrays present.
- A second full sync inside the same hour returned
  `{"error":true,"reason":"Hourly API request limit exceeded..."}` — an
  **hourly** limit, arriving as an error envelope rather than a bare 429.
  Note this is a different exposure from the daily quota of weather.md §8.1
  and applies however private the IP turns out to be (§0.5): two runs in one
  hour is enough to hit it. The client recognises a quota by its reason text and does not
  spend thirty seconds retrying it.

### 0.7 GD is invisible to `memory_get_peak_usage()` — Phase 4

**Measured 2026-08-31** in the development container, PHP 8.4 with the host's
limits from `dev/php.ini`. It is a property of PHP and libgd, not of sh193, so
it holds on the server too.

| | Process resident | `memory_get_peak_usage(true)` |
| --- | --- | --- |
| Baseline | 35.7 MB | 2.0 MB |
| Five open 1920×1440 truecolour images (53 MB of pixels) | 88.9 MB | **2.0 MB** |

**PHP's own counter did not move at all.** libgd allocates its pixel buffers
outside the Zend allocator, so `memory_get_peak_usage()` cannot see them.

Two consequences pull in opposite directions and both matter:

- `memory_limit` is enforced *by* the Zend allocator, so decoded images do not
  count against it. Twenty open photographs will not produce "Allowed memory
  size exhausted". Handoff §13.2's 64 MB budget is therefore **not** a
  `memory_limit` question.
- The host's own per-process ceiling still applies, and when a shared host
  kills a process for exceeding one there is no PHP error to read — the page
  simply does not arrive.

So a memory budget for anything touching GD has to be checked against the
**process**, not against PHP's counter. `Carl\Support\ProcessMemory` reads
`VmHWM` from `/proc/self/status` for exactly this; `tests/measure_report.php`
is the runnable measurement, and `11_reports_test.php` runs it in a child
process on every suite run.

The child process is not ceremony either: resident memory is a high-water mark
a long-lived process never gives back, so a delta measured around one report
inside a test suite that has already churned through images reads **zero**
however much that report used. Only a process that boots, builds one report
and exits gives a real figure — which is also exactly what a web request is.

### 0.8 The PDF report budget — Phase 4

Handoff §13.2 sets the target at **under 10 s and 64 MB on a 20-photo report**
and says to measure it rather than assume it. Measured 2026-08-31, in a fresh
process, three times, stable to ±0.2 s:

| | |
| --- | --- |
| Time | **1.9 s** (budget 10 s) |
| Process growth | **+16 MB** resident (budget 64 MB) |
| Absolute process peak | 53 MB, of which 37 MB is a booted PHP with the app loaded |
| `memory_get_peak_usage(true)` | 6 MB — see §0.7, it is not the figure that matters |
| Output | 489 KB PDF, 4 pages, 20 photographs and 3 charts |

The fixture is what the server actually gets: 25 stored photographs at the
1920 px long edge `Photos::store()` writes, of which 20 reach the report, plus
three 1140×720 chart PNGs — the size a 380 px canvas posts from a phone with a
3× device pixel ratio.

The 16 MB is what the design predicts: one decoded photograph (1920×1440×4 ≈
11 MB) at a time, plus one decoded chart PNG (≈3 MB), plus the small JPEGs
already collected and FPDF's buffer. Holding twenty open at once would be
about 220 MB. `PdfBuilder::photoSection()` is the loop that keeps it to one,
and the child-process measurement is what proves it still does.

### 0.9 What a five-year account's export actually weighs — Phase 5

Phase 5 handoff §3.1 says to decide how to bound the Recommendations document
and to **measure a real one first**. The live account is a few weeks old, so
this is a synthetic one built to the shape a five-year account has: 150
plantings, 4,500 events with a sentence of narrative each, 1,826 days of
weather, one garden of twelve rows.

**Measured 2026-08-31** on MySQL 8.0.46, the production engine.
`/export/claude.json` for that account:

| Section | Bytes | Share |
| --- | --- | --- |
| `plant_events` | 2,257,261 | 68% |
| `weather.days` | 820,079 | 25% |
| `plantings` | 166,829 | 5% |
| `research` | 51,452 | 2% |
| everything else | 10,667 | <1% |
| **Total** | **3,306,288** | **≈918,000 tokens** |

That is the number that decided the design. It exceeds every model's context
window, and at Claude Opus 5's input rate one analysis of it would cost about
$4.60 — per run, per user, for a document three quarters of which is four
thousand rows saying "watered".

§3.1 offers two bounds: cap the date range, or summarise the weather into
weekly rows. **Neither alone is enough**, and the table above is why — the
range cap alone still leaves a heavy year at roughly 450 KB of event log, and
the weekly rows alone leave 75% of the bytes untouched. `Carl\Analysis\Document`
applies three, in the order the measurement puts them in: a 365-day window,
weekly weather rows, and per-planting event roll-ups with the narratives kept
verbatim and capped by count.

The same account through that class, same run:

| | |
| --- | --- |
| Bytes | **140,510** (≈39,000 tokens) |
| Against the raw export | **a factor of 23.5** |
| Build time | 16 ms |
| Statements | **12, and 12 for a one-garden account too** |
| Cost per analysis at Opus 5 input rates | ≈$0.20 |

Two things worth keeping:

- **The statement count is flat, not proportional.** It was 13 in the first
  cut, because `gardenSection()` read the rows of each garden in a loop. One
  garden hides that completely. `12_analysis_test.php` adds a second garden
  and asserts the count did not move, which is the only thing that will
  notice when the loop comes back.
- **`research` is 44,606 bytes of the 140,510 — a third of the document — for
  thirty plant types.** It is the section with the worst ratio of bytes to
  signal and the obvious next thing to trim if the bill matters. It is left
  whole because the citations and confidences in it are what stop the answer
  presenting a catalogue default as a local measurement. **Phase 6 trimmed
  it — see §0.10.**

---

### 0.10 Where the research section's bytes actually were — Phase 6

Phase 5 handoff §3.5 said `research` was the worst ratio of bytes to signal
and that a trimmed version "would have to keep the citations and
confidences". So it was measured before anything was cut, on an account
holding a planting of **every** plant type in the catalogue — 35 of them,
which is what makes the research section the whole of it.

**Measured 2026-08-31** on MariaDB 10.11.14. Document total 74,893 bytes, of
which `research` was **51,288 — 68%**. Inside it:

| Where the bytes were | Bytes | Share of `research` |
| --- | --- | --- |
| `source` strings, repeated per row | 8,886 | 17% |
| explicit nulls | 5,518 | 11% |
| `dataset_version`, identical on all 99 rows | 3,168 | 6% |
| `region_id` and `plant_type_id` | 1,966 | 4% |
| the actual agronomic values | 31,750 | 62% |

**Twelve distinct citations were cited ninety-nine times.** That single fact
is the whole finding: the section was not big because it carried a lot, it
was big because it carried the same twelve strings again and again.

Four cuts, and **not one of them removes information**:

1. The citations move into a `sources` map and each row carries a
   `source_id`. Every citation is still in the document.
2. Nulls are dropped. An absent key and a null key say the same thing, and
   one of them is free.
3. `dataset_version` is stated once for the section instead of on every row.
4. Each plant's region windows are nested underneath it, so the two opaque
   ids that existed only to join them disappear.

| | Before | After |
| --- | --- | --- |
| `research` | 51,288 | **34,379** (−33%) |
| Whole document | 74,893 | **58,172** (−22%) |

`read_me` gained a line explaining the map, because a reader handed
`source_id: "s3"` with no explanation has been given a worse document rather
than a smaller one. `Document::VERSION` went to 2. `19_advice_scope_test.php`
asserts that every citation the database holds is still reachable, that no
`source_id` dangles, and that all 99 confidences survived — which is what
turns "we kept the citations" from a claim into a check.

### 0.11 The squash vine borer biofix, validated — Phase 6

Phase 6 handoff §3.1 said the GDD data was stored but "Texas biofix needs
validating first", and that firing on the wrong week teaches people to
dismiss the whole digest. The one `pest_region` row carrying GDD data warned
that the Midwest threshold of 1000 DD50 would be wrong for central Texas
"because emergence is earlier" there.

**Measured 2026-08-31** against seven years of Open-Meteo archive for
Hillsboro (32.0107, −97.1300), accumulating DD50 from 01-01 by the simple
average method:

| Year | 750 DD | 900 DD | 1000 DD |
| --- | --- | --- | --- |
| 2019 | 24 Apr | 1 May | 6 May |
| 2020 | 7 Apr | 19 Apr | 24 Apr |
| 2021 | 14 Apr | 27 Apr | 2 May |
| 2022 | 18 Apr | 24 Apr | 30 Apr |
| 2023 | 3 Apr | 14 Apr | 20 Apr |
| 2024 | 6 Apr | 15 Apr | 19 Apr |
| 2025 | 4 Apr | 14 Apr | 18 Apr |

AgriLife reports central Texas adult emergence as "as early as April/May".
The model agrees with the observation, so **the Midwest threshold transfers
unchanged** — and the note feared the wrong thing. Emergence in Texas *is*
earlier in calendar terms, and that is exactly what a degree-day model
produces on its own: the threshold is the constant and the date is what
moves. The row is now `confidence=verified` and carries both sources and the
measurement.

---

## 1. cPanel: database

1. **MySQL Databases** → create `reshiftmanager_carl` and a user for it, with
   all privileges on that database only.
2. **Remote MySQL** → confirm the host. It is an IP, not `localhost`
   (hosting §2.1). `config/app.php` already carries `152.160.193.196`; change
   it only if that page disagrees.

## 2. cPanel: Git Version Control

1. **Create** → Clone URL `https://github.com/adamdill-RE/carl.git`,
   Repository Path **`/home/reshiftmanager/repos/carl`**.

   The path matters. The clone holds the whole repository — `app/`, every
   migration, `docs/`, and `.git` itself. Put it anywhere under
   `public_html` and all of that is fetchable over the web, which is the
   thing hosting §5 exists to prevent. Anywhere outside `public_html` is
   fine; `repos/carl` is just a tidy choice.

2. The checked-out branch is the repository's default. Right now that is
   `claude/carl-garden-helper-phase-one-he3fyp`, because it is the only
   branch, so there is nothing to change. If you later make `main` the
   default, come back and switch the checkout here too — cPanel does not
   follow a default-branch change on its own.

3. **Manage** → **Deploy HEAD Commit.**

What the tasks in `.cpanel.yml` do, in order: create
`/home/reshiftmanager/carl-app` and `/home/reshiftmanager/public_html/carl`;
remove the code directories *before* copying, so a file deleted in git is
deleted on the server; copy `app/ db/ bin/ vendor/` and `public/.`; copy
`config/app.php` only; fix modes in one pass with `chmod -R u=rwX,go=rX`;
tighten `config/local.php` to 0600 if it is there; and create the private
`var/` directories at 0700.

The deploy never touches `config/local.php` and never runs a migration.

**This has been dry-run against a fresh clone**, into a sandbox laid out like
the account. It completes with no failing task and produces: `carl-app/` with
`app db bin vendor config` and `var/` at 0700, `public_html/carl/` holding
only `index.php`, `.htaccess`, `assets/` and `sw.js`, directories at 0755 and
files at 0644, and no `.sql`, `.md`, `local.php` or `.git` anywhere under the
web directory. `tests/lint_cpanel_yml.php` asserts in CI that every path the
deploy copies actually exists in a clean checkout — the first version of this
file did not, and would have failed the whole deployment on `cp -R vendor`.

### If the deploy button does nothing

A `.cpanel.yml` the host's parser rejects **disables deployment entirely
rather than failing loudly** (hosting §6.2). If Deploy HEAD Commit appears to
succeed but nothing changes on disk, that is the first thing to suspect.
`php tests/lint_cpanel_yml.php` checks the rules that bite — ASCII only, no
tabs, no braces, plain-string tasks — and runs on every push.

## 3. File Manager: the credentials file

Create `/home/reshiftmanager/carl-app/config/local.php`, mode **0600**, from
`config/local.php.example`:

```php
<?php
return [
    'db' => ['user' => 'reshiftmanager_carl', 'pass' => '...'],
    'status_key' => '<long random string>',
    'setup_key'  => '<long random string>',   // REMOVE after step 4
    'cron_key'   => '<long random string>',
    'diag_key'   => '<long random string>',   // REMOVE after the spikes
];
```

It is PHP, hand-edited on a server with no shell to lint it. A missing comma
is a parse error and every page returns 500 — the bootstrap catches that one
case and prints the file and the line to fix, without echoing a value.

## 4. Run the migrations

`https://www.reshiftmanager.com/carl/setup?key=<setup_key>`

- Apply the migrations. Migration 011 loads the 33,791-row ZIP table; expect
  it to take a second or two.
- Set the administrator password on the same page. That replaces the seeded
  `admin` / `1234` before it is ever reachable.
- **Comment out the `setup_key` line and save** (§8). Whoever holds it can
  take the master admin account. With no key configured the route does not
  exist, and that is the state to leave it in between deploys.

## 5. Check the health page

`https://www.reshiftmanager.com/carl/status?key=<status_key>`

Confirm, in this order:

- **CODE PLACEMENT → app outside it: yes.** If this says NO, application code
  is web-reachable and the deploy went to the wrong place.
- **SESSION** — cookie path `/carl/`, httponly yes, secure yes, samesite Lax,
  strict mode yes, save path under `carl-app/var/sessions`.
- **PRIVATE DIRECTORIES** — all four at 0700. A web-reachable directory at
  0700 gives a 404 rather than a 403, so if a file you can see in File Manager
  404s, check the directory's mode first (hosting §5.3).
- **DATABASE** — engine 8.0.x, migrations pending none.
- **WEATHER** — no locations until someone finishes onboarding.

## 6. First research import

Sign in as the administrator, then Admin → Research import, and upload
`research-template/populated/research_US-48217_2026-08-31.2.zip`.

Nothing is written until you confirm the preview. Without this there is no
plant catalog and the plant forms have nothing to offer.

## 7. Cron

**cPanel → Cron Jobs.** Six jobs. The weather one is the one whose hour
matters; the rest do their own clock arithmetic and can fire whenever.

| What | Minute | Hour | Day | Month | Weekday | Command |
| --- | --- | --- | --- | --- | --- | --- |
| Weather | `15` | `5` | `*` | `*` | `*` | `bin/weather_sync.php` |
| Watering model | `45` | `5` | `*` | `*` | `*` | `bin/weather_sync.php --recommend` |
| NWS alerts | `20` | `*/3` | `*` | `*` | `*` | `bin/alerts_poll.php` |
| Mail outbox | `*/10` | `*` | `*` | `*` | `*` | `bin/mail_send.php` |
| Digest | `15` | `*` | `*` | `*` | `*` | `bin/daily_digest.php` |
| Analyses | `40` | `*` | `*` | `*` | `*` | `bin/analysis_run.php` |

Each command in full, with the pinned PHP binary and the output redirect —
cPanel emails the account on every run otherwise, and a job that mails 365
times a year trains everyone to ignore it:

```
15 5 * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/weather_sync.php >/dev/null 2>&1
45 5 * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/weather_sync.php --recommend >/dev/null 2>&1
20 */3 * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/alerts_poll.php >/dev/null 2>&1
*/10 * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/mail_send.php >/dev/null 2>&1
15 * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/daily_digest.php >/dev/null 2>&1
40 * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/analysis_run.php >/dev/null 2>&1
```

**The watering model runs after the weather, not with it.** It reads
`weather_daily` and `weather_forecast` and computes; it fetches nothing. Half
an hour is generous room for the sync to finish, and if it has not, the
recommendation is simply a day behind rather than wrong.

**The digest is hourly, and that is not a mistake.** It sends to each user
whose *own local time* is between 06:00 and 07:00, computed from
`user.timezone`. The server's zone never enters into it, which is exactly why
the hour field is `*`: the job's job is to notice which users it is 06:00 for.

**The mail drain is separate from everything that queues mail.** Nothing sends
inline in a request or inside another job; pages and jobs write `email_outbox`
rows and this drains them with bounded retries. A mail server being down then
delays mail, which is what should happen, instead of making a page hang.

**The analysis job is hourly, and it is the wait a person is actually
waiting on.** Somebody pressed a button on `/advice` and is waiting for a
reply; nightly would be a day. It is the same discipline as the mail drain and
for the same reason — the page writes an `analysis` row and returns, and this
job is the only thing in Carl that calls the Anthropic API. Minute 40 puts it
clear of the digest at :15 and the mail drain at :00, :10, :20… so a slow
analysis is not competing with a send.

With no key in `config/local.php` it costs one statement and writes one
`analysis_run` row saying it skipped, which is the correct state until §7.6
is done.

### The weather job's hour

**Hour 5, not 9.** Cron runs in the operating system's timezone, and this
server is **US Eastern** — measured, not assumed (§0.6). PHP's `date.timezone`
is UTC and says nothing about it.

So `15 5 * * *` fires at 05:15 Eastern = **04:15 Central**, before anyone is
up. Written as `15 9` it would fire at 08:15 Central, mid-morning, and a
gardener checking at six would find yesterday missing with nothing reporting a
fault.

Eastern and Central shift on the same DST dates, so that holds all year; only
the UTC time drifts, and nothing cares about that.

`/status?key=` reports the setting so you never have to remember it:

```
  php timezone       UTC (date.timezone; app pins UTC in code regardless)
  system timezone    America/New_York (from /etc/localtime)
  cron clock now     2026-08-31 09:27:01 EDT  <- cron schedules run in THIS
```

### Settling it before that build is deployed

cPanel's **Server Information** page does not show a clock on this version —
it lists the package, versions, IP and service states, and nothing else. Do
not go looking for one there.

The reliable way needs no shell and no deploy: fold `/bin/date` into the
one-off cron you are running for spike 3 anyway. `date` prints the timezone
abbreviation, which is the answer outright.

```
/bin/date > /home/reshiftmanager/carl-app/var/cron-test.log 2>&1; /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/weather_sync.php --verbose >> /home/reshiftmanager/carl-app/var/cron-test.log 2>&1
```

Schedule it a few minutes out, then read `carl-app/var/cron-test.log` in File
Manager:

```
Mon Aug 31 09:27:01 EDT 2026
weather_sync archive+forecast: 1 locations, 28 rows, 0 failures, 1.0 s
  archive 2026-08-17..2026-08-30: 14 rows in 515 ms
  forecast: 14 rows in 483 ms
```

The abbreviation on that first line settles the schedule — it came back `EDT`
(§0.6), which is why §7 uses hour 5. The rest closes spike 3 in the same run — it proves cron
fired, `ea-php82` ran, the bootstrap loaded, `local.php` parsed, the database
connected and rows landed.

Delete the temporary job and the log afterwards. Note that `%` is special
inside a crontab — it becomes a newline — so do not add a `date +%format` to
that line without escaping it. Plain `date` is deliberate.

Nothing about the application depends on any of this. PHP is pinned to UTC in
the bootstrap, the database session is pinned to `+00:00`, and each user's
"today" is computed through their own IANA zone. The only thing the OS setting
decides is what wall-clock time the job fires.

Command:

```
/usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/weather_sync.php >/dev/null 2>&1
```

### Why `ea-php82` and not `php`

All five candidates exist on this host (§0.2), so this is a choice rather than
a constraint. `/usr/local/bin/php` follows whatever the account's default PHP
version is set to in MultiPHP Manager; `/usr/local/bin/ea-php82` is pinned to
8.2, which is the version the web requests run under (8.2.33, measured) and
the version CI tests against.

Pinning matters because the failure it prevents is a quiet one. If the account
default were ever moved to 8.3, `/usr/local/bin/php` would follow it and the
nightly job would start running on a different PHP from every other part of
the system — the exact split hosting §10 argues against, and one nobody would
notice until a deprecation changed a value. The bootstrap refuses anything
below 8.2 outright, so the dangerous direction is newer, not older.

### The fallback, which is not needed here

Kept for the record. If no PHP binary had resolved:

```
/usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/weather-sync?key=<cron_key>" >/dev/null 2>&1
```

That form runs through the web server and inherits the 30 s ceiling. The job
chunks its work either way, so the two are interchangeable — but the CLI form
has no time limit to work around, so prefer it.

### Keep the redirect

`>/dev/null 2>&1` is not optional. Without it cPanel emails the account after
every run, and a nightly job that mails 365 times a year is one everybody
learns to ignore — which defeats the purpose of having it report at all.

### Confirming it ran (spike 3)

The morning after, open `/status?key=` and look for **last successful run**
against the location. That closes the last open spike.

If you would rather not wait a day: set the Minute and Hour to five minutes
from now, save, wait, check `/status`, then set it back to `15` / `9`. A row
appears in `weather_sync_run` either way, success or failure — the job always
writes one, because a cron that silently stops is otherwise invisible for
months.

### Verifying backfill-on-backdate (Phase 3 handoff §3.3)

The chain is tested end to end in `tests/cases/06_backfill_test.php` against a
stub provider — backdate moves the window, the run chunks by year, the report
fills in — but §3.3 asks to see it once against the real archive, because
Open-Meteo is the half the suite deliberately does not call.

Once, on the live install:

1. Note today's value: `/status?key=` prints **days held since ‹date›** per
   location. That date is `weather_location.backfill_from`.
2. Start a plant with a date 60 days in the past (any of the three forms).
3. Reload `/status?key=`. The **since** date has moved back to the planting's
   date. Nothing has been fetched yet — nothing fetches on the request path.
4. Open the plant. Its weather section says how many days in the range have
   not been fetched yet. That is the honest state, not a bug.
5. Wait for the 05:15 Eastern run, or bring the cron forward as described
   above.
6. Reload `/status?key=`. **missing in range** is 0 and **days held** has
   grown by roughly 60. The archive is fetched in calendar-year chunks, so a
   backdate that crosses New Year costs two calls rather than one long one.
7. Reload the plant. The weather table is populated and the gap notice is gone.

If step 6 shows a 429 or an `error: true` reason mentioning a limit, that is
the hourly quota rather than a failure — the run is not retried inside the
same hour on purpose, and the next night's run picks the gap up, because the
fetch plan is derived from what is missing rather than from a cursor.

## 7.5 Mail (handoff §12.1)

Nothing here is needed for the app to run. Until it is done, Carl **queues its
mail and waits**: nothing is lost, nothing sends twice when the credentials
arrive, and the temporary password for a new account is still shown on screen,
which is the path that has always worked. `/admin/mail-test` and `/status?key=`
both say which state it is in.

1. **cPanel → Email Accounts → Create.** `carl@reshiftmanager.com`, a strong
   password, 250 MB quota. Open **Connect Devices** and read the outgoing
   server, the port, and the username — the username is the full address, not
   the local part.

   **Confirmed 2026-08-31**, and it matches `config/app.php` exactly, so
   nothing about the host needs configuring:

   | | |
   | --- | --- |
   | Outgoing server | `mail.reshiftmanager.com` |
   | SMTP port | `465` (Secure SSL/TLS — implicit TLS, which is `'tls'` here) |
   | Username | `carl@reshiftmanager.com` |

2. **cPanel → Email Deliverability → `reshiftmanager.com` → Manage.** Install
   the suggested **SPF** and **DKIM** records. If Brevo is adopted later, merge
   SPF into one record — `v=spf1 +mx +a include:spf.brevo.com ~all` — because a
   domain with two SPF records has none.

3. **DMARC.** Email Deliverability creates one for you, as bare
   `v=DMARC1; p=none;`, and reports it VALID. That is enough to satisfy the
   bulk-sender rules, but on its own it does nothing at all: `p=none` asks
   receivers not to enforce, and with no `rua=` there is nowhere for them to
   report to. **Edit it in Zone Editor** — `_dmarc.reshiftmanager.com.` → Edit
   — to:

   ```
   v=DMARC1; p=none; rua=mailto:carl@reshiftmanager.com
   ```

   Then the receivers that matter send a daily XML summary of how your mail
   authenticated, which is the only way to find out you have a problem before
   somebody tells you they never got their digest.

4. **File Manager → `carl-app/config/local.php`,** mode 0600. Only the driver
   choice and the credential go here; the host and port are not secret and
   live in `config/app.php`, which is why the block is this short:

   ```php
   'mail' => [
       'driver' => 'smtp',
       'smtp' => [
           'username' => 'carl@reshiftmanager.com',
           'password' => 'THE-MAILBOX-PASSWORD',
       ],
   ],
   ```

   Keep the trailing comma. `config/local.php.example` carries the same block
   commented out, and the Brevo one beside it. Set `driver` to `smtp` **or**
   `api`, never both.

5. **Sign in as an admin → Admin → Mail.** It should now say
   `smtp mail.reshiftmanager.com:465 (tls) as carl@reshiftmanager.com` rather
   than "no driver".

   **Put an address outside `reshiftmanager.com` in the "Send the test to"
   field** — a Gmail account — then **Queue a test**. This is not a detail.
   The field defaults to the signed-in admin's own address, and on this
   install that address is `carl@reshiftmanager.com`, on the sending domain.
   A message from the domain to the domain is handed straight to the local
   mailbox by the same Exim that accepted it. It never crosses the internet,
   no receiver ever authenticates it, and step 7 has nothing to read.
6. The drain sends it within ten minutes. To not wait:
   `/tasks/mail-send?key=<cron_key>`. Reload the Mail page: the message reads
   `sent`, or `failed` with the reason on the row.

   **Confirmed 2026-08-31.** The first send returned:

   ```
   mail send: driver smtp
   considered 1, sent 1, failed 0, outcome ok, 0.1 s
   ```

   That settles the certificate question below — `SmtpMailer` verifies peer
   and peer name, and it connected to `mail.reshiftmanager.com:465` and sent.
   AutoSSL covers the `mail.` subdomain; **no `'host'` override is needed.**
   It settles nothing about deliverability: that send was to
   `carl@reshiftmanager.com` and stayed on the box, which is what 0.1 s means.
7. In the received message, **View original** (Gmail) and look for
   `spf=pass` and `dkim=pass`. If either says `fail` or `none`, step 2 or 3 has
   not propagated yet — DNS takes up to an hour. Note whether it arrived in
   the inbox or in spam.

   **Confirmed 2026-08-31**, `smtp` driver to a Gmail account:

   ```
   Authentication-Results: mx.google.com;
     dkim=pass header.i=@reshiftmanager.com header.s=default
     spf=pass (... designates 152.160.193.193 as permitted sender)
             smtp.mailfrom=carl@reshiftmanager.com
     dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=reshiftmanager.com
   ```

   All three, and **aligned**: DKIM signed `d=reshiftmanager.com`, the
   envelope sender is `carl@reshiftmanager.com` and `header.from` is the same
   domain. DMARC passes on either leg rather than on one of them, which is
   what stops a single record change from silently costing you delivery.
8. **Spike 4** (handoff §6.2) is steps 4 to 7 done once with `driver = smtp`
   and once with `driver = api`, noting which lands in the inbox rather than
   in spam. Record the answer in this file.

   **The `smtp` half is done and it passes.** No reason found to reach for
   Brevo: cPanel's own mail authenticates cleanly from this domain, and the
   `api` driver stays built and unused until something makes it necessary —
   a volume ceiling, or a provider that starts filtering this IP.

### Two things about this host that look like they need configuring, and do not

**The alternate HELO.** Email Deliverability says *"The system uses an
alternate HELO of `sh193.sameservers.com` when sending mail from the
reshiftmanager.com domain."* That is Exim's HELO on the hop from this server
out to Gmail, and it is what has to match the PTR record — which cPanel
reports VALID. `SmtpMailer` sends `EHLO reshiftmanager.com` on a different
hop: an authenticated submission to `mail.reshiftmanager.com:465` from the
same box. Nothing on the internet sees that name, and no receiver checks it.
Leave both alone.

The received headers of the 2026-08-31 test show both hops, and settle it:

```
Received: from [152.160.208.75] (port=51110 helo=reshiftmanager.com)
        by sh193.sameservers.com with esmtpsa (TLS1.3) ...   <- Carl submitting
Received: from sh193.sameservers.com ([152.160.193.193])
        by mx.google.com with ESMTPS ...                     <- Exim delivering
```

Two hops, two IPs, two HELO names. Google evaluated SPF against the second
one only, and passed it. Note which IP is which: `152.160.208.75` is the
shared outbound address weather.md §8.1 tracks for the Open-Meteo quota and
is what the *application* talks out of; mail *leaves* on `152.160.193.193`.
The submission hop is `esmtpsa` over TLS 1.3 with
`X-Authenticated-Sender: carl@reshiftmanager.com`, so the mailbox password
never crosses in the clear.

**`mail.reshiftmanager.com` is a CNAME to the domain.** That is normal cPanel,
and the certificate is served by SNI for whatever name is asked for. It only
matters because `SmtpMailer` verifies certificates properly — `verify_peer`
and `verify_peer_name` are both on. **Measured 2026-08-31: it verifies.** The
first real send connected and delivered, so AutoSSL does cover the subdomain
and there is nothing to do here.

Kept only because it is the failure that would be hard to place: if a future
certificate renewal ever misses the `mail.` subdomain, the outbox row fails
with `certificate verify failed` or a CN mismatch. The fix is **SSL/TLS Status
→ tick `mail.reshiftmanager.com` → Run AutoSSL**, or failing that, set
`'host' => '<the server's own hostname>'` in the `local.php` smtp block, whose
certificate will match.

**Never turn certificate verification off to get past it.** That connection
carries the mailbox password on every single send.

### The key-guarded task routes

Every cron job has a browser twin, because the account has no shell. All five
take `?key=<cron_key>`, and a wrong or absent key is 404:

| Route | What it runs |
| --- | --- |
| `/tasks/weather-sync` | The nightly sync. `&kind=archive`, `&kind=forecast` or `&kind=recommend` to run one part. |
| `/tasks/alerts-poll` | The NWS alerts poll. It stops after 20 s and says how far it got; it polls least-recently-polled first, so calling it again continues rather than repeating. |
| `/tasks/daily-digest` | The digest. `&force=1` ignores the 06:00-local rule; the once-a-day key still holds, so forcing it cannot send two. |
| `/tasks/mail-send` | The outbox drain. |
| `/tasks/analysis-run` | The analysis drain (§7.6). It stops *starting* requests at 20 s; anything already in flight when the 30 s ceiling kills the process is picked up by its lease on the next run. |

These run under the web SAPI and inherit the 30 s ceiling, so each chunks its
work the same way the CLI form does and the two stay interchangeable.

## 7.6 Recommendations (Phase 5 handoff §3.1)

Nothing here is needed for the app to run, and it is the same shape as §7.5:
until it is done, Carl **queues the requests and waits**. `/advice` works, the
button works, the row goes in the queue, and the drain writes an
`analysis_run` row saying `skipped`. Nothing is lost and nothing is charged.

**One value, in one place.**

1. Get an API key from the Anthropic Console.
2. **File Manager → `/home/reshiftmanager/carl-app/config/local.php`**, and add:

   ```php
   'analysis' => [
       'api' => ['key' => 'sk-ant-api03-...'],
   ],
   ```

3. Save. Check the mode is still **0600**, and check `/status?key=` — the
   `ANALYSIS` block should now name a model instead of saying "no API key".

**Nothing in the repository may ever hold that key.** `config/app.php` carries
the URL and the model, which are not secret; the key goes only in
`config/local.php`, which is gitignored, lives outside `public_html`, and
survives every deploy (hosting §6.4).

### What it costs, and the three caps that bound it

Measured in §0.9: a five-year account's analysis document is about 39,000
tokens, roughly **$0.20 per analysis** at Claude Opus 5's input rate. Three
caps stand between that and a surprise:

| Cap | Default | In |
| --- | --- | --- |
| Analyses per user per local day | 3 | `analysis.max_per_day` |
| Requests answered per drain | 3 | `analysis.batch` |
| Attempts before a request is failed | 4 | `analysis.max_attempts` |

Lower `analysis.max_per_day` in `config/local.php` if three is still too many.
Setting `analysis.effort` to `low` or `medium` is the other lever, and it
trades depth for cost rather than capping it.

### The one thing here that has never been tested on this host

**`api.anthropic.com` is not one of the five hosts Phase 0 spike 1 proved
reachable from sh193** (§0.1). Egress was open to all five, so there is no
reason to expect a block — but it has not been shown.

The first drain is the test, and it is a safe one: a failure lands in
`analysis_run.error_text` and on `/status`, never on anybody's page. To see it
without waiting for the hour, open
`/tasks/analysis-run?key=<cron_key>` with something queued. A "Could not
resolve host" or a connection timeout there is the egress answer; anything
else is a configuration one and the message says which.

## 8. Close the door

Two keys were only ever meant to be temporary.

- **`setup_key`** — comment it out once the migrations are applied. Commenting
  is exactly as effective as deleting: `Config::secret()` treats an absent,
  null or empty value identically, and the route returns 404 before it ever
  compares a key, so even the correct key gets nothing.
- **`diag_key`** — same, once §0's numbers are recorded here.

Leave `status_key` and `cron_key` set. They are meant to stay.

**Paste a fresh value rather than un-commenting the old one** when you next
need `setup_key`. Not out of paranoia about the file — it is 0600 and outside
the web root — but because the key travels in a URL query string, so it has
been through a browser address bar and the account's raw access log. That is
the one key that can take the master admin account; it is worth five seconds:

```
openssl rand -hex 24
```

---

## Redeploying

Push, then **Deploy HEAD Commit**. There is no build and no restart; the next
request picks the files up, because there is no OPcache on this host.

### The Phase 9 deploy adds TWO migrations and one file that is not code

Migration **022** (`pest_reference`), pure DDL: thirteen columns on `pest` —
eleven nullable, plus `pollinator_risk` and `is_builtin`, which default to 0 —
and one index. Nothing reads them until 023 fills them and nothing on any hot
path reads them at all, so unlike 021 the window here is genuinely harmless: a
pending 022 leaves every existing screen working.

Migration **023** (`pest_catalog`), pure DML and a `.php` file: it applies
`db/seed/pest_catalog.csv`, adopts the pest entries accounts typed for
themselves, inserts what is missing, and seeds the treatment shelf. Measured at
26 ms on an empty database and a little over a second on one with a few hundred
accounts, because the work is per account and set-based rather than per row.

**THE SEED FILE HAS TO BE ON THE SERVER BEFORE THE MIGRATION RUNS.**
`.cpanel.yml` copies the whole of `db/`, so a normal deploy does that by
itself — but running the migration against a half-copied deploy fails loudly
with `Pest catalogue seed file is missing`, which is the good failure. Nothing
is written; deploy again and re-run.

Nothing else needs a deploy step: no new cron job, no new directory, no new
vendored file. The client shell went from 19.1 KB to 20.1 KB gzipped — 20,540
bytes as `tests/check_asset_budget.php` measures it, against a 150 KB budget —
and all of it is CSS, because the three new screens add no JavaScript.

The one page worth knowing the size of is `/pests`: 57 KB of HTML, 11 KB
gzipped, for the whole seventy-six-entry list. Drawn as full cards it was
202 KB, which is why it is a list that expands one entry rather than a wall of
them.

#### The catalogue is editorial content, and there is a button for that

`db/seed/pest_catalog.csv` is prose about living things. It will be wrong about
something, and a correction must not need a schema version — migrations are
immutable once applied (`hosting.md` §7), so the catalogue cannot live in one.

The maintenance path is therefore: **edit the CSV, deploy, then
Admin → Research import → "Re-apply and sync every account"**. It is
idempotent, so it is safe to press twice, and pressing it is the only way a
corrected sentence reaches an installation that has already run 023.

What it does and does not touch:

* It **replaces** `description`, `signs`, `source` and the twelve Phase 9
  columns on every entry it ships.
* It **leaves `treatments` exactly as it found it**, which is where a county
  research dataset says something local.
* It **never touches anything anybody typed.** A `user_list_item` with a NULL
  `pest_id` is that account's own entry and stays one.
* Re-importing a county zip afterwards puts that dataset's description back.
  Both writers are idempotent and each is one action from the other.

#### After the migration: two minutes of looking

1. **Open `/pests`.** It should show the entries that can affect the plants
   this account grows, plus the ones that affect anything — slugs, cutworms,
   frost, herbicide drift. If it shows nothing at all, 023 did not run.
2. **Open Lists → Pests and diseases.** Every entry should carry either a
   "reference" link or a "yours" badge. An entry with neither means the
   adoption step did not match, which is not harmful and is worth a look.
3. **Open Log Plant Activity on any plant and choose "Pest or disease
   observed".** The dropdown should be full rather than empty, which was the
   whole point.
4. **Open `/calendar`.** The grid should draw; without a researched county the
   upcoming table will be thin and says so in words.

### The Phase 8 deploy adds ONE migration, and one decision to make afterwards

Migration **021** (`qr_tags`), pure DDL: three new tables (`qr_tag_batch`,
`qr_tag`, `qr_tag_binding`) and two columns on `user` (`label_stock`,
`tagging_started_at`), both with defaults, so there is no backfill and no
second file.

**The usual window is unusually small this time, and it is not zero.** The two
`user` columns are read by `Auth::user()`, which runs on **every request**, so
between the deploy and `/setup?key=` **every signed-in page returns 500** —
not just the new ones. It is the widest blast radius of any migration here
even though it is the smallest migration, because of which query the columns
land in. Check `/status?key=` first and run it immediately; it takes
milliseconds.

Nothing else needs a deploy step: no new cron job, no new directory, no new
vendored file (the QR encoder is hand-written PHP under `app/src/Qr/`), and no
template version change — a research zip that imported yesterday imports
today. The client shell went from 17.4 KB to 18.3 KB gzipped against a 150 KB
budget; the tags add no JavaScript at all, because the symbol is inline SVG
and the scanner is the phone's own camera app.

#### After the migration: print ONE sheet before printing thirty

In this order, and it is worth the ten minutes:

1. **Tags → Print a sheet of tags → mint one sheet.**
2. On the sheet's page, download the **registration test** and print it on
   **plain paper at 100% scale**. Hold it against a real sheet of the label
   stock, up to a window. Every outline should sit on a label.
   * Outlines that drift across the sheet mean the pitch is wrong.
   * Outlines all shifted the same way mean the margin is wrong.
   * Either is one edit to `Carl\Domain\LabelStock`, no migration, and every
     batch already minted re-renders correctly afterwards.
   * **This is not optional the first time a stock is used.** Half of each
     stock's geometry is derived rather than published by Avery — the class
     marks which numbers are which — and this sheet is what turns the
     derivation into a measurement. It costs a sheet of paper against a wasted
     sheet of polyester.
3. Print the real sheet, **at 100% scale, from a PDF viewer, never "fit to
   page"**. Measure the 100 mm rule at the foot before peeling anything.
4. Scan one tag with a phone. It should open a Carl page, signed in.

#### The one setting to decide, and how to decide it

`tags.uppercase_url` in `config/app.php` is `null`, which means **off** here,
and off is correct until somebody checks.

Upper-casing the whole tag URL puts it in QR alphanumeric mode: version 3
instead of version 4, and 0.649 mm modules instead of 0.585 on the same 1 in
stake. That is worth having. It is only safe if the web server answers an
upper-case address, and **it does not by default**: Carl is served from
`public_html/carl/`, Apache and LiteSpeed map URL paths onto filesystem paths
case-sensitively on Linux, and `/CARL/T/AB7K4M` therefore looks for a
directory named `CARL`, does not find one, and returns the web server's own
404 — the `.htaccess` inside `carl/` is never consulted and `index.php` never
runs.

To settle it: open `https://www.reshiftmanager.com/CARL/` in a browser.

* **A Carl page** → set `'uppercase_url' => true` and reprint. Existing tags
  keep working: the app matches both cases.
* **A 404** → leave it off. 0.585 mm is still 2.3x ISO 18004's practical print
  floor and gives a 600 dpi laser fourteen dots per module, and the thing that
  limits a phone here is how close it can focus, not how small the modules
  are.

The Tags screen shows which encoding is in force, measured by encoding a real
code rather than quoted from a spec, so this is never a silent difference.

**A short domain beats both.** At `carl.garden` even lower case fits version 3,
and upper case fits version 2 — 0.727 mm modules, 12% bigger than the best
case on the current domain. That is a domain registration, not an engineering
task, and it is the thing to do if a tag ever proves marginal in the field.

### The Phase 7 deploy adds TWO migrations, and they are a PAIR

Migrations **019** (`planting_split`, DDL) and **020**
(`planting_root_backfill`, DML). Run them together, in order, and do not stop
between the two.

They are two files because they have to be: MySQL commits implicitly on DDL,
so a file mixing schema and data cannot be rolled back and is never safe to
retry (hosting §7). `01_core_test.php` refuses to pass on a migration that
mixes them, and it caught this one when it was a single file.

**The window between them is the thing to know about.** 019 adds
`planting.root_planting_id` as `NOT NULL DEFAULT 0`, so between 019 and 020
every planting that already existed says its root is 0 and is invisible to a
whole-sowing query. Nothing in the application reads that column yet — the
whole-sowing report is a later feature — so the window is harmless. It is
still a window, and `/setup?key=` runs both in one pass, which is the reason
to use it rather than running them one at a time.

The usual trap applies to 019 in full: it adds four columns to `planting` and
one to `plant_event`, and between the deploy and the migration **every plant
page, the plant list, the log screen, both CSV exports and the PDFs return
500** — the code selects columns the schema does not have yet. That is more of
the application than any previous migration has taken down. Run them
immediately, in the order the Phase 5 section below gives, and check
`/status?key=` first.

Nothing else in Phase 7 needs a deploy step. No new cron job, no new
directory, no new vendored file, no template version change: a research zip
that imported yesterday imports today.

### The Phase 6 deploy adds ONE migration — the same trap as Phase 5

Migration **018** (`plant_companion`), pure DDL, one new table. Between the
deploy and `/setup?key=`, anything reading that table is a 500 — which is
`/companions` and the research card on every plant page. Run the migration
immediately, in the order the Phase 5 section below gives.

Nothing else in Phase 6 needs a deploy step. The thirteen reminder kinds, the
succession planner, the field sheet and the trimmed analysis document are all
code. Two things are worth doing after it, and neither is urgent: import the
Phase 6 dataset (owner action 9) so the companion reference has content, and
look at `/admin/analysis` to see what Recommendations has cost.

### The Phase 5 deploy DOES add migrations — read the next section first

Phase 5 adds **two**, `016_analysis.sql` and `017_invite.sql`, both pure DDL
and both new tables. So the trap below applies in full: between the deploy
finishing and `/setup?key=` running, `/advice` and the Reports menu return
500, and so does creating a user.

The order that avoids that window is the one the next section describes —
deploy, check `/status?key=`, add `setup_key`, run them, remove `setup_key`.
Budget five minutes rather than pressing Deploy and walking away.

Two more things about this deploy:

- **A sixth cron job.** `bin/analysis_run.php`, hourly at minute 40 (§7). The
  application works without it; the queue simply never drains, and `/advice`
  says a request is on its way for ever.
- **Nothing new to create by hand.** No new `var/` directory, no new vendored
  file. The API key is optional and goes in `config/local.php` (§7.6); with no
  key the feature queues and waits, which is a working state.

### The Phase 4 deploy added no migration

Kept for the record: Phase 4 added five routes, two vendored libraries and no
schema change at all.

- **`vendor/` grew.** `.cpanel.yml` already copies it, and it now carries FPDF
  (`fpdf.php`, `font/` and `license.txt`). `public/assets/vendor/chart.umd.js`
  goes over with the rest of `public/`. Nothing new has to be created by hand.
- **`var/reports/` is no longer created.** Nothing ever wrote there — the PDF
  is built in memory and sent (handoff §13.2, "streams the file; nothing
  kept") — so the two lines that made the directory are gone from
  `.cpanel.yml`. An existing empty `carl-app/var/reports/` on the server can be
  deleted by hand or left; nothing reads it either way.

### If the deploy added a migration, the site is down until you run it

**Check `/status?key=` immediately after every deploy.** The deploy copies code
and never touches the database (hosting §6.3), so between the deploy finishing
and `/setup?key=` running, the code is ahead of the schema and **every page that
touches a new table returns a 500**. That is not a subtle degradation; the main
menu is one of those pages.

This bit on the Phase 3 deploy, which added four migrations.

```
  migrations applied 11
  migrations pending 4
    - 012_mail.sql (ddl)
    ...
    run them at /carl/setup?key=<setup_key>
```

To run them:

1. **File Manager → `carl-app/config/local.php`** → add
   `'setup_key' => '<paste a fresh random string>',`
   Generate one with `openssl rand -hex 24` anywhere, or use any long random
   string. Save.
2. Open `https://www.reshiftmanager.com/carl/setup?key=<that string>`.
3. Apply the pending migrations from that page.
4. Reload `/status?key=` — pending should read 0.
5. **Remove the `setup_key` line from `local.php`.** Whoever holds it can take
   the master admin account. Paste a *fresh* value next time rather than
   reusing this one: it has been in a URL, so it is in a browser bar and the
   account's raw access log.

Signed in as an admin, a 500 caused by this now says so on the page itself
rather than leaving you guessing. A user gets the generic message; the schema
is not their business.

Migrations are immutable once applied: the checksum is recorded and a changed
file is refused rather than silently re-run.

## When something is wrong

| Symptom | Look at |
| --- | --- |
| Every page 500s right after editing config | The bootstrap prints the file and line. A missing trailing comma is the usual cause. |
| Every page 500s right after a **deploy** | Pending migrations. `/status?key=` names them; see "Redeploying" for how to apply them. Signed in as an admin, the error page says so itself. |
| A file you can see in File Manager 404s | The directory's mode. 0700 inside the document root produces a 404, not a 403. |
| `Plugin 'unix_socket' is not loaded` | `db.host` is pointing at `localhost`. It must be the Remote MySQL address. |
| Weather stopped | `/status?key=` — newest observation, last successful run, last bad status. A 429 is the Open-Meteo per-IP quota, most likely another of your own projects, or another account on sh193 if outbound leaves by the server address (§0.5). Either way it heals on its own. |
| No watering advice on the MOTD | `/status?key=` — "watering rows today". The model needs the weather in first, so it runs at 05:45, half an hour after the sync. An indoor garden never gets one on purpose: ET₀ is an outdoor number. |
| No morning email | `/status?key=` — the DIGEST block. "last run NEVER" means the hourly cron entry is missing. "0 due" every hour means nobody's local clock has hit 06:00 during a run. Then the MAIL block: with no driver, mail queues and waits, which is the expected state until §7.5 is done. |
| A message stuck in the outbox | Admin → Mail lists the last fifteen with the reason on each. `failed` after five attempts is a real refusal and the row says which; `queued` with attempts above zero is a backoff and will go by itself. |
| Alerts never appear | `/status?key=` — "active nws alerts" per location. Zero is usually correct; most days have none. `/tasks/alerts-poll?key=` runs it on demand and prints what it found. |
| `apache_php_fpm` shows down in cPanel | Correct. It is not in use; the server is LiteSpeed over LSAPI. |
| A printed tag will not scan | Measure the 100 mm rule at the foot of the sheet. If it is short, the print was scaled — reprint at 100%, never "fit to page". If the rule measures 100 mm, wipe the code and try again in better light; then read the six characters underneath it into Tags → "Find a tag by its code", which is what they are printed for. |
| Every label on a sheet is a few millimetres off | The sheet geometry, not the printer. Print the **registration test** for that stock on plain paper and hold it against a real sheet: drift across the sheet is the pitch, a uniform shift is the margin. Both are one edit to `Carl\Domain\LabelStock` and no migration. |
| A scanned tag says "Not found" | Four things, in order: it is somebody else's tag (deliberately the same 404 as a code that does not exist — §6.2 of the spec says why); the sheet was retired; the URL is upper-case and the server does not answer upper-case paths (see the Phase 8 deploy section); or the code really was mistyped. |
| `/pests` is empty, or a pest dropdown still is | Migration 023 has not run, or it ran against a deploy that had not copied `db/seed/`. Check `bin/migrate.php --status`, then Admin → Research import → "Re-apply and sync every account", which is idempotent. |
| A pest entry reads as one terse line | A county research dataset owns `treatments` and wrote `description` last. Press "Re-apply and sync every account" to restore Carl's text; re-import the zip to put the dataset's back. Both are idempotent and neither loses anything. |
| Every page 500s and the only change was Phase 8 | Migration 021. Its two `user` columns are read by `Auth::user()`, which runs on every request, so a pending 021 takes down every signed-in page rather than only the tag screens. |

## Still open for the owner

1. **Print one tag sheet and scan it** (the Phase 8 deploy section, steps 1–4).
   Mint one sheet, print the registration test on plain paper, hold it against
   a real label sheet, then print the real one and scan a tag. Ten minutes, and
   it is the only thing that verifies the half of each stock's geometry that is
   derived rather than published. Do this before buying a hundred stakes, and
   before printing more than one sheet.
2. **Decide `tags.uppercase_url`** — open `https://www.reshiftmanager.com/CARL/`
   and see whether it is a Carl page or a 404. Two minutes; the Phase 8 deploy
   section has what each answer means.
3. **§1.7 of the QR spec, if a hundred stakes are going to be bought:** make
   five tags, put one in full sun, one half-buried in wet soil, one under grow
   lights, one on a car dashboard and one indoors as a control, and scan all
   five weekly for four weeks. Then buy the rest.
4. Settle spike 0.5 — which IP Open-Meteo sees — whenever convenient. One
   line of curl; nothing depends on the answer. Spikes 1, 2, 3 and 5 are done
   and recorded in §0.
5. ~~The §12.1 mailbox and DNS steps.~~ **Done 2026-08-31**, and spike 4's
   SMTP half with it — mailbox, SPF, DKIM, DMARC with `rua=`, credentials in
   `local.php`, and a Gmail-authenticated send. §7.5 records the headers.
   What is left there is small: the DMARC `rua=` reports start arriving at
   `carl@reshiftmanager.com`, and moving `p=none` to `p=quarantine` is worth
   doing once a few weeks of them look clean.
6. Ask Ahosting whether `ea-php82-php-opcache` can be enabled. If it is,
   `opcache.validate_timestamps` becomes a deploy concern — a file-copy deploy
   may not take effect until revalidation.
7. Leave the account's default PHP version alone in MultiPHP Manager, or if it
   moves, revisit the cron command in §7. It is pinned to 8.2 for that reason.
8. Email Open-Meteo describing Carl (internal, unsold, no ads) and keep the
   reply in `docs/`. Attribution is already in the footer and is generated
   from `source_model`, so it stays honest if NCEI rows are ever mixed in.
9. ~~Claude Design: the logo and the palette.~~ **Done, Phase 10.** Delivered
   and wired in. `public/assets/css/tokens.css` is still the only file that
   names a colour; `tokens-dark.css` sits beside it for dark mode and the PDF
   deliberately does not read it.
   ~~The field-recording sheet.~~ **Built, Phase 6** — designed and
   implemented as `Carl\Reports\FieldSheet`, and deliberately generated
   rather than checked in as a static PDF (handoff §13.4 explains why).
10. **An Anthropic API key**, if Recommendations is wanted (§7.6). One line in
   `config/local.php`. Nothing breaks without it — requests queue and wait —
   and it is the only owner action Phase 5 added.
11. **Rotate `cron_key`** (visible in a Phase 3 screenshot, and it travels in
   URLs) and **delete `diag_key`** so `/diag` shuts. Both were on the Phase 4
   list and both are still outstanding; Phase 5 added a sixth route behind
   `cron_key`, which does not change the argument but does add one more thing
   a leaked key can start.
9. **Import the current dataset**,
   `research-template/populated/research_US-48217_2026-08-31.2.zip`, at
   `/admin/research-import`. It is template_version 2 and carries the
   companion pairings, the five companion crops, and the validated squash
   vine borer GDD row. Without it the companion reference is an empty page
   that explains itself, and the GDD reminder stays on the unvalidated
   `approx` row. Nothing else changes.
10. **Check the analysis rates** in `config/app.php` before trusting the
   figure on `/admin/analysis`. They were read from the published prices on
   2026-08-31 and nothing fetches them; the page labels the money an estimate
   for that reason.
