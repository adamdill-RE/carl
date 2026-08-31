-- carl:kind=ddl
-- Garden actions (handoff Section 4.7). Watering a zone also fans out a
-- derived water record to every living plant in the zone's rows, stored as
-- plant_event rows carrying source_garden_event_id so it is not
-- double-counted.

CREATE TABLE `garden_event` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT UNSIGNED NOT NULL,
  `garden_id`          INT UNSIGNED NOT NULL,
  `event_type`         ENUM('watered','pest_observed','pest_treated','fertilized',
                            'amended','mulched','photo_added','note') NOT NULL,
  `event_date`         DATE         NOT NULL,
  `recorded_at`        DATETIME     NOT NULL,
  `water_zone_id`      INT UNSIGNED     NULL,
  `duration_min`       SMALLINT UNSIGNED NULL,
  `ref_list_item_id`   INT UNSIGNED     NULL,
  `ref_list_item_id_2` INT UNSIGNED     NULL,
  `narrative`          TEXT             NULL,
  `payload`            JSON             NULL,
  `fanout_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`         DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ge_garden_date` (`garden_id`, `event_date`),
  KEY `idx_ge_user_date` (`user_id`, `event_date`),
  KEY `idx_ge_type` (`event_type`),
  CONSTRAINT `fk_ge_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ge_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ge_zone` FOREIGN KEY (`water_zone_id`)
    REFERENCES `water_zone` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ge_ref1` FOREIGN KEY (`ref_list_item_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ge_ref2` FOREIGN KEY (`ref_list_item_id_2`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `garden_event_row` (
  `garden_event_id` BIGINT UNSIGNED NOT NULL,
  `garden_row_id`   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`garden_event_id`, `garden_row_id`),
  KEY `idx_ger_row` (`garden_row_id`),
  CONSTRAINT `fk_ger_event` FOREIGN KEY (`garden_event_id`)
    REFERENCES `garden_event` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ger_row` FOREIGN KEY (`garden_row_id`)
    REFERENCES `garden_row` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `plant_event`
  ADD CONSTRAINT `fk_pe_source_ge` FOREIGN KEY (`source_garden_event_id`)
    REFERENCES `garden_event` (`id`) ON DELETE CASCADE;
