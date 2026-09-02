-- carl:kind=ddl
-- Bearer tokens for the MCP server (Phase 16; Phase 15 handoff Section 3.1).
--
-- A token is a long-lived credential a person pastes into a Claude Code
-- config file once, so it is the `auth_token` shape of hosting Section 8.3
-- MINUS the rotation: the cookie rotates on every use because a browser can
-- receive a new one, and a config file cannot. The selector is the indexed
-- lookup and useless alone; only a SHA-256 of the verifier is stored, so a
-- database copy is not a working token.
--
-- Revoked rows are kept, not deleted: the Connect screen lists them so a
-- person can see that the token they lost is dead, and `last_used_at` is
-- what tells a token nobody has used in a year from one in daily use.
--
-- The two window columns are the rate limit (Section 3.1, "limits that are
-- not optional"): calls in the current minute and when that minute started,
-- kept on the row rather than in a log table because every call already
-- writes `last_used_at` here and a second statement per call would be the
-- whole cost of the feature doubled (hosting Section 9).

CREATE TABLE `api_token` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  -- What the person called it: "laptop", "work machine". Shown back to them
  -- and nowhere else.
  `label`             VARCHAR(60)  NOT NULL,
  `selector`          CHAR(32)     NOT NULL,
  `verifier_hash`     CHAR(64)     NOT NULL,
  `created_at`        DATETIME     NOT NULL,
  `last_used_at`      DATETIME         NULL,
  `revoked_at`        DATETIME         NULL,
  -- Lifetime calls, for the screen.
  `calls`             INT UNSIGNED NOT NULL DEFAULT 0,
  -- The rate-limit window: calls since `window_started_at`.
  `window_started_at` DATETIME         NULL,
  `window_calls`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_token_selector` (`selector`),
  KEY `idx_api_token_user` (`user_id`, `revoked_at`),
  CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
