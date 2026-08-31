# Carl The Garden Helper

A garden logging system for hobby and small-market gardeners. Record plants
through a lifecycle, log what you did to them and to the garden, attach
photographs, and later read reports that line your practices up against the
weather that actually happened.

**Public URL:** `https://www.reshiftmanager.com/carl/`
**Stack:** PHP 8.2 / MySQL 8.0, no build step, no Composer.

## The three documents that govern this repository

| Document | Authority over |
| --- | --- |
| [`docs/CARL-HANDOFF.md`](docs/CARL-HANDOFF.md) | Scope. What Carl is, the screens, the data model, the phasing. |
| [`docs/PHASE-3-HANDOFF.md`](docs/PHASE-3-HANDOFF.md) | What to build next, and the facts Phase 1 measured that the original scope could only assume. |
| [`docs/hosting.md`](docs/hosting.md) | Every platform constraint. Overrides the handoff where they conflict. |
| [`docs/weather.md`](docs/weather.md) | Weather ingestion. Overrides the handoff where they conflict. |

Read hosting.md before writing code. Roughly a third of its facts *remove* an
option, and three of them — which server the database is on, which engine it
runs, and the 0700 → 404 rule — each cost a day to discover the hard way.

Where a constraint shaped a decision, the code cites the section. Search for
`hosting Section` or `weather.md Section` to find the reasoning behind
anything that looks unusual.

## What is built

Phases 1, 2 and 3 (handoff §14). Accounts log real data; the nightly jobs turn
it into advice.

- Login, forced first reset, onboarding wizard, ZIP → county → region.
- Start a New Plant (indoor seed start, direct sow, transplant), each with the
  research card for that plant and region, and the row occupancy hint.
- Log Plant Activity: every action the plant's state allows, all backdatable,
  all able to carry a narrative and photos, single or batched.
