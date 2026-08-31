-- carl:kind=dml
-- The first administrator. Handoff Section 14: seeded admin/1234, forced
-- reset on first login.
--
-- The hash below is bcrypt cost 11 (hosting Section 8.4) of the literal
-- string 1234. It is a placeholder credential and must_reset_password is 1,
-- so the first login cannot proceed without replacing it. /setup?key= can
-- also set this credential directly, which is the route to use if the seed
-- has already been consumed.
--
-- Idempotent: re-running changes nothing, because the row is matched on the
-- username and the update touches no column.

INSERT INTO `user`
  (`username`, `email`, `name`, `role`, `password_hash`, `must_reset_password`,
   `email_unsubscribe_token`, `onboarding_step`, `created_at`, `updated_at`)
VALUES
  ('admin', 'carl@reshiftmanager.com', 'Administrator', 'admin',
   '$2y$11$IA0AcmNwBSvGKn0IRspyJu.k7oGdDZ1vLiuZcEwmOVZfBrwt9Nvuu',
   1, 'efa91420980ec413d5a32edb1eb974b3d95a3afeecb90ac89eaf8823a6cc1341', 'profile', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `username` = `username`;
