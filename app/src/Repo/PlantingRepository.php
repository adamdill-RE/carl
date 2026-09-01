<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Domain\EventType;
use Carl\Domain\PlantingState;

/**
 * Plantings (handoff Section 5.3). A planting is the asset; everything that
 * happens to it is a plant_event. state, quantity_live, quantity_lost and
 * ended_reason on this table are caches of the event log, recomputed by
 * recomputeState() -- all four by the one UPDATE in it.
 *
 * A planting has exactly ONE location, and split() is what keeps that true
 * when only part of a group moves (docs/PLANTING-SPLIT-SPEC.md).
 */
final class PlantingRepository extends Repository
{
    protected function table(): string
    {
        return 'planting';
    }

    protected function writable(): array
    {
        return [
            'plant_type_id', 'split_from_id', 'root_planting_id',
            'garden_id', 'garden_row_id', 'container_id', 'label',
            'start_method', 'start_date', 'quantity_initial', 'quantity_live',
            'quantity_lost',
            'state', 'state_changed_at', 'in_ground_date', 'ended_at', 'ended_reason',
            'germinated_at',
            'hardening_started_at', 'hardening_days', 'hardening_schedule_id',
            'default_water_method_id', 'seed_source_id', 'nursery_id', 'trellis_used',
            'collar_used', 'seeds_per_collar', 'initial_height_in', 'initial_width_in', 'notes',
        ];
    }

    /** The columns every list and report needs, joined once. */
    private const LIST_SELECT =
        'SELECT p.*, pt.category, pt.type, pt.plant_family, pt.dtm_days_min, pt.dtm_days_max,'
        . ' pt.dtm_counted_from, pt.is_tree,'
        . ' g.name AS garden_name, g.is_indoor, gr.name AS row_name, gr.ordinal AS row_ordinal,'
        . ' c.name AS container_name,'
        // The stakes on this plant right now (docs/QR-TAGS-SPEC.md Section
        // 14.7): how many, and the codes in one string. Two correlated
        // subqueries and no more statements. NOT a LEFT JOIN: a planting can
        // carry a stake per cell, and a join would return the tray once per
        // stake. `unbound_at IS NULL` is the live binding; closed ones -- a
        // stake that moved on with a split -- are exactly what it excludes.
        . ' (SELECT COUNT(*) FROM `qr_tag_binding` qb'
        . '   WHERE qb.planting_id = p.id AND qb.unbound_at IS NULL) AS tag_count,'
        . ' (SELECT GROUP_CONCAT(qt.code ORDER BY qt.code SEPARATOR \' \')'
        . '   FROM `qr_tag_binding` qb JOIN `qr_tag` qt ON qt.id = qb.tag_id'
        . '   WHERE qb.planting_id = p.id AND qb.unbound_at IS NULL) AS tag_codes'
        . ' FROM `planting` p'
        . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
        . ' LEFT JOIN `garden` g ON g.id = p.garden_id'
        . ' LEFT JOIN `garden_row` gr ON gr.id = p.garden_row_id'
        . ' LEFT JOIN `container` c ON c.id = p.container_id';

