-- carl:kind=ddl
-- The spine (handoff Section 5.3): an asset plus an append-only event log.
-- Every action is a plant_event row with a backdatable date. Lifecycle state
-- and every rate (germination, survival, cull) are derived, never stored --
-- except planting.state and planting.quantity_live, which are caches
-- recomputed after any event insert, edit or delete.

CREATE TABLE `planting` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                INT UNSIGNED NOT NULL,
  `plant_type_id`          INT UNSIGNED NOT NULL,
  `garden_id`              INT UNSIGNED     NULL,
  `garden_row_id`          INT UNSIGNED     NULL,
  `container_id`           INT UNSIGNED     NULL,
  `label`                  VARCHAR(120)     NULL,
  `start_method`           ENUM('indoor_seed','direct_sow','nursery_transplant') NOT NULL,
  `start_date`             DATE         NOT NULL,
  `quantity_initial`       INT          NOT NULL DEFAULT 1,
  `quantity_live`          INT          NOT NULL DEFAULT 1,
  `state`                  ENUM('seed_started','hardening','planted','yielding','ended')
                           NOT NULL DEFAULT 'seed_started',
  `state_changed_at`       DATETIME     NOT NULL,
  `in_ground_date`         DATE             NULL,
  `ended_at`               DATE             NULL,
  `germinated_at`          DATE             NULL,
  `hardening_started_at`   DATE             NULL,
  `hardening_days`         SMALLINT UNSIGNED NULL,
  `hardening_schedule_id`  INT UNSIGNED     NULL,
  `default_water_method_id` INT UNSIGNED    NULL,
  `seed_source_id`         INT UNSIGNED     NULL,
  `nursery_id`             INT UNSIGNED     NULL,
  `trellis_used`           TINYINT(1)   NOT NULL DEFAULT 0,
  `collar_used`            TINYINT(1)   NOT NULL DEFAULT 0,
  `seeds_per_collar`       SMALLINT UNSIGNED NULL,
  `initial_height_in`      DECIMAL(6,2)     NULL,
  `initial_width_in`       DECIMAL(6,2)     NULL,
  `notes`                  TEXT             NULL,
  `created_at`             DATETIME     NOT NULL,
  `updated_at`             DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_planting_user_state` (`user_id`, `state`),
  KEY `idx_planting_user_start` (`user_id`, `start_date`),
  KEY `idx_planting_garden` (`garden_id`),
  KEY `idx_planting_row` (`garden_row_id`),
  KEY `idx_planting_container` (`container_id`),
  KEY `idx_planting_type` (`plant_type_id`),
  CONSTRAINT `fk_planting_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planting_type` FOREIGN KEY (`plant_type_id`)
    REFERENCES `plant_type` (`id`),
  CONSTRAINT `fk_planting_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planting_row` FOREIGN KEY (`garden_row_id`)
    REFERENCES `garden_row` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planting_container` FOREIGN KEY (`container_id`)
    REFERENCES `container` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planting_hard` FOREIGN KEY (`hardening_schedule_id`)
    REFERENCES `hardening_schedule` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Handoff Section 6: event_date is the user's LOCAL calendar day, because it
-- must join to weather_daily.obs_date, which is also a local calendar day
-- (weather.md Section 6.3). recorded_at is UTC.
CREATE TABLE `plant_event` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                INT UNSIGNED NOT NULL,
  `planting_id`            INT UNSIGNED NOT NULL,
  `event_type`             ENUM(
                             'seed_started','direct_sown','transplanted_in','watered',
                             'germinated','germination_failed','died','up_potted',
                             'hardening_started','hardening_schedule_set','transplanted',
                             'culled','yielded','pest_observed','pest_treated',
                             'fertilized','amended','mulched','photo_added','note','moved'
                           ) NOT NULL,
  `event_date`             DATE         NOT NULL,
  `recorded_at`            DATETIME     NOT NULL,
  `quantity_delta`         INT              NULL,
  `duration_min`           SMALLINT UNSIGNED NULL,
  `weight_g`               DECIMAL(10,2)    NULL,
  `count_qty`              INT              NULL,
  `unit`                   VARCHAR(16)      NULL,
  `narrative`              TEXT             NULL,
  `ref_list_item_id`       INT UNSIGNED     NULL,
  `ref_list_item_id_2`     INT UNSIGNED     NULL,
  `garden_id`              INT UNSIGNED     NULL,
  `garden_row_id`          INT UNSIGNED     NULL,
  `container_id`           INT UNSIGNED     NULL,
  `source_garden_event_id` BIGINT UNSIGNED  NULL,
  `payload`                JSON             NULL,
  `created_at`             DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pe_planting_date` (`planting_id`, `event_date`),
  KEY `idx_pe_user_date` (`user_id`, `event_date`),
  KEY `idx_pe_type` (`event_type`),
  KEY `idx_pe_source` (`source_garden_event_id`),
  CONSTRAINT `fk_pe_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_planting` FOREIGN KEY (`planting_id`)
    REFERENCES `planting` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_ref1` FOREIGN KEY (`ref_list_item_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pe_ref2` FOREIGN KEY (`ref_list_item_id_2`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `planting`
  ADD CONSTRAINT `fk_planting_water` FOREIGN KEY (`default_water_method_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planting_seed_source` FOREIGN KEY (`seed_source_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planting_nursery` FOREIGN KEY (`nursery_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL;
