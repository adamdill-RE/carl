-- carl:kind=ddl
-- The watering recommendation (handoff Sections 5.8 and 11).
--
-- Computed nightly by bin/weather_sync.php --recommend, after the weather is
-- in, and NEVER at render: the MOTD and the digest read the stored row.
--
-- deficit_mm is the soil moisture deficit at the start of for_date. It is a
-- recursion -- D = clamp(D_prev + ET0*Kc - rain_eff - irrigation, 0, TAW) --
-- so the row exists partly to be read back tomorrow as D_prev rather than
-- recomputing the whole season every night (Phase 3 handoff Section 4.2).

CREATE TABLE `watering_recommendation` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  -- Exactly one of these is set. Containers are evaluated as their own
  -- "garden" with the container TAW (handoff Section 11), and a container is
  -- not a row of `garden`, so it needs its own key.
  `garden_id`     INT UNSIGNED     NULL,
  `container_id`  INT UNSIGNED     NULL,
  -- 'g:12' or 'c:7'. The unique key cannot be (garden_id, container_id,
  -- for_date): one of the two is always NULL, and MySQL permits any number
  -- of NULLs in a unique index, so that key would enforce nothing. This
  -- column is what the index can actually be built on. The application
  -- writes it; nothing reads it but the index.
  `place_key`     VARCHAR(24)  NOT NULL,
  `for_date`      DATE         NOT NULL,
  `tier`          ENUM('water','likely','skip') NOT NULL,
  `deficit_mm`    DECIMAL(6,2) NOT NULL DEFAULT 0,
  `taw_mm`        SMALLINT UNSIGNED NOT NULL,
  `mad_mm`        SMALLINT UNSIGNED NOT NULL,
  `kc`            DECIMAL(4,2)     NULL,
  `et0_mm`        DECIMAL(6,2)     NULL,
  `rain_eff_mm`   DECIMAL(6,2)     NULL,
  `irrigation_mm` DECIMAL(6,2)     NULL,
  -- One sentence with the numbers in it, and with any assumption the model
  -- had to make about how much water a method applies, so the user can
  -- correct it (handoff Section 11).
  `reason_text`   VARCHAR(500) NOT NULL,
  `computed_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reco_place_date` (`place_key`, `for_date`),
  KEY `idx_reco_user_date` (`user_id`, `for_date`),
  KEY `idx_reco_garden` (`garden_id`),
  KEY `idx_reco_container` (`container_id`),
  CONSTRAINT `fk_reco_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reco_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reco_container` FOREIGN KEY (`container_id`)
    REFERENCES `container` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same run log the weather and mail jobs keep, extended rather than
-- duplicated: 'recommend' joins 'archive', 'forecast' and 'alerts'.
ALTER TABLE `weather_sync_run`
  MODIFY COLUMN `kind` ENUM('archive','forecast','alerts','recommend')
    NOT NULL DEFAULT 'archive';
