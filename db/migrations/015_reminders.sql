-- carl:kind=ddl
-- Reminders and the daily digest (handoff Sections 5.8 and 12).
--
-- Reminders are computed by bin/daily_digest.php, stored here, and read back
-- by the main menu and the email. Nothing recomputes them at render.

CREATE TABLE `reminder` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `planting_id` INT UNSIGNED     NULL,
  -- 'p:123' for a reminder about one planting, '-' for one about the whole
  -- garden. Section 5.8 asks for the key (user_id, planting_id, kind,
  -- due_date), but planting_id is NULL on five of the eleven kinds and MySQL
  -- permits any number of NULLs in a unique index -- so that key would let
  -- every watering reminder be written again on every run. This column is
  -- what the index can actually be built on, and it carries the same
  -- meaning.
  `subject_key` VARCHAR(32)  NOT NULL DEFAULT '-',
  `kind`        VARCHAR(32)  NOT NULL,
  `due_date`    DATE         NOT NULL,
  `title`       VARCHAR(190) NOT NULL,
  `body`        VARCHAR(700) NOT NULL DEFAULT '',
  -- Ordering in the digest and on the menu: lower is more urgent.
  `priority`    TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `dismissed_at` DATETIME        NULL,
  `sent_at`      DATETIME        NULL,
  `created_at`   DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder` (`user_id`, `subject_key`, `kind`, `due_date`),
  KEY `idx_reminder_user_due` (`user_id`, `due_date`),
  KEY `idx_reminder_planting` (`planting_id`),
  CONSTRAINT `fk_reminder_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_planting` FOREIGN KEY (`planting_id`)
    REFERENCES `planting` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Always written, success or nothing to do, like the weather and mail jobs.
-- This one runs HOURLY and sends to each user whose OWN local time is between
-- 06:00 and 07:00, so a row per hour is expected and most of them will say
-- "nobody was due" (Phase 3 handoff Section 1.1).
CREATE TABLE `digest_run` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at`    DATETIME     NOT NULL,
  `finished_at`   DATETIME         NULL,
  `users_due`     INT UNSIGNED NOT NULL DEFAULT 0,
  `reminders`     INT UNSIGNED NOT NULL DEFAULT 0,
  `emails_queued` INT UNSIGNED NOT NULL DEFAULT 0,
  `silent`        INT UNSIGNED NOT NULL DEFAULT 0,
  `outcome`       ENUM('ok','partial','failed') NOT NULL DEFAULT 'ok',
  `error_text`    VARCHAR(500)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_digest_run_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
