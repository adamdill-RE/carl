# Carl The Garden Helper — handoff to Claude Code

**Public URL `https://www.reshiftmanager.com/carl/` · PHP 8.2 / MySQL 8.0 on the
Ahosting reseller account shared with RESM · mobile-first · no build step**

This document is the scope. Two companion documents are the authority on their
own subjects and override anything here that conflicts with them:

- `docs/hosting.md` (copy of `hostingplatformscope.md`) — every platform
  constraint. Read it in full before writing any code. §2 (database host,
  engine, LiteSpeed), §3 (hard constraints), §5 (layout, 0700 → 404), §6
  (deploy, browser-run migrations), §7 (PDO), §8 (sessions/auth) are
  non-negotiable.
- `docs/weather.md` (copy of `weatherdatascope.md`) — historical weather
  ingestion. This document extends it with forecasts, alerts and zip lookup.

Everything else in this document was decided in scoping and should be built as
written. Where a section says **Owner action**, the product owner does it (the
account has no shell, so cPanel steps are his). Where it says **Claude
Design**, the artefact is produced there, not in Claude Code.

---

## 0. Decisions already made (do not re-litigate)

1. **Alpha first.** The growing season is ending; accounts must be able to log
   real data as early as possible. Phase 1 (§14) is the alpha cut. Reports,
   PDFs, email, watering recommendations and reminders come after data starts
   flowing.
2. **Region-agnostic from day one.** Hill County, TX (`US-48217`) is the test
   region, not an assumption. Every piece of agronomic knowledge lives in
   region-keyed research tables loaded by an admin import (§9). Nothing is
   hard-coded to Texas.
3. **Research arrives as a versioned zip of CSVs** produced by Claude and
   uploaded by the admin. Format: `research-template/README.md`. First dataset:
   `research-template/populated/research_US-48217_2026-08-31.2.zip`. New
   regions and new plant types use the same route.
4. **One location per user.** The user's zip at onboarding is their weather
   location and their region. Gardens inherit it. A person with two zips makes
   two accounts.
5. **Users only see their own data.** Reference data (research, pests, zip
   lookup) is global and read-only to users.
6. **Data model is asset + append-only event log** (§5). Every action is a
   `plant_event` row with a backdatable date. Lifecycle state is derived.
7. **Hobby / small-market scale.** No expense tracking, QR labels, multi-user
   sharing, offline mode, or acreage features. Deferred list in §15.
8. **Hardening countdown is "N days from start date."**
9. **Charts: Chart.js**, vendored, loaded only on report pages.
10. **PDF reports: FPDF**, vendored single file. Field-recording sheet, logo
    and colour scheme: **Claude Design**.
11. **Email: two drivers behind one interface.** SMTP-AUTH to a cPanel mailbox
    first; HTTPS API (Brevo) as the swap. Both are spiked before either is
    relied on.
12. **Weather never on the request path.** Pages read tables the cron filled.
13. **Photos ≤ 2 MB, resized client-side**, re-encoded server-side by GD (§10).
14. **Admin has exactly three functions:** create users, import research,
    review "regions needing research."

---

## 1. What Carl is

A garden logging system for hobby and small-market gardeners. The user records
plants through a lifecycle, logs what they did to them and to their gardens,
attaches photographs, and later reads reports that line up their practices with
the weather that actually happened. The purpose of the data is correlation:
practices + weather → outcomes. Eventually the logged data is exported to
Claude for analysis and recommendations (v2).

Lifecycle for most plants (not trees):
`Seed Start / Direct Sow → Hardening → Transplant → Yield → Cull`.
Store-bought plants enter at Transplant. Any plant can be culled at any point.

Two user roles: **admin** (creates users, imports research) and **user**.

---

## 2. Platform recap (details in `docs/hosting.md`)

- App served from subpath `/carl/`; **everything** is built from one configured
  `base_path`. Session cookie `CARLSESS`, path `/carl/`.
- Layout: `/home/reshiftmanager/public_html/carl/` (index.php, .htaccess,
  assets/, vendor js) and `/home/reshiftmanager/carl-app/` (app/ db/ bin/
  config/ var/) outside the document root. Config env prefix `CARL_`.
- Own database `reshiftmanager_carl` on `152.160.193.196`, `utf8mb4_unicode_ci`
  named on every table, `VIRTUAL` generated columns only, no `RETURNING`.
