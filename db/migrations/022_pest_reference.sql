-- carl:kind=ddl
-- The pest and disease reference (Phase 9).
--
-- THE QUESTION THIS ANSWERS was "should Carl load a list of pests for the
-- user, or leave it self-serve", and the answer is in three parts.
--
-- 1. THE MECHANISM WAS ALREADY BUILT AND HAD NOTHING TO COPY.
--    `ListRepository::seedForNewUser()` has copied the global `pest` table
--    into each account's `pest_disease` list since Phase 1, carrying
--    `user_list_item.pest_id` as the join back, and
--    `syncPestsFromReference()` back-fills the ones added later. But `pest`
--    is only ever written by the research import, which is per-county and is
--    an owner action -- so in practice every account has opened an EMPTY
--    dropdown and typed. This migration is not a new mechanism. It is a
--    source for the one that exists.
--
-- 2. FREE TEXT DESTROYS THE DATA, QUIETLY. "aphids", "Aphids", "aphid" and
--    "green fly" are four rows with four ids, and every report, every
--    year-over-year comparison and every recommendation document then reads
--    them as four different problems. `pest_id` is the join that makes "what
--    did aphids cost me over three seasons" answerable at all, and it can
--    only ever be set by choosing from a catalogue.
--
-- 3. THE MOMENT OF USE IS THE WORST MOMENT TO ASK. Somebody logging a pest
--    is standing at a plant with a hole in a leaf. That is a diagnosis, not
--    data entry. A named list with the signs beside each name turns typing
--    into recognising -- which is the same trade the QR tag made, and the
--    reason both features exist.
--
-- WHAT THE USER MAY STILL DO IS UNCHANGED AND IS THE POINT: `user_list_item`
-- with a NULL `pest_id` is "mine", it is still created by the inline "+ Add
-- new..." on the log form, and the Lists screen now shows which entries came
-- with Carl and which are the account's own. A catalogue you cannot add to is
-- a catalogue that is wrong about somebody's garden.
--
-- WHY THE COLUMNS ARE STRUCTURED RATHER THAN ONE `treatments` BLOB. The ask
-- was for chemicals, other remedies and consequences, and those are four
-- different decisions a gardener makes at four different moments: what will
-- happen if I ignore this, what should I have done in March, what can I do
-- today without reaching for a spray, and what is the spray if I must. A
-- single paragraph makes all four unfindable. The shape follows what
-- extension pest notes have used for decades -- identification, damage,
-- monitoring, cultural, biological and chemical control -- because that shape
-- is what the literature this is summarised from is already written in.
--
-- `treatments` STAYS, and it is the RESEARCH IMPORT's column, not this one.
-- The importer writes seven columns keyed on `pest_key`
-- (research-template/README.md) and knows nothing about the ones added here,
-- so a county dataset enriches a built-in row rather than replacing it, and
-- a template version 1 or 2 zip still imports unchanged. That is deliberate:
-- taking the template to version 3 would have broken every dataset already
-- produced, for a gain a later phase can still have.
--
-- ON NAMING CHEMICALS AT ALL. `chemical_controls` names ACTIVE INGREDIENTS
-- and never a brand, never a rate, and never a crop clearance. Under FIFRA
-- section 12(a)(2)(G) using a pesticide inconsistently with its labeling is a
-- federal violation, registrations differ by state, and a product's label --
-- not this table -- is the legal authority on what it may be used on and how
-- much. Extension publications write for home gardeners the same way and for
-- the same reason. `pollinator_risk` is a column rather than a sentence
-- because it is the one hazard that is invisible at the moment of spraying:
-- spinosad, for instance, is acutely toxic to bees while it is wet and
-- effectively harmless once it has dried, so "spray at dusk" is the whole
-- difference and nothing on the packet shouts it.

ALTER TABLE `pest`
  -- Identity. `also_called` exists because a gardener searches for what they
  -- call it: nobody types "Melittia cucurbitae", and half the country says
  -- "cabbage worm" for three different caterpillars.
  ADD COLUMN `latin_name`         VARCHAR(120)     NULL AFTER `name`,
  ADD COLUMN `also_called`        VARCHAR(255)     NULL AFTER `latin_name`,

  -- The GLOBAL host list, semicolon-separated, empty meaning "anything"
  -- (the same convention as `pest_region.affects_categories` and every other
  -- multi-valued cell in the research template). The regional row still
  -- overrides it: what a pest attacks is a fact about the pest, and which of
  -- those crops matter in one county is a fact about the county.
  ADD COLUMN `affects_categories` VARCHAR(500)     NULL AFTER `kind`,

  -- What it costs to ignore. An enum for sorting and a sentence for reading:
  -- "serious" and "you will lose the vine in a fortnight" are answering the
  -- same question at two different levels of detail.
  ADD COLUMN `severity`           ENUM('cosmetic','manageable','serious','fatal') NULL
                                  AFTER `affects_categories`,
  ADD COLUMN `consequence`        TEXT             NULL AFTER `signs`,

  -- Diagnosis. `look_alikes` is the field that stops a nutrient deficiency
  -- being sprayed with an insecticide, which is a real and common way for a
  -- garden to get worse.
  ADD COLUMN `look_alikes`        VARCHAR(500)     NULL AFTER `consequence`,
  ADD COLUMN `monitoring`         TEXT             NULL AFTER `look_alikes`,

  -- The four answers, in the order IPM asks them.
  ADD COLUMN `prevention`         TEXT             NULL AFTER `monitoring`,
  ADD COLUMN `organic_controls`   TEXT             NULL AFTER `prevention`,
  ADD COLUMN `chemical_controls`  TEXT             NULL AFTER `organic_controls`,
  ADD COLUMN `beneficials`        VARCHAR(500)     NULL AFTER `chemical_controls`,

  -- Set where a control that is otherwise sensible will kill bees if it is
  -- applied on open flowers or while they are flying.
  ADD COLUMN `pollinator_risk`    TINYINT(1)   NOT NULL DEFAULT 0 AFTER `beneficials`,

  -- "This row came with Carl." Not "this row is authoritative": a research
  -- import can and should overwrite the seven columns it owns on top of it.
  ADD COLUMN `is_builtin`         TINYINT(1)   NOT NULL DEFAULT 0 AFTER `pollinator_risk`,

  -- The reference screen lists by kind and then by name, and the catalogue is
  -- now big enough that the sort is worth an index rather than a filesort.
  ADD KEY `idx_pest_kind_name` (`kind`, `name`);