    /**
     * Every planting is its own root until it is split off something.
     *
     * root_planting_id is NOT NULL and has no default, so it cannot be left
     * for later. An AUTO_INCREMENT id is not known until the row exists, so a
     * sowing is inserted with a placeholder and pointed at itself in the same
     * breath -- one extra statement on a write path that already costs five,
     * in exchange for "everything descended from this sowing" being one
     * indexed read forever after.
     *
     * A split passes its own root_planting_id and never reaches the UPDATE.
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        $root = $data['root_planting_id'] ?? null;
        $data['root_planting_id'] = $root ?? 0;
        $id = parent::insert($data);

        if ($root === null) {
            $this->db->run(
                'UPDATE `planting` SET `root_planting_id` = `id` WHERE ' . $this->scoped('`id` = :id'),
                $this->bind(['id' => $id])
            );
        }
        return $id;
    }

    /**
     * @param array{category?:string,type?:string,state?:string,garden_id?:int,
     *              garden_row_id?:int,living?:bool,search?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listWithDetail(array $filters = [], int $limit = 500): array
    {
        $predicates = ['p.user_id = :' . self::SCOPE];
        $params = [self::SCOPE => $this->userId];

        if (($filters['category'] ?? '') !== '') {
            $predicates[] = 'pt.category = :category';
            $params['category'] = $filters['category'];
        }
        if (($filters['type'] ?? '') !== '') {
            $predicates[] = 'pt.type = :type';
            $params['type'] = $filters['type'];
        }
        if (($filters['state'] ?? '') !== '') {
            $predicates[] = 'p.state = :state';
            $params['state'] = $filters['state'];
        }
        if (($filters['garden_id'] ?? 0) > 0) {
            $predicates[] = 'p.garden_id = :garden_id';
            $params['garden_id'] = (int) $filters['garden_id'];
        }
        if (($filters['garden_row_id'] ?? 0) > 0) {
            $predicates[] = 'p.garden_row_id = :garden_row_id';
            $params['garden_row_id'] = (int) $filters['garden_row_id'];
        }
        if (($filters['living'] ?? null) === true) {
            $predicates[] = 'p.state <> :ended_state';
            $params['ended_state'] = PlantingState::ENDED;
        }
        if (($filters['search'] ?? '') !== '') {
            $predicates[] = '(pt.category LIKE :search1 OR pt.type LIKE :search2'
                . ' OR p.label LIKE :search3'
                . ' OR EXISTS (SELECT 1 FROM `qr_tag_binding` sb JOIN `qr_tag` st ON st.id = sb.tag_id'
                . '   WHERE sb.planting_id = p.id AND sb.unbound_at IS NULL AND st.code LIKE :search4))';
            $like = '%' . $filters['search'] . '%';
            // Emulation is off, so a placeholder cannot be reused within one
            // statement -- four names for one value (hosting Section 7).
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
            // The tag code, so four characters read off a faded stake narrow
            // the list. A WHOLE code does not come through here at all: the
            // controller recognises it first and goes straight to the plant.
            $params['search4'] = $like;
        }

        return $this->db->all(
            self::LIST_SELECT . ' WHERE ' . \implode(' AND ', $predicates)
            . ' ORDER BY p.state = :ended_sort, p.start_date DESC, pt.category, pt.type'
            . ' LIMIT ' . (int) $limit,
            $params + ['ended_sort' => PlantingState::ENDED]
        );
    }

    /** @return array<string,mixed>|null */
    public function findWithDetail(int $id): ?array
    {
        return $this->db->one(
            self::LIST_SELECT . ' WHERE p.user_id = :' . self::SCOPE . ' AND p.id = :id',
            $this->bind(['id' => $id])
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function findManyWithDetail(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $params = [];
        $in = self::inClause($ids, 'p', $params);
        return $this->db->all(
            self::LIST_SELECT . ' WHERE p.user_id = :' . self::SCOPE . ' AND p.id ' . $in
            . ' ORDER BY pt.category, pt.type',
            $this->bind($params)
        );
    }

    /**
     * The distinct filter values this user actually has, so the filter
     * dropdowns never offer a choice that returns nothing. One statement.
     *
     * @return array{categories:list<string>,types:list<string>,states:list<string>}
     */
    public function filterOptions(): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT pt.category, pt.type, p.state FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE p.user_id = :' . self::SCOPE
            . ' ORDER BY pt.category, pt.type',
            $this->bind([])
        );
        $categories = [];
        $types = [];
        $states = [];
        foreach ($rows as $row) {
            $categories[(string) $row['category']] = true;
            $types[(string) $row['type']] = true;
            $states[(string) $row['state']] = true;
        }
        return [
            'categories' => \array_keys($categories),
            'types'      => \array_keys($types),
            'states'     => \array_keys($states),
        ];
    }