- No shell, no Composer, no build step, no OPcache, no `intl`, no `sodium`, no
  WebSockets/SSE. `memory_limit` 128M, `max_execution_time` 30 s,
  `upload_max_filesize` 2M, `post_max_size` 8M, `max_input_vars` 1000 (silent
  truncation — never post a whole garden's rows in one form).
- Deploy: git push → cPanel Deploy HEAD Commit via `.cpanel.yml` (ASCII, no
  tabs, no braces). Migrations never run on deploy; they run from
  `/setup?key=`. `/status?key=` reports pending migrations and health.
- Cron is available (cPanel Cron Jobs). RTT to the DB host is under 1 ms;
  design to statement count regardless.
- Vendored third-party code allowed: Chart.js (one file), FPDF (one file), and
  a hand-rolled or vendored PHPMailer for SMTP. Nothing else without asking.

---

## 3. Repository layout

```
carl/
  .cpanel.yml
  .github/workflows/ci.yml
  docs/hosting.md  docs/weather.md  docs/CARL-HANDOFF.md
  research-template/            (spec + first dataset, committed)
  public/                       → public_html/carl/
    index.php  .htaccess  sw.js
    assets/css/  assets/js/  assets/vendor/chart.umd.js
  app/
    bootstrap.php  src/ (PSR-4-ish, own autoloader)  views/
  db/migrations/NNN_*.sql
  db/seed/zcta.csv              (see §8.3)
  bin/weather_sync.php  bin/daily_digest.php  bin/alerts_poll.php
  config/app.php (committed)  config/local.php (gitignored, credentials)
  var/sessions  var/photos  var/imports  var/reports   (0700, outside web root)
  vendor/fpdf/fpdf.php
```

Front controller finds the app root by probing (hosting §5.1). Local dev
mirrors the server layout (public mounted at `/carl/`, app as a sibling) with a
`php.ini` reproducing production limits (hosting §10).

---

## 4. Screens (all mobile-first, 380 px viewport is the design width)

Every date field defaults to today and accepts past dates. Every dropdown
allows "+ Add new…" inline, creating the list item without leaving the form
(exception: Plant Category/Type, which comes from research and is read-only
for users).

### 4.1 Login, forced reset, onboarding
- Username + password. Forced reset on first login (admin-created temporary
  password, and the seeded `admin`/`1234`).
- Onboarding wizard after first login: name, garden county, zip. Zip resolves
  to lat/long + county FIPS (§8.3). If the county has no researched region, the
  user is told their area is "using general guidance for now" and the region is
  flagged for research (§9.4). Then: create first garden (§4.6), optionally
  first plant. "Skip to main menu" available on every step after profile
  submit; the wizard can be resumed from the main menu until complete.
- Every account gets an **Indoor Garden** created at signup (the default
  location for indoor seed starts).

### 4.2 Main menu
- **MOTD weather matrix** at the top: recent (last 3 days) rainfall, ET₀,
  soil moisture, temperature; forecast next 3 days: rain probability and
  amount, high/low, humidity, soil moisture projection; **watering
  recommendation** (Phase 3); active NWS alerts (Phase 3). Dismissable for the
  session; reappears next day, or immediately if the forecast changed
  materially since it was dismissed (store a hash of the forecast rows the
  user dismissed).
- **Region guidance** lines for today's date and the user's plant categories
  (from `region_guidance`), MOTD-style, dismissable the same way.
- Today's items (Phase 3): countdowns and reminders, same content as the
  daily email.
- Menu: Start a New Plant · Log Plant Activity · View Plants · Build Garden ·
  Garden Actions · View Garden · Lists · (v2: Reports · Recommendations · End
  Growing Season).

### 4.3 Start a New Plant
Three entry forms; the choice sets the initial event and state.

**Indoor Seed Start** (garden defaults to Indoor Garden): category → type
(type list filtered to those with a `plant_region` row for the user's region,
recommended ones marked; others available under "show all"); on select, show
the research card (windows, DTM, germination, notes, confidence marker); seed
source; seed-starting soil; seed-starting vessel; **quantity sown**; date.

**Direct Sow**: category/type + research card; seed source; fertilizer used at
sow; collar used Y/N; seeds per collar; trellis/cage Y/N; garden + row (or
container); date; quantity sown (collars × seeds, editable).

**Transplant** (nursery-bought or unknown origin): category/type + research
card; nursery source; fertilizer used; trellis/cage Y/N; garden + row (or
container); default water method; initial height/width; notes; date;
quantity (default 1).

Light occupancy hint on row selection: "Row 3 already has 4 living plants."
Not a block.

### 4.4 Log Plant Activity
Table of plants (garden, row/container, category/type, state, days since
start) with filters: category, type, state, garden, row. Tap one plant or
select several (batch action applies the same event to each). Research card
shown MOTD-style above the actions, dismissable for that page.

Actions by state (each writes one `plant_event`, all backdatable, all with
optional narrative, photos attachable to any event):

| State | Actions |
| --- | --- |
| Seed started | Water (duration) · Germinated (quantity) · Failed to germinate (quantity) · Death (quantity) · Up-pot (soil type, container type; repeatable) · Photos · Begin hardening · Transplant (garden+row or container) |
| Hardening | Set/choose hardening schedule · Projected duration (days) · Hardening start date · Transplant · Photos |
| Planted (transplanted or direct-sown) | Cull (reason, quantity) · Yield (weight **or** count, unit) · Water (method, duration) · Observe pest/disease · Treat pest/disease (offers to record the observation too if none exists) · Fertilize or amend (fertilizer **or** amendment) · Mulch (type) · Photos · Death (quantity) |

Quantities default to the planting's current live count; entering fewer
records partial attrition (§5.3).

### 4.5 View Plants
Same list and filters, living and culled. Opening a plant shows the **plant
report**: research card, full event timeline, photos in chronological order,
charts (Phase 4) of weather during the plant's in-ground period (from
transplant or direct sow) with events overlaid, and yield summary. "Download
PDF" (Phase 4).

### 4.6 Build Garden
Name; N/S and E/W dimensions (ft, one decimal); number of rows; row
orientation (N/S or E/W); rows are auto-created and can be renamed. Per row:
sun exposure (High/Medium/Low, default High), shade cloth (from list or new),
notes. Water zones: name, method (from Water Methods list), rows covered.
Soil type for the garden: clay / loam / sandy / raised-bed mix / container
(feeds the watering model). Containers are a separate list (size,
description) and behave as a "garden" of one location.

