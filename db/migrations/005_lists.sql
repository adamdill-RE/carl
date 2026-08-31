-- carl:kind=ddl
-- User-set variables (handoff Section 4.9 / 5.6): one screen, one generic
-- table. Containers and hardening schedules keep their own tables because
-- other rows hold foreign keys to them.

CREATE TABLE `user_list_item` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `list_type`     ENUM(
                    'seed_source','seed_starting_soil','seed_starting_vessel',
                    'up_pot_soil','up_pot_container','fertilizer_sow',
                    'fertilizer_garden','nursery','water_method','shade_cloth',
                    'soil_amendment','pest_treatment','pest_disease',
                    'cull_reason','mulch_type'
                  ) NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `attr_1`        VARCHAR(120)     NULL,
  `attr_2`        VARCHAR(255)     NULL,
  `garden_id`     INT UNSIGNED     NULL,
  `water_zone_id` INT UNSIGNED     NULL,
  `pest_id`       INT UNSIGNED     NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_list_item` (`user_id`, `list_type`, `name`),
  KEY `idx_list_lookup` (`user_id`, `list_type`, `is_active`),
  CONSTRAINT `fk_list_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_list_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_list_zone` FOREIGN KEY (`water_zone_id`)
    REFERENCES `water_zone` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_list_pest` FOREIGN KEY (`pest_id`)
    REFERENCES `pest` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hardening_schedule` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `duration_days` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `is_default`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hardening_name` (`user_id`, `name`),
  CONSTRAINT `fk_hard_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hardening_schedule_day` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `weekday`     TINYINT UNSIGNED NOT NULL,
  `time_from`   TIME         NOT NULL,
  `time_to`     TIME         NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hard_day` (`schedule_id`, `weekday`),
  CONSTRAINT `fk_hard_day` FOREIGN KEY (`schedule_id`)
    REFERENCES `hardening_schedule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `garden_row`
  ADD CONSTRAINT `fk_row_shade` FOREIGN KEY (`shade_cloth_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL;

ALTER TABLE `water_zone`
  ADD CONSTRAINT `fk_zone_method` FOREIGN KEY (`water_method_id`)
    REFERENCES `user_list_item` (`id`) ON DELETE SET NULL;
