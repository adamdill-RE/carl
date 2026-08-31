-- carl:kind=ddl
-- Global reference data. Admin-imported through /admin/research-import
-- (handoff Section 9), never hand-edited, and read-only to users.
-- Region-agnostic from day one: nothing here is keyed to one state.

CREATE TABLE `region` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_key`           VARCHAR(24)  NOT NULL,
  `country`              VARCHAR(2)   NOT NULL DEFAULT 'US',
  `state`                VARCHAR(8)       NULL,
  `county`               VARCHAR(120)     NULL,
  `label`                VARCHAR(190) NOT NULL,
  `usda_zone`            VARCHAR(16)      NULL,
  `region_scheme`        VARCHAR(48)      NULL,
  `region_code`          VARCHAR(24)      NULL,
  `last_frost_avg`       CHAR(5)          NULL,
  `last_frost_early`     CHAR(5)          NULL,
  `last_frost_late`      CHAR(5)          NULL,
  `first_frost_avg`      CHAR(5)          NULL,
  `first_frost_early`    CHAR(5)          NULL,
  `first_frost_late`     CHAR(5)          NULL,
  `growing_season_days`  SMALLINT UNSIGNED NULL,
  `frost_stations`       VARCHAR(255)     NULL,
  `research_status`      ENUM('researched','generic','none') NOT NULL DEFAULT 'none',
  `confidence`           ENUM('verified','approx','generic') NULL,
  `source`               VARCHAR(500)     NULL,
  `notes`                TEXT             NULL,
  `dataset_version`      VARCHAR(24)      NULL,
  `first_seen_at`        DATETIME     NOT NULL,
  `created_at`           DATETIME     NOT NULL,
  `updated_at`           DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_region_key` (`region_key`),
  KEY `idx_region_status` (`research_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global plant catalog, region-independent. plant_family is stored now so
-- crop-rotation warnings are free later (handoff Section 15).
CREATE TABLE `plant_type` (
  `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`                        VARCHAR(80)  NOT NULL,
  `type`                            VARCHAR(120) NOT NULL,
  `plant_family`                    VARCHAR(80)  NOT NULL,
  `latin_name`                      VARCHAR(120)     NULL,
  `lifecycle`                       ENUM('annual','perennial') NOT NULL DEFAULT 'annual',
  `is_tree`                         TINYINT(1)   NOT NULL DEFAULT 0,
  `dtm_days_min`                    SMALLINT UNSIGNED NULL,
  `dtm_days_max`                    SMALLINT UNSIGNED NULL,
  `dtm_counted_from`                ENUM('seed','transplant') NOT NULL DEFAULT 'seed',
  `spacing_in`                      DECIMAL(5,1)     NULL,
  `seed_depth_in`                   DECIMAL(4,2)     NULL,
  `germ_days_min`                   SMALLINT UNSIGNED NULL,
  `germ_days_max`                   SMALLINT UNSIGNED NULL,
  `germ_soil_temp_f_min`            SMALLINT         NULL,
  `germ_soil_temp_f_max`            SMALLINT         NULL,
  `sun`                             ENUM('full','partial','shade') NULL,
  `kc_ini`                          DECIMAL(4,2)     NULL,
  `kc_mid`                          DECIMAL(4,2)     NULL,
  `kc_end`                          DECIMAL(4,2)     NULL,
  `stage_days_ini`                  SMALLINT UNSIGNED NULL,
  `stage_days_dev`                  SMALLINT UNSIGNED NULL,
  `stage_days_mid`                  SMALLINT UNSIGNED NULL,
  `stage_days_late`                 SMALLINT UNSIGNED NULL,
  `typical_start_method`            ENUM('indoor','direct','transplant') NULL,
  `weeks_before_transplant_to_start` SMALLINT UNSIGNED NULL,
  `hardening_days_default`          SMALLINT UNSIGNED NULL,
  `heat_tolerant`                   TINYINT(1)   NOT NULL DEFAULT 0,
  `confidence`                      ENUM('verified','approx','generic') NULL,
  `source`                          VARCHAR(500)     NULL,
  `notes`                           TEXT             NULL,
  `dataset_version`                 VARCHAR(24)      NULL,
  `created_at`                      DATETIME     NOT NULL,
  `updated_at`                      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plant_type` (`category`, `type`),
  KEY `idx_plant_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plant_region` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`              INT UNSIGNED NOT NULL,
  `plant_type_id`          INT UNSIGNED NOT NULL,
  `season`                 ENUM('spring','summer','fall','winter') NOT NULL,
  `window_start`           CHAR(5)          NULL,
  `window_end`             CHAR(5)          NULL,
  `method`                 ENUM('seed','transplant') NULL,
  `recommended`            TINYINT(1)   NOT NULL DEFAULT 0,
  `dtm_days_min_override`  SMALLINT UNSIGNED NULL,
  `dtm_days_max_override`  SMALLINT UNSIGNED NULL,
  `confidence`             ENUM('verified','approx','generic') NULL,
  `source`                 VARCHAR(500)     NULL,
  `regional_notes`         TEXT             NULL,
  `dataset_version`        VARCHAR(24)      NULL,
  `created_at`             DATETIME     NOT NULL,
  `updated_at`             DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plant_region` (`region_id`, `plant_type_id`, `season`),
  KEY `idx_pr_plant` (`plant_type_id`),
  CONSTRAINT `fk_pr_region` FOREIGN KEY (`region_id`)
    REFERENCES `region` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_plant` FOREIGN KEY (`plant_type_id`)
    REFERENCES `plant_type` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pest` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pest_key`    VARCHAR(64)  NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `kind`        ENUM('pest','disease','disorder') NOT NULL DEFAULT 'pest',
  `description` TEXT             NULL,
  `signs`       TEXT             NULL,
  `treatments`  TEXT             NULL,
  `source`      VARCHAR(500)     NULL,
  `dataset_version` VARCHAR(24)  NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pest_key` (`pest_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pest_region` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`          INT UNSIGNED NOT NULL,
  `pest_id`            INT UNSIGNED NOT NULL,
  `active_start`       CHAR(5)          NULL,
  `active_end`         CHAR(5)          NULL,
  `affects_categories` VARCHAR(500)     NULL,
  `gdd_base_f`         DECIMAL(5,2)     NULL,
  `gdd_threshold`      DECIMAL(8,2)     NULL,
  `gdd_biofix`         CHAR(5)          NULL,
  `confidence`         ENUM('verified','approx','generic') NULL,
  `source`             VARCHAR(500)     NULL,
  `regional_notes`     TEXT             NULL,
  `dataset_version`    VARCHAR(24)      NULL,
  `created_at`         DATETIME     NOT NULL,
  `updated_at`         DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pest_region` (`region_id`, `pest_id`),
  KEY `idx_prg_pest` (`pest_id`),
  CONSTRAINT `fk_prg_region` FOREIGN KEY (`region_id`)
    REFERENCES `region` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prg_pest` FOREIGN KEY (`pest_id`)
    REFERENCES `pest` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `region_guidance` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`           INT UNSIGNED NOT NULL,
  `topic`               ENUM('season','soil','water','shade','mulch','seed_start','hardening','frost','other')
                        NOT NULL DEFAULT 'other',
  `applies_to_categories` VARCHAR(500) NOT NULL DEFAULT '',
  `show_from`           CHAR(5)      NOT NULL,
  `show_to`             CHAR(5)      NOT NULL,
  `guidance`            TEXT         NOT NULL,
  `confidence`          ENUM('verified','approx','generic') NULL,
  `source`              VARCHAR(500)     NULL,
  `dataset_version`     VARCHAR(24)      NULL,
  `created_at`          DATETIME     NOT NULL,
  `updated_at`          DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guidance` (`region_id`, `topic`, `applies_to_categories`, `show_from`),
  CONSTRAINT `fk_guid_region` FOREIGN KEY (`region_id`)
    REFERENCES `region` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per accepted import. sha256 makes re-uploading the same zip a no-op
-- the page can name (handoff Section 9.3 step 6).
CREATE TABLE `research_import` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dataset_version` VARCHAR(24)  NOT NULL,
  `region_keys`     VARCHAR(500) NOT NULL,
  `filename`        VARCHAR(190) NOT NULL,
  `sha256`          CHAR(64)     NOT NULL,
  `imported_by`     INT UNSIGNED     NULL,
  `imported_at`     DATETIME     NOT NULL,
  `row_counts`      JSON             NULL,
  `status`          ENUM('applied','rejected','noop') NOT NULL DEFAULT 'applied',
  PRIMARY KEY (`id`),
  KEY `idx_import_sha` (`sha256`),
  KEY `idx_import_version` (`dataset_version`),
  CONSTRAINT `fk_import_user` FOREIGN KEY (`imported_by`)
    REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user`
  ADD CONSTRAINT `fk_user_region` FOREIGN KEY (`region_id`)
    REFERENCES `region` (`id`) ON DELETE SET NULL;