### 4.7 Garden Actions
Water (zone or method, duration) · Observe pest/disease · Treat pest/disease
· Fertilize or amend · Mulch (rows or zones, type) · Photos. Each writes one
`garden_event`; watering a zone also fans out a derived water record to every
living plant in the zone's rows (stored as `plant_event` rows with
`source_garden_event_id` so it is not double-counted).

### 4.8 View Garden
Garden report: layout summary, all plants (living and culled) with state and
days-since-start, garden events, photos, weather charts for the garden's
period, per-row yield totals. "Download PDF" (Phase 4).

### 4.9 Lists (user-set variables)
One screen, one generic table (§5.6). Lists: seed sources, seed-starting
soils, seed-starting vessels, up-pot soils, up-pot containers, fertilizers
(sow), fertilizers (garden), nurseries, water methods (free or tied to a
garden/zone), containers (size, description), hardening schedules (named;
days of week each with a time range; projected duration in days), shade cloths
(brand, % shade), soil amendments, pest/disease treatments, pests/diseases
(seeded from research, user can add), cull reasons, mulch types.

### 4.10 Admin
- Create user: username, email, name → generates temporary password, emails
  it (Phase 3; until then, shows it once on screen), forces reset.
- Research import (§9.3).
- Regions needing research (§9.4).

---

## 5. Data model