    /**
     * Recompute the cached state and live quantity from the whole event log.
     *
     * Called after any event insert, edit or delete -- a backdated event
     * changes the answer, which is why this reads the log rather than
     * adjusting the cache in place (handoff Section 5.3).
     *
     * @return array<string,mixed> the derived values
     */
    public function recomputeState(int $plantingId): array
    {
        $planting = $this->find($plantingId);
        if ($planting === null) {
            return [];
        }

        $events = $this->db->all(
            'SELECT `event_type`, `event_date`, `quantity_delta`, `recorded_at`'
            . ' FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `planting_id` = :planting_id'
            . ' ORDER BY `event_date`, `recorded_at`, `id`',
            $this->bind(['planting_id' => $plantingId])
        );

        $derived = PlantingState::derive(
            [
                'start_method'     => (string) $planting['start_method'],
                'start_date'       => (string) $planting['start_date'],
                'quantity_initial' => (int) $planting['quantity_initial'],
            ],
            $events
        );

        $stateChanged = (string) $planting['state'] !== $derived['state'];

        // Still ONE statement, and still the only writer of the derived
        // quantities. quantity_lost and ended_reason ride along with
        // quantity_live rather than getting a writer of their own, because
        // three caches kept by three statements is three ways to disagree.
        $this->db->run(
            'UPDATE `planting` SET `state` = :state, `quantity_live` = :quantity_live,'
            . ' `quantity_lost` = :quantity_lost,'
            . ' `in_ground_date` = :in_ground_date, `ended_at` = :ended_at,'
            . ' `ended_reason` = :ended_reason,'
            . ' `germinated_at` = :germinated_at, `hardening_started_at` = :hardening_started_at,'
            . ' `state_changed_at` = ' . ($stateChanged ? 'UTC_TIMESTAMP()' : '`state_changed_at`') . ','
            . ' `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind([
                'state'                => $derived['state'],
                'quantity_live'        => $derived['quantity_live'],
                'quantity_lost'        => $derived['quantity_lost'],
                'in_ground_date'       => $derived['in_ground_date'],
                'ended_at'             => $derived['ended_at'],
                'ended_reason'         => $derived['ended_reason'],
                'germinated_at'        => $derived['germinated_at'],
                'hardening_started_at' => $derived['hardening_started_at'],
                'id'                   => $plantingId,
            ])
        );

        return $derived;
    }

    /**
     * Move a subset of a planting somewhere else: the subset BECOMES a
     * planting, descended from this one (spec Section 2.3).
     *
     * The user never sees the word "split". Their sentence is "I
     * transplanted six of them", and this is what Carl does behind it:
     *
     *   1. a child planting, at the new location, with the subset as its
     *      quantity_initial and every descriptive field copied across;
     *   2. `split_out` on the PARENT, quantity_delta = -k, pointing at the
     *      child;
     *   3. the physical event -- transplanted, up_potted or moved -- on the
     *      CHILD, because that is what happened to it;
     *   4. both states re-derived.
     *
     * All of it in one transaction. A child with no parent event is a
     * planting that came from nowhere and takes six plants with it; a parent
     * event with no child is six plants that went nowhere. Neither is a state
     * a gardener could be expected to unpick, and both are one failed
     * statement away without this.
     *
     * The events repository is passed in rather than held: EventRepository
     * already takes a PlantingRepository, and a field here would close the
     * loop.
     *
     * @param array{garden_id?:?int,garden_row_id?:?int,container_id?:?int} $destination
     * @param array<string,mixed> $eventData extra columns for the child's own event
     * @return array{child_id:int,event_id:int,quantity:int}
     */
    public function split(
        EventRepository $events,
        int $parentId,
        int $quantity,
        string $eventType,
        string $eventDate,
        array $destination,
        array $eventData = [],
    ): array {
        $parent = $this->findOrFail($parentId);

        $live = (int) $parent['quantity_live'];
        if ($live <= 0) {
            throw new \Carl\Core\HttpException(
                400, 'There is nothing living on that planting to move.'
            );
        }
        $quantity = \max(1, \min($quantity, $live));

        return $this->db->transaction(function () use (
            $events, $parent, $parentId, $quantity, $eventType, $eventDate, $destination, $eventData
        ): array {
            $childId = $this->insert([
                'plant_type_id'    => (int) $parent['plant_type_id'],
                'split_from_id'    => $parentId,
                // The chain is flattened as it is built: a split of a split
                // carries the ORIGINAL sowing, not its immediate parent, so
                // "everything from this tray" stays one indexed read however
                // many generations deep it goes.
                'root_planting_id' => (int) $parent['root_planting_id'],
                'garden_id'        => $destination['garden_id'] ?? null,
                'garden_row_id'    => $destination['garden_row_id'] ?? null,
                'container_id'     => $destination['container_id'] ?? null,
                'label'            => $parent['label'],
                // The child inherits the parent's start_method and start_date
                // rather than being born on the day it moved. An indoor seed
                // start that is transplanted out is still an indoor seed
                // start, and "day 62" in the plant list is counted from the
                // sowing -- which is the number a gardener means.
                'start_method'     => (string) $parent['start_method'],
                'start_date'       => (string) $parent['start_date'],
                'quantity_initial' => $quantity,
                'quantity_live'    => $quantity,
                // Corrected by recomputeState() the moment the child's own
                // event lands; it is here because the column is NOT NULL and
                // a row has to be insertable before it can be derived.
                'state'            => (string) $parent['state'],
                'state_changed_at' => \gmdate('Y-m-d H:i:s'),
                // The DERIVED dates are deliberately not copied.
                // germinated_at, hardening_started_at and in_ground_date are
                // functions of a planting's OWN log, and the child's log
                // starts at the move. The tray germinated; these six did not
                // germinate again on their way to the bed. The lineage link
                // is what carries that history, and merging it in would be
                // the thing Section 4.6 rejects, done quietly in a column.
                'default_water_method_id' => $parent['default_water_method_id'],
                'seed_source_id'   => $parent['seed_source_id'],
                'nursery_id'       => $parent['nursery_id'],
                'trellis_used'     => (int) $parent['trellis_used'],
                'collar_used'      => (int) $parent['collar_used'],
                'seeds_per_collar' => $parent['seeds_per_collar'],
                'notes'            => $parent['notes'],
            ]);

            // On the parent: they left, and where they went.
            $events->record($parentId, EventType::SPLIT_OUT, $eventDate, [
                'quantity_delta'    => -$quantity,
                'count_qty'         => $quantity,
                'split_planting_id' => $childId,
                'garden_id'         => $destination['garden_id'] ?? null,
                'garden_row_id'     => $destination['garden_row_id'] ?? null,
                'container_id'      => $destination['container_id'] ?? null,
            ]);

            // On the child: what physically happened to it. This one carries
            // the narrative, the reference lists and the duration, because it
            // is the event the gardener thinks they are logging.
            $eventId = $events->record($childId, $eventType, $eventDate, $eventData + [
                'garden_id'     => $destination['garden_id'] ?? null,
                'garden_row_id' => $destination['garden_row_id'] ?? null,
                'container_id'  => $destination['container_id'] ?? null,
            ]);

            return ['child_id' => $childId, 'event_id' => $eventId, 'quantity' => $quantity];
        });
    }

    /**
     * The lineage line at the head of a child's history, and the children a
     * parent sent out. ONE statement, whichever end is asked about.
     *
     * The two timelines are deliberately NOT merged. Walking the ancestor
     * chain on every plant page costs a statement per generation and breaks
     * the assertions in 11_reports_test.php that a 200-day planting costs the
     * same three statements as a two-day one. The link costs nothing, and it
     * is also more honest: those events happened to the tray, not to these
     * six (spec Section 4.6).
     *
     * @return array{parent:?array<string,mixed>,children:list<array<string,mixed>>}
     */
    public function lineage(int $plantingId, ?int $splitFromId): array
    {
        $rows = $this->db->all(
            'SELECT p.id, p.split_from_id, p.quantity_initial, p.quantity_live, p.state,'
            . ' p.start_date, p.label, pt.category, pt.type,'
            . ' g.name AS garden_name, gr.name AS row_name, c.name AS container_name,'
            . ' e.event_date AS moved_on, e.event_type AS moved_by'
            . ' FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' LEFT JOIN `garden` g ON g.id = p.garden_id'
            . ' LEFT JOIN `garden_row` gr ON gr.id = p.garden_row_id'
            . ' LEFT JOIN `container` c ON c.id = p.container_id'
            // The split_out row on the PARENT is what dates a move, so a
            // child reads its own date from the event that sent it and a
            // parent reads each child's from the event that named it.
            . ' LEFT JOIN `plant_event` e ON e.split_planting_id = p.id AND e.user_id = p.user_id'
            . "     AND e.event_type = '" . EventType::SPLIT_OUT . "'"
            . ' WHERE p.user_id = :' . self::SCOPE
            . '   AND (p.id = :parent_id OR p.split_from_id = :child_of)'
            . ' ORDER BY p.start_date, p.id',
            $this->bind(['parent_id' => $splitFromId ?? 0, 'child_of' => $plantingId])
        );

        $parent = null;
        $children = [];
        foreach ($rows as $row) {
            if ($splitFromId !== null && (int) $row['id'] === $splitFromId) {
                $parent = $row;
                continue;
            }
            $children[] = $row;
        }
        return ['parent' => $parent, 'children' => $children];
    }

    /**
     * Everything descended from one sowing, the sowing included.
     *
     * This is what root_planting_id exists for: one indexed statement rather
     * than a walk up a chain of unknown depth.
     *
     * @return list<array<string,mixed>>
     */
    public function wholeSowing(int $rootPlantingId): array
    {
        return $this->db->all(
            self::LIST_SELECT . ' WHERE p.user_id = :' . self::SCOPE
            . ' AND p.root_planting_id = :root ORDER BY p.start_date, p.id',
            $this->bind(['root' => $rootPlantingId])
        );
    }

    /**
     * The earliest date this user has any planting for. Drives
     * weather_location.backfill_from, which is what makes backdating a plant
     * pull the weather that goes with it (handoff Section 8.1).
     */
    public function earliestStartDate(): ?string
    {
        $value = $this->db->value(
            'SELECT MIN(`start_date`) FROM `planting` WHERE `user_id` = :' . self::SCOPE,
            $this->bind([])
        );
        return \is_string($value) ? $value : null;
    }

    /**
     * The earliest start date of anything planted in one garden. A garden
     * report covers the weather from when it was first used, not from when
     * the row was created: an empty bed built in January for an April sowing
     * has no April weather to answer for.
     */
    public function earliestStartDateInGarden(int $gardenId): ?string
    {
        $value = $this->db->value(
            'SELECT MIN(`start_date`) FROM `planting`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `garden_id` = :garden_id',
            $this->bind(['garden_id' => $gardenId])
        );
        return \is_string($value) ? $value : null;
    }

    /** @return list<array<string,mixed>> living plantings in the given rows */
    public function livingInRows(array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }
        $params = [];
        $in = self::inClause($rowIds, 'r', $params);
        return $this->db->all(
            'SELECT `id`, `garden_id`, `garden_row_id`, `quantity_live` FROM `planting`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `garden_row_id` ' . $in
            . '   AND `state` <> :ended AND `quantity_live` > 0',
            $this->bind($params + ['ended' => PlantingState::ENDED])
        );
    }

    /**
     * Every planting whose life overlaps a date window, for the analysis
     * document (Phase 5 handoff Section 3.1).
     *
     * "Overlaps" and not "started in": a tomato sown in March and pulled in
     * October is the subject of an August review, and a window that only
     * caught plantings by start_date would drop it. A planting with no
     * ended_at is still running, so its end is open.
     *
     * @return list<array<string,mixed>>
     */
    public function overlappingWindow(string $from, string $to, int $limit = 400): array
    {
        return $this->db->all(
            self::LIST_SELECT
            . ' WHERE p.user_id = :' . self::SCOPE
            . '   AND p.start_date <= :to'
            . '   AND (p.ended_at IS NULL OR p.ended_at >= :from)'
            . ' ORDER BY p.start_date DESC, p.id DESC LIMIT ' . (int) $limit,
            $this->bind(['from' => $from, 'to' => $to])
        );
    }

    /**
     * What plant family last grew in each row, and when (Phase 5 handoff
     * Section 3.4).
     *
     * `plant_family` is on every `plant_type` and `garden_row_id` is on every
     * planting, so "this bed grew a Solanaceae last year" really is one
     * statement -- and it is one statement for EVERY row at once, which is
     * what lets the Start a New Plant form carry the warning for whichever
     * row the gardener picks without a lookup per row (hosting Section 9).
     *
     * Only rows with a family recorded come back: a plant type with no family
     * in the research set cannot say anything about rotation, and inventing a
     * warning from a blank is worse than staying quiet.
     *
     * @param string $since the earliest start_date that still counts
     * @return array<int,list<array{family:string,last_date:string,plantings:int}>>
     *         keyed by garden_row_id, most recent family first
     */
    public function familyHistoryByRow(string $since): array
    {
        $rows = $this->db->all(
            'SELECT p.garden_row_id, pt.plant_family,'
            . ' MAX(p.start_date) AS last_date, COUNT(*) AS plantings'
            . ' FROM `planting` p JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE p.user_id = :' . self::SCOPE
            . '   AND p.garden_row_id IS NOT NULL'
            . "   AND pt.plant_family IS NOT NULL AND pt.plant_family <> ''"
            . '   AND p.start_date >= :since'
            . ' GROUP BY p.garden_row_id, pt.plant_family'
            . ' ORDER BY p.garden_row_id, last_date DESC',
            $this->bind(['since' => $since])
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['garden_row_id']][] = [
                'family'    => (string) $row['plant_family'],
                'last_date' => (string) $row['last_date'],
                'plantings' => (int) $row['plantings'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> living plantings anywhere in a garden */
    public function livingInGarden(int $gardenId): array
    {
        return $this->db->all(
            'SELECT `id`, `garden_id`, `garden_row_id`, `quantity_live` FROM `planting`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `garden_id` = :garden_id'
            . '   AND `state` <> :ended AND `quantity_live` > 0',
            $this->bind(['garden_id' => $gardenId, 'ended' => PlantingState::ENDED])
        );
    }

    /**
     * One chunk of the CSV export (handoff Section 13.3), scoped by the base
     * class like every other read.
     *
     * Keyset, not OFFSET: the caller walks forward by id, so the cost of the
     * last chunk is the same as the first and a row inserted mid-export
     * cannot make one slide past unread.
     *
     * @return list<array<string,mixed>>
     */
    public function exportChunk(int $afterId, int $limit): array
    {
        return $this->db->all(
            'SELECT p.*, pt.category, pt.type, pt.plant_family, pt.latin_name,'
            . ' pt.dtm_days_min, pt.dtm_days_max, pt.dtm_counted_from,'
            . ' g.name AS garden_name, gr.name AS row_name, c.name AS container_name,'
            . ' seed.name AS seed_source_name, nur.name AS nursery_name,'
            . ' wm.name AS water_method_name, hs.name AS hardening_schedule_name,'
            . ' (SELECT COALESCE(SUM(y.weight_g), 0) FROM `plant_event` y'
            . "    WHERE y.planting_id = p.id AND y.event_type = 'yielded') AS yield_weight_g,"
            . ' (SELECT COALESCE(SUM(y2.count_qty), 0) FROM `plant_event` y2'
            . "    WHERE y2.planting_id = p.id AND y2.event_type = 'yielded') AS yield_count_qty"
            . ' FROM `planting` p'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' LEFT JOIN `garden` g ON g.id = p.garden_id'
            . ' LEFT JOIN `garden_row` gr ON gr.id = p.garden_row_id'
            . ' LEFT JOIN `container` c ON c.id = p.container_id'
            . ' LEFT JOIN `user_list_item` seed ON seed.id = p.seed_source_id'
            . ' LEFT JOIN `user_list_item` nur ON nur.id = p.nursery_id'
            . ' LEFT JOIN `user_list_item` wm ON wm.id = p.default_water_method_id'
            . ' LEFT JOIN `hardening_schedule` hs ON hs.id = p.hardening_schedule_id'
            . ' WHERE p.user_id = :' . self::SCOPE . ' AND p.id > :after_id'
            . ' ORDER BY p.id LIMIT ' . (int) $limit,
            $this->bind(['after_id' => $afterId])
        );
    }

    /**
     * Yield totals for a planting. Weight and count are separate because a
     * gardener records tomatoes by weight and cucumbers by the piece.
     *
     * @return array{weight_g:float,count_qty:int,events:int,first:?string,last:?string}
     */
    public function yieldSummary(int $plantingId): array
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(`weight_g`), 0) AS weight_g, COALESCE(SUM(`count_qty`), 0) AS count_qty,'
            . ' COUNT(*) AS events, MIN(`event_date`) AS first_date, MAX(`event_date`) AS last_date'
            . ' FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `planting_id` = :planting_id'
            . '   AND `event_type` = :yielded',
            $this->bind(['planting_id' => $plantingId, 'yielded' => 'yielded'])
        );

        return [
            'weight_g'  => (float) ($row['weight_g'] ?? 0),
            'count_qty' => (int) ($row['count_qty'] ?? 0),
            'events'    => (int) ($row['events'] ?? 0),
            'first'     => $row['first_date'] ?? null,
            'last'      => $row['last_date'] ?? null,
        ];
    }    /**
     * The last time this account sowed each plant type, and how many rounds
     * of it have gone in, for the succession planner (Phase 6).
     *
     * Sowings only, and ALL of them including ended ones: "you have sown
     * three rounds of beans, the last on 12 June" is true whether or not the
     * first two are still standing. The digest's own succession rule reads
     * living plantings instead, because it is asking a different question --
     * whether there is a round growing that the next one should follow.
     *
     * @return array<int,array{last_sown:string,rounds:int}> keyed by plant_type_id
     */
    public function lastSownByType(): array
    {
        $rows = $this->db->all(
            'SELECT `plant_type_id`, MAX(`start_date`) AS last_sown, COUNT(*) AS rounds'
            . ' FROM `planting` WHERE ' . $this->scoped("`start_method` = 'direct_sow'")
            . ' GROUP BY `plant_type_id`',
            $this->bind([])
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['plant_type_id']] = [
                'last_sown' => (string) $row['last_sown'],
                'rounds'    => (int) $row['rounds'],
            ];
        }
        return $out;
    }


}
