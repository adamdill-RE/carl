# Research import template — specification (template_version 2)

A research dataset is one `.zip` (under 2 MB, hosting `upload_max_filesize`)
containing up to eight CSV files. The admin uploads it at
`/admin/research-import`. The importer validates the whole set, then upserts
each row on its natural key, so re-uploading a file is safe and converges to
the same state (the same idempotence rule as migrations and the weather sync).

Files, in the order the importer processes them:

| File | Natural key | Scope | Required |
| --- | --- | --- | --- |
| `manifest.csv` | — | dataset metadata | yes |
| `regions.csv` | `region_key` | one row per region | when a new region is introduced |
| `plant_types.csv` | `(category, type)` | **global** catalog, region-independent | when new plants are introduced |
| `plant_region.csv` | `(region_key, category, type, season)` | region-specific windows and overrides | yes |
| `pests.csv` | `pest_key` | global catalog | optional |
| `pest_region.csv` | `(region_key, pest_key)` | region-specific activity/GDD | optional |
| `region_guidance.csv` | `(region_key, topic, applies_to_categories, show_from)` | MOTD-style advice | optional |
| `companions.csv` | `(category, other_category)`, unordered | **global** companion pairings | optional |

A file that is absent is skipped. A file that is present must validate in full
or nothing from the zip is written.

## Keys and conventions

- `region_key`: `US-<county FIPS 5 digits>` (Hill County TX = `US-48217`,
  Wayne County MI = `US-26163`). Non-US regions use `<ISO2>-<admin code>`.
- `category` / `type`: `Tomato` / `Roma`. Category is the species-level group
  the user picks first; type is the variety/cultivar. Both are case-insensitive
  on match, stored as written on first insert.
- Dates in `MM-DD` for recurring windows; `YYYY-MM-DD` only in the manifest.
- Units: inches for spacing/depth, °F for germination soil temperature,
  days for everything else. Weather stays SI in the database; these agronomic
  fields are the values gardeners read on seed packets.
- `confidence`: `verified` (read from an extension/authoritative source),
  `approx` (reasonable regional estimate; confirm), `generic` (non-regional
  reference value). Displayed to the user as a small marker; drives an admin
  "needs confirmation" list.
- `source`: free text naming the publication. Shown in reports as the citation.
- Empty cell = NULL, never zero.
- Multi-valued cells (`affects_categories`, `applies_to_categories`) are
  `;`-separated category names; empty means "all".

## Column reference

### manifest.csv (`key,value`)
`template_version` (`1` or `2`; `2` is current), `dataset_version` (`YYYY-MM-DD.n`, must be
greater than the last imported version for the same region or the import is
refused), `region_keys` (`;`-separated), `produced_on`, `produced_by`, `notes`.

### regions.csv
`region_key, country, state, county, label, usda_zone, region_scheme,
region_code, last_frost_avg, last_frost_early, last_frost_late,
first_frost_avg, first_frost_early, first_frost_late, growing_season_days,
frost_stations, research_status, confidence, source, notes`

`region_scheme`/`region_code` name the extension's own regionalisation
(`tx_agrilife` / `III`, `msu_extension` / `SE`), so a second region in the
same scheme can share windows later. `research_status` is `researched`,
`generic` or `none`.

### plant_types.csv (global)
`category, type, plant_family, latin_name, lifecycle (annual|perennial),
is_tree (Y|N), dtm_days_min, dtm_days_max, dtm_counted_from (seed|transplant),
spacing_in, seed_depth_in, germ_days_min, germ_days_max,
germ_soil_temp_f_min, germ_soil_temp_f_max, sun (full|partial|shade),
kc_ini, kc_mid, kc_end, stage_days_ini, stage_days_dev, stage_days_mid,
stage_days_late, typical_start_method (indoor|direct|transplant),
weeks_before_transplant_to_start, hardening_days_default, heat_tolerant (Y|N),
confidence, source, notes`

`plant_family` is what crop-rotation warnings will key on (v2), so it is
required. `kc_*` and `stage_days_*` feed the watering model (FAO-56 shape).
`dtm_counted_from` is required: tomato/pepper count from transplant; beans,
squash, okra, lettuce count from seed.

### plant_region.csv
`region_key, category, type, season (spring|fall|winter|summer), window_start,
window_end, method (seed|transplant), recommended (Y|N),
dtm_days_min_override, dtm_days_max_override, confidence, source,
regional_notes`

One row per (plant, season). `recommended = N` keeps a plant selectable but
shows it without the "recommended for your area" marker. Overrides replace the
global DTM for this region only.

### pests.csv (global)
`pest_key, name, kind (pest|disease|disorder), description, signs, treatments,
source`