All tables `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
`created_at`/`updated_at` UTC `DATETIME`. Every user-owned table carries
`user_id` and every query filters on it — enforced in one repository base
class, not per query.

### 5.1 Accounts
- `user` — id, username (unique), email, name, role (`admin|user`),
  password_hash (bcrypt cost 11), must_reset_password, zip, county_fips,
  region_id (nullable), latitude, longitude, timezone (IANA, from zip),
  email_digest_enabled, email_unsubscribe_token, created_at, last_login_at.
- `auth_token` — rotating selector/verifier token rows per hosting §8.3.
- `login_attempt` — rate limiting (10 / 15 min / 60 s lockout, per hosting
  §8.4).

### 5.2 Reference (global, admin-imported)
- `region` — region_key (unique), country, state, county, label, usda_zone,
  region_scheme, region_code, frost dates (six DATE-less `MM-DD` CHAR(5)),
  growing_season_days, frost_stations, research_status, confidence, source,
  notes, dataset_version.
- `plant_type` — category, type (unique together), plant_family, latin_name,
  lifecycle, is_tree, dtm_days_min/max, dtm_counted_from, spacing_in,
  seed_depth_in, germ_days_min/max, germ_soil_temp_f_min/max, sun, kc_ini,
  kc_mid, kc_end, stage_days_ini/dev/mid/late, typical_start_method,
  weeks_before_transplant_to_start, hardening_days_default, heat_tolerant,
  confidence, source, notes, dataset_version.
- `plant_region` — region_id, plant_type_id, season, window_start,
  window_end, method, recommended, dtm overrides, confidence, source,
  regional_notes; unique (region_id, plant_type_id, season).
- `pest` — pest_key (unique), name, kind, description, signs, treatments,
  source.
- `pest_region` — region_id, pest_id, active_start, active_end,
  affects_categories, gdd_base_f, gdd_threshold, gdd_biofix, confidence,
  source, regional_notes.
- `region_guidance` — region_id, topic, applies_to_categories, show_from,
  show_to, guidance, confidence, source.
- `research_import` — id, dataset_version, region_keys, filename, sha256,
  imported_by, imported_at, row_counts JSON, status.
- `zcta` — zip (PK), latitude, longitude, county_fips, state, place_name
  (§8.3).

### 5.3 Plants and events (the spine)
- `planting` — id, user_id, plant_type_id, garden_id (nullable), garden_row_id
  (nullable), container_id (nullable), label (optional user nickname),
  start_method (`indoor_seed|direct_sow|nursery_transplant`), start_date,
  quantity_initial, quantity_live (cached; recomputed from events),
  state (cached, derived), state_changed_at, in_ground_date (date of transplant
  or direct sow; NULL until planted), ended_at (culled/dead/harvest-complete),
  default_water_method_id, trellis_used, collar_used, seeds_per_collar,
  initial_height_in, initial_width_in, notes.
- `plant_event` — id, user_id, planting_id, event_type, event_date (DATE, the
  user's local calendar day), recorded_at (UTC), quantity_delta (signed INT,
  NULL when not a count change), duration_min, weight_g, count_qty, unit,
  narrative, ref_list_item_id (the dropdown choice: fertilizer, mulch type,
  pest, treatment, container, soil…), ref_list_item_id_2 (second choice where
  an action takes two, e.g. up-pot soil + container), garden_id, garden_row_id,
  container_id (for transplant/moves), source_garden_event_id (fan-out from a
  garden watering), payload JSON (anything else).
  Index (planting_id, event_date), (user_id, event_date), (event_type).

`event_type` vocabulary: `seed_started, direct_sown, transplanted_in
(nursery), watered, germinated, germination_failed, died, up_potted,
hardening_started, hardening_schedule_set, transplanted, culled, yielded,
pest_observed, pest_treated, fertilized, amended, mulched, photo_added,
note, moved`.

**State derivation** (recompute after any event insert/edit/delete, store in
`planting.state`):
`seed_started` → `hardening` on `hardening_started` → `planted` on
`transplanted` or when start_method is `direct_sow`/`nursery_transplant` →
`yielding` after first `yielded` → `ended` when `quantity_live` reaches 0 or
on `culled` of the remainder. Backdated events re-derive; the timeline is
always sorted by `event_date` then `recorded_at`.

**Quantity attrition**: `quantity_live = quantity_initial + SUM(quantity_delta)`.
`germinated` has delta 0 but sets a marker; `germination_failed`, `died`,
`culled` carry negative deltas. Germination rate, survival rate and cull rate
are derived, never stored.

- `photo` — id, user_id, planting_id or garden_id, plant_event_id or
  garden_event_id (nullable), taken_on (DATE, from EXIF if present else
  event_date), stored_name (random), width, height, bytes, created_at. Files
  in `var/photos/<user_id>/`, served through a controller that checks
  ownership (never a direct URL).

### 5.4 Gardens
- `garden` — user_id, name, is_indoor, ns_ft, ew_ft, row_count,
  row_orientation, soil_type, notes.
- `garden_row` — garden_id, ordinal, name, sun_exposure, shade_cloth_id,
  notes.
- `water_zone` — garden_id, name, water_method_id.
- `water_zone_row` — water_zone_id, garden_row_id.
- `container` — user_id, name, size, description, soil_type.
- `garden_event` — mirrors `plant_event` (event_type, event_date, duration,
  ref_list_item_id, narrative, payload) plus water_zone_id and a
  `garden_event_row` child for mulch-by-rows.

### 5.5 Hardening
- `hardening_schedule` — user_id, name, duration_days, is_default;
  `hardening_schedule_day` — schedule_id, weekday (0–6), time_from, time_to.
  A planting's `hardening_started` event stores schedule_id and duration in
  payload; the countdown is `start_date + duration_days − today`.

### 5.6 Lists
- `user_list_item` — user_id, list_type (ENUM of the §4.9 lists), name,
  attr_1, attr_2 (size/brand, description/percentage), garden_id, water_zone_id
  (for tied water methods), is_active, sort_order; unique (user_id, list_type,
  name). Pests/diseases and cull reasons are seeded per user from the global
  reference on account creation so the dropdowns are not empty.

### 5.7 Weather (extends `docs/weather.md` §6)
- `weather_location` — as in weather.md, plus `zip`. One row per distinct
  zip among active users; created at onboarding, `backfill_from` = earliest
  `start_date` of any planting at that location (recomputed when a user
  backdates a plant; the nightly sync fetches the gap).
- `weather_daily` — as in weather.md.
- `weather_forecast` — location_id, forecast_date, issued_at, temp_max_c,
  temp_min_c, precip_mm, precip_prob_pct, precip_hours, et0_mm, rh_mean_pct,
  wind_max_kmh, soil_moist_0_7, soil_temp_0_7_c, weather_code; PK
  (location_id, forecast_date). Overwritten each run; a hash of the 3-day
  block is stored on `weather_location.forecast_hash` for MOTD re-post logic.
- `weather_alert` — location_id, nws_id (unique), event, severity, headline,
  onset, expires, fetched_at, is_active.
- `weather_sync_run` — as in weather.md, plus `kind` (`archive|forecast|
  alerts`).

### 5.8 Recommendations, reminders, mail (Phase 3)
- `watering_recommendation` — user_id, garden_id, for_date, tier
  (`water|likely|skip`), deficit_mm, reason_text, computed_at; unique
  (garden_id, for_date).
- `reminder` — user_id, planting_id (nullable), kind, due_date, title, body,
  dismissed_at, sent_at; unique (user_id, planting_id, kind, due_date).
- `email_outbox` — user_id, kind, to_email, subject, body_text, body_html,
  status, attempts, last_error, created_at, sent_at. Cron drains it; nothing
  sends inline in a request.

> **Correction, Phase 3 build, 2026-08-31.** Neither unique key above can be
> built as written. `planting_id` is NULL on five of the eleven reminder kinds
> and `garden_id` is NULL for every container recommendation (§11 evaluates
> containers as their own gardens), and **MySQL permits any number of NULLs in
> a unique index** — so both keys would enforce nothing on exactly the rows
> that need them. Every watering reminder would be written again on every run.
>
> Both tables carry an extra NOT NULL key column that the index is built on
> instead: `reminder.subject_key` and `watering_recommendation.place_key`. The
> nullable foreign keys remain, for the cascade. See
> `PHASE-3-HANDOFF.md` §9.1.

---

## 6. Dates and time

- Server and DB are UTC. `plant_event.event_date` and `garden_event.event_date`
  are the **user's local calendar day** (DATE), because they must join to
  `weather_daily.obs_date`, which is a local calendar day (weather.md §6.3).
- "Today" for defaults is computed in the user's timezone
  (`DateTimeImmutable` with the IANA name from `user.timezone`), never from
  the server clock directly.
- `recorded_at`, `created_at`, `fetched_at` are UTC.

---

## 7. Security and sessions

Exactly hosting §8: every session ini overridden before `session_start()`;
cookie `CARLSESS`, path `/carl/`, HttpOnly, Secure, SameSite=Lax; private
0700 save path; DB-backed rotating login token; bcrypt cost 11; CSRF token on
every POST; output escaping everywhere; CSP, nosniff, X-Frame-Options DENY,
no-store on personal pages; CSV export cells guarded against formula
injection. Photos served only through an ownership-checking controller.
Admin routes require role admin **and** are hidden (404) to users.

---

## 8. Weather, forecasts, alerts, zip lookup

### 8.1 Historical
As `docs/weather.md`: nightly cron, Open-Meteo `/v1/archive`, rolling 14-day
window plus gap dates, `is_provisional` handling, NCEI fallback, single-digit
calls per day. **Backfill on backdating**: when a planting's `start_date` (or
`in_ground_date`) is earlier than the location's oldest `obs_date`, set
`backfill_from` back and let the nightly run fetch it, chunked by year
(weather.md §2). If the user opens a report before the gap is filled, the
report renders the gap and says so.

### 8.2 Forecast
Add `bin/weather_sync.php --forecast`, run by the same cron after the archive
step: one call per location to `https://api.open-meteo.com/v1/forecast` with
`forecast_days=7`, `past_days=7`, `timezone=<location tz>`, daily variables
matching `weather_forecast` columns, hourly `soil_moisture_0_to_1cm,
soil_moisture_1_to_3cm, soil_moisture_3_to_9cm` aggregated to a daily mean.
`past_days` rows are also upserted into `weather_daily` with
`source_model='forecast_past'` and `is_provisional=1`, so the archive's 5-day
lag never leaves a hole in yesterday's MOTD; the archive run overwrites them
when ERA5 arrives.

