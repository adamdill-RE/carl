-- carl:kind=ddl
-- QR plant tags (docs/QR-TAGS-SPEC.md).
--
-- A physical tag that lives with a plant from the seed cell to the end of the
-- season, carrying a code that a phone camera turns into a one-tap logging
-- screen. Section 0 is the whole argument: logging a watering costs six
-- interactions today and a scan collapses it to two.
--
-- THE SPEC CALLS THIS MIGRATION 016 AND IT CANNOT BE. 016 is the analysis
-- queue, applied in Phase 5, and migrations are immutable once applied
-- (hosting Section 7). The spec was written against the Phase 5 handoff, when
-- 015 was the last one. This is 021, and nothing else about Section 3.1
-- changes.
--
-- WHAT THE CODE IDENTIFIES (Section 3): a reusable PHYSICAL TAG, bound to a
-- planting -- not a planting, and not a position in a garden. Three reasons,
-- and the first is the one that decides it: printing is decoupled from
-- planting. You print a stack of blank tags in January at a desk; in April
-- you are in the garage with wet hands and a tray and you need a tag NOW. A
-- planting-specific tag cannot exist until the planting does. Tags also
-- outlive plantings -- a hundred stakes are bought once and reused for years
-- -- and the binding itself is worth keeping: "this tag was Cherokee Purple
-- in 2026 and Provider beans in 2027" is a fact about a real object.
--
-- ONE TAG PER PLANTING IS CORRECT BY CONSTRUCTION, and nothing here has to
-- enforce it as a rule. Section 10 Q1 asked whether twelve tags may point at
-- one planting; the answer became docs/PLANTING-SPLIT-SPEC.md, which was
-- built first for exactly this reason. A planting is location-singular, so a
-- tag names a group that is all in one place. There is no rule to invent for
-- "a tag on a planting that later splits" either: the tag stays on the
-- planting it was bound to and the plants that move out get their own.


-- A printed sheet. The spec's Section 3.1 lists two tables; its Section 5.4
-- lists /tags/batches/{id}.pdf, .../retire and "stock_sku is recorded on the
-- batch", which is a third. It is here because the render has to be a pure
-- function of this row:
--
--   The MINT is a POST and the RENDER is a GET, so a paper jam does not cost
--   thirty codes -- you re-open the same URL and print it again. That only
--   works if the second render is byte-identical to the first, which means
--   the stock cannot be read from the user's current preference. A user who
--   printed on 60517 in January and has since switched to 00757 must get
--   60517 back, or the reprint is subtly misaligned against the half-used
--   sheet they are holding.
CREATE TABLE `qr_tag_batch` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  -- The Carl\Domain\LabelStock key, VARCHAR and validated in code rather than
  -- an ENUM: the list of stocks will grow, migrations are immutable, and an
  -- ENUM would make adding a third stock cost an ALTER TABLE. Section 5.3,
  -- and `user.onboarding_step` is the precedent.
  `stock_sku`  VARCHAR(32)  NOT NULL,
  -- How many tags were minted into it. Denormalised on purpose: the pool
  -- screen lists batches and would otherwise cost a COUNT per row.
  `tag_count`  SMALLINT UNSIGNED NOT NULL,
  -- A sheet that was lost or ruined. Retiring is not deleting: the rows stay,
  -- so a batch that turns up in a drawer next spring still resolves and can
  -- be un-retired (Section 5.4).
  `retired_at` DATETIME         NULL,
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_batch_user` (`user_id`, `retired_at`),
  CONSTRAINT `fk_batch_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The tag itself: a piece of plastic with six characters printed on it.
