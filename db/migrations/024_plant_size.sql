-- carl:kind=ddl
-- How big is it? (Phase 12.)
--
-- The event log already answers "what did you do" and "how much did it give
-- you". It could not answer "how big is it", which is the question a gardener
-- asks a plant more often than any other and the one every photograph is a
-- worse record of than a tape measure.
--
-- TWO COLUMNS, NOT ONE, and either may stand alone. A tomato is a height, a
-- squash is a spread, and a shrub is both -- so "height OR diameter OR both"
-- is the shape of the field, exactly as `yielded` is "weight OR count,
-- whichever you actually measured" (handoff Section 4.4).
--
-- MILLIMETRES, because weather.md Section 6.3 is the rule the rest of this
-- schema follows: store SI, convert once at display in Carl\Support\Units.
-- `weight_g` is grams for the same reason. The exception in this schema is
-- `planting.initial_height_in` / `initial_width_in`, which are inches because
-- they were written to match the research tables, where an inch is the unit
-- printed on a seed packet rather than a measurement to convert
-- (research-template README, "Units"). A measurement a gardener takes with a
-- tape is not that, so these two do not follow those two.
--
-- DECIMAL(8,2) millimetres reaches 9,999,999.99 mm -- about ten kilometres.
-- That is absurd for a plant and it is the point: the type can never be the
-- thing that refuses a legitimate measurement, and the form is where an
-- implausible one is caught, in units the gardener typed.
ALTER TABLE `plant_event`
  -- A measurement is not tied to `measured`: it rides on whatever event the
  -- gardener was already logging, the way `narrative` and photos do. Somebody
  -- standing at a bed with a hose in one hand records ONE thing -- "watered
  -- it, it's fourteen inches now" -- and asking them to submit the form twice
  -- is how a field stops being filled in. `measured` below is for the visit
  -- where measuring was the whole errand.
  ADD COLUMN `height_mm`   DECIMAL(8,2) NULL AFTER `count_qty`,
  -- "Diameter" is the word the gardener used and so it is the word on the
  -- form; the column is the widest measurement across the plant, whether that
  -- is a canopy, a spread or a rosette.
  ADD COLUMN `diameter_mm` DECIMAL(8,2) NULL AFTER `height_mm`,
  -- The vocabulary gains one type. It carries no quantity delta, no reference
  -- and no placement: it is `note` with a tape measure, and like `note` it
  -- changes no state (Carl\Domain\PlantingState::derive has no case for it,
  -- deliberately -- measuring a plant does not move it, end it or grow it).
  MODIFY COLUMN `event_type` ENUM(
    'seed_started','direct_sown','transplanted_in','watered',
    'germinated','germination_failed','died','up_potted',
    'hardening_started','hardening_schedule_set','transplanted',
    'culled','yielded','pest_observed','pest_treated',
    'fertilized','amended','mulched','photo_added','note','moved',
    'split_out','measured'
  ) NOT NULL,
  -- The size series is read as "every measurement this planting has, in date
  -- order", which is a range scan the existing (planting_id, event_date) key
  -- already serves. What it does NOT serve is finding them at all without
  -- reading every event of the planting, so the index is on the column that
  -- says a row has one. Height is the one indexed because a plant measured at
  -- all is nearly always measured for height; a diameter-only row is found by
  -- the same scan and is rare enough not to earn a second key.
  ADD KEY `idx_pe_measured` (`planting_id`, `height_mm`);
