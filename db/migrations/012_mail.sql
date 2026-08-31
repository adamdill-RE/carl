-- carl:kind=ddl
-- The mail outbox (handoff Section 5.8).
--
-- Nothing sends inline in a request. A page writes a row here and returns;
-- a cron drains it with bounded retries. That is the same discipline weather
-- follows, for the same reason: a third-party outage must not be able to make
-- a page slow or 500 (Phase 3 handoff Section 4.1).

CREATE TABLE `email_outbox` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- NULL for a message that is not about an account (the mail test).
  `user_id`         INT UNSIGNED     NULL,
  `kind`            VARCHAR(32)  NOT NULL,
  `to_email`        VARCHAR(190) NOT NULL,
  `to_name`         VARCHAR(120)     NULL,
  `subject`         VARCHAR(255) NOT NULL,
  `body_text`       MEDIUMTEXT   NOT NULL,
  -- Plain text first with a simple HTML twin (handoff Section 12).
  `body_html`       MEDIUMTEXT       NULL,
  -- List-Unsubscribe and List-Unsubscribe-Post ride here rather than in
  -- columns of their own: they are per-message and the driver only has to
  -- pass them through.
  `headers`         JSON             NULL,
  -- One digest per user per day, enforced by the database rather than by
  -- reading first (hosting Section 7). NULL for anything not deduplicated;
  -- MySQL allows any number of NULLs in a UNIQUE index.
  `dedupe_key`      VARCHAR(120)     NULL,
  `status`          ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`      VARCHAR(500)     NULL,
  `driver`          VARCHAR(16)      NULL,
  -- Backoff between attempts, so a mail server that is down is not hammered.
  `next_attempt_at` DATETIME     NOT NULL,
  `created_at`      DATETIME     NOT NULL,
  `sent_at`         DATETIME         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbox_dedupe` (`dedupe_key`),
  KEY `idx_outbox_due` (`status`, `next_attempt_at`),
  KEY `idx_outbox_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_outbox_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Always written, success or failure, like weather_sync_run and for the same
-- reason: a cron that silently stops is otherwise invisible for months.
CREATE TABLE `mail_send_run` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at`  DATETIME     NOT NULL,
  `finished_at` DATETIME         NULL,
  `driver`      VARCHAR(16)  NOT NULL,
  `considered`  INT UNSIGNED NOT NULL DEFAULT 0,
  `sent`        INT UNSIGNED NOT NULL DEFAULT 0,
  `failed`      INT UNSIGNED NOT NULL DEFAULT 0,
  `outcome`     ENUM('ok','partial','failed','skipped') NOT NULL,
  `error_text`  VARCHAR(500)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mail_run_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
