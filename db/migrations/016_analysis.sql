-- carl:kind=ddl
-- Recommendations: the Claude analysis queue and its run table
-- (handoff Section 14 "v2 -- Recommendations"; Phase 5 handoff Section 3.1).
--
-- This is the first third-party call Carl makes that is neither weather nor
-- mail, and the rule it obeys is the same one those two obey and the reason
-- is the same: NO THIRD-PARTY CALL ON THE REQUEST PATH (Phase 3 handoff
-- Section 5). A page writes a row here and returns; a cron drains it with
-- bounded retries; the answer appears on the next page load. An API that is
-- slow, rate limited or down can make the drain slow. It cannot make a page
-- slow, and it cannot 500 one.
--
-- Two tables, exactly as `email_outbox` + `mail_send_run`:
--   * `analysis` is both the queue row and, once answered, the answer.
--   * `analysis_run` is written on every drain, success or nothing to do,
--     because a cron that silently stops is otherwise invisible for months.

CREATE TABLE `analysis` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  -- Room for a per-garden or per-plant analysis later without a migration.
  -- Everything Phase 5 ships is 'season'.
  `scope`           VARCHAR(16)  NOT NULL DEFAULT 'season',
  -- The gardener's OWN local day the request was made on (handoff Section 6),
  -- not the server's. It is what the answer is dated by on screen and what
  -- the per-day cap counts.
  `requested_on`    DATE         NOT NULL,
  -- The gardener's own question, if they asked one. Bounded here as well as
  -- in the controller: this text is sent to a third party and shown back on a
  -- page, and a column is the only bound that cannot be forgotten.
  `question`        VARCHAR(500)     NULL,
  `status`          ENUM('queued','sending','done','failed') NOT NULL DEFAULT 'queued',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME     NOT NULL,
  -- Set when a drain picks the row up, cleared when it finishes. A shared
  -- host that kills a long request leaves no PHP error behind (hosting
  -- Section 4 annotation), so a row stuck in 'sending' past the lease is how
  -- the next run learns the previous one died, and it counts as an attempt
  -- rather than retrying for ever.
  `leased_until`    DATETIME         NULL,
  `model`           VARCHAR(64)      NULL,
  -- What was actually sent and what it cost, so the size question of Phase 5
  -- handoff Section 3.1 stays answerable from the live data rather than from
  -- a measurement taken once in a container.
  `document_bytes`  INT UNSIGNED NOT NULL DEFAULT 0,
  `input_tokens`    INT UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens`   INT UNSIGNED NOT NULL DEFAULT 0,
  `answer`          MEDIUMTEXT       NULL,
  `last_error`      VARCHAR(500)     NULL,
  `created_at`      DATETIME     NOT NULL,
  `completed_at`    DATETIME         NULL,
  -- One request per user per local day per question, enforced by the database
  -- rather than by reading first (hosting Section 7): a double-tapped button
  -- produces one row and the loser learns that from the duplicate-key error.
  `dedupe_key`      VARCHAR(120)     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_analysis_dedupe` (`dedupe_key`),
  KEY `idx_analysis_due` (`status`, `next_attempt_at`),
  KEY `idx_analysis_user` (`user_id`, `id`),
  CONSTRAINT `fk_analysis_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Always written, like weather_sync_run and mail_send_run.
CREATE TABLE `analysis_run` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at`  DATETIME     NOT NULL,
  `finished_at` DATETIME         NULL,
  `model`       VARCHAR(64)  NOT NULL DEFAULT '',
  `considered`  INT UNSIGNED NOT NULL DEFAULT 0,
  `completed`   INT UNSIGNED NOT NULL DEFAULT 0,
  `failed`      INT UNSIGNED NOT NULL DEFAULT 0,
  `outcome`     ENUM('ok','partial','failed','skipped') NOT NULL,
  `error_text`  VARCHAR(500)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_analysis_run_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
