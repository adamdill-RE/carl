-- carl:kind=ddl
-- What a water zone actually puts down (Phase 14).
--
-- Handoff Section 11 turns a logged watering into a depth by the flow rate
-- on the water METHOD -- a free-text "12 mm/h" typed once under Lists -- or,
-- failing that, by a guess per method name. Nobody knows the flow rate of
-- their drip line in millimetres an hour. What they know is what is printed
-- on the emitter packet: half a gallon an hour, every twelve inches. And
-- what the packet does not say is how far apart the lines are, which is the
-- other half of the area each emitter wets, and which is a fact about the
-- ZONE rather than about the method. So the figures live here.
--
-- The arithmetic is the one every extension service prints:
--
--     rate (in/h) = 231 x gph / (emitter spacing in x line spacing in)
--
-- 231 being the cubic inches in a US gallon, so gallons an hour over square
-- inches is inches an hour; and 25.4 turns that into the millimetres the
-- checkbook is kept in. See Carl\Domain\DripLine.
--
-- EFFICIENCY, because not all of the water an emitter puts out reaches the
-- root zone: a line lies on a slope, an emitter clogs, a run off the end of
-- a bed waters the path. Drip systems in the field measure 80-95 per cent;
-- the default here is 80, which is the conservative end and errs the way
-- WaterMethod already errs -- toward thinking the soil is drier than it is,
-- because a suggestion to water that was not needed is the mistake a
-- gardener notices and ignores, and the other one is a dead bed.
--
-- All four are NULL-able or defaulted so every existing zone is unchanged:
-- a zone with no emitter figure keeps using the method's rate exactly as
-- before, and the recommendation says which it used.
ALTER TABLE `water_zone`
  -- US gallons per hour per emitter, the unit on the packet. DECIMAL(6,3)
  -- because drip tape is sold at 0.25 gph and some at 0.16.
  ADD COLUMN `emitter_gph`        DECIMAL(6,3)     NULL AFTER `water_method_id`,
  -- Inches between emitters along the line.
  ADD COLUMN `emitter_spacing_in` DECIMAL(6,2)     NULL AFTER `emitter_gph`,
  -- Inches between lines. NULL means "use this garden's row spacing", which
  -- the model derives from the garden's width and row count when it can.
  ADD COLUMN `line_spacing_in`    DECIMAL(6,2)     NULL AFTER `emitter_spacing_in`,
  -- Per cent of the emitter's output that reaches the root zone.
  ADD COLUMN `efficiency_pct`     TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER `line_spacing_in`;
