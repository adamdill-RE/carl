# Weather data — scope

**Primary source: Open-Meteo Historical Weather API (`/v1/archive`) · Fallback: NOAA NCEI Access Data Service · Ingested nightly by cron, never at page render**

This document is a companion to `hostingplatformscope.md` and inherits every
constraint in it. Where a decision here is forced by the hosting account rather
than by the weather provider, the governing section is cited (e.g. §9 → statement
count, §3 → no Composer).

The goal is a fully automated historical weather series, stored locally, joinable
to plant observations by location and date, with no manual step after first
configuration.

Provenance labels match the hosting document:

| Label | Means |
| --- | --- |
| **Measured** | Called the live API on the date given and read the response. Trust it. |
| **Documented** | Stated by the provider's own docs on the date given. |
| **Unverified** | Not tested from sh193. A scoping question, not a fact. |

---

## 1. Recommendation at a glance

| | Primary | Fallback / cross-check |
| --- | --- | --- |
| Provider | Open-Meteo Historical Weather API | NOAA NCEI Access Data Service |
| Endpoint | `https://archive-api.open-meteo.com/v1/archive` | `https://www.ncei.noaa.gov/access/services/data/v1` |
| API key | **None** | **None** |
| Cost | Free | Free |
| Coverage | Global, 1940 → present | US-focused stations, varies by station |
| Model | Reanalysis grid (ERA5 0.25°, ERA5-Land 0.1°, ECMWF IFS 9 km) | Physical station observations (GHCNd) |
| Resolution | Hourly and daily | Daily |
| Variables | ~40 incl. ET₀, VPD, soil moisture/temp, solar radiation | Mostly TMAX / TMIN / PRCP / SNOW |
| Gaps | **None** — reanalysis is spatially complete | Yes — stations move, break, and report late |
| Lag | ~0 days (IFS) to 5 days (ERA5) | ~3 days |
| Licence | CC BY 4.0, attribution required | US Government work / CC0 — public domain |
| Commercial use | **Free tier is non-commercial only** | Unrestricted |
| Rate limit | 10,000 calls/day **per IP** | Not documented; be polite |

Open-Meteo is the primary because it is gap-free and carries the variables that
make a plant-performance correlation meaningful. A reanalysis grid gives a value
for *your coordinates* on *every* date; a station network gives you whatever the
nearest airport happened to record, with holes.

NCEI stays in scope for two reasons: it is the escape hatch if the commercial-use
question in §10 goes the wrong way, and it is a genuine cross-check — when a
correlation looks surprising, being able to ask "did the nearest real thermometer
agree?" is worth the small amount of extra code.

---

## 2. What this costs the platform

Almost nothing, and that is the point. The whole feature is one nightly outbound
HTTPS call per location and a handful of bulk upserts.

| Hosting constraint | Effect here |
| --- | --- |
| **Outbound HTTP unverified** (hosting §12) | **This is the blocking spike.** If egress from sh193 is filtered, the entire feature is impossible as designed. Test before anything else — see §11 |
| **Cron available** (confirmed 2026-08-30) | The automation path exists. Without it this would need a human to load a page |
| `max_execution_time` 30 s (hosting §4) | Applies to the web SAPI. Chunk the backfill; make every run resumable |
| **No Composer** (hosting §3) | Raw `curl` extension (present, hosting §4) and `json_decode`. No Guzzle, no SDK |
| **Remote database** (hosting §2.1) | Every statement is a round trip. Bulk-upsert 200 rows per statement, never one statement per day |
| **No `RETURNING`** (hosting §2.2) | Nothing here needs it — upserts by natural key |
| Window functions available (hosting §2.2) | Cumulative GDD and rolling rainfall are `SUM() OVER (...)`, computed at read time, not stored |
| Prefer `VIRTUAL` generated columns (hosting §2.2) | Derived values like mean temperature can be `VIRTUAL`. Do **not** make them `STORED` — hosting §2.2's error 1215 trap |
| `memory_limit` 128M (hosting §4) | A 5-year daily backfill JSON for one location is well under 1 MB. Hourly for 5 years is ~15 MB decoded — chunk hourly by year |
| **No shell** (hosting §3) | cPanel Cron Jobs *does* execute commands, so a CLI entry point works. Keep a key-guarded browser route as the manual fallback, per hosting §6.3 |

