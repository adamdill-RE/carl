# Deploying Carl

Everything here is dictated by `hosting.md`. The account has no shell, so
every administrative action is either a cPanel page or a key-guarded route.

---

## 0. Before the first deploy: the Phase 0 spikes

Handoff §14 lists five spikes. The first can kill the weather feature, so it
goes first. All of them are answered by the `/diag` route, which exists only
while `diag_key` is configured.

**These have not been run on sh193.** They were run from a development
container on 2026-08-31, which proves the request shapes are right but says
nothing about egress from the real host — the two are different machines with
different network policy. Run them on the server and record the numbers here.

| # | Spike | How | Result on sh193 |
| --- | --- | --- | --- |
| 1 | Outbound HTTPS to `archive-api.open-meteo.com`, `api.open-meteo.com`, `api.weather.gov`, `api.zippopotam.us`, `www.ncei.noaa.gov` | `/diag?key=` | *not yet run* |
| 2 | PHP CLI binary path for cron | `/diag?key=` lists which candidates exist | *not yet run* |
| 3 | A real cron execution writing a `weather_sync_run` row | schedule the job five minutes out | *not yet run* |
| 4 | SMTP-AUTH send, and one Brevo API send | Phase 3; needs the §12.1 mailbox first | *not yet run* |
| 5 | 200-row and 2,000-row upsert timing, and RTT | `/diag?key=` | *not yet run* |

**If spike 1 fails for Open-Meteo, stop and rescope.** Weather is the reason
the event log is worth keeping; without it the correlation the app exists for
cannot be drawn.

### What was measured off-host, 2026-08-31 — and what it does not tell you

Useful as a contract check, not as evidence about sh193:

- `archive-api.open-meteo.com/v1/archive` with every variable in
  `OpenMeteoClient::ARCHIVE_DAILY`: HTTP 200, ~750 ms, all seventeen daily
  arrays present and the same length as `time`.
- `api.open-meteo.com/v1/forecast` with `forecast_days=7&past_days=7`: HTTP
  200, ~850 ms, all nine daily arrays and all four hourly soil arrays present.
- A second full sync inside the same hour returned
  `{"error":true,"reason":"Hourly API request limit exceeded..."}`. This is
  the shared-IP risk weather.md §8.1 describes, arriving as an error envelope
  rather than a bare 429. The client now recognises a quota by its reason text
  and does **not** spend thirty seconds retrying it.
- The ZCTA migration loads 33,791 rows in 419 ms at 2,000 rows per statement,
  comfortably inside the 30 s browser ceiling.

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
- Set the administrator password on the same page.
- **Remove the `setup_key` line and save.** Whoever holds it can take the
  master admin account. With no key configured the route does not exist, and
  that is the state to leave it in.

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

**cPanel → Cron Jobs.** Once daily, around 09:15 UTC:

```
/usr/local/bin/php -q /home/reshiftmanager/carl-app/bin/weather_sync.php >/dev/null 2>&1
```

The CLI path is unverified on this host — `/diag` says which of
`/usr/local/bin/php`, `/usr/local/bin/ea-php82` and `/opt/alt/php82/usr/bin/php`
exists. If none resolves, use the browser form instead:

```
/usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/weather-sync?key=<cron_key>" >/dev/null 2>&1
```

The curl form runs under the web SAPI and inherits the 30 s ceiling. The job
chunks either way, so the two forms are interchangeable.

**Redirect the output.** cPanel emails the account on every run otherwise, and
a nightly job that mails 365 times a year trains everyone to ignore it.

Confirm it ran: `/status?key=` shows "last successful run" per location. A
cron job that stops is otherwise invisible for months — that line is the whole
reason `/status` reports it.

## 8. Run the spikes, then close the door

With `diag_key` set, open `/diag?key=`, record the five spike results in the
table at the top of this file, and **remove the `diag_key` line**.

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

1. Run the Phase 0 spikes and fill in the table above.
2. The §12.1 mailbox and DNS steps, before any Phase 3 email work.
3. Ask Ahosting whether `ea-php82-php-opcache` can be enabled. If it is,
   `opcache.validate_timestamps` becomes a deploy concern.
4. Email Open-Meteo describing Carl (internal, unsold, no ads) and keep the
   reply in `docs/`. Attribution is already in the footer and is generated
   from `source_model`, so it stays honest if NCEI rows are ever mixed in.
5. Claude Design: the logo, the palette, and the field-recording sheet.
   `public/assets/css/tokens.css` is a neutral placeholder defining exactly
   the `--carl-*` names to deliver; it is the only file that names a colour,
   so the palette is a one-file swap.
