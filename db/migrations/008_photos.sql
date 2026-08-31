-- carl:kind=ddl
-- Photographs (handoff Section 10). Files live in var/photos/<user_id>/
-- outside public_html and are served only through an ownership-checking
-- controller -- never a direct URL.

CREATE TABLE `photo` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `planting_id`     INT UNSIGNED     NULL,
  `garden_id`       INT UNSIGNED     NULL,
  `plant_event_id`  BIGINT UNSIGNED  NULL,
  `garden_event_id` BIGINT UNSIGNED  NULL,
  `taken_on`        DATE         NOT NULL,
  `stored_name`     VARCHAR(80)  NOT NULL,
  `thumb_name`      VARCHAR(80)      NULL,
  `width`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `height`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `bytes`           INT UNSIGNED NOT NULL DEFAULT 0,
  `caption`         VARCHAR(255)     NULL,
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_photo_stored` (`stored_name`),
  KEY `idx_photo_planting` (`planting_id`, `taken_on`),
  KEY `idx_photo_garden` (`garden_id`, `taken_on`),
  KEY `idx_photo_user` (`user_id`),
  KEY `idx_photo_pe` (`plant_event_id`),
  KEY `idx_photo_ge` (`garden_event_id`),
  CONSTRAINT `fk_photo_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photo_planting` FOREIGN KEY (`planting_id`)
    REFERENCES `planting` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photo_garden` FOREIGN KEY (`garden_id`)
    REFERENCES `garden` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photo_pe` FOREIGN KEY (`plant_event_id`)
    REFERENCES `plant_event` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_photo_ge` FOREIGN KEY (`garden_event_id`)
    REFERENCES `garden_event` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
