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
| 3 | A real cron execution writing a `weather_sync_run` row | Pending — confirm the morning after §7 |
| 4 | SMTP-AUTH send, and one Brevo API send | Phase 3; needs the §12.1 mailbox first |
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

### 0.4 What was measured off-host beforehand

From a development container on the same day. It proved the request shapes
were right; the table above is what proves the host allows them.

- Every variable in `OpenMeteoClient::ARCHIVE_DAILY` present, all seventeen
  daily arrays the same length as `time`.
- Forecast with `forecast_days=7&past_days=7`: all nine daily arrays and all
  four hourly soil arrays present.
- A second full sync inside the same hour returned
  `{"error":true,"reason":"Hourly API request limit exceeded..."}` — the
  shared-IP risk of weather.md §8.1, arriving as an error envelope rather than
  a bare 429. The client recognises a quota by its reason text and does not
  spend thirty seconds retrying it.

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
`research-template/populated/research_US-48217_2026-08-30.1.zip`.

Nothing is written until you confirm the preview. Without this there is no
plant catalog and the plant forms have nothing to offer.

## 7. Cron

**cPanel → Cron Jobs.** One job, once a day. The fields, left to right:

| Minute | Hour | Day | Month | Weekday |
| --- | --- | --- | --- | --- |
| `15` | `9` | `*` | `*` | `*` |

Cron runs in the **operating system's** timezone. That is a different setting
from PHP's `date.timezone`, and hosting §1 and §4 both record UTC without
distinguishing them — §4's reading came from a PHP script, which cannot see
the OS setting at all.

`/status?key=` now reports both, so check it rather than assuming:

```
  php timezone       UTC (date.timezone; app pins UTC in code regardless)
  system timezone    Etc/UTC (from /etc/localtime)
  cron clock now     2026-08-31 13:16:55 UTC  <- cron schedules run in THIS
```

**If `system timezone` is UTC**, `15 9 * * *` fires at 09:15 UTC — 4:15 am
Central in summer, 3:15 am in winter. Late enough that yesterday has settled
at the provider, early enough to be waiting before anyone opens the app.

**If it is `America/Chicago`**, that same line fires at 9:15 in the morning
local, which is five hours later than intended: a gardener checking at six
would find yesterday missing. Use `15 4 * * *` instead.

Nothing about the application depends on this. PHP is pinned to UTC in the
bootstrap, the database session is pinned to `+00:00`, and each user's "today"
is computed through their own IANA zone. The only thing the OS setting decides
is what wall-clock time the job fires.

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

If a deploy adds a migration, `/status?key=` will say so and `/setup?key=`
applies it — which means re-adding `setup_key` for the length of the migration
and removing it again afterwards. Migrations are immutable once applied: the
checksum is recorded and a changed file is refused rather than silently re-run.

## When something is wrong

| Symptom | Look at |
| --- | --- |
| Every page 500s right after editing config | The bootstrap prints the file and line. A missing trailing comma is the usual cause. |
| A file you can see in File Manager 404s | The directory's mode. 0700 inside the document root produces a 404, not a 403. |
| `Plugin 'unix_socket' is not loaded` | `db.host` is pointing at `localhost`. It must be the Remote MySQL address. |
| Weather stopped | `/status?key=` — newest observation, last successful run, last bad status. A 429 is somebody else's traffic on the shared IP; it heals on its own. |
| `apache_php_fpm` shows down in cPanel | Correct. It is not in use; the server is LiteSpeed over LSAPI. |

## Still open for the owner

1. Confirm spike 3 — a real cron execution — the morning after §7. The other
   four are done and recorded in §0.
2. The §12.1 mailbox and DNS steps, before any Phase 3 email work. That is
   also spike 4.
3. Ask Ahosting whether `ea-php82-php-opcache` can be enabled. If it is,
   `opcache.validate_timestamps` becomes a deploy concern — a file-copy deploy
   may not take effect until revalidation.
4. Leave the account's default PHP version alone in MultiPHP Manager, or if it
   moves, revisit the cron command in §7. It is pinned to 8.2 for that reason.
5. Email Open-Meteo describing Carl (internal, unsold, no ads) and keep the
   reply in `docs/`. Attribution is already in the footer and is generated
   from `source_model`, so it stays honest if NCEI rows are ever mixed in.
6. Claude Design: the logo, the palette, and the field-recording sheet.
   `public/assets/css/tokens.css` is a neutral placeholder defining exactly
   the `--carl-*` names to deliver; it is the only file that names a colour,
   so the palette is a one-file swap.
