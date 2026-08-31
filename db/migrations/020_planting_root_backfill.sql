-- carl:kind=dml
-- The backfill half of 019 (docs/PLANTING-SPLIT-SPEC.md Section 4.2).
--
-- Separate from the DDL because it has to be: MySQL commits implicitly on
-- DDL, so a migration that mixes schema and data cannot be rolled back and
-- is never safe to retry on a host with no staging copy (hosting Section 7).
-- `01_core_test.php` asserts that no migration mixes them.
--
-- Every planting that exists today is its own root. Predicated on the
-- placeholder rather than run blind, so a re-run applies to nothing and the
-- migration is idempotent the way a browser-run migration has to be.
--
-- ORDER MATTERS ON DEPLOY. Between 019 landing and this running, every
-- pre-existing planting has root_planting_id = 0 and is invisible to a
-- whole-sowing query. Nothing in the application reads that column yet, so
-- the window is harmless -- but run them together, in order, and do not stop
-- between the two.

UPDATE `planting` SET `root_planting_id` = `id` WHERE `root_planting_id` = 0;