### 8.3 Zip → coordinates → county
- `db/seed/zcta.csv`: Census ZCTA gazetteer joined to the ZCTA-to-county
  relationship file (public domain), columns `zip, lat, lon, county_fips,
  state, place`. Loaded by a migration **in chunks of 2,000 rows per
  statement** (≈33k rows; must complete under 30 s from `/setup`).
- Onboarding looks up `zcta` first. If absent, call Zippopotam.us
  (`https://api.zippopotam.us/us/<zip>`) once, store the result in `zcta`
  with `source='zippopotam'`, county_fips NULL, and flag for admin.
- Timezone from a small state→IANA table with the handful of split-state
  exceptions; refine to a proper polygon lookup only if a user is ever placed
  wrong.
- Region resolution: `region.region_key = 'US-' + county_fips`. No match →
  `user.region_id` NULL and §9.4 applies.

### 8.4 NWS alerts (Phase 3, US only)
`bin/alerts_poll.php` every 3 hours via cron: for each location, GET
`https://api.weather.gov/alerts/active?point=<lat>,<lon>` with a descriptive
User-Agent. Store events matching `Freeze Warning, Frost Advisory, Hard Freeze
Warning, Heat Advisory, Excessive Heat Warning, Flood Watch/Warning, Severe
Thunderstorm Warning, High Wind Warning`. New alerts of the frost/freeze/heat
classes generate a reminder and, if the user's digest already went out today,
one immediate email. Egress to `api.weather.gov` is a Phase 0 spike.

---

## 9. Research: regional knowledge and the import route

### 9.1 What the research tables drive
- Category/type dropdowns and the research card on every plant form.
- "Recommended for your area" markers and season windows.
- DTM countdowns (`dtm_counted_from` decides the anchor event).
- Start-seeds-by dates: `window_start − weeks_before_transplant_to_start`.
- Watering model (Kc curve and stage lengths).
- Pest scouting reminders (calendar window now, GDD threshold v2).
- MOTD region guidance.
- Report citations (`source` and `confidence` shown per value).

### 9.2 Template
`research-template/README.md` is the contract. `template_version` is checked
on import; a bump requires a code change and a migration note.

### 9.3 Import route `/admin/research-import`
1. Upload the zip (≤ 2 MB). Store in `var/imports/` with a random name.
2. Unzip with `ZipArchive` (extension present) to memory; reject entries other
   than the seven known filenames; reject anything over 5 MB uncompressed.
3. Validate every file completely: headers exact, required cells present,
   enums valid, dates parse, every `plant_region` row's (category,type) exists
   in `plant_types.csv` or already in the DB, every `pest_region.pest_key`
   likewise, `dataset_version` greater than the last import for each region.
4. Show a preview: per-file counts of new vs. changed rows, and the first 20
   validation errors if any. Nothing is written until the admin confirms.
5. On confirm: one transaction (pure DML, so it can be), upserts in
   dependency order with `INSERT … ON DUPLICATE KEY UPDATE`, 200 rows per
   statement. Record `research_import`. Set `region.research_status =
   'researched'` for regions in the manifest.
6. Idempotent: uploading the same zip twice changes nothing and says so.

### 9.4 Regions needing research
Admin page listing every distinct `county_fips` among users whose
`region_id` is NULL or whose region has `research_status <> 'researched'`,
with user count, first-seen date, and the zip/place. This is the queue the
owner brings to Claude. Until a region is researched, users there get:
category/type list from the global `plant_type` table (all rows, none marked
recommended), no windows, no guidance lines, and DTM countdowns still work
(DTM is global). Frost-date-dependent reminders are suppressed with a one-line
explanation.

### 9.5 Updating research
Re-importing a newer `dataset_version` overwrites reference values. Plantings
reference `plant_type_id`, so history is unaffected. Reports read current
values, and show the dataset version they used.

---

## 10. Photographs

