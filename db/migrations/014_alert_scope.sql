-- carl:kind=ddl
-- An NWS alert is unique PER LOCATION, not globally.
--
-- Migration 009 put a unique key on `nws_id` alone. That is wrong the moment
-- two weather locations share a county, which two users a few ZIPs apart
-- routinely do: api.weather.gov returns the same alert id to both, the second
-- upsert moves the single row from one location to the other, and one of the
-- two users silently stops seeing a freeze warning that is genuinely over
-- their garden.
--
-- Migrations are immutable once applied (hosting Section 7), so 009 stays as
-- it is and this corrects it.

ALTER TABLE `weather_alert`
  DROP INDEX `uq_alert_nws`,
  ADD UNIQUE KEY `uq_alert_loc_nws` (`location_id`, `nws_id`);
