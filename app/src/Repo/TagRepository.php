<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;
use Carl\Domain\LabelStock;
use Carl\Domain\PlantingState;
use PDOException;
use RuntimeException;

/**
 * QR plant tags: the pool, the bindings, and the two reads the garden makes
 * (docs/QR-TAGS-SPEC.md).
 *
 * A tag is a reusable physical object and a binding is a period of time, so
 * every read here is really one of two questions: "what is this tag on right
 * now" (scan) and "which of my plants has no tag" (untagged). The live
 * binding is the row with `unbound_at IS NULL`, and both indexes on
 * `qr_tag_binding` put that column second so it is a range and not a filter.
 */
final class TagRepository extends Repository
{
    public const CODE_LENGTH = 6;

    /**
     * Crockford base32. I, L, O and U are absent on purpose: the fallback when
     * a symbol is caked in soil is a human reading six characters off a faded
     * tag and typing them in, and those four are what a person gets wrong
     * (Section 2.4). It is also why the code is short.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Section 6.5: a tagging session that outlives the potting session is a bug. */
    public const SESSION_HOURS = 2;

    /**
     * A collision at 32^6 with a pool of a few hundred will not happen in this
     * application's lifetime, and the retry must still be written: the
     * alternative failure is a 500 on the one screen that mints things
     * (Section 5.8).
     */
    private const MINT_ATTEMPTS = 5;

    private const SCAN_SELECT =
        'SELECT t.`id` AS tag_id, t.`code`, t.`retired_at` AS tag_retired_at,'
        . ' t.`batch_id`, t.`printed_at`,'
        // planting_id comes from the BINDING, not from p.*: `planting` has an
        // `id` and no `planting_id`, so on an unbound tag -- where every p.*
        // column is null from the LEFT JOIN -- there would be no such key at
        // all, and "is this tag on something?" would be an undefined-index
        // notice instead of a null.
        . ' b.`id` AS binding_id, b.`planting_id`, b.`bound_at`,'
        . ' p.*, pt.`category`, pt.`type`, pt.`plant_family`, pt.`is_tree`,'
        . ' g.`name` AS garden_name, g.`is_indoor`, gr.`name` AS row_name,'
        . ' c.`name` AS container_name'
        . ' FROM `qr_tag` t'
        // The live binding, and through it the planting. LEFT, because an
        // unbound tag is the normal state of a freshly printed one and the
        // bind screen is what it lands on.
        . ' LEFT JOIN `qr_tag_binding` b ON b.`tag_id` = t.`id` AND b.`unbound_at` IS NULL'
        . ' LEFT JOIN `planting` p ON p.`id` = b.`planting_id`'
        . ' LEFT JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
        . ' LEFT JOIN `garden` g ON g.`id` = p.`garden_id`'
        . ' LEFT JOIN `garden_row` gr ON gr.`id` = p.`garden_row_id`'
        . ' LEFT JOIN `container` c ON c.`id` = p.`container_id`';

    protected function table(): string
    {
        return 'qr_tag';
    }

    protected function writable(): array
    {
        return ['code', 'batch_id', 'printed_at', 'retired_at'];
    }

    // -- Codes ------------------------------------------------------------

    /**
     * What a human typed, turned into what is stored.
     *
     * Upper-cases, and drops everything that is not in the alphabet -- so
     * "ab7k-4m", "AB7K 4M" and "ab7k4m" are all the same tag. Deliberately
     * does NOT map O to 0 or I to 1: the alphabet excludes them so that a
     * person never has to choose, and silently rewriting a character would
     * turn a typo into a different valid code.
     */
    public static function normalise(string $code): string
    {
        $out = '';
        $upper = \strtoupper(\trim($code));
        $length = \strlen($upper);
        for ($i = 0; $i < $length; $i++) {
            if (\strpos(self::ALPHABET, $upper[$i]) !== false) {
                $out .= $upper[$i];
            }
        }
        return $out;
    }

    public static function isWellFormed(string $code): bool
    {
        return \strlen($code) === self::CODE_LENGTH && self::normalise($code) === $code;
    }