Client: canvas resize in a Web Worker (main-thread fallback), 1920 px long
edge, JPEG q0.85, step quality down then dimensions until ≤ 1.5 MB; one photo
per XHR request with progress; the form never posts the photo with the other
fields. Server: validate with `getimagesize`, guard against decompression
bombs (reject > 40 MP), re-decode and re-encode with GD (strips EXIF payloads;
read the date-taken first if present), random filename under
`var/photos/<user_id>/`, 0644, DB row with dimensions. Detect the empty
`$_POST`/`$_FILES` case that means `post_max_size` was exceeded and return a
clear error. Thumbnails (320 px) generated at upload for lists.

---

## 11. Watering recommendation (Phase 3)

Computed nightly per garden by `bin/weather_sync.php --recommend` after
weather is in, stored in `watering_recommendation`, shown on the MOTD and in
the digest. Never computed at render.

Model (FAO-56 checkbook, simplified for gardeners):

- Per garden: `soil_type` → total available water in the root zone (TAW, mm)
  and management-allowed depletion (MAD = 50 % TAW): clay 60/30, loam 50/25,
  sandy 35/17, raised-bed mix 45/22, container 20/10.
- Per planting: `Kc` for today from the plant type's curve and days since
  `in_ground_date` (or start_date for seedlings); the garden's Kc is the max
  across its living plantings.
- Daily balance: `D = clamp(D_prev + ET0 × Kc − rain_eff − irrigation_applied,
  0, TAW)`. `rain_eff = min(precip_mm, 25) × f`, where `f` = 0.8, reduced to
  0.5 when `precip_hours ≤ 1` on clay (runoff). Mulched gardens (any `mulched`
  event in the last 60 days) scale ET by 0.85.
- `irrigation_applied` from logged watering: drip zones with a configured
  flow rate → depth; otherwise a per-method default depth per 10 minutes
  (hand/can 3 mm, hose 6 mm, sprinkler 5 mm) stated in the reason text so the
  user can correct the assumption.
- Tier: **water** if `D ≥ MAD` and forecast `rain_prob < 50 %` or forecast
  `precip_mm < D`, or forecast Tmax ≥ 35 °C and `D ≥ 0.4·MAD`; **likely**
  if `D ≥ 0.4·MAD` and meaningful rain is probable within 48 h; else
  **skip**.
