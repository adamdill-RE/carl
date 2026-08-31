-- carl:kind=ddl
-- The tokenised set-password link (Phase 5 handoff Section 3.5, which is
-- Phase 3 handoff Section 9.4's outstanding item).
--
-- A temporary password in an inbox sits there until the account is first
-- used, and it is still readable long after. The link this table backs takes
-- the password out of the mail entirely: the mail carries a one-shot,
-- expiring credential that can do exactly one thing -- set the password on
-- the account it was issued for -- and stops working the moment it is used.
--
-- The ON-SCREEN temporary password is unchanged and stays (Phase 3 handoff
-- Section 4.1): it works with no mailbox configured, it works when a message
-- bounces, and it is the only path that works the first time an install is
-- stood up. The link supplements it; it does not replace it.
--
-- Phase 5 handoff Section 3.5 says to reuse `Carl\Auth\TokenStore`. It is the
-- right PATTERN and the wrong class: TokenStore's rows are login sessions and
-- resolve() rotates them on every use, which is exactly wrong for a one-shot
-- invitation. What is reused is its discipline, and this table mirrors
-- `auth_token` deliberately -- the cookie there and the URL here are both a
-- selector plus a verifier, the selector is the indexed lookup and useless on
-- its own, and only a SHA-256 of the verifier is stored so a database copy
-- does not hand anyone a working link.

CREATE TABLE `password_invite` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `selector`      CHAR(32)     NOT NULL,
  `verifier_hash` CHAR(64)     NOT NULL,
  `expires_at`    DATETIME     NOT NULL,
  -- Single use. A used row is kept rather than deleted so the page can say
  -- "this link has already been used" instead of "this link is not valid",
  -- which is the difference between a person signing in and a person emailing
  -- the administrator.
  `used_at`       DATETIME         NULL,
  `created_at`    DATETIME     NOT NULL,
  `created_by`    INT UNSIGNED     NULL,
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_selector` (`selector`),
  KEY `idx_invite_user` (`user_id`, `id`),
  KEY `idx_invite_expires` (`expires_at`),
  CONSTRAINT `fk_invite_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invite_creator` FOREIGN KEY (`created_by`)
    REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
