-- carl:kind=ddl
-- Accounts, the rotating login token, and login rate limiting.
-- Hosting Section 8.3: the PHP session is short and holds only the id of a
-- server-side token row; the token row is the real session.

CREATE TABLE `user` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`                VARCHAR(64)   NOT NULL,
  `email`                   VARCHAR(190)  NOT NULL,
  `name`                    VARCHAR(120)  NOT NULL DEFAULT '',
  `role`                    ENUM('admin','user') NOT NULL DEFAULT 'user',
  `password_hash`           VARCHAR(255)  NOT NULL,
  `must_reset_password`     TINYINT(1)    NOT NULL DEFAULT 1,
  `zip`                     VARCHAR(10)       NULL,
  `county_fips`             CHAR(5)           NULL,
  `region_id`               INT UNSIGNED      NULL,
  `latitude`                DECIMAL(8,5)      NULL,
  `longitude`               DECIMAL(8,5)      NULL,
  `timezone`                VARCHAR(64)       NULL,
  `weather_location_id`     INT UNSIGNED      NULL,
  `email_digest_enabled`    TINYINT(1)    NOT NULL DEFAULT 1,
  `email_unsubscribe_token` CHAR(64)      NOT NULL,
  `onboarded_at`            DATETIME          NULL,
  `onboarding_step`         VARCHAR(24)   NOT NULL DEFAULT 'profile',
  `created_at`              DATETIME      NOT NULL,
  `updated_at`              DATETIME      NOT NULL,
  `last_login_at`           DATETIME          NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_unsub` (`email_unsubscribe_token`),
  KEY `idx_user_county` (`county_fips`),
  KEY `idx_user_region` (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hosting Section 8.3. The cookie is selector.verifier: the selector is the
-- indexed lookup key and is useless alone; only a SHA-256 of the verifier is
-- stored, compared with hash_equals. Rotation is a compare-and-swap and only
-- the request that wins the swap sends a new cookie.
CREATE TABLE `auth_token` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `selector`       CHAR(32)     NOT NULL,
  `verifier_hash`  CHAR(64)     NOT NULL,
  `expires_at`     DATETIME     NOT NULL,
  `created_at`     DATETIME     NOT NULL,
  `rotated_at`     DATETIME     NOT NULL,
  `user_agent`     VARCHAR(190) NOT NULL DEFAULT '',
  `ip`             VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_selector` (`selector`),
  KEY `idx_auth_user` (`user_id`),
  KEY `idx_auth_expires` (`expires_at`),
  CONSTRAINT `fk_auth_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hosting Section 8.4: rate limiting and lockout do the real work on a
-- low-entropy credential. Deliberately loose numbers (10 / 15 min / 60 s).
CREATE TABLE `login_attempt` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(64)  NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `succeeded`    TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_username` (`username`, `attempted_at`),
  KEY `idx_attempt_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