---

## 3. Automation model

```
cPanel Cron Jobs (nightly, ~09:15 UTC)
        │
        ▼
bin/weather_sync.php  ── CLI entry point, no HTTP context
        │
        ├─ for each active weather_location:
        │     ├─ compute the date range to fetch (see §6.2)
        │     ├─ one HTTPS GET to archive-api.open-meteo.com
        │     ├─ decode, map to rows
        │     └─ bulk upsert 200 rows/statement
        │
        └─ write one weather_sync_run row (success or failure, always)
```

Two rules that make this safe to run unattended:

1. **Idempotent.** The natural key is `(location_id, obs_date)` with an
   `INSERT … ON DUPLICATE KEY UPDATE`. Running the job twice, or re-running after
   a partial failure, converges to the same state. This is the same discipline
   hosting §10 requires of migrations, for the same reason: there is no staging.
2. **Never on the request path.** No page render, no poll endpoint, and no report
   may call the weather API. A page reads `weather_daily` and nothing else. If the
   data is not there, the UI renders the gap — it does not go fetch it. This is
   what stops a third-party outage from taking the app down with it.

### 3.1 The cron entry

cPanel → Cron Jobs, once daily. Preferred form:

```
/usr/local/bin/php -q /home/<account>/<app>-app/bin/weather_sync.php >/dev/null 2>&1
```

**The PHP CLI binary path is Unverified** — `/usr/local/bin/php`,
`/usr/local/bin/ea-php82` and `/opt/alt/php82/usr/bin/php` are all plausible on
CloudLinux. Confirm it in the §11 spike; if none resolves, fall back to:

```
/usr/bin/curl -s "https://<domain>/<app>/tasks/weather-sync?key=<cron_key>" >/dev/null 2>&1
```

The curl form runs under the web SAPI, so it inherits the 30 s ceiling and must
chunk. The CLI form usually gets `max_execution_time=0`, but **do not depend on
it** — write the job to chunk either way and the two forms stay interchangeable.

Redirect output. cPanel emails the account on every run otherwise, and a nightly
job that mails 365 times a year trains everyone to ignore it.

### 3.2 The manual route

Mirror hosting §6.3's pattern exactly: `/tasks/weather-sync?key=…`, guarded by a
`cron_key` in the gitignored local config, **404 without the key rather than 403**.
It exists so a human can force a resync after an outage without waiting for
midnight, and so the same code path is exercisable from a browser on an account
with no shell.

Extend `/status` to report: last successful run per location, the newest
`obs_date` held, the count of missing dates inside the covered range, and the last
non-200 HTTP status seen. Those four numbers are the whole health picture.

---

## 4. The API contract

### 4.1 Request

**Documented 2026-08-30** from Open-Meteo's Historical Weather API docs.

| Parameter | Value | Note |
| --- | --- | --- |
| `latitude` / `longitude` | decimal degrees | **Comma-separate for multiple locations in one call** — the response becomes a list. This is the single biggest call-count saving |
| `start_date` / `end_date` | `YYYY-MM-DD` | Inclusive |
| `daily` | comma-separated variables | See §5 |
| `hourly` | comma-separated variables | Only where genuinely needed — see §5.2 |
| `timezone` | e.g. `America/Detroit` | **Required when `daily` is used.** Daily buckets are then local-calendar days |
| `elevation` | metres | Optional. Set it for a site whose elevation differs from the 90 m DEM's guess |
| `cell_selection` | `land` (default) | Correct for a growing site. `nearest` only if the site is coastal and you want the literal cell |
| `temperature_unit` etc. | omit | Store SI, convert at display — see §6.3 |

Example — five days of daily data for one site:

```
https://archive-api.open-meteo.com/v1/archive
  ?latitude=42.4895&longitude=-83.1446
  &start_date=2026-08-01&end_date=2026-08-05
  &timezone=America%2FDetroit
  &daily=temperature_2m_max,temperature_2m_min,temperature_2m_mean,
         precipitation_sum,precipitation_hours,
         et0_fao_evapotranspiration,shortwave_radiation_sum,
         relative_humidity_2m_mean,relative_humidity_2m_min,
         wind_speed_10m_max,weather_code,
         daylight_duration,sunshine_duration
```

