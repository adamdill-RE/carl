-- carl:kind=ddl
-- The companion planting reference (handoff Section 14 v2, Phase 6 handoff
-- Section 3.3).
--
-- The last of the three v2 items and the only one with no data behind it:
-- GDD thresholds and sowing windows were already in the research tables from
-- Phase 1, and this was not. So it is the one item that touches the
-- `research-template/` contract, and the template version goes 1 to 2 with
-- it. `companions.csv` is OPTIONAL in both versions, which is what lets the
-- datasets already imported stay valid.
--
-- **Global, not regional.** Every other pairing in this schema hangs off a
-- region, because frost dates and sowing windows are facts about a place.
-- Whether basil sits well beside a tomato is a fact about the two plants,
-- and duplicating it per county would mean thirty copies of one sentence and
-- thirty chances for them to disagree.
--
-- **Keyed on category, not on plant_type.** This is the one table in the
-- research schema that does not carry a plant_type_id, and the reason is
-- that companion advice is about the species and never about the cultivar:
-- nothing anybody has written says basil suits a Roma but not a Celebrity.
-- Keyed on plant_type it would be six identical rows per tomato in this
-- dataset alone, six chances to disagree, and a new cultivar would silently
-- have no companions at all. `pest_region.affects_categories` already keys
-- on category strings for exactly this reason, so this is the existing
-- convention rather than a new one.
--
-- The cost is that there is no foreign key here. The importer checks instead
-- that both categories exist in the catalogue, which is the same check
-- `affects_categories` gets, and a category that later disappears leaves a
-- row that simply never matches.
--
-- **One row per stated pair, read in both directions.** Storing the mirror
-- as well would double the table and make "the importer wrote it" and "the
-- researcher stated it" indistinguishable -- and a re-import that changed a
-- direction would leave the stale mirror behind. Companion advice is
-- symmetric in practice (the plants are neighbours; neither is upwind of the
-- other), so the read is `category = :c OR other_category = :c`.
--
-- For that to hold, the importer stores the pair with the two categories in
-- lexical order. The unique key below is on an ORDERED pair, so without that
-- normalisation a dataset stating "Basil with Tomato" and a later one
-- stating "Tomato with Basil" would satisfy it twice, and the table would
-- hold both rows, free to disagree.
--
-- **The reason column is not decoration.** Companion planting is the corner
-- of gardening advice with the widest gap between what is repeated and what
-- is measured: most of the familiar pairings trace back to one 1940s book
-- rather than to a trial, and the extension charts that carry them do not
-- separate the two. So `reason` and `confidence` are what stop this table
-- becoming a list of assertions -- a row that cannot say WHY, and how well
-- established it is, is a row the screen shows differently and says so.

CREATE TABLE `plant_companion` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`        VARCHAR(80)  NOT NULL,
  `other_category`  VARCHAR(80)  NOT NULL,
  `relationship`    ENUM('good','bad') NOT NULL DEFAULT 'good',
  -- What the pairing is supposed to DO, in the words of the source. A
  -- gardener deciding whether to believe it needs the mechanism, not a verdict.
  `reason`          VARCHAR(500)     NULL,
  -- 'verified' means a trial or an extension publication naming a mechanism,
  -- which for this subject is the minority. 'generic' means traditional and
  -- widely repeated. The screen shows the difference rather than flattening it.
  `confidence`      ENUM('verified','approx','generic') NULL,
  `source`          VARCHAR(500)     NULL,
  `dataset_version` VARCHAR(24)      NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companion_pair` (`category`, `other_category`),
  -- Both columns are indexed because both are queried: the read is an OR over
  -- the two, and without the second index half of every lookup is a scan.
  KEY `idx_companion_other` (`other_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