### pest_region.csv
`region_key, pest_key, active_start, active_end, affects_categories,
gdd_base_f, gdd_threshold, gdd_biofix (MM-DD), confidence, source,
regional_notes`

Rows with `gdd_threshold` set enable a GDD-accumulated scouting reminder (v2);
rows without it use the calendar window.

### region_guidance.csv
`region_key, topic (season|soil|water|shade|mulch|seed_start|hardening|frost|
other), applies_to_categories, show_from, show_to, guidance, confidence, source`

These are the sentences the app shows MOTD-style on the main menu and on the
plant-selection screen, filtered by today's date and the plant categories the
user has.

### companions.csv (global, template_version 2)
`category, other_category, relationship (good|bad), reason, confidence, source`

**Keyed on category, not on (category, type).** Nothing anybody has written
says basil suits a Roma but not a Celebrity, and keying on the variety would
mean six identical rows per tomato and six chances for them to disagree.
`affects_categories` in `pest_region.csv` already works this way.

**Each pair belongs in the file once.** The reference reads a pair in both
directions and shows it under both crops, so stating both `Basil,Tomato` and
`Tomato,Basil` is refused — that is how a dataset ends up asserting two
different reasons for one fact. Both categories must exist in
`plant_types.csv` or already be in the catalogue, and a category cannot be
its own companion.

**`reason` and `confidence` are not optional in spirit.** Companion planting
is the corner of gardening advice with the widest gap between what is
repeated and what has been measured: most familiar pairings trace to one
1940s book rather than to a trial, and the extension charts that carry them
do not separate the two. So here `verified` means a trial or an extension
publication that names a mechanism, `approx` means the mechanism is plausible
and partly supported, and `generic` means traditional and widely printed with
nothing measured behind it. The screen shows the difference and explains it.
A row with no `reason` is an assertion, and the app has nothing useful to do
with one.

### Template version 2

Version 2 adds `companions.csv` and changes nothing else. The file is
optional, so **a version 1 dataset still imports unchanged** — Carl reads
both, and `02_research_test.php` keeps the Phase 5 zip in the repository so
that promise has a file behind it rather than a sentence here.

## Producing a new dataset

1. Ask Claude (this project) to research `<county, state>` and/or `<plant
   types>`, naming the target `region_key` and citing extension sources.
2. Claude returns a populated zip in this format with a new `dataset_version`.
3. Upload at `/admin/research-import`. The page shows the validation result and
   a per-file count of inserted/updated rows before committing.
4. The admin "Regions needing research" list drives step 1 for any region a
   user has signed up from that has `research_status <> researched`.

## Provenance of the datasets

### `research_US-48217_2026-08-31.1.zip` (Phase 6, current)

Adds `companions.csv` — 20 pairings, of which 4 are `verified`, 7 `approx`
and 9 `generic`, which is roughly the honest ratio for this subject. Sources
are Virginia Cooperative Extension SPES-620, the trap-cropping review in
*Insects* 2018 9(4):128, and Cryan et al., *Plants People Planet* 2025 on the
Three Sisters. The pairings marked `generic` are the famous ones, including
basil with tomato, and they say so.

Adds the five crops those pairings need, which the catalogue had none of:
basil, French marigold, nasturtium, dill and Blue Hubbard squash. Agronomic
columns are filled only where a value is actually known — an empty cell is
NULL, never a guess — so basil carries no FAO-56 Kc, because none is
published for it.

Promotes the `squash_vine_borer` GDD row from `approx` to `verified`. The
Phase 5 note on it warned that the Midwest threshold of 1000 DD50 would be
wrong for central Texas because "emergence is earlier" there. Measured
against seven years of Open-Meteo archive for Hillsboro (32.0107/-97.1300),
1000 DD50 accumulated from 01-01 reaches the threshold between 18 April and 6
May over 2019–2025 — which is the April/May adult emergence AgriLife reports
for central Texas. The threshold transfers unchanged; it is the calendar date
that moves, which is the entire point of a degree-day model. The note feared
the opposite.

### `research_US-48217_2026-08-30.1.zip` (Phase 5, kept)

Populated from the 2026-08-30 scoping research report: AgriLife Spring Planting
Guide (Region III), AgriLife Fall Vegetable Gardening Guide, AgriLife
Recommended Vegetable Cultivars for North Central Texas, FAO-56 Kc tables, UGA
B577 germination values, OSU/Illinois Extension GDD thresholds, NOAA 1991–2020
normals. Fall windows are marked `approx` because the fall guide gives ranges
by crop group rather than by variety; frost dates are `approx` because Whitney
has no NOAA station of its own.
