-- carl:kind=ddl
-- Weather (weather.md Section 6.1, extended by handoff Section 5.7 with
-- forecasts, alerts and the zip that ties a location to a user).
--
-- Weather is never on the request path: pages read these tables and nothing
-- else. If the data is not there, the UI renders the gap (weather.md 3.2).

CREATE TABLE `weather_location` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`            VARCHAR(120)  NOT NULL,
  `zip`              VARCHAR(10)       NULL,
  `latitude`         DECIMAL(8,5)  NOT NULL,
  `longitude`        DECIMAL(8,5)  NOT NULL,
  `timezone`         VARCHAR(64)   NOT NULL,
  `elevation_m`      DECIMAL(7,2)      NULL,
  `ncei_station_id`  VARCHAR(24)       NULL,
  `hourly_enabled`   TINYINT(1)    NOT NULL DEFAULT 0,
  `backfill_from`    DATE          NOT NULL,
  `forecast_hash`    CHAR(64)          NULL,
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coords` (`latitude`, `longitude`),
  KEY `idx_wl_zip` (`zip`),
  KEY `idx_wl_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- obs_date is a DATE in the location's own timezone (weather.md 6.3), which
-- is what lets it join to plant_event.event_date. fetched_at is UTC.
CREATE TABLE `weather_daily` (
  `location_id`      INT UNSIGNED NOT NULL,
  `obs_date`         DATE         NOT NULL,
  `temp_max_c`       DECIMAL(5,2) NULL,
  `temp_min_c`       DECIMAL(5,2) NULL,
  `temp_mean_c`      DECIMAL(5,2) NULL,
  `precip_mm`        DECIMAL(6,2) NULL,
  `precip_hours`     DECIMAL(4,1) NULL,
  `et0_mm`           DECIMAL(6,2) NULL,
  `radiation_mj`     DECIMAL(6,2) NULL,
  `sunshine_s`       INT UNSIGNED NULL,
  `daylight_s`       INT UNSIGNED NULL,
  `rh_mean_pct`      DECIMAL(5,2) NULL,
  `rh_min_pct`       DECIMAL(5,2) NULL,
  `vpd_max_kpa`      DECIMAL(5,3) NULL,
  `wind_max_kmh`     DECIMAL(5,2) NULL,
  `gust_max_kmh`     DECIMAL(5,2) NULL,
  `soil_moist_0_7`   DECIMAL(5,3) NULL,
  `soil_temp_0_7_c`  DECIMAL(5,2) NULL,
  `weather_code`     TINYINT UNSIGNED NULL,
  `source_model`     VARCHAR(24)  NOT NULL,
  `is_provisional`   TINYINT(1)   NOT NULL DEFAULT 1,
  `fetched_at`       DATETIME     NOT NULL,
  -- VIRTUAL, never STORED: a column feeding a STORED generated column cannot
  -- carry ON DELETE CASCADE under MySQL 8 (hosting Section 2.2, error 1215).
  `water_balance_mm` DECIMAL(7,2) AS (`precip_mm` - `et0_mm`) VIRTUAL,
  PRIMARY KEY (`location_id`, `obs_date`),
  KEY `idx_wd_date` (`obs_date`),
  KEY `idx_wd_provisional` (`is_provisional`, `obs_date`),
  CONSTRAINT `fk_wd_loc` FOREIGN KEY (`location_id`)
    REFERENCES `weather_location` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Overwritten each run. A hash of the three-day block lives on
-- weather_location.forecast_hash and drives MOTD re-post (handoff 4.2).
CREATE TABLE `weather_forecast` (
  `location_id`     INT UNSIGNED NOT NULL,
  `forecast_date`   DATE         NOT NULL,
  `issued_at`       DATETIME     NOT NULL,
  `temp_max_c`      DECIMAL(5,2) NULL,
  `temp_min_c`      DECIMAL(5,2) NULL,
  `precip_mm`       DECIMAL(6,2) NULL,
  `precip_prob_pct` TINYINT UNSIGNED NULL,
  `precip_hours`    DECIMAL(4,1) NULL,
  `et0_mm`          DECIMAL(6,2) NULL,
  `rh_mean_pct`     DECIMAL(5,2) NULL,
  `wind_max_kmh`    DECIMAL(5,2) NULL,
  `soil_moist_0_7`  DECIMAL(5,3) NULL,
  `soil_temp_0_7_c` DECIMAL(5,2) NULL,
  `weather_code`    TINYINT UNSIGNED NULL,
  PRIMARY KEY (`location_id`, `forecast_date`),
  CONSTRAINT `fk_wf_loc` FOREIGN KEY (`location_id`)
    REFERENCES `weather_location` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `weather_alert` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id` INT UNSIGNED NOT NULL,
  `nws_id`      VARCHAR(255) NOT NULL,
  `event`       VARCHAR(120) NOT NULL,
  `severity`    VARCHAR(32)      NULL,
  `headline`    VARCHAR(500)     NULL,
  `onset`       DATETIME         NULL,
  `expires`     DATETIME         NULL,
  `fetched_at`  DATETIME     NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_nws` (`nws_id`),
  KEY `idx_alert_loc` (`location_id`, `is_active`),
  CONSTRAINT `fk_wa_loc` FOREIGN KEY (`location_id`)
    REFERENCES `weather_location` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Always written, success or failure. Pruned to the last 90 days by the same
-- job: an unpruned log table on a nightly job is a slow-growing bug.
CREATE TABLE `weather_sync_run` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`   INT UNSIGNED     NULL,
  `kind`          ENUM('archive','forecast','alerts') NOT NULL DEFAULT 'archive',
  `started_at`    DATETIME     NOT NULL,
  `finished_at`   DATETIME         NULL,
  `range_start`   DATE             NULL,
  `range_end`     DATE             NULL,
  `http_status`   SMALLINT         NULL,
  `rows_upserted` INT UNSIGNED NOT NULL DEFAULT 0,
  `outcome`       ENUM('ok','partial','failed') NOT NULL,
  `error_text`    VARCHAR(500)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`),
  KEY `idx_run_loc_kind` (`location_id`, `kind`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user`
  ADD CONSTRAINT `fk_user_weather_loc` FOREIGN KEY (`weather_location_id`)
    REFERENCES `weather_location` (`id`) ON DELETE SET NULL;
