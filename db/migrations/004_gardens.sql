-- carl:kind=ddl
-- Gardens, rows, water zones and containers (handoff Section 5.4).
-- Every user-owned table carries user_id; the repository base class is what
-- guarantees every query filters on it.

CREATE TABLE `garden` (
  `user_id`         INT UNSIGNED NOT NULL,
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `is_indoor`       TINYINT(1)   NOT NULL DEFAULT 0,
  `ns_ft`           DECIMAL(6,1)     NULL,
  `ew_ft`           DECIMAL(6,1)     NULL,
  `row_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `row_orientation` ENUM('ns','ew') NOT NULL DEFAULT 'ns',
  `soil_type`       ENUM('clay','loam','sandy','raised_bed_mix','container') NULL,
  `notes`           TEXT             NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_garden_name` (`user_id`, `name`),
  KEY `idx_garden_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_garden_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `garden_row` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `garden_id`      INT UNSIGNED NOT NULL,
  `ordinal`        SMALLINT UNSIGNED NOT NULL,
  `name`           VARCHAR(60)  NOT NULL,
  `sun_exposure`   ENUM('high','medium','low') NOT NULL DEFAULT 'high',
  `shade_cloth_id` INT UNSIGNED     NULL,
  `notes`          TEXT             NULL,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_row_ordinal` (`garden_id`, `ordinal`),
  KEY `idx_row_user` (`user_id`),
  CONSTRAINT `fk_row_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `water_zone` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `garden_id`       INT UNSIGNED NOT NULL,
  `name`            VARCHAR(80)  NOT NULL,
  `water_method_id` INT UNSIGNED     NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zone_name` (`garden_id`, `name`),
  KEY `idx_zone_user` (`user_id`),
  CONSTRAINT `fk_zone_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `water_zone_row` (
  `water_zone_id` INT UNSIGNED NOT NULL,
  `garden_row_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`water_zone_id`, `garden_row_id`),
  KEY `idx_wzr_row` (`garden_row_id`),
  CONSTRAINT `fk_wzr_zone` FOREIGN KEY (`water_zone_id`)
    REFERENCES `water_zone` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wzr_row` FOREIGN KEY (`garden_row_id`)
    REFERENCES `garden_row` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A container behaves as a garden of one location (handoff Section 4.6).
CREATE TABLE `container` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `size`        VARCHAR(60)      NULL,
  `description` VARCHAR(255)     NULL,
  `soil_type`   ENUM('clay','loam','sandy','raised_bed_mix','container') NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_container_name` (`user_id`, `name`),
  CONSTRAINT `fk_container_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
