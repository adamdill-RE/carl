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
| [`docs/hosting.md`](docs/hosting.md) | Every platform constraint. Overrides the handoff where they conflict. |
| [`docs/weather.md`](docs/weather.md) | Weather ingestion. Overrides the handoff where they conflict. |

Read hosting.md before writing code. Roughly a third of its facts *remove* an
option, and three of them — which server the database is on, which engine it
runs, and the 0700 → 404 rule — each cost a day to discover the hard way.

Where a constraint shaped a decision, the code cites the section. Search for
`hosting Section` or `weather.md Section` to find the reasoning behind
anything that looks unusual.

## What is built

Phase 1, the alpha cut (handoff §14): accounts can log real data. Reports,
PDFs, email, watering recommendations and reminders come after data starts
flowing.

- Login, forced first reset, onboarding wizard, ZIP → county → region.
- Start a New Plant (indoor seed start, direct sow, transplant), each with the
  research card for that plant and region.
- Log Plant Activity: every action the plant's state allows, all backdatable,
  all able to carry a narrative and photos, single or batched.
- Build Garden, Garden Actions (including zone watering that fans out to every
  living plant in the zone's rows), View Garden.
- Lists — the user's own seed sources, soils, fertilisers and the rest, with
  inline "+ Add new…" on every dropdown.
- Admin: create user, research import, regions needing research.
- Weather: nightly archive and forecast sync, and the MOTD matrix.

Not built yet, by design: reports and charts (Phase 4), PDFs (Phase 4), email
and reminders (Phase 3), the watering model (Phase 3), NWS alerts (Phase 3),
CSV export (Phase 2).

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
  bin/                      migrate.php, weather_sync.php
  app/bootstrap.php         autoloader, config, the parse-error guard
  app/src/                  Core, Auth, Repo, Domain, Controller, Research, Weather, Support
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

Every configuration value can be overridden by an environment variable with a
`CARL_` prefix and underscores for the path: `CARL_DB_HOST`, `CARL_BASE_PATH`,
`CARL_WEATHER_RETRY_DELAY`.

## Tests

```bash
php tests/run.php --strict          # 116 cases; needs a database
php tests/lint_cpanel_yml.php       # the host's parser rules
php tests/check_collation.php       # utf8mb4_unicode_ci on every table
php tests/check_asset_budget.php    # the client shell against 150 KB gzipped
```

The suite drives the real kernel through the §14 alpha acceptance run end to
end — sign-in, forced reset, onboarding at ZIP 76692, three kinds of backdated
plant, every action type, the zone fan-out, photos, data isolation between two
accounts — and smoke-tests every GET route by enumerating the router, so a
template that only breaks on an unusual value cannot slip through.

Weather is tested against a stub provider rather than the live API: the free
tier is shared-IP rate limited (weather.md §8.1), so a suite that called it
would be flaky *and* would spend the quota the nightly job depends on.

## Deploying

See [`docs/deploy.md`](docs/deploy.md) for the first deploy, the cron entry,
and the Phase 0 spikes that have to be run on the real host before the weather
feature can be trusted.

## Attribution

Weather data by [Open-Meteo.com](https://open-meteo.com/) (CC BY 4.0), based on
ERA5 reanalysis from Copernicus / ECMWF. ZIP data from the US Census Bureau
(public domain). Regional agronomic research is cited per row and shown in the
app beside the value it justifies.