### 4.2 Response shape

Column-oriented, not row-oriented — parallel arrays indexed by position:

```json
{
  "latitude": 42.496906, "longitude": -83.14098, "elevation": 200.0,
  "utc_offset_seconds": -14400, "timezone": "America/Detroit",
  "daily": {
    "time": ["2026-08-01", "2026-08-02"],
    "temperature_2m_max": [28.6, 26.4],
    "precipitation_sum": [0.0, 3.2]
  },
  "daily_units": { "temperature_2m_max": "°C" }
}
```

Two consequences for the parser:

- **Zip the arrays by index.** Assert every array has the same length as `time`
  before iterating, and fail the run loudly if not. A silently short array
  produces silently wrong rows.
- **`null` is a legitimate value** inside an array, not an error. Store it as SQL
  `NULL`. Do not coerce to `0` — a null ET₀ and a zero ET₀ mean opposite things.

### 4.3 Errors

An invalid parameter returns **HTTP 400** with `{"error": true, "reason": "…"}`.
A quota breach returns **429** with the same envelope. Check for the `error` key
on every response regardless of status code, and log `reason` verbatim into
`weather_sync_run` — it is specific enough to fix the bug from the log alone.

---

## 5. Variables to store

> **Assumption to confirm:** this list is written for *growing* plants — crops,
> nursery stock, greenhouse or field plantings. If "plant" means a production
> facility, the ingestion design in §3, §6 and §7 is unchanged, but swap the
> variable list for `temperature_2m_mean`, `relative_humidity_2m_mean`,
> `dew_point_2m_mean`, `wet_bulb_temperature_2m_mean` and
> `shortwave_radiation_sum` — the drivers of HVAC load, condensation and solar
> yield. Everything else in this document stands either way.

### 5.1 Daily — store all of these from day one

Backfilling a variable you skipped costs one API call per location, so there is
no reason to be frugal. Storage is trivial: 13 columns × 365 days × 10 years is
under 4 MB.

| Variable | Why it earns its place |
| --- | --- |
| `temperature_2m_max`, `temperature_2m_min` | The inputs to growing degree days. Store these, not GDD — see §7.1 |
| `temperature_2m_mean` | Convenience; also the honest average when max/min hide a flat day |
| `precipitation_sum` | Water in |
| `precipitation_hours` | 20 mm in one hour runs off; 20 mm over eight hours soaks in. The distinction shows up in performance and a daily sum alone hides it |
| `et0_fao_evapotranspiration` | Water out. **`precipitation_sum − et0` is the single most useful derived series in this whole document** — it is the actual water balance |
| `shortwave_radiation_sum` | Photosynthetic energy delivered, MJ/m² |
| `sunshine_duration`, `daylight_duration` | Cloud-adjusted light hours vs. astronomical daylength. Daylength drives photoperiod responses; the ratio of the two is a clean cloudiness index |
| `relative_humidity_2m_mean`, `relative_humidity_2m_min` | Disease pressure sits in the humidity record, and minimum RH is the stress signal |
| `vapour_pressure_deficit_max` | The direct measure of transpiration demand. Above ~1.6 kPa transpiration climbs; below ~0.4 it stalls |
| `wind_speed_10m_max`, `wind_gusts_10m_max` | Physical damage, and drying |
| `weather_code` | WMO code, for a human-readable day summary in the UI |
| `soil_moisture_0_to_7cm_mean`, `soil_temperature_0_to_7cm_mean` | Root-zone conditions. Requires the ERA5-Land or IFS model, which `best_match` supplies |

### 5.2 Hourly — behind a flag, not by default

Hourly multiplies row count by 24 and API payload by roughly the same. It buys
you exactly three things a daily aggregate cannot express:

- **Frost duration and depth.** "Min 30 °F" and "six hours below freezing" are
  different events with different outcomes.
- **Heat-hours above a threshold.** Hours over 32 °C predicts damage far better
  than a daily maximum.
- **Leaf-wetness proxy.** Consecutive hours at RH > 90 % is the standard input to
  most disease models.

