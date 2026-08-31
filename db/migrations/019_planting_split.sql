-- carl:kind=ddl
-- Splitting a planting (docs/PLANTING-SPLIT-SPEC.md).
--
-- A `planting` is a group with ONE location: it carries garden_id,
-- garden_row_id and container_id, one of each and not a list. Five features
-- read that single location as a fact -- the weather series, the watering
-- model, row occupancy, the zone fan-out and the crop-rotation warning -- so
-- a hundred tomatoes started in one tray and moved out six at a time over
-- three weekends could not be recorded at all.
--
-- The model chosen is the third of the three the spec weighs: **moving a
-- subset to a different location creates a planting**, descended from the
-- first. Every planting still has exactly one location, so all five features
-- keep working untouched -- and become correct, where they used to average
-- over plants that were not where the row said they were.
--
-- The two rejected models are worth naming so they are not re-proposed. A
-- `planting_placement` table (one planting, many locations) means a
-- quantity-weighted join in five features to avoid one row in one table. A
-- row per physical plant reintroduces the group it removes: nobody logs
-- watering a hundred times, so every event write becomes a fan-out one level
-- below the one plant_event already has.
--
-- **The backfill is `root_planting_id = id` and nulls everywhere else**, so
-- this change is a no-op for anybody who never splits. That is the main
-- de-risking property of the design, and `20_split_test.php` asserts it
-- rather than trusting it. The backfill itself is `020`, because DDL and DML
-- may not share a file (hosting Section 7).

ALTER TABLE `planting`
  -- The planting this one was moved out of. NULL for every planting that was
  -- sown rather than split off something.
  ADD COLUMN `split_from_id`    INT UNSIGNED NULL AFTER `plant_type_id`,
  -- The sowing at the top of the chain; self when this planting has never
  -- been split off anything.
  --
  -- It exists so that "everything descended from this sowing" is ONE indexed
  -- statement rather than a recursive walk. split_from_id alone would force
  -- the walk, and the report endpoints have statement-count tests
  -- (11_reports_test.php) that a walk would break.
  --
  -- NOT NULL, because a planting with no root is a planting a whole-sowing
  -- query cannot find. The DEFAULT 0 is not a value anything should ever
  -- read: it exists only because ADD COLUMN needs something to put in the
  -- rows already there, and 020 replaces every one of them in the next
  -- migration. PlantingRepository::insert() is the one writer from then on --
  -- `id` for a sowing, the parent's root for a split -- and
  -- `20_split_test.php` asserts that no row anywhere still says 0.
  --
  -- The backfill is a separate migration because it has to be: MySQL commits
  -- implicitly on DDL, so a file that mixes the two cannot be rolled back and
  -- is never safe to retry (hosting Section 7). `01_core_test.php` refuses to
  -- pass on a migration that mixes them, and it caught this one.
  ADD COLUMN `root_planting_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `split_from_id`,
  -- How many of quantity_initial were lost to ATTRITION -- died, failed to
  -- germinate, or were culled. Dispersal is deliberately not loss, which is
  -- the whole point of the column: without it, six plants transplanted out
  -- of a tray of a hundred read as six plants dying, and the plant report
  -- prints 94% survival for a planting that lost nothing.
  --
  -- A cache of the event log in the same sense that quantity_live is one,
  -- written by the same single UPDATE in recomputeState() so there is still
  -- exactly one writer of the derived quantities.
  ADD COLUMN `quantity_lost`    INT NOT NULL DEFAULT 0 AFTER `quantity_live`,
  -- Why a planting ended. `state` does not gain a value: adding one to the
  -- ENUM would touch every switch on state and every label map, where a
  -- nullable reason changes the sentence the UI prints and nothing else.
  --
  -- NULL on the rows that ended before this migration. They can only have
  -- ended by attrition -- nothing could disperse yet -- and the UI branches
  -- on 'dispersed', so a null reads as the sentence it has always read as.
  ADD COLUMN `ended_reason`     ENUM('attrition','dispersed') NULL AFTER `ended_at`,
  ADD KEY `idx_planting_split_from` (`split_from_id`),
  -- user_id first: every read is scoped, and a whole-sowing query wants the
  -- scope and the root together.
  ADD KEY `idx_planting_root` (`user_id`, `root_planting_id`);

ALTER TABLE `planting`
  ADD CONSTRAINT `fk_planting_split_from` FOREIGN KEY (`split_from_id`)
    REFERENCES `planting` (`id`) ON DELETE SET NULL;

-- The event vocabulary gains one type. `split_out` is recorded ON THE PARENT
-- with a negative quantity_delta, and the child's own first event is the
-- physical one -- transplanted, up_potted or moved -- because that is what
-- happened to it.
--
-- The negative delta flows through the quantity arithmetic with no change to
-- it at all: PlantingState::derive() already sums every non-null delta. What
-- it is NOT is attrition, and EventType keeps the two apart by name
-- (isAttrition / isDispersal) rather than by sign.
ALTER TABLE `plant_event`
  MODIFY COLUMN `event_type` ENUM(
    'seed_started','direct_sown','transplanted_in','watered',
    'germinated','germination_failed','died','up_potted',
    'hardening_started','hardening_schedule_set','transplanted',
    'culled','yielded','pest_observed','pest_treated',
    'fertilized','amended','mulched','photo_added','note','moved',
    'split_out'
  ) NOT NULL,
  -- Which planting the plants went to. On the split_out row only; the join
  -- is what lets the parent's timeline say "6 moved to Bed A" with a link,
  -- and it is a column rather than a JSON payload key so that reading it
  -- back is a join and not a decode.
  ADD COLUMN `split_planting_id` INT UNSIGNED NULL AFTER `container_id`,
  ADD KEY `idx_pe_split_planting` (`split_planting_id`),
  -- SET NULL, not CASCADE: deleting a child must not delete the parent's
  -- record that the plants left. The event survives with its quantity and
  -- its date, and only the link goes.
  ADD CONSTRAINT `fk_pe_split_planting` FOREIGN KEY (`split_planting_id`)
    REFERENCES `planting` (`id`) ON DELETE SET NULL;