CREATE TABLE `qr_tag` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  -- Six characters of Crockford base32 -- 0123456789ABCDEFGHJKMNPQRSTVWXYZ,
  -- which omits I, L, O and U precisely so a human reading a faded tag cannot
  -- confuse them (Section 2.4). 32^6 is about 1.07 billion.
  --
  -- GLOBALLY UNIQUE, not unique per user, and that is deliberate: the code
  -- appears in a URL that must resolve BEFORE the user is known. user_id
  -- scopes it after resolution, through the repository base class like every
  -- other table (handoff Section 5).
  --
  -- Stored uppercase. The route matches case-insensitively and upper-cases
  -- before lookup, because a camera may hand back either and because
  -- uppercase is what keeps the URL in QR alphanumeric mode (Section 2.2).
  `code`       CHAR(6)      NOT NULL,
  `batch_id`   INT UNSIGNED     NULL,
  `printed_at` DATETIME         NULL,
  -- Physically destroyed, lost, or on a sheet that was retired.
  `retired_at` DATETIME         NULL,
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qr_tag_code` (`code`),
  KEY `idx_qr_tag_user` (`user_id`, `retired_at`),
  KEY `idx_qr_tag_batch` (`batch_id`),
  CONSTRAINT `fk_qr_tag_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  -- SET NULL, not CASCADE: deleting a batch must not destroy tags that are on
  -- stakes in a garden. The tag loses only its provenance.
  CONSTRAINT `fk_qr_tag_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `qr_tag_batch` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Which planting a tag is on, and when it was and was not.
--
-- A HISTORY, NOT A POINTER. The live binding is the row with unbound_at NULL,
-- and there is at most one per tag. Keeping the closed rows is what makes an
-- old photograph of a stake tell the truth about what it was.
--
-- user_id IS ON THIS TABLE even though it is reachable through qr_tag, and
-- that is not redundancy for its own sake: handoff Section 5 says every
-- user-owned table carries user_id and every query filters on it, enforced in
-- ONE base class. A binding table that could only be scoped by joining is a
-- table whose next query is the one that forgets to.
CREATE TABLE `qr_tag_binding` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `tag_id`      INT UNSIGNED NOT NULL,
  `planting_id` INT UNSIGNED NOT NULL,
  `bound_at`    DATETIME     NOT NULL,
  `unbound_at`  DATETIME         NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  -- The live-binding lookups, both directions. unbound_at is second in each
  -- so "the live one" is an index range and not a filter.
  KEY `idx_binding_tag` (`tag_id`, `unbound_at`),
  KEY `idx_binding_planting` (`planting_id`, `unbound_at`),
  KEY `idx_binding_user` (`user_id`, `unbound_at`),
  CONSTRAINT `fk_binding_user` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binding_tag` FOREIGN KEY (`tag_id`)
    REFERENCES `qr_tag` (`id`) ON DELETE CASCADE,
  -- CASCADE, unlike the batch link above: a binding to a planting that no
  -- longer exists is not history, it is a dangling row.
  CONSTRAINT `fk_binding_planting` FOREIGN KEY (`planting_id`)
    REFERENCES `planting` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE `user`
  -- Which label stock this user prints on (Section 5.3). Both recommended
  -- stocks are first-class because the choice follows the printer they own,
  -- and the default is the SELF-LAMINATING one because it is the one that
  -- cannot fail on an unknown printer: the print is sealed under a clear
  -- polyester flap, so ordinary inkjet output survives a season of watering.
  -- A user with a laser printer changes this once.
  --
  -- A column on `user`, following email_digest_enabled, rather than a
  -- settings table: the schema does not have one and two preferences do not
  -- justify inventing it.
  ADD COLUMN `label_stock` VARCHAR(32) NOT NULL DEFAULT 'avery_00757'
    AFTER `email_digest_enabled`,
  -- The tagging session (Section 6.5). Twelve plants and twelve tags is the
  -- seed-starting reality, and scan-pick-scan-pick is twelve list-picks on a
  -- phone with wet hands. A session inverts it: Carl holds the cursor, names
  -- the next untagged plant, and the SCAN is the confirm. Twelve scans, zero
  -- taps.
  --
  -- IT LIVES ON THE USER ROW because it cannot live in the page: the scan is
  -- a full page load -- the camera navigates to /t/<code> -- so nothing
  -- client-side survives it. It costs no statement either, because
  -- Auth::user() already SELECTs this row on every request, so the flag rides
  -- along in a query that was happening anyway and the three-statement budget
  -- of Section 6.3 survives intact.
  --
  -- A TIMESTAMP AND NOT A FLAG, because the session expires. Two hours. A
  -- silent binding mode that outlives the potting session is a way to attach
  -- a tag to the wrong plant a week later and never find out.
  --
  -- There is deliberately no cursor column: "the next untagged plant" is a
  -- query with LIMIT 1, and a stored pointer would go stale the moment a
  -- plant was tagged by another route or ended.
  ADD COLUMN `tagging_started_at` DATETIME NULL AFTER `label_stock`;