- Build Garden, Garden Actions (including zone watering that fans out to every
  living plant in the zone's rows), View Garden.
- Lists — the user's own seed sources, soils, fertilisers and the rest, with
  inline "+ Add new…" on every dropdown.
- Admin: create user, research import, regions needing research, mail health.
- Weather: nightly archive and forecast sync, and the MOTD matrix.
- **Watering recommendation** (§11): FAO-56's checkbook, computed nightly per
  garden and container, shown on the MOTD with the numbers behind it.
- **NWS alerts** (§8.4): polled every three hours; only the classes that
  matter to a garden.
- **Reminders and the daily digest** (§12): eleven kinds, computed hourly and
  sent at each user's own 06:00, with a tokenised One-Click unsubscribe.
- **Mail**: an outbox drained by cron, with an SMTP and a Brevo driver. Until
  the mailbox exists (§12.1, an owner action) mail queues and waits — nothing
  is lost, and the temporary password for a new account is still shown on
  screen.
- **CSV export** (§13.3): plants, events and weather, formula-injection
  guarded and streamed.

Not built yet, by design: reports and charts (Phase 4), PDFs (Phase 4), the
field-recording sheet and the palette (both Claude Design).

## Layout

```
carl/
  .cpanel.yml               deploy tasks, validated in CI against the host parser
  .github/workflows/ci.yml  PHP 8.2, MySQL 8.0 + MariaDB 10.11
  config/app.php            committed configuration; no credentials
  config/local.php          gitignored, 0600, on the server only
  docs/                     the three governing documents
  research-template/        the research dataset contract and the first dataset
  db/migrations/            numbered, immutable once applied
  db/seed/zcta.csv          33,791 ZIP rows, public-domain Census data
  bin/                      migrate.php, weather_sync.php, alerts_poll.php,
                            daily_digest.php, mail_send.php
  app/bootstrap.php         autoloader, config, the parse-error guard
  app/src/                  Core, Auth, Repo, Domain, Controller, Research,
                            Weather, Mail, Reminders, Support
  app/views/                plain PHP templates
  public/                   the ONLY directory the web reaches
  tests/                    run.php --strict, plus the CI lint scripts
```

On the server, `app/ db/ bin/ vendor/ config/ var/` live in
`/home/reshiftmanager/carl-app`, outside `public_html`, and `public/` is copied
to `/home/reshiftmanager/public_html/carl`. `/status` reports whether that is
actually true.

## Running it locally

```bash
# 1. A database
mysql -e "CREATE DATABASE carl_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. Point at it (or write config/local.php)
export CARL_DB_HOST=127.0.0.1 CARL_DB_NAME=carl_dev \
       CARL_DB_USER=carl CARL_DB_PASS=... CARL_DB_ALLOW_LOCAL=true
export CARL_STATUS_KEY=dev-status CARL_SETUP_KEY=dev-setup CARL_CRON_KEY=dev-cron

# 3. Schema and the ZIP table
php bin/migrate.php

# 4. Serve it the way the server does: public/ at /carl/, app code a sibling,
#    and production's limits reproduced (hosting §10)
php -S 127.0.0.1:8088 -t public dev-router.php -c dev/php.ini
```

Then `http://127.0.0.1:8088/carl/` — sign in as `admin` / `1234`, which forces
a reset. Import `research-template/populated/research_US-48217_2026-08-30.1.zip`
from Admin → Research import before starting plants; without it there is no
plant catalog.

`http://127.0.0.1:8088/carl/status?key=dev-status` is the health page.

The nightly and hourly jobs can all be run by hand:

```bash
php bin/weather_sync.php --verbose         # archive + forecast
php bin/weather_sync.php --recommend -v    # the watering model; calls no API
php bin/alerts_poll.php --verbose          # NWS alerts
php bin/daily_digest.php --force --verbose # ignore the 06:00-local rule
php bin/mail_send.php --status             # what is queued, and with what driver
```

Each has a key-guarded browser twin under `/tasks/`, because the production
account has no shell.

Every configuration value can be overridden by an environment variable with a
`CARL_` prefix and underscores for the path: `CARL_DB_HOST`, `CARL_BASE_PATH`,
`CARL_WEATHER_RETRY_DELAY`.

## Tests

```bash
php tests/run.php --strict          # 238 cases; needs a database
php tests/lint_cpanel_yml.php       # the host's parser rules
php tests/check_collation.php       # utf8mb4_unicode_ci on every table
php tests/check_asset_budget.php    # the client shell against 150 KB gzipped
```

The suite drives the real kernel through the §14 alpha acceptance run end to
end — sign-in, forced reset, onboarding at ZIP 76692, three kinds of backdated
plant, every action type, the zone fan-out, photos, data isolation between two
accounts — and smoke-tests every GET route by enumerating the router, so a
template that only breaks on an unusual value cannot slip through.

Weather and NWS alerts are tested against stub providers rather than the live
APIs: Open-Meteo's free tier is rate limited per IP, both daily and hourly, so
a suite that called it would be flaky *and* would spend the quota the nightly
job depends on. The hourly limit is easy to hit — two full syncs in one hour
did it during development. `api.weather.gov` cannot be asked for a freeze
warning in August, and should not be asked at all by a test.

Both were driven against the real services once, by hand, and what they
returned is recorded in the tests that stand in for them.

The SMTP driver is the exception: it is exercised against a real socket, a
forked listener that speaks enough SMTP to accept or refuse a message. A
hand-rolled SMTP client that has only ever met a mock is a client whose
multi-line reply handling has never actually been tried.

The digest's two hardest cases are both about time and both silent — sending
at the server's morning instead of the user's, and sending the same thing
twice — so they are tested with a frozen clock and two accounts eleven hours
apart. The eight reminder kinds that fire on one or two days of the year are
tested the same way; without it, a rule that never fires would stay
undiscovered for a season.

## Deploying

See [`docs/deploy.md`](docs/deploy.md) for the first deploy and the cron entry.

The Phase 0 spikes ran on sh193 on 2026-08-31 and are recorded there. The one
that could have killed the weather feature — outbound HTTPS — passed on all
five hosts. Round trip to the database is 0.81 ms, which is what makes
hosting §9's `time ≈ measured + statements × RTT` a real budget rather than a
principle.

## Attribution

Weather data by [Open-Meteo.com](https://open-meteo.com/) (CC BY 4.0), based on
ERA5 reanalysis from Copernicus / ECMWF. ZIP data from the US Census Bureau
(public domain). Regional agronomic research is cited per row and shown in the
app beside the value it justifies.