    /** Random, never sequential: a sequential code makes every other tag guessable. */
    public static function generate(): string
    {
        $code = '';
        $last = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[\random_int(0, $last)];
        }
        return $code;
    }

    // -- The scan ---------------------------------------------------------

    /**
     * One statement: the tag, its live binding, and the planting on the other
     * end with everything the field screen shows.
     *
     * Scoped, so a tag belonging to another user comes back null -- which the
     * controller renders as the SAME 404 as a code that does not exist
     * (Section 6.2). That is deliberate: a tag on a stake in a front garden is
     * photographable from the pavement, and a distinguishable response would
     * let a stranger enumerate which codes are real.
     *
     * @return array<string,mixed>|null
     */
    public function scan(string $code): ?array
    {
        return $this->db->one(
            self::SCAN_SELECT . ' WHERE t.`user_id` = :' . self::SCOPE . ' AND t.`code` = :code',
            $this->bind(['code' => $code])
        );
    }

    /**
     * The first stake put on a planting that is still on it, or null.
     *
     * A planting can carry many stakes (Phase 13, Section 14.7) -- a tray of
     * twenty-four cells gets twenty-four -- so "the tag" is a question with
     * a list for an answer, and that list is tagsOn(). This is the single
     * answer for the places that need one: "does it have a stake at all", and
     * the code to print on a named label.
     *
     * @return array<string,mixed>|null
     */
    public function forPlanting(int $plantingId): ?array
    {
        return $this->tagsOn($plantingId)[0] ?? null;
    }

    /**
     * Every stake on a planting right now, in the order they went on.
     *
     * @return list<array<string,mixed>>
     */
    public function tagsOn(int $plantingId): array
    {
        return $this->db->all(
            'SELECT t.`id`, t.`code`, t.`retired_at`, b.`id` AS binding_id, b.`bound_at`'
            . ' FROM `qr_tag_binding` b JOIN `qr_tag` t ON t.`id` = b.`tag_id`'
            . ' WHERE b.`user_id` = :' . self::SCOPE
            . ' AND b.`planting_id` = :planting_id AND b.`unbound_at` IS NULL'
            . ' ORDER BY b.`id`',
            $this->bind(['planting_id' => $plantingId])
        );
    }

    /**
     * The codes currently on a set of plantings, keyed by planting id --
     * a LIST per planting, since a tray can carry a stake per cell.
     *
     * One statement for a whole list screen, which is what keeps "which of
     * these already has a tag" from costing a statement per row.
     *
     * @param list<int> $plantingIds
     * @return array<int,list<string>>
     */
    public function codesForPlantings(array $plantingIds): array
    {
        if ($plantingIds === []) {
            return [];
        }
        $params = [];
        $in = self::inClause($plantingIds, 'p', $params);

        $rows = $this->db->all(
            'SELECT b.`planting_id`, t.`code` FROM `qr_tag_binding` b'
            . ' JOIN `qr_tag` t ON t.`id` = b.`tag_id`'
            . ' WHERE b.`user_id` = :' . self::SCOPE
            . ' AND b.`unbound_at` IS NULL AND b.`planting_id` ' . $in
            . ' ORDER BY b.`planting_id`, t.`code`',
            $this->bind($params)
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['planting_id']][] = (string) $row['code'];
        }
        return $out;
    }

    // -- The bind list ----------------------------------------------------

    /**
     * Living plantings with no tag on them, most recently started first.
     *
     * THE PREDICATE IS "UNTAGGED" AND RECENCY IS ONLY THE SORT (Section 6.4).
     * The first draft of the spec said "recent living plantings", which breaks
     * exactly the case this exists for: a tomato that went in the ground in
     * May has no tag and is not recent, so a recency FILTER hides the plant
     * you are standing in front of. Nothing is filtered out; the May tomato is
     * four screens down and the search box finds it by name.
     *
     * Nothing here ties binding to a life stage either. A tag bound to a
     * yielding plant behaves exactly like one bound at seed_started, and the
     * only question ever asked is whether the plant already has a tag.
     *
     * @return list<array<string,mixed>>
     */
    public function untagged(string $search = '', int $limit = 200): array
    {
        $params = [self::SCOPE => $this->userId, 'ended' => PlantingState::ENDED];
        $predicate = '';

        if ($search !== '') {
            $predicate = ' AND (pt.`category` LIKE :s1 OR pt.`type` LIKE :s2 OR p.`label` LIKE :s3)';
            $like = '%' . $search . '%';
            // Emulation is off, so one value needs three names (hosting Section 7).
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
        }

        return $this->db->all(
            'SELECT p.`id`, p.`label`, p.`start_date`, p.`state`, p.`quantity_live`,'
            . ' pt.`category`, pt.`type`, g.`name` AS garden_name, gr.`name` AS row_name,'
            . ' c.`name` AS container_name'
            . ' FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' LEFT JOIN `garden` g ON g.`id` = p.`garden_id`'
            . ' LEFT JOIN `garden_row` gr ON gr.`id` = p.`garden_row_id`'
            . ' LEFT JOIN `container` c ON c.`id` = p.`container_id`'
            // NOT EXISTS over the (planting_id, unbound_at) index rather than a
            // LEFT JOIN with an IS NULL test: the join would also have to be
            // deduplicated, because a tag that has been rebound leaves closed
            // rows behind for the same planting.
            . ' WHERE p.`user_id` = :' . self::SCOPE . ' AND p.`state` <> :ended'
            . ' AND NOT EXISTS (SELECT 1 FROM `qr_tag_binding` b'
            . ' WHERE b.`planting_id` = p.`id` AND b.`unbound_at` IS NULL)'
            . $predicate
            . ' ORDER BY p.`start_date` DESC, p.`id` DESC'
            . ' LIMIT ' . (int) $limit,
            $params
        );
    }

    /**
     * The tagging session's cursor: computed, never stored.
     *
     * The plant with NO stake at all, most recently started first. A plant
     * with some of its stakes is the session's FILL TARGET, which is the
     * other half of the cursor and lives in TagController::scan().
     *
     * @return array<string,mixed>|null
     */
    public function nextUntagged(): ?array
    {
        $rows = $this->untagged('', 1);
        return $rows[0] ?? null;
    }

    public function untaggedCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM `planting` p WHERE p.`user_id` = :' . self::SCOPE
            . ' AND p.`state` <> :ended'
            . ' AND NOT EXISTS (SELECT 1 FROM `qr_tag_binding` b'
            . ' WHERE b.`planting_id` = p.`id` AND b.`unbound_at` IS NULL)',
            $this->bind(['ended' => PlantingState::ENDED]),
            0
        );
    }

    /**
     * What a scanned free tag can go on: every living plant, with how many
     * stakes it has and how many plants it has, split into the ones that
     * still want stakes and the ones that have one per plant.
     *
     * A PLANTING CAN CARRY MANY STAKES (Section 14.7), so the question the
     * bind screen asks is no longer "which plants have no tag" but "which
     * plants have fewer stakes than plants". A tray of twenty-four with three
     * stakes in it is exactly the plant you are standing at with the fourth
     * in your hand, and the list must offer it. The ones with no stake at
     * all come first, then the partly staked, most recently started first
     * within each -- and recency is only the sort (Section 6.4): the May
     * tomato with no stake is on the list, four screens down.
     *
     * The count is a guide and not a rule. A row of a hundred carrots is not
     * going to get a hundred stakes, so it sits in the "wants" list with one
     * stake for the season; and a plant that has its full count is still
     * offered, under the fold, because quantity_live is what the log says
     * and the tray is what the gardener sees.
     *
     * ONE statement for both lists.
     *
     * @return array{wants:list<array<string,mixed>>,full:list<array<string,mixed>>}
     */
    public function bindCandidates(string $search = '', int $limit = 300): array
    {
        $params = [self::SCOPE => $this->userId, 'ended' => PlantingState::ENDED];
        $predicate = '';

        if ($search !== '') {
            $predicate = ' AND (pt.`category` LIKE :s1 OR pt.`type` LIKE :s2 OR p.`label` LIKE :s3)';
            $like = '%' . $search . '%';
            // Emulation is off, so one value needs three names (hosting Section 7).
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
        }

        $rows = $this->db->all(
            'SELECT p.`id`, p.`label`, p.`start_date`, p.`state`, p.`quantity_live`,'
            . ' pt.`category`, pt.`type`, g.`name` AS garden_name, gr.`name` AS row_name,'
            . ' c.`name` AS container_name,'
            . ' (SELECT COUNT(*) FROM `qr_tag_binding` b'
            . '   WHERE b.`planting_id` = p.`id` AND b.`unbound_at` IS NULL) AS tag_count'
            . ' FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' LEFT JOIN `garden` g ON g.`id` = p.`garden_id`'
            . ' LEFT JOIN `garden_row` gr ON gr.`id` = p.`garden_row_id`'
            . ' LEFT JOIN `container` c ON c.`id` = p.`container_id`'
            . ' WHERE p.`user_id` = :' . self::SCOPE . ' AND p.`state` <> :ended'
            . $predicate
            . ' ORDER BY p.`start_date` DESC, p.`id` DESC'
            . ' LIMIT ' . (int) $limit,
            $params
        );

        $none = [];
        $some = [];
        $full = [];
        foreach ($rows as $row) {
            $count = (int) $row['tag_count'];
            if ($count === 0) {
                $none[] = $row;
            } elseif ($count < (int) $row['quantity_live']) {
                $some[] = $row;
            } else {
                $full[] = $row;
            }
        }

        return ['wants' => \array_merge($none, $some), 'full' => $full];
    }

    /**
     * Whether a planting can take another stake in a tagging session: living,
     * and fewer stakes than plants. Null when it cannot.
     *
     * @return array<string,mixed>|null the planting row with its tag_count
     */
    public function fillTarget(int $plantingId): ?array
    {
        $row = $this->db->one(
            'SELECT p.`id`, p.`label`, p.`quantity_live`, pt.`category`, pt.`type`,'
            . ' (SELECT COUNT(*) FROM `qr_tag_binding` b'
            . '   WHERE b.`planting_id` = p.`id` AND b.`unbound_at` IS NULL) AS tag_count'
            . ' FROM `planting` p JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' WHERE p.`user_id` = :' . self::SCOPE . ' AND p.`id` = :id AND p.`state` <> :ended',
            $this->bind(['id' => $plantingId, 'ended' => PlantingState::ENDED])
        );
        if ($row === null || (int) $row['tag_count'] >= (int) $row['quantity_live']) {
            return null;
        }
        return $row;
    }

    // -- Binding ----------------------------------------------------------

    /**
     * Put a tag on a planting.
     *
     * ONE LIVE BINDING PER TAG, AND AS MANY TAGS AS A PLANTING WANTS. The
     * first is physics: a stake is in one place. The second is Phase 13
     * (Section 14.7): a planting is a group -- a tray of twenty-four cells,
     * a row of thirty -- and the stake goes in the cell, so a planting of
     * twenty-four carries twenty-four. Phase 8 closed the planting's old
     * binding here as well, which made "a second stake for this tray"
     * silently pull the first one off.
     *
     * ONE TRANSACTION for the close and the open: a tag moved from one plant
     * to another must never be live on both, or on neither.
     *
     * NAMED bindTo() and not bind(): Repository::bind() is the base class's
     * parameter-binding helper, which every scoped query in this file calls.
     * PHP method names are case-insensitive and an override would have been a
     * fatal at load time -- loudly, which is the good version of this mistake.
     *
     * @return int the new binding id
     */
    public function bindTo(int $tagId, int $plantingId): int
    {
        return (int) $this->db->transaction(function () use ($tagId, $plantingId): int {
            $now = $this->now();

            $this->closeBindings('`tag_id` = :tag_id', ['tag_id' => $tagId], $now);

            $this->db->run(
                'INSERT INTO `qr_tag_binding`'
                . ' (`user_id`, `tag_id`, `planting_id`, `bound_at`, `created_at`, `updated_at`)'
                . ' VALUES (:user_id, :tag_id, :planting_id, :bound_at, :created_at, :updated_at)',
                [
                    'user_id'     => $this->userId,
                    'tag_id'      => $tagId,
                    'planting_id' => $plantingId,
                    'bound_at'    => $now,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );

            return $this->db->insertId();
        });
    }

    /**
     * Move stakes from the planting they are on to the planting the plants
     * went to -- the transplant that splits a tray (PLANTING-SPLIT-SPEC
     * Section 7: "the natural moment to bind a tag to the six plants you are
     * moving is the transplant itself").
     *
     * Only tags live on $fromPlantingId are moved; anything else in the list
     * is ignored, so a forged or stale form cannot pull a stake off some
     * other plant. Each move is a close and an open, so the stake's history
     * says "the tray, then bed two", which is what happened.
     *
     * @param list<int> $tagIds
     * @return int how many moved
     */
    public function moveTags(array $tagIds, int $fromPlantingId, int $toPlantingId): int
    {
        if ($tagIds === []) {
            return 0;
        }
        $onParent = [];
        foreach ($this->tagsOn($fromPlantingId) as $tag) {
            $onParent[(int) $tag['id']] = true;
        }

        $moved = 0;
        foreach (\array_unique($tagIds) as $tagId) {
            if (isset($onParent[(int) $tagId])) {
                $this->bindTo((int) $tagId, $toPlantingId);
                $moved++;
            }
        }
        return $moved;
    }

    /** Take a tag off whatever it is on. Returns whether anything was live. */
    public function unbind(int $tagId): bool
    {
        return $this->closeBindings('`tag_id` = :tag_id', ['tag_id' => $tagId], $this->now()) > 0;
    }

    /**
     * Undo the last bind, exactly (Section 6.5).
     *
     * Not "unbind the tag": the session binds optimistically and offers an
     * undo, and the thing to undo is the ROW that was just written. Deleting
     * it rather than closing it is what makes undo leave no trace -- a closed
     * binding would read, forever after, as "this tag was on that plant for
     * four seconds", which is a lie about a physical object.
     */
    public function undoBinding(int $bindingId): bool
    {
        return $this->db->run(
            'DELETE FROM `qr_tag_binding` WHERE `user_id` = :user_id'
            . ' AND `id` = :id AND `unbound_at` IS NULL',
            ['user_id' => $this->userId, 'id' => $bindingId]
        )->rowCount() > 0;
    }

    /**
     * Release every tag on a set of plantings -- what End Growing Season
     * offers (Section 8).
     *
     * @param list<int> $plantingIds
     * @return int how many tags went back to the pool
     */
    public function releaseForPlantings(array $plantingIds): int
    {
        if ($plantingIds === []) {
            return 0;
        }
        $params = [];
        $in = self::inClause($plantingIds, 'rel', $params);

        return $this->closeBindings('`planting_id` ' . $in, $params, $this->now());
    }

    /** @param array<string,mixed> $params */
    private function closeBindings(string $predicate, array $params, string $now): int
    {
        return $this->db->run(
            'UPDATE `qr_tag_binding` SET `unbound_at` = :now, `updated_at` = :updated'
            . ' WHERE `user_id` = :user_id AND `unbound_at` IS NULL AND ' . $predicate,
            $params + ['now' => $now, 'updated' => $now, 'user_id' => $this->userId]
        )->rowCount();
    }

    // -- The desk half (Section 5.2, finished in Phase 13) -----------------

    /**
     * Every free code, IN CODE ORDER, split by where it physically is.
     *
     * THE DESK DIRECTION OF SECTION 5.2. The scan says "here is a tag, which
     * plant?"; this answers "here is a plant, which tag?" for the plant page
     * and Start a New Plant. What the person has in front of them decides
     * the shape of the list, and over a season it is two different things:
     *
     *  - **Still on a sheet.** A code that has never been on a plant is, in
     *    all likelihood, still a label on the sheet it was printed on. It is
     *    listed by code, with the sheet and the row and column beside it, so
     *    the one you tick is the one you peel. Minting order is sheet order
     *    (LabelSheet::sheetsOf()), and the position is the tag's rank among
     *    EVERY code minted into its batch, bound or not -- so the query reads
     *    the bound ones too and ranks in PHP. A few hundred rows at most.
     *
     *  - **Loose.** A code that has been on a plant before is a stake in a
     *    box, pulled at the end of a season. Its sheet position means
     *    nothing any more; the only way to find it in a list is to read the
     *    code off the stake, so the list is by code and nothing else. This
     *    is the list that grows every year, and it is why the first version
     *    of this method -- sheet order throughout -- would have become
     *    unusable in season two.
     *
     * Both lists ascend by code so type-to-jump and a glance both work.
     * Retired sheets and retired codes are left out.
     *
     * @return array{
     *   sheet:list<array{id:int,code:string,batch_id:int,stock_sku:string,sheet:int,row:int,column:int}>,
     *   loose:list<array{id:int,code:string,batch_id:int}>
     * }
     */
    public function free(): array
    {
        $rows = $this->db->all(
            'SELECT t.`id`, t.`code`, t.`batch_id`, t.`retired_at`, bt.`stock_sku`,'
            . ' b.`id` AS binding_id,'
            . ' EXISTS (SELECT 1 FROM `qr_tag_binding` h WHERE h.`tag_id` = t.`id`) AS used_before'
            . ' FROM `qr_tag` t'
            . ' JOIN `qr_tag_batch` bt ON bt.`id` = t.`batch_id`'
            . ' LEFT JOIN `qr_tag_binding` b ON b.`tag_id` = t.`id` AND b.`unbound_at` IS NULL'
            . ' WHERE t.`user_id` = :' . self::SCOPE . ' AND bt.`retired_at` IS NULL'
            . ' ORDER BY t.`batch_id`, t.`id`',
            $this->bind([])
        );

        $sheet = [];
        $loose = [];
        $ordinal = [];
        foreach ($rows as $row) {
            $batchId = (int) $row['batch_id'];
            $index = $ordinal[$batchId] ?? 0;
            $ordinal[$batchId] = $index + 1;

            if ($row['binding_id'] !== null || $row['retired_at'] !== null) {
                continue;
            }

            if ((int) $row['used_before'] === 1) {
                $loose[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'batch_id' => $batchId];
                continue;
            }

            $stock = (string) $row['stock_sku'];
            $place = LabelStock::place($stock, $index);
            $sheet[] = [
                'id'        => (int) $row['id'],
                'code'      => (string) $row['code'],
                'batch_id'  => $batchId,
                'stock_sku' => $stock,
                'sheet'     => $place['sheet'],
                'row'       => $place['row'],
                'column'    => $place['column'],
                // The rank, so a script can take "the next twelve on this
                // sheet" in the order they will be peeled.
                'ordinal'   => $index,
            ];
        }

        $byCode = static fn (array $a, array $b): int => \strcmp($a['code'], $b['code']);
        \usort($sheet, $byCode);
        \usort($loose, $byCode);

        return ['sheet' => $sheet, 'loose' => $loose];
    }

    /** @param array{sheet:list<mixed>,loose:list<mixed>} $free */
    public static function countFree(array $free): int
    {
        return \count($free['sheet']) + \count($free['loose']);
    }

    /**
     * Every tag that is on a plant right now, with the plant: the directory
     * on the Plant tags screen.
     *
     * "Which stake is on which plant" was answerable only one sheet at a
     * time before this, which is the wrong shape for the question a person
     * asks in October -- "which of these do I pull?" -- and for the one they
     * ask in April, holding a stake with a faded code, which is "what was
     * this on?". Most recently bound first.
     *
     * @return list<array<string,mixed>>
     */
    public function inUse(int $limit = 500): array
    {
        return $this->db->all(
            'SELECT t.`id` AS tag_id, t.`code`, b.`bound_at`, p.`id` AS planting_id,'
            . ' p.`label`, p.`state`, p.`start_date`, pt.`category`, pt.`type`,'
            . ' g.`name` AS garden_name, gr.`name` AS row_name, c.`name` AS container_name'
            . ' FROM `qr_tag_binding` b'
            . ' JOIN `qr_tag` t ON t.`id` = b.`tag_id`'
            . ' JOIN `planting` p ON p.`id` = b.`planting_id`'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' LEFT JOIN `garden` g ON g.`id` = p.`garden_id`'
            . ' LEFT JOIN `garden_row` gr ON gr.`id` = p.`garden_row_id`'
            . ' LEFT JOIN `container` c ON c.`id` = p.`container_id`'
            . ' WHERE b.`user_id` = :' . self::SCOPE . ' AND b.`unbound_at` IS NULL'
            . ' ORDER BY b.`id` DESC LIMIT ' . (int) $limit,
            $this->bind([])
        );
    }

    /**
     * Retire ONE code -- the stake that snapped, the label that tore on the
     * way off the sheet -- or put it back.
     *
     * Section 13.2 left this out on the grounds that a ruined stake's code
     * "sits unbound in the pool, which is the correct state for it". It is
     * not, quite: the pool count then says a code is printed and free when
     * it is in the bin, and the day that matters is the one where the count
     * says twenty-three and the sheet has twenty-two labels left on it.
     * Nothing is deleted, as with a sheet.
     *
     * A code that is on a plant is refused: take it off first, so the
     * plant's page never claims a stake that does not exist.
     */
    public function retireTag(int $tagId, bool $retired = true): bool
    {
        $now = $this->now();

        return $this->db->run(
            'UPDATE `qr_tag` SET `retired_at` = :retired, `updated_at` = :updated'
            . ' WHERE `user_id` = :user_id AND `id` = :id'
            . ' AND NOT EXISTS (SELECT 1 FROM `qr_tag_binding` b'
            . ' WHERE b.`tag_id` = `qr_tag`.`id` AND b.`unbound_at` IS NULL)',
            ['retired' => $retired ? $now : null, 'updated' => $now,
             'user_id' => $this->userId, 'id' => $tagId]
        )->rowCount() > 0;
    }

    // -- Minting ----------------------------------------------------------

    /**
     * Mint whole sheets of blank tags.
     *
     * WHOLE SHEETS, and the form asks how many SHEETS (Section 5.1). Blank
     * tags are never printed to demand -- you print a sheet in January, the
     * codes go in a box, and you take one out whenever a plant needs a tag --
     * so there is no count to choose and no start position to set. That is the
     * whole reason the pre-printed pool exists, and it is what makes the
     * physical sheet its own state: nothing needs to track which labels have
     * been peeled, because you can see the empty positions.
     *
     * ONE multi-row INSERT for the whole batch. The codes are generated in
     * PHP, so nothing needs reading back -- which matters, because MySQL has
     * no RETURNING (hosting Section 2.2) and the database is on other
     * hardware.
     *
     * @return array{batch_id:int,codes:list<string>}
     */
    public function mint(int $sheets, string $stock): array
    {
        $stock = LabelStock::orFallback($stock);
        $sheets = \max(1, \min($sheets, 20));
        $count = $sheets * LabelStock::perSheet($stock);
        $now = $this->now();

        // ONE transaction for the batch row and its tags. A batch with no
        // tags is a sheet that renders blank and a pool count that lies; the
        // retry below lives inside it because a failed INSERT does not abort a
        // MySQL transaction, only the statement.
        /** @var array{batch_id:int,codes:list<string>} $result */
        $result = $this->db->transaction(function () use ($count, $stock, $now): array {
            $this->db->run(
                'INSERT INTO `qr_tag_batch`'
                . ' (`user_id`, `stock_sku`, `tag_count`, `created_at`, `updated_at`)'
                . ' VALUES (:user_id, :stock_sku, :tag_count, :created_at, :updated_at)',
                [
                    'user_id'    => $this->userId,
                    'stock_sku'  => $stock,
                    'tag_count'  => $count,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $batchId = $this->db->insertId();

            return ['batch_id' => $batchId, 'codes' => $this->insertCodes($batchId, $count, $now)];
        });

        return $result;
    }

    /**
     * @return list<string>
     * @throws RuntimeException when the codes cannot be made unique
     */
    private function insertCodes(int $batchId, int $count, string $now): array
    {
        for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
            // A LIST plus a separate seen-map, and NOT a map keyed by the
            // code with array_keys() over it.
            //
            // PHP casts a canonical decimal string used as an array key to an
            // integer, so the day a generated code comes out all digits with
            // no leading zero -- "123456", about one code in twelve hundred,
            // which is a couple of times a sheet over a season -- array_keys()
            // hands back an int where every signature here says string. It
            // reaches the database intact and breaks at the far end instead:
            // isWellFormed() rejects it, a strict comparison against a scanned
            // code fails, and the tag on the stake is the one Carl cannot
            // find. 21_tags_test.php caught this on its first run.
            //
            // The seen-map is still keyed by the code, which is fine and is
            // the point: both sides of the lookup cast identically, so the
            // dedupe is exact while the list keeps real strings.
            $codes = [];
            $seen = [];
            while (\count($codes) < $count) {
                $code = self::generate();
                if (isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $codes[] = $code;
            }

            // EVERY value gets its own placeholder name, including the ones
            // that are the same in every row. Emulation is off, so these are
            // real server-side prepares and a named placeholder cannot be
            // reused within one statement (hosting Section 7) -- reusing
            // :user_id across twenty-four tuples is "Invalid parameter
            // number", at the moment of minting, on the one screen that mints.
            $values = [];
            $params = [];
            foreach ($codes as $i => $code) {
                $values[] = '(:u' . $i . ', :c' . $i . ', :b' . $i . ', :p' . $i
                    . ', :cr' . $i . ', :up' . $i . ')';
                $params['u' . $i] = $this->userId;
                $params['c' . $i] = $code;
                $params['b' . $i] = $batchId;
                $params['p' . $i] = $now;
                $params['cr' . $i] = $now;
                $params['up' . $i] = $now;
            }

            try {
                $this->db->run(
                    'INSERT INTO `qr_tag` (`user_id`, `code`, `batch_id`, `printed_at`,'
                    . ' `created_at`, `updated_at`) VALUES ' . \implode(', ', $values),
                    $params
                );
                /** @var list<string> $codes */
                return $codes;
            } catch (PDOException $e) {
                // 23000 is SQLSTATE "integrity constraint violation", which
                // here can only be uq_qr_tag_code. Anything else is not ours.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException(
            'Could not mint ' . $count . ' unique tag codes in ' . self::MINT_ATTEMPTS
            . ' attempts. At 32^6 this cannot happen by chance; look for a broken '
            . 'random source before widening the retry.'
        );
    }

    // -- Batches and the pool ---------------------------------------------

    /** @return array<string,mixed>|null */
    public function findBatch(int $batchId): ?array
    {
        return $this->db->one(
            'SELECT * FROM `qr_tag_batch` WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind(['id' => $batchId])
        );
    }

    /**
     * A batch's tags with whatever each is bound to, in minting order.
     *
     * The sheet render needs the codes; the named-label render needs the plant
     * on each one. One statement serves both, because a blank sheet is the
     * same query with every join coming back null.
     *
     * @return list<array<string,mixed>>
     */
    public function batchTags(int $batchId): array
    {
        return $this->db->all(
            'SELECT t.`id`, t.`code`, t.`retired_at`, p.`id` AS planting_id, p.`label`, p.`start_date`,'
            . ' pt.`category`, pt.`type`'
            . ' FROM `qr_tag` t'
            . ' LEFT JOIN `qr_tag_binding` b ON b.`tag_id` = t.`id` AND b.`unbound_at` IS NULL'
            . ' LEFT JOIN `planting` p ON p.`id` = b.`planting_id`'
            . ' LEFT JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' WHERE t.`user_id` = :' . self::SCOPE . ' AND t.`batch_id` = :batch_id'
            . ' ORDER BY t.`id`',
            $this->bind(['batch_id' => $batchId])
        );
    }

    /**
     * Retire a whole sheet -- the one you lost.
     *
     * Under a pre-printed pool this is a real event rather than a
     * hypothetical: twenty-four codes that will never be scanned, cluttering
     * the pool count. Retiring is not deleting, so a sheet that turns up in a
     * drawer next spring still resolves and can be un-retired.
     */
    public function retireBatch(int $batchId, bool $retired = true): int
    {
        $now = $this->now();

        // ONE timestamp for the batch and its codes, and un-retiring clears
        // only the codes that carry it. A code retired on its own -- the
        // stake that snapped in May (retireTag()) -- keeps its own earlier
        // stamp, so a sheet that turns up in a drawer and is put back in the
        // pool does not resurrect the one label on it that is in the bin.
        $previous = $retired ? null : $this->db->value(
            'SELECT `retired_at` FROM `qr_tag_batch` WHERE `user_id` = :user_id AND `id` = :id',
            ['user_id' => $this->userId, 'id' => $batchId]
        );

        $this->db->run(
            'UPDATE `qr_tag_batch` SET `retired_at` = :now, `updated_at` = :updated'
            . ' WHERE `user_id` = :user_id AND `id` = :id',
            ['now' => $retired ? $now : null, 'updated' => $now,
             'user_id' => $this->userId, 'id' => $batchId]
        );

        if ($retired) {
            return $this->db->run(
                'UPDATE `qr_tag` SET `retired_at` = :now, `updated_at` = :updated'
                . ' WHERE `user_id` = :user_id AND `batch_id` = :batch_id AND `retired_at` IS NULL',
                ['now' => $now, 'updated' => $now, 'user_id' => $this->userId, 'batch_id' => $batchId]
            )->rowCount();
        }

        if (!\is_string($previous)) {
            return 0;
        }
        return $this->db->run(
            'UPDATE `qr_tag` SET `retired_at` = NULL, `updated_at` = :updated'
            . ' WHERE `user_id` = :user_id AND `batch_id` = :batch_id AND `retired_at` = :was',
            ['updated' => $now, 'user_id' => $this->userId, 'batch_id' => $batchId, 'was' => $previous]
        )->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function batches(): array
    {
        return $this->db->all(
            'SELECT b.*, COUNT(bd.`id`) AS bound_count'
            . ' FROM `qr_tag_batch` b'
            . ' LEFT JOIN `qr_tag` t ON t.`batch_id` = b.`id`'
            . ' LEFT JOIN `qr_tag_binding` bd ON bd.`tag_id` = t.`id` AND bd.`unbound_at` IS NULL'
            . ' WHERE b.`user_id` = :' . self::SCOPE
            . ' GROUP BY b.`id` ORDER BY b.`id` DESC',
            $this->bind([])
        );
    }

    /**
     * The pool, in one statement: how many tags exist, how many are on a
     * plant, how many are free, how many are retired.
     *
     * @return array{total:int,bound:int,free:int,retired:int}
     */
    public function pool(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS total,'
            . ' SUM(t.`retired_at` IS NOT NULL) AS retired,'
            . ' SUM(t.`retired_at` IS NULL AND b.`id` IS NOT NULL) AS bound,'
            . ' SUM(t.`retired_at` IS NULL AND b.`id` IS NULL) AS free'
            . ' FROM `qr_tag` t'
            . ' LEFT JOIN `qr_tag_binding` b ON b.`tag_id` = t.`id` AND b.`unbound_at` IS NULL'
            . ' WHERE t.`user_id` = :' . self::SCOPE,
            $this->bind([])
        );

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'bound'   => (int) ($row['bound'] ?? 0),
            'free'    => (int) ($row['free'] ?? 0),
            'retired' => (int) ($row['retired'] ?? 0),
        ];
    }

    /**
     * The tags bound during the current tagging session, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function boundSince(string $since, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT b.`id`, b.`bound_at`, t.`code`, p.`label`, pt.`category`, pt.`type`'
            . ' FROM `qr_tag_binding` b'
            . ' JOIN `qr_tag` t ON t.`id` = b.`tag_id`'
            . ' JOIN `planting` p ON p.`id` = b.`planting_id`'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' WHERE b.`user_id` = :' . self::SCOPE . ' AND b.`bound_at` >= :since'
            . ' AND b.`unbound_at` IS NULL'
            . ' ORDER BY b.`id` DESC LIMIT ' . (int) $limit,
            $this->bind(['since' => $since])
        );
    }
}
