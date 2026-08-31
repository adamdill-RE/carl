<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Domain\PlantingState;

/**
 * Plantings (handoff Section 5.3). A planting is the asset; everything that
 * happens to it is a plant_event. state and quantity_live on this table are
 * caches of the event log, recomputed by recomputeState().
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
            'plant_type_id', 'garden_id', 'garden_row_id', 'container_id', 'label',
            'start_method', 'start_date', 'quantity_initial', 'quantity_live',
            'state', 'state_changed_at', 'in_ground_date', 'ended_at', 'germinated_at',
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
        . ' c.name AS container_name'
        . ' FROM `planting` p'
        . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
        . ' LEFT JOIN `garden` g ON g.id = p.garden_id'
        . ' LEFT JOIN `garden_row` gr ON gr.id = p.garden_row_id'
        . ' LEFT JOIN `container` c ON c.id = p.container_id';

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
            $predicates[] = '(pt.category LIKE :search1 OR pt.type LIKE :search2 OR p.label LIKE :search3)';
            $like = '%' . $filters['search'] . '%';
            // Emulation is off, so a placeholder cannot be reused within one
            // statement -- three names for one value (hosting Section 7).
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
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

        $this->db->run(
            'UPDATE `planting` SET `state` = :state, `quantity_live` = :quantity_live,'
            . ' `in_ground_date` = :in_ground_date, `ended_at` = :ended_at,'
            . ' `germinated_at` = :germinated_at, `hardening_started_at` = :hardening_started_at,'
            . ' `state_changed_at` = ' . ($stateChanged ? 'UTC_TIMESTAMP()' : '`state_changed_at`') . ','
            . ' `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind([
                'state'                => $derived['state'],
                'quantity_live'        => $derived['quantity_live'],
                'in_ground_date'       => $derived['in_ground_date'],
                'ended_at'             => $derived['ended_at'],
                'germinated_at'        => $derived['germinated_at'],
                'hardening_started_at' => $derived['hardening_started_at'],
                'id'                   => $plantingId,
            ])
        );

        return $derived;
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
    }
}