**Recommendation:** ship daily-only. Add an `hourly_enabled` boolean on
`weather_location` and populate `weather_hourly` for flagged sites once there is a
concrete question that needs it. Do not backfill hourly for every location "in
case" — that is 88,000 rows per location-decade against a remote database.

---

## 6. Schema

Names collation explicitly per hosting §2.2. Generated columns are `VIRTUAL` per
hosting §2.2.

### 6.1 Tables

```sql
CREATE TABLE weather_location (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label           VARCHAR(120)  NOT NULL,
  latitude        DECIMAL(8,5)  NOT NULL,
  longitude       DECIMAL(8,5)  NOT NULL,
  timezone        VARCHAR(64)   NOT NULL,   -- IANA name, e.g. America/Detroit
  elevation_m     DECIMAL(7,2)      NULL,   -- as resolved by the API
  hourly_enabled  TINYINT(1)    NOT NULL DEFAULT 0,
  backfill_from   DATE          NOT NULL,   -- earliest date to hold
  is_active       TINYINT(1)    NOT NULL DEFAULT 1,
  created_at      DATETIME      NOT NULL,
  UNIQUE KEY uq_coords (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weather_daily (
  location_id     INT UNSIGNED NOT NULL,
  obs_date        DATE         NOT NULL,
  temp_max_c      DECIMAL(5,2) NULL,
  temp_min_c      DECIMAL(5,2) NULL,
  temp_mean_c     DECIMAL(5,2) NULL,
  precip_mm       DECIMAL(6,2) NULL,
  precip_hours    DECIMAL(4,1) NULL,
  et0_mm          DECIMAL(6,2) NULL,
  radiation_mj    DECIMAL(6,2) NULL,
  sunshine_s      INT UNSIGNED NULL,
  daylight_s      INT UNSIGNED NULL,
  rh_mean_pct     DECIMAL(5,2) NULL,
  rh_min_pct      DECIMAL(5,2) NULL,
  vpd_max_kpa     DECIMAL(5,3) NULL,
  wind_max_kmh    DECIMAL(5,2) NULL,
  gust_max_kmh    DECIMAL(5,2) NULL,
  soil_moist_0_7  DECIMAL(5,3) NULL,
  soil_temp_0_7_c DECIMAL(5,2) NULL,
  weather_code    TINYINT UNSIGNED NULL,
  source_model    VARCHAR(24)  NOT NULL,   -- 'best_match', 'era5', 'ncei:USW00094847'
  is_provisional  TINYINT(1)   NOT NULL DEFAULT 1,
  fetched_at      DATETIME     NOT NULL,
  water_balance_mm DECIMAL(7,2)
      AS (precip_mm - et0_mm) VIRTUAL,
  PRIMARY KEY (location_id, obs_date),
  KEY idx_date (obs_date),
  CONSTRAINT fk_wd_loc FOREIGN KEY (location_id)
      REFERENCES weather_location (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`water_balance_mm` is `VIRTUAL`. Note hosting §2.2's trap: a column feeding a
**STORED** generated column cannot carry `ON DELETE CASCADE` under MySQL 8. The
cascade above is on `location_id`, which feeds nothing generated, so it is safe —
but if anyone later makes a generated column `STORED`, check what it reads first.

```sql
CREATE TABLE weather_sync_run (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location_id   INT UNSIGNED     NULL,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME         NULL,
  range_start   DATE             NULL,
  range_end     DATE             NULL,
  http_status   SMALLINT         NULL,
  rows_upserted INT UNSIGNED NOT NULL DEFAULT 0,
  outcome       ENUM('ok','partial','failed') NOT NULL,
  error_text    VARCHAR(500)     NULL,
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Prune this table to the last 90 days in the same job. An unpruned log table on a
nightly job is a slow-growing bug.

### 6.2 The revision window — why `is_provisional` exists

**Documented 2026-08-30.** Open-Meteo's `best_match` stitches three models with
different lags:

| Model | Lag |
| --- | --- |
| ECMWF IFS 9 km | none — updates every 6 hours |
| ERA5 / ERA5-Land | **5 days** |
| IFS Assimilation | 2 days |

So the value stored for a given date **can and will change** for about a week
after that date, as the definitive reanalysis supersedes the forecast-derived
figure. Ignore this and you get a database where yesterday's numbers quietly
disagree with the same numbers pulled next month, which is exactly the kind of
inconsistency that discredits an analysis.

The handling is cheap:

- Every nightly run re-fetches a **rolling 14-day window** ending yesterday, plus
  any gap dates. Upsert overwrites. 14 days covers the 5-day ERA5 lag with a wide
  margin.
- Rows inside the window are `is_provisional = 1`. On a run, any row now older
  than 10 days is flipped to `0` and never re-fetched again.
- Surface it: a chart that includes the last few days should mark them as
  provisional rather than pretend they are settled.

### 6.3 Units and time

**Store SI, convert at display.** Request without `temperature_unit`,
`wind_speed_unit` or `precipitation_unit` so everything arrives °C / km/h / mm,
and put the conversion in one display helper. Storing Fahrenheit because the UI
shows Fahrenheit means every future analysis, every threshold constant and every
imported dataset has to know which convention that column follows.

Time is the one deliberate departure from hosting §4's "store and compare in UTC".
A daily weather aggregate is inherently a **local-calendar-day** concept: it must
line up with the day a person walked out and recorded an observation. So:

- `weather_daily.obs_date` is a `DATE` in the location's own timezone, produced by
  passing `timezone=America/Detroit` to the API.
- `weather_location.timezone` records which timezone that was, so the meaning is
  never ambiguous.
- `weather_hourly`, if built, stores a UTC `DATETIME` — hourly data has no
  calendar-day problem and follows the hosting rule unchanged.
- Everything else — `fetched_at`, `started_at` — is UTC, per hosting §4.

Never construct a local day boundary with a fixed offset. Michigan observes DST;
`America/Detroit` through `DateTimeImmutable` is the only correct instrument.

---

## 7. Reading it back

### 7.1 Do not store growing degree days

GDD depends on a base temperature that varies by species — 10 °C for maize, 4 °C
for cool-season grass, 7 °C for many woody ornamentals. Storing a single
precomputed GDD column bakes in one crop's assumption and is wrong for every
other. Store `temp_max_c` and `temp_min_c`; compute GDD at read time with the base
as a bound parameter:

```sql
SELECT obs_date,
       GREATEST(((temp_max_c + temp_min_c) / 2) - :base_c, 0) AS gdd,
       SUM(GREATEST(((temp_max_c + temp_min_c) / 2) - :base_c, 0))
         OVER (ORDER BY obs_date)                             AS gdd_cumulative
FROM weather_daily
WHERE location_id = :loc AND obs_date BETWEEN :from AND :to
ORDER BY obs_date;
```

Window functions are available (hosting §2.2) and this is one statement — one
round trip. Note the hosting §7 rule: with `ATTR_EMULATE_PREPARES` off, a named
placeholder **cannot be reused** in one statement. `:base_c` appears twice above,
so **bind it under two distinct names** (`:base_c1`, `:base_c2`) or this fails at
runtime. This is the exact shape of bug that hosting §7 warns about, and GDD is
where it will first appear.

The same pattern gives rolling rainfall, cumulative ET₀ and a running water
balance by swapping the expression.

### 7.2 Joining to plant observations

The join is `(location_id, obs_date)` against the observation's own local date.
Every plant record needs a `location_id` — attach it to the *site or bed*, not to
the individual plant, so a hundred plants in one greenhouse share one weather
series and one API call.

For "conditions leading up to this observation", which is usually the real
question, a lagged window beats a same-day join:

```sql
SELECT o.id, o.observed_on,
       AVG(w.temp_mean_c)  AS temp_7d,
       SUM(w.precip_mm)    AS rain_7d,
       SUM(w.water_balance_mm) AS balance_7d
FROM plant_observation o
JOIN weather_daily w
  ON w.location_id = o.location_id
 AND w.obs_date BETWEEN o.observed_on - INTERVAL 7 DAY AND o.observed_on
WHERE o.id = :id
GROUP BY o.id, o.observed_on;
```

Make the lag window configurable rather than hard-coding 7 days. Different
responses have different memories — heat stress shows in days, water stress in
weeks.

### 7.3 Charting

Weather is context, not the subject. On a plant-performance chart it belongs as a
muted background band or a secondary axis, never competing with the performance
line for attention. On mobile, one weather series at a time — a chart with four
overlaid weather variables is unreadable at 380 px and nobody will pinch-zoom it.

---

## 8. Failure modes

| Failure | Detection | Response |
| --- | --- | --- |
| **429 rate limited** | HTTP 429, or `error: true` with a quota `reason` | Log it, skip the run, retry tomorrow. **Do not retry in a loop** — see below |
| Provider outage / timeout | `curl` error or 5xx | Same. The gap heals on the next successful run because the fetch range is derived from what is missing, not from a cursor |
| Egress blocked at the host | Connection refused or timeout on **every** call | The §11 spike catches this before any code is written |
| Malformed response | `time` array length ≠ variable array length | Fail the run, upsert nothing for that location. A partial row set is worse than no rows |
| Coordinates changed | New `latitude`/`longitude` on the location row | Treat as a new location. Do not silently reuse the old series under new coordinates |
| Cron silently stopped | `/status` shows a stale newest `obs_date` | This is the whole reason `/status` reports it. A cron job that stops is otherwise invisible for months |

### 8.1 The shared-IP rate limit is a real risk

**Measured 2026-08-30** (from a third-party host, not sh193): a single
`archive-api.open-meteo.com` request returned
`{"error":true,"reason":"Daily API request limit exceeded. Please try again
tomorrow."}` with HTTP 429, while `api.open-meteo.com` on the same machine
returned 200. The quota had been consumed by other traffic from that shared
address.

Your account sits on **shared IP 152.160.208.75** (hosting §1). The 10,000/day
Open-Meteo limit is per IP, and you share it with every other account on sh193.
You cannot see or control their usage.

This is survivable — the design needs single-digit calls per day — but only if:

- **No API call ever happens on a page render.** A 429 must never be able to
  produce a slow page or a 500. This is the operational reason for the §3 rule,
  not just architectural tidiness.
- **Failures are silent to the user and loud on `/status`.** A missing day renders
  as a gap in a chart; nobody gets an error.
- **Retries are bounded.** One retry after 30 seconds, then give up until
  tomorrow. Hammering a quota that resets daily just burns the account's
  reputation with the provider.
- **The NCEI fallback in §9 is implemented, not just documented.** A persistent
  429 that is somebody else's fault is exactly the scenario it exists for.

---

## 9. The NOAA NCEI fallback

**Measured 2026-08-30.** No token, no registration, JSON out, 371 ms round trip:

```
https://www.ncei.noaa.gov/access/services/data/v1
  ?dataset=daily-summaries
  &stations=USW00094847
  &startDate=2026-08-01&endDate=2026-08-05
  &dataTypes=TMAX,TMIN,PRCP
  &format=json&units=standard
```

Returns row-oriented JSON — a genuinely different shape from Open-Meteo, so the
two need separate mappers:

```json
[{"DATE":"2026-08-01","STATION":"USW00094847","TMAX":"81","TMIN":"65","PRCP":"0.12"}]
```

**Measured lag:** on 2026-08-30 the newest available row for station USW00094847
(Detroit Metro) was **2026-08-27** — about three days, consistent with NCEI's own
statement that GHCNd lags for quality assurance.

Three things to know before relying on it:

- **All values arrive as strings**, including numerics. Cast explicitly.
- **`units=standard` returns °F and inches**; `units=metric` returns °C and mm.
  Use metric and store SI, per §6.3.
- **Coverage is per station and inconsistent.** Roughly half of GHCNd stations
  report precipitation only. Verify that your chosen station actually carries
  TMAX/TMIN over your whole date range before adopting it — a station that stops
  reporting in 2019 will happily return an empty array for 2020 with HTTP 200.

Station IDs are found through NCEI's station search or `.../services/search/v1`.
Store the chosen station on `weather_location` as a nullable
`ncei_station_id`; where it is null, the fallback is simply unavailable for that
location and the UI shows the gap.

Set `source_model` to `ncei:<station>` on any row it writes, so a mixed series is
never mistaken for a homogeneous one.

---

## 10. The licence question — decide this before building

Two separate things, and conflating them is the trap:

**The data** is CC BY 4.0. That permits commercial use, redistribution and
adaptation, and requires attribution. Fine either way.

**The free API service** is restricted to non-commercial use. Open-Meteo's own
terms name as commercial: *operating websites or apps that have subscriptions or
display advertisements*, and *integrating the service into commercial products*.
They name as non-commercial: private or non-profit sites and apps without
subscriptions or advertising, personal automation, and public research.

An internal business tool with no subscription and no ads sits in a genuine grey
area between those lists. So:

| If the app is… | Do this |
| --- | --- |
| Internal, unsold, no ads | Free tier is defensible. **Email Open-Meteo and describe the use** — they answer, and a one-line reply in the repo settles it permanently |
| Sold, subscription, or ad-supported | Take a paid plan (`customer-api.open-meteo.com` + `apikey` parameter — one hostname and one query parameter, so the switch is a config change), **or** make NCEI the primary and accept station gaps |

Either way, **attribution is required and non-optional.** Put a visible credit
wherever weather data appears — a footer line on any chart or table:

> Weather data by [Open-Meteo.com](https://open-meteo.com/) (CC BY 4.0), based on
> ERA5 reanalysis from Copernicus / ECMWF.

If NCEI rows are mixed in, credit NOAA NCEI GHCNd alongside it. Because
`source_model` is on every row, the credit line can be generated from the data
rather than hard-coded, which keeps it honest.

---

## 11. Spikes to run first

In this order. The first one can kill the feature, so it goes first.

1. **Outbound HTTPS from sh193.** Hosting §12 lists egress as untested. Deploy a
   temporary key-guarded diagnostic route that `curl`s
   `https://archive-api.open-meteo.com/v1/archive?…` for a two-day range and
   prints the HTTP status, the total time and the first 200 bytes. Delete it after
   — the same instrument, and the same discipline, as every "Measured" row in
   the hosting document. **If this fails, stop and rescope.**
2. **The same call to `https://www.ncei.noaa.gov/…`.** A different host, a
   different port profile, a government domain. Confirm both, not one.
3. **PHP CLI binary path**, for the cron entry in §3.1. Try each candidate from a
   cPanel cron job that writes `php -v` output to a file in `var/`.
4. **A real cron execution.** Schedule the job for five minutes out and confirm a
   `weather_sync_run` row appears. Cron availability is confirmed for the account;
   whether *this* command line runs is not.
5. **RTT and payload size for a 5-year daily backfill.** One call, timed, with the
   decoded row count. This sizes the chunking in §3 and tells you whether a
   browser-run backfill fits inside 30 s or needs splitting by year.
6. **Upsert cost against the remote database.** Time a 200-row multi-row
   `ON DUPLICATE KEY UPDATE`. Hosting §9's arithmetic — `time ≈ measured +
   statements × RTT` — is what makes the batch size decision, and RTT to
   152.160.193.196 is under 1 ms, so batching is about statement count, not bytes.

---

## 12. Per-application blanks

| Value | Fill in |
| --- | --- |
| Locations to track (label, lat, lon, timezone) | |
| Earliest date to backfill (`backfill_from`) | |
| Any location needing hourly data, and the question that justifies it | |
| GDD base temperature(s) by species/crop | |
| Default lag window for observation joins (days) | |
| NCEI station ID per location, if adopted | |
| Cron schedule and the key name for `/tasks/weather-sync` | |
| Commercial-use determination from §10, and the date Open-Meteo confirmed it | |
| Attribution placement in the UI | |

---

## 13. Provenance

| Claim | Source | Date |
| --- | --- | --- |
| Open-Meteo endpoints, parameters, variables, model lag table, error envelope | `open-meteo.com/en/docs/historical-weather-api` | 2026-08-30 |
| 10,000 calls/day, non-commercial free tier, CC BY 4.0 | Open-Meteo pricing, terms and GitHub README | 2026-08-30 |
| Open-Meteo 429 on a shared IP; `api.open-meteo.com` 200 on the same host | Live `curl`, third-party host | 2026-08-30 |
| NCEI keyless JSON, response shape, 371 ms, ~3-day lag | Live `curl` to `ncei.noaa.gov/access/services/data/v1` | 2026-08-30 |
| GHCNd public domain (CC0), station coverage caveats | NOAA NCEI product pages, AWS Open Data registry | 2026-08-30 |
| Every hosting constraint cited by section | `hostingplatformscope.md` | 2026-08 |
