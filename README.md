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
| [`docs/PHASE-7-HANDOFF.md`](docs/PHASE-7-HANDOFF.md) | What to build next, and the facts each phase measured that the original scope could only assume. The earlier phase handoffs are kept as they were written. |
| [`docs/hosting.md`](docs/hosting.md) | Every platform constraint. Overrides the handoff where they conflict. |
| [`docs/weather.md`](docs/weather.md) | Weather ingestion. Overrides the handoff where they conflict. |
| [`docs/deploy.md`](docs/deploy.md) | The runbook, and §0 is every measurement taken on the live host. |

Read hosting.md before writing code. Roughly a third of its facts *remove* an
option, and three of them — which server the database is on, which engine it
runs, and the 0700 → 404 rule — each cost a day to discover the hard way.

Where a constraint shaped a decision, the code cites the section. Search for
`hosting Section` or `weather.md Section` to find the reasoning behind
anything that looks unusual.

## What is built

Phases 1 through 6 (handoff §14) — **v2 is complete**. Accounts log real data,
the nightly jobs turn it into advice, the reports turn a season into something
you can read, and Carl will read it back to you.

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
- **Reports and charts** (§13.1): the plant and garden pages draw the weather
  that actually happened over the dates they cover — a temperature band,
  rainfall, ET₀, and the logged actions as markers on top of it. Chart.js is a
  vendored file; the data comes from `/api/plant/<id>/series`, which costs one
  statement for the weather and one for the events however long the season was.
- **PDF reports** (§13.2): "Download PDF" posts the charts back up and gets a
  document with the research card, the full event table, the photographs and
  the citations. Measured at 1.9 s and 16 MB on a twenty-photo report against a
  10 s / 64 MB budget (`deploy.md` §0.8).
- **`/export/claude.json`** (§13.3): the same records as one document, with the
  research values in force for your region, shaped for pasting into a
  conversation with Claude yourself.
- **Recommendations**: ask Carl what your own records say, and it answers from
  the log, the weather over those dates, and the research for your county. The
  API call is made by a cron job and never by a page — the same rule weather
  and mail follow — so the answer appears on the next page load rather than
  while you wait. The document it sends is a bounded summary: a five-year
  account's raw export is 3.3 MB and roughly 918,000 tokens, and the same
  account summarised is 140 KB (`deploy.md` §0.9).
- **A Reports menu**, because by the end of Phase 4 there were six downloads
  and two report pages and no screen that named them.
- **End Growing Season**: ends every living planting in a garden on one date.
  The one destructive action in Carl, so the confirmation screen names every
  planting first and asks for the words to be typed.
- **Crop rotation warnings** beside the row picker: what that bed grew, and
  when. A nudge, never a block.
- **A tokenised set-password link.** The account-creation email no longer
  carries a password — it carries a one-shot link that expires. The
  temporary password shown on screen is unchanged, because that is the path
  that works with no mailbox.

And, from Phase 6, the last of v2:

- **GDD pest reminders.** The calendar rule says "spider mites turn up in
  July"; this one says the heat your garden actually had puts the moths out
  this week, which a cool spring moves by a fortnight and a calendar never
  notices. The forecast extends the count past today, so the reminder can
  arrive before the pest does.
- **Succession planting**, as both halves of the same arithmetic:
  `/succession` lays out every sowing your area's research still allows this
  season, each date a link straight into Start a New Plant; and a fortnight
  after each sowing the digest says another round is due. Nothing is stored —
  sowing a round is what records it.
- **A companion planting reference** at `/companions`, and on every research
  card. Twenty pairings, each with the mechanism behind it and how well
  established that is — four verified, seven approximate, nine simply
  traditional. Carl does nothing with them, deliberately: this is the corner
  of gardening advice with the widest gap between what is repeated and what
  has been tested, and the page says so.
- **The field-recording sheet** (§13.4), blank or prefilled per garden. A page
  to take to the beds and write on, sized to print on A4 and Letter alike.
- **Recommendations, narrower and cheaper.** Ask about one bed or one plant
  rather than the year; the document it sends lost a third of its research
  section without losing a single citation; and `/admin/analysis` says what
  the month cost.

Not built yet, by design: the logo and the palette, which are Claude Design
deliverables. `public/assets/css/tokens.css` is a neutral placeholder naming
exactly the variables to deliver, and it is the only file that names a colour.

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
                            daily_digest.php, mail_send.php, analysis_run.php
  app/bootstrap.php         autoloader, config, the parse-error guard
  app/src/                  Core, Auth, Repo, Domain, Controller, Research,
                            Weather, Mail, Reminders, Reports, Analysis,
                            Planting, Support
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
a reset. Import `research-template/populated/research_US-48217_2026-08-31.2.zip`
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
php bin/analysis_run.php --status          # what is waiting, and for which model
```

Each has a key-guarded browser twin under `/tasks/`, because the production
account has no shell.

Every configuration value can be overridden by an environment variable with a
`CARL_` prefix and underscores for the path: `CARL_DB_HOST`, `CARL_BASE_PATH`,
`CARL_WEATHER_RETRY_DELAY`.

## Tests

```bash
php tests/run.php --strict          # 335 cases; needs a database
php tests/lint_cpanel_yml.php       # the host's parser rules
php tests/check_collation.php       # utf8mb4_unicode_ci on every table
php tests/check_asset_budget.php    # the client shell against 150 KB gzipped
```

CI runs the suite on MySQL 8.0 (production) and MariaDB 10.11 (insurance on a
database host that is not ours to control). Both were run locally from empty
databases for the Phase 5 build — MySQL 8.0.46 and MariaDB 10.11.14, migrated
twice each for idempotence — and both were green.

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

The Anthropic API is stubbed for the same reason Open-Meteo is, with the
quota denominated in dollars: a suite that called it would need a live key in
CI and would spend real money on every run of every branch. What the stub
leaves testable is all of the behaviour that is actually Carl's — the queue,
the lease, the backoff, which failures are worth retrying, and the size of the
document. The wire shape of a reply is covered by driving every documented
response shape, success and failure, through the exact function the live path
uses.

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