- Reason text is one sentence with the numbers ("Deficit 27 mm after 4 dry
  days; 20 % chance of rain tomorrow").
- Containers: always evaluated as their own "garden" with the container TAW.

---

## 12. Reminders and the daily digest (Phase 3)

`bin/daily_digest.php` runs hourly; for each user whose local time is between
06:00 and 07:00 and who has not been sent today's digest, it computes reminders
and queues one email if there is anything to say. Silence is the default.

Reminder kinds (computed, stored in `reminder`, deduplicated by unique key):

| Kind | Rule |
| --- | --- |
| hardening_countdown | `hardening_started` + duration − today, daily while > 0; "transplant due" at 0 |
| transplant_window | user has seedlings of a type whose region window opens in 7 days / today / closes in 7 days |
| start_seeds_by | region window_start − weeks_before_transplant_to_start, 14 and 7 days out |
| first_harvest_expected | anchor date + dtm_days_min, 7 days out and on the day |
| harvest_window_closing | anchor + dtm_days_max + 14, if no `yielded` event yet |
| frost_watch | region first_frost_early − 14 days, then any NWS freeze/frost alert |
| heat_watch | forecast Tmax ≥ 35 °C tomorrow and user has heat-sensitive plantings |
| pest_scouting | pest_region active_start for categories the user grows (calendar; GDD v2) |
| watering | today's tier is water or likely |
| inactivity | no events in 7 days (one nudge, then silent until activity resumes) |
| research_diff | a planting's dates fall outside the research window (once) |

Email: plain-text first with a simple HTML twin; subject "Carl: N items for
today"; `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
headers pointing at a tokenised route; per-user opt-out; sent from
`carl@reshiftmanager.com`.

### 12.1 Mail drivers
`Mailer` interface, two implementations, chosen in `config/local.php`:
- `SmtpMailer`: SMTP-AUTH over TLS (port 465 or STARTTLS 587) to the cPanel
  mailbox, using `stream_socket_client` + `openssl` (no Composer; vendoring
  PHPMailer's single-file build is acceptable if hand-rolling proves brittle).
- `ApiMailer`: HTTPS POST to Brevo `/v3/smtp/email` via curl, key in
  `local.php`.
Both write to `email_outbox` first; the cron sends with bounded retries.

**Owner action — create the mailbox and authenticate the domain (cPanel):**
1. Email Accounts → Create: `carl@reshiftmanager.com`, strong password, 250 MB
   quota. Note the outgoing server/ports shown in "Connect Devices".
2. Email Deliverability → `reshiftmanager.com` → Manage.
3. SPF: install the suggested record. If Brevo is adopted later, merge into a
   single record: `v=spf1 +mx +a include:spf.brevo.com ~all` (one SPF record
   only).
4. DKIM: "Install the suggested record" (automatic if DNS is on the host).
5. DMARC: add TXT `_dmarc.reshiftmanager.com` = `v=DMARC1; p=none;
   rua=mailto:carl@reshiftmanager.com`.
6. Put SMTP host, port, username, password in `config/local.php` via File
   Manager (0600).
7. Send yourself a test from `/admin/mail-test?key=` (Phase 3 route) and check
   headers for `spf=pass dkim=pass`.

> **Correction, Phase 3 build, 2026-08-31.** Step 7's route is
> `/admin/mail-test`, admin-guarded rather than key-guarded, and it **queues**
> rather than sends. A key-guarded route that mails an address from the query
> string is an open relay to anyone who ever sees the key, and a key travels
> in a URL — through a browser bar and the account's access log. The
> destination is fixed to the signed-in admin's own address, and the drain
> sends it, because §5 of the Phase 3 handoff forbids a third-party call on
> the request path. See `PHASE-3-HANDOFF.md` §9.2.

---

## 13. Reports, PDFs, exports

### 13.1 Plant and garden reports (Phase 4)
Server-rendered HTML; charts drawn with Chart.js from a JSON endpoint
(`/api/plant/<id>/series`), one statement for weather + one for events. On a
plant: temp max/min band, rainfall bars, ET₀ line, events as markers; one
weather series visible at a time on mobile, toggled. Provisional days marked.
Attribution line generated from `source_model` (weather.md §10).

### 13.2 PDF download
"Download PDF" posts the visible chart canvases as PNG data URLs (well under
2 MB each) to `/report/plant/<id>/pdf`, which builds the document with FPDF:
research card, event table, charts, photos (GD-downscaled to 800 px, max 20
per report, a note if truncated), citations. Streams the file; nothing kept.
Budget: under 10 s and 64 MB on a 20-photo report; measure in Phase 4.

### 13.3 CSV export (Phase 2)
`/export/plants.csv`, `/export/events.csv`, `/export/weather.csv` for the
user's own data, formula-injection guarded, streamed in 1,000-row chunks.
`/export/claude.json` (Phase 4): one JSON document per user with plantings,
events, gardens, weather for the covered dates, and the research values in
force, in a shape designed for pasting to Claude. This is the v2
"Recommendations" bridge.

### 13.4 Field-recording sheet — **Built, Phase 6**
Printable A4/Letter sheet mirroring the Log Plant Activity and Garden Actions
fields: plant/garden identifier, date, action tick boxes, quantity, duration,
dropdown-name blanks, narrative lines, photo count. A per-garden prefilled
version lists the rows and the living plants.

**Delivered as a generator, not a static file.** This section said "a static
PDF in `public/assets/field-sheet.pdf`", which was written in Phase 1 before
there was any PDF layer. Phase 4 built one, and this section also asks for a
prefilled version "using the same layout" — so a checked-in binary would be a
second artefact from one layout, and the one that goes stale silently because
nothing tests it. `Carl\Reports\FieldSheet` is the layout; the blank sheet is
that class with no garden and the prefilled one is that class with a garden.
Three routes under `/reports/`.

Sized 210 × 270 mm: A4 is the narrower and Letter the shorter, so one file
prints on either without a shrink-to-fit that would take the writing lines
under the ~7 mm a pen needs. Black on white with no grey — a dotted or
60%-grey rule is a sub-pixel mark a 600 dpi mono engine halftones into a
broken line or drops.

### 13.5 Logo and colour scheme — **Claude Design, still outstanding**
Garden palette, mobile-first; deliver CSS variables (`--carl-*`) and an SVG
logo. Claude Code uses the variables as given.

---

## 14. Phasing

### Phase 0 — spikes (one temporary key-guarded diagnostic route, deleted after)
1. Outbound HTTPS from sh193 to `archive-api.open-meteo.com`,
   `api.open-meteo.com`, `api.weather.gov`, `api.zippopotam.us`,
   `www.ncei.noaa.gov`. Status, time, first 200 bytes. **If Open-Meteo fails,
   stop and rescope.**
2. PHP CLI path for cron (`/usr/local/bin/php`, `/usr/local/bin/ea-php82`,
   `/opt/alt/php82/usr/bin/php`); a cron job that writes `php -v` to `var/`.
3. A real cron run of a stub that inserts a `weather_sync_run` row.
4. SMTP-AUTH send from the cPanel mailbox (after the §12.1 owner action) and,
   separately, one Brevo API send. Record which one lands in Gmail's inbox.
5. Time a 200-row multi-row upsert and a 2,000-row chunk (for the ZCTA load).

### Phase 1 — **Alpha** (accounts logging real data)
Foundation: repo, `.cpanel.yml`, CI (PHP 8.2, MySQL 8.0 + MariaDB 10.11
legs, `php -l`, mode 100644 assertion, migrate-twice idempotence, cpanel.yml
lint), bootstrap, autoloader, base_path, sessions, auth, `/status`, `/setup`.
Migrations 001–0NN: accounts, reference, zcta, gardens, lists, planting,
events, photos, weather tables. Seed: admin/1234 forced reset; first research
import applied by the admin through the route (not a migration).
Screens: login/reset, onboarding wizard, main menu with region guidance (no
weather yet if cron is not live; the box says "weather arrives nightly"),
Start a New Plant (all three), Log Plant Activity (all actions), View Plants
(list + timeline + photos, no charts), Build Garden, Garden Actions, View
Garden (list form), Lists, Admin (create user with on-screen temp password,
research import, regions queue).
Weather: archive + forecast sync live via cron for the tester zip; MOTD
matrix populated.
**Alpha acceptance:** a second user can be created, log in, be forced to
reset, onboard with zip 76692, get the Indoor Garden and a built garden, start
a plant of each of the three kinds with backdated dates, log every action type
with a photo from a phone, see the timeline, and see yesterday's weather on
the main menu the next morning. Both users see only their own data.

### Phase 2 — hardening the log
Batch actions with filters; hardening schedules and countdown display; row
occupancy hint; CSV exports; backfill-on-backdate verified against a plant
started 60 days ago; field sheet linked; `/status` extended with weather
health per weather.md §3.2.

### Phase 3 — reminders, email, watering, alerts
Mail drivers and outbox; account-creation email; daily digest with the §12
reminders; watering model; NWS alerts poll; MOTD re-post on forecast change;
unsubscribe route.

### Phase 4 — reports and PDFs
Chart.js plant/garden charts; FPDF plant and garden PDFs; per-garden prefilled
field sheet; `/export/claude.json`.

**Built 2026-08-31**, except the per-garden prefilled field sheet, which needs
the static sheet of §13.4 first. See `docs/PHASE-5-HANDOFF.md`.

### Phase 5 — v2, the first half

Reports menu; Recommendations (Claude analysis) as a cron-driven queue; End
Growing Season; crop rotation warnings by plant family. Plus the tokenised
set-password link Phase 3 deferred (§9.4 of that handoff).

**Built 2026-08-31.** Migrations 016 and 017; six cron jobs now, not five.
Three v2 items are not built and are described in `docs/PHASE-6-HANDOFF.md`:
GDD pest reminders, succession planting, and the companion planting reference.

### Phase 6 — v2, the second half

GDD pest reminders; succession planting, as both a planner and a digest
reminder; the companion planting reference, with the template version that
carries it. Plus the field-recording sheet of §13.4, which had blocked two
phases, and the three things Recommendations was left wanting.

**Built 2026-08-31.** Migration 018; `research-template` template_version 2;
thirteen reminder kinds, not eleven. **v2 is complete.** See
`docs/PHASE-7-HANDOFF.md`.

### v2 — complete
~~Reports menu~~; ~~Recommendations (Claude analysis)~~; ~~End Growing
Season~~; ~~crop rotation warnings by plant family~~; ~~GDD pest reminders~~;
~~succession planting~~; ~~companion planting reference~~.

---

## 15. Explicitly deferred or dropped

| Item | Status | Reason |
| --- | --- | --- |
| Seed inventory (packet counts) | Dropped | Chore nobody keeps up; germination rate comes from the event log anyway |
| Expense / cost tracking | Dropped v1 | Market-farm feature; revisit if testers ask |
| QR / plant labels, multi-user sharing | Dropped | Scale mismatch; sharing contradicts data isolation |
| Offline / PWA | Deferred | Paper field sheet is the answer for now; service worker only caches the shell |
| Succession planting | **Built, Phase 6** | Both shapes, sharing one calculator: `/succession` lays the season out, and a digest reminder is the one line of it true today. No accepted-plan table — a plan in its own table is a second answer to "when should I sow" |
| Task calendar UI | Deferred | Still the digest's job. The succession planner is the nearest thing and it proposes rather than schedules |
| Crop rotation warnings | **Built, Phase 5** | It was free later, exactly as predicted: one grouped statement over `plant_family` and `garden_row_id` |
| Drip emitter → depth conversion UI | Deferred | Log method + duration; per-method defaults until data shows people enter specs |
| Runtime plant APIs (Trefle, Permapeople) | Dropped | Outage history; used only as sources when producing research zips |
| GDD pest thresholds | **Built, Phase 6** | The biofix was validated first, as the handoff asked: 1000 DD50 from 01-01 lands 18 Apr – 6 May over 2019–2025 at Hillsboro, which is the April/May emergence AgriLife reports. The threshold transfers; the calendar date is what moves |
| Companion planting reference | **Built, Phase 6** | The one v2 item with no data behind it, so the one that touched the template contract. A reference only: nothing acts on it, because most of the evidence will not carry that weight |

---

## 16. Open items for the owner

1. Add the plant research file (or confirm the first dataset zip is the whole
   set for now).
2. Do the §12.1 mailbox and DNS steps before Phase 0 spike 4.
3. Confirm with Ahosting whether OPcache can be enabled
   (`ea-php82-php-opcache`); if yes, `opcache.validate_timestamps` becomes a
   deploy concern (hosting §12).
4. Email Open-Meteo describing Carl (internal, unsold, no ads) and keep the
   reply in `docs/` (weather.md §10).
5. Claude Design: logo, palette, field sheet (§13.4–13.5).
6. Fill hosting §11 blanks: `/home/reshiftmanager/public_html/carl`,
   `/home/reshiftmanager/carl-app`, `base_path /carl/`, cookie `CARLSESS`,
   database `reshiftmanager_carl`, env prefix `CARL_`.
7. **An Anthropic API key** in `config/local.php`, if Recommendations is
   wanted (Phase 5; `deploy.md` §7.6). Nothing breaks without it — requests
   queue and wait, exactly as mail did before the mailbox existed.

---

## 17. Working agreement with Claude Code

- Read `docs/hosting.md` and `docs/weather.md` before the first commit; cite
  the section when a constraint shapes a decision.
- Every migration: numbered, immutable after apply, idempotent, safe to
  retry, pure-DDL or pure-DML, never mixed.
- Every query: prepared, bound, user-scoped; count statements per request and
  keep hot paths under 5.
- Every form: CSRF, server-side validation, date fields default to the user's
  local today and accept the past.
- Every third-party call: cron only, bounded retry, logged to a run table,
  never from a request.
- Ask before adding any vendored library beyond Chart.js, FPDF and a mailer.
- When the field sheet, logo or palette are needed, stop and request them from
  Claude Design rather than improvising.
