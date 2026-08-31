<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Domain\EventType;
use Carl\Domain\PlantingState;

/**
 * The append-only event log (handoff Sections 4.4, 4.7, 5.3).
 *
 * Every action writes exactly one row. Every row is backdatable, carries an
 * optional narrative, and can have photos attached. Nothing is ever updated
 * in place to "correct" history.
 */
final class EventRepository extends Repository
{
    public function __construct(
        \Carl\Core\Database $db,
        int $userId,
        private PlantingRepository $plantings,
    ) {
        parent::__construct($db, $userId);
    }

    protected function table(): string
    {
        return 'plant_event';
    }

    protected function writable(): array
    {
        return ['planting_id', 'event_type', 'event_date', 'recorded_at', 'quantity_delta',
                'duration_min', 'weight_g', 'count_qty', 'unit', 'narrative',
                'ref_list_item_id', 'ref_list_item_id_2', 'garden_id', 'garden_row_id',
                'container_id', 'source_garden_event_id', 'payload'];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;   // plant_event has created_at only: the log is append-only.
    }

    /**
     * Record one plant event and re-derive the planting's cached state.
     *
     * @param array<string,mixed> $data
     * @return int the new event id
     */
    public function record(int $plantingId, string $eventType, string $eventDate, array $data = []): int
    {
        if (!EventType::isValid($eventType)) {
            throw new \InvalidArgumentException('Unknown event type: ' . $eventType);
        }

        $payload = $data['payload'] ?? null;
        if (\is_array($payload)) {
            $data['payload'] = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        $eventId = $this->insert($data + [
            'planting_id' => $plantingId,
            'event_type'  => $eventType,
            'event_date'  => $eventDate,
            'recorded_at' => $this->now(),
        ]);

        // A backdated event changes the derived state, so this runs on every
        // write rather than only on the ones that look like transitions.
        $this->plantings->recomputeState($plantingId);

        return $eventId;
    }

    /**
     * The full timeline for a planting, sorted the way the state derivation
     * sorts it: event_date, then recorded_at (handoff Section 5.3).
     *
     * @return list<array<string,mixed>>
     */
    public function timeline(int $plantingId): array
    {
        return $this->db->all(
            'SELECT e.*, l1.name AS ref_name, l1.list_type AS ref_type,'
            . ' l2.name AS ref_name_2, l2.list_type AS ref_type_2,'
            . ' g.name AS garden_name, gr.name AS row_name, c.name AS container_name'
            . ' FROM `plant_event` e'
            . ' LEFT JOIN `user_list_item` l1 ON l1.id = e.ref_list_item_id'
            . ' LEFT JOIN `user_list_item` l2 ON l2.id = e.ref_list_item_id_2'
            . ' LEFT JOIN `garden` g ON g.id = e.garden_id'
            . ' LEFT JOIN `garden_row` gr ON gr.id = e.garden_row_id'
            . ' LEFT JOIN `container` c ON c.id = e.container_id'
            . ' WHERE e.user_id = :' . self::SCOPE . ' AND e.planting_id = :planting_id'
            . ' ORDER BY e.event_date DESC, e.recorded_at DESC, e.id DESC',
            $this->bind(['planting_id' => $plantingId])
        );
    }

    /** @return list<array<string,mixed>> the most recent events across everything */
    public function recent(int $limit = 20): array
    {
        return $this->db->all(
            'SELECT e.id, e.event_type, e.event_date, e.planting_id, pt.category, pt.type'
            . ' FROM `plant_event` e'
            . ' JOIN `planting` p ON p.id = e.planting_id'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE e.user_id = :' . self::SCOPE
            . ' ORDER BY e.event_date DESC, e.recorded_at DESC LIMIT ' . (int) $limit,
            $this->bind([])
        );
    }

    public function lastActivityDate(): ?string
    {
        $value = $this->db->value(
            'SELECT MAX(`event_date`) FROM `plant_event` WHERE `user_id` = :' . self::SCOPE,
            $this->bind([])
        );
        return \is_string($value) ? $value : null;
    }

    /** Has this planting an unresolved pest observation of this pest? */
    public function hasPestObservation(int $plantingId, ?int $pestListItemId): bool
    {
        if ($pestListItemId === null) {
            return false;
        }
        return $this->db->value(
            'SELECT 1 FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `planting_id` = :planting_id'
            . '   AND `event_type` = :observed AND `ref_list_item_id` = :pest_id LIMIT 1',
            $this->bind([
                'planting_id' => $plantingId,
                'observed'    => EventType::PEST_OBSERVED,
                'pest_id'     => $pestListItemId,
            ])
        ) !== null;
    }

    /**
     * One chunk of the CSV export (handoff Section 13.3), scoped by the base
     * class like every other read.
     *
     * Keyset, not OFFSET: the caller walks forward by id, so the cost of the
     * last chunk is the same as the first.
     *
     * @return list<array<string,mixed>>
     */
    public function exportChunk(int $afterId, int $limit): array
    {
        return $this->db->all(
            'SELECT e.id, e.event_type, e.event_date, e.recorded_at, e.planting_id,'
            . ' e.quantity_delta, e.duration_min, e.weight_g, e.count_qty, e.unit,'
            . ' e.narrative, e.source_garden_event_id,'
            . ' pt.category, pt.type, p.label AS plant_label,'
            . ' g.name AS garden_name, gr.name AS row_name, c.name AS container_name,'
            . ' l1.name AS ref_name, l2.name AS ref_name_2'
            . ' FROM `plant_event` e'
            . ' JOIN `planting` p ON p.id = e.planting_id'
            . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' LEFT JOIN `garden` g ON g.id = e.garden_id'
            . ' LEFT JOIN `garden_row` gr ON gr.id = e.garden_row_id'
            . ' LEFT JOIN `container` c ON c.id = e.container_id'
            . ' LEFT JOIN `user_list_item` l1 ON l1.id = e.ref_list_item_id'
            . ' LEFT JOIN `user_list_item` l2 ON l2.id = e.ref_list_item_id_2'
            . ' WHERE e.user_id = :' . self::SCOPE . ' AND e.id > :after_id'
            . ' ORDER BY e.id LIMIT ' . (int) $limit,
            $this->bind(['after_id' => $afterId])
        );
    }

    /**
     * The same, for garden_event. A garden action that is not a zone watering
     * never fans out to a plant (only zone watering does -- handoff 4.7), so
     * an export of plant events alone would silently lose every garden-level
     * mulch, fertilise and pest record. They go in the same file under a
     * scope column rather than a fourth endpoint.
     *
     * @return list<array<string,mixed>>
     */
    public function gardenExportChunk(int $afterId, int $limit): array
    {
        return $this->db->all(
            'SELECT ge.id, ge.event_type, ge.event_date, ge.recorded_at,'
            . ' ge.duration_min, ge.narrative, ge.fanout_count,'
            . ' g.name AS garden_name, z.name AS zone_name,'
            . ' l1.name AS ref_name, l2.name AS ref_name_2,'
            . ' (SELECT GROUP_CONCAT(gr2.name ORDER BY gr2.ordinal SEPARATOR \' | \')'
            . '    FROM `garden_event_row` ger JOIN `garden_row` gr2 ON gr2.id = ger.garden_row_id'
            . '    WHERE ger.garden_event_id = ge.id) AS row_names'
            . ' FROM `garden_event` ge'
            . ' JOIN `garden` g ON g.id = ge.garden_id'
            . ' LEFT JOIN `water_zone` z ON z.id = ge.water_zone_id'
            . ' LEFT JOIN `user_list_item` l1 ON l1.id = ge.ref_list_item_id'
            . ' LEFT JOIN `user_list_item` l2 ON l2.id = ge.ref_list_item_id_2'
            . ' WHERE ge.user_id = :' . self::SCOPE . ' AND ge.id > :after_id'
            . ' ORDER BY ge.id LIMIT ' . (int) $limit,
            $this->bind(['after_id' => $afterId])
        );
    }

    // -- Garden events ---------------------------------------------------

    /**
     * Record one garden event. Watering a zone also fans out a derived water
     * record to every living plant in the zone's rows, carrying
     * source_garden_event_id so it is not double-counted (handoff 4.7).
     *
     * @param array<string,mixed> $data
     * @param list<int> $rowIds rows the action covers, for mulch-by-rows
     * @return array{event_id:int,fanout:int}
     */
    public function recordGardenEvent(
        int $gardenId,
        string $eventType,
        string $eventDate,
        array $data = [],
        array $rowIds = [],
        ?int $waterZoneId = null,
        bool $fanOutToPlants = false,
    ): array {
        if (!\in_array($eventType, EventType::gardenTypes(), true)) {
            throw new \InvalidArgumentException('Not a garden event type: ' . $eventType);
        }

        $payload = $data['payload'] ?? null;
        if (\is_array($payload)) {
            $data['payload'] = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        $now = $this->now();
        $columns = [
            'user_id'            => $this->userId,
            'garden_id'          => $gardenId,
            'event_type'         => $eventType,
            'event_date'         => $eventDate,
            'recorded_at'        => $now,
            'water_zone_id'      => $waterZoneId,
            'duration_min'       => $data['duration_min'] ?? null,
            'ref_list_item_id'   => $data['ref_list_item_id'] ?? null,
            'ref_list_item_id_2' => $data['ref_list_item_id_2'] ?? null,
            'narrative'          => $data['narrative'] ?? null,
            'payload'            => $data['payload'] ?? null,
            'created_at'         => $now,
        ];

        $names = \array_keys($columns);
        $this->db->run(
            'INSERT INTO `garden_event` ('
            . \implode(', ', \array_map(static fn (string $c): string => '`' . $c . '`', $names))
            . ') VALUES (' . \implode(', ', \array_map(static fn (string $c): string => ':' . $c, $names)) . ')',
            $columns
        );
        $eventId = $this->db->insertId();

        if ($rowIds !== []) {
            $rows = [];
            foreach ($rowIds as $rowId) {
                $rows[] = [$eventId, $rowId];
            }
            $this->db->upsertChunk('garden_event_row', ['garden_event_id', 'garden_row_id'], $rows, []);
        }

        $fanout = 0;
        if ($fanOutToPlants) {
            $fanout = $this->fanOut($eventId, $gardenId, $eventType, $eventDate, $rowIds, $columns);
        }

        return ['event_id' => $eventId, 'fanout' => $fanout];
    }

    /**
     * Write one derived plant_event per living plant the garden action
     * covered. These carry source_garden_event_id, so a report can tell a
     * hand-logged watering from a zone watering and never counts both.
     *
     * @param list<int> $rowIds
     * @param array<string,mixed> $source
     */
    private function fanOut(
        int $gardenEventId,
        int $gardenId,
        string $eventType,
        string $eventDate,
        array $rowIds,
        array $source,
    ): int {
        $targets = $rowIds !== []
            ? $this->plantings->livingInRows($rowIds)
            : $this->plantings->livingInGarden($gardenId);

        if ($targets === []) {
            return 0;
        }

        $now = $this->now();
        $rows = [];
        foreach ($targets as $planting) {
            $rows[] = [
                $this->userId,
                (int) $planting['id'],
                $eventType,
                $eventDate,
                $now,
                $source['duration_min'],
                $source['ref_list_item_id'],
                $source['ref_list_item_id_2'],
                $gardenId,
                $planting['garden_row_id'],
                $gardenEventId,
                $now,
            ];
        }

        $columns = ['user_id', 'planting_id', 'event_type', 'event_date', 'recorded_at',
                    'duration_min', 'ref_list_item_id', 'ref_list_item_id_2',
                    'garden_id', 'garden_row_id', 'source_garden_event_id', 'created_at'];

        // 200 rows per statement: the round trips are the cost, not the bytes
        // (hosting Section 9).
        foreach (\array_chunk($rows, 200) as $chunk) {
            $this->db->upsertChunk('plant_event', $columns, $chunk, []);
        }

        $this->db->run(
            'UPDATE `garden_event` SET `fanout_count` = :count'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind(['count' => \count($rows), 'id' => $gardenEventId])
        );

        return \count($rows);
    }

    /** @return list<array<string,mixed>> */
    public function gardenTimeline(int $gardenId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT e.*, l1.name AS ref_name, l2.name AS ref_name_2, z.name AS zone_name'
            . ' FROM `garden_event` e'
            . ' LEFT JOIN `user_list_item` l1 ON l1.id = e.ref_list_item_id'
            . ' LEFT JOIN `user_list_item` l2 ON l2.id = e.ref_list_item_id_2'
            . ' LEFT JOIN `water_zone` z ON z.id = e.water_zone_id'
            . ' WHERE e.user_id = :' . self::SCOPE . ' AND e.garden_id = :garden_id'
            . ' ORDER BY e.event_date DESC, e.recorded_at DESC LIMIT ' . (int) $limit,
            $this->bind(['garden_id' => $gardenId])
        );
    }

    /**
     * Apply the same event to several plantings at once -- the batch action
     * of handoff Section 4.4.
     *
     * @param list<int> $plantingIds
     * @param array<string,mixed> $data
     * @return int events written
     */
    public function recordBatch(array $plantingIds, string $eventType, string $eventDate, array $data = []): int
    {
        $written = 0;
        foreach ($plantingIds as $plantingId) {
            // Confirm ownership per planting before writing: a posted id is
            // not evidence of anything.
            if (!$this->plantings->exists($plantingId)) {
                continue;
            }
            $perPlanting = $data;
            // Quantities default to that planting's own live count, so a batch
            // cull of "all of them" means each planting's own remainder.
            if (($data['quantity_all'] ?? false) === true) {
                $row = $this->plantings->find($plantingId);
                $live = (int) ($row['quantity_live'] ?? 0);
                unset($perPlanting['quantity_all']);
                $perPlanting['quantity_delta'] = -$live;
            }
            $this->record($plantingId, $eventType, $eventDate, $perPlanting);
            $written++;
        }
        return $written;
    }

    /**
     * Rows that still count as living, for the "N living plants" hint.
     */
    public function livingTotal(): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(`quantity_live`), 0) FROM `planting`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `state` <> :ended',
            $this->bind(['ended' => PlantingState::ENDED]),
            0
        );
    }
}
