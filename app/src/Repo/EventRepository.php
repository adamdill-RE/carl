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
                'duration_min', 'weight_g', 'count_qty', 'unit', 'height_mm', 'diameter_mm',
                'narrative', 'ref_list_item_id', 'ref_list_item_id_2', 'garden_id',
                'garden_row_id', 'container_id', 'split_planting_id',
                'source_garden_event_id', 'payload'];
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
            . ' g.name AS garden_name, gr.name AS row_name, c.name AS container_name,'
            // A sixth LEFT JOIN and not a sixth statement: the split_out row
            // is the only one that carries a split_planting_id, and without
            // its category the timeline can only say "6 went somewhere".
            . ' spt.category AS split_category, spt.type AS split_type'
            . ' FROM `plant_event` e'
            . ' LEFT JOIN `user_list_item` l1 ON l1.id = e.ref_list_item_id'
            . ' LEFT JOIN `user_list_item` l2 ON l2.id = e.ref_list_item_id_2'
            . ' LEFT JOIN `garden` g ON g.id = e.garden_id'
            . ' LEFT JOIN `garden_row` gr ON gr.id = e.garden_row_id'
            . ' LEFT JOIN `container` c ON c.id = e.container_id'
            . ' LEFT JOIN `planting` sp ON sp.id = e.split_planting_id AND sp.user_id = e.user_id'
            . ' LEFT JOIN `plant_type` spt ON spt.id = sp.plant_type_id'
            . ' WHERE e.user_id = :' . self::SCOPE . ' AND e.planting_id = :planting_id'
            . ' ORDER BY e.event_date DESC, e.recorded_at DESC, e.id DESC',
            $this->bind(['planting_id' => $plantingId])
        );
    }

    /**
     * The events of one planting as chart markers (handoff Section 13.1).
     *
     * One statement, no joins: a marker needs a date, a type and a note, and
     * the five LEFT JOINs timeline() carries exist to name the fertiliser on
     * a timeline row -- which a dot on a chart does not print. Ordered
     * forwards, the direction a chart reads.
     *
     * FIVE MEASURED COLUMNS COME BACK WITH THEM, and they cost nothing: they
     * are on the rows this already reads. Phase 12 turns the plant into the
     * SUBJECT of its own chart rather than forty identical triangles on a
     * weather one (weather.md Section 7.3 -- "weather is context, not the
     * subject"), and the numbers it draws are these. Adding columns to a
     * SELECT is not adding a statement, which is what
     * `11_reports_test.php` counts.
     *
     * @return list<array<string,mixed>>
     */
    public function seriesMarkers(int $plantingId): array
    {
        return $this->db->all(
            'SELECT `id`, `event_type`, `event_date`, `narrative`, `source_garden_event_id`,'
            . ' `height_mm`, `diameter_mm`, `weight_g`, `count_qty`, `duration_min`'
            . ' FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `planting_id` = :planting_id'
            . ' ORDER BY `event_date`, `recorded_at`, `id`',
            $this->bind(['planting_id' => $plantingId])
        );
    }

    /**
     * The same for a garden, from garden_event.
     *
     * Deliberately NOT the fanned-out plant_event copies: a zone watering
     * writes one derived row per living plant (handoff Section 4.7), so
     * reading plant_event here would draw one watering as forty markers on
     * the same day. source_garden_event_id is what tells them apart, and this
     * reads the side that has one row per thing that happened.
     *
     * @return list<array<string,mixed>>
     */
    public function gardenSeriesMarkers(int $gardenId): array
    {
        return $this->db->all(
            // The measured columns are named as NULL rather than left out: a
            // garden has no height and no harvest weight of its own, and the
            // shared assembler in Carl\Reports\Series reads the same keys for
            // both subjects. Answering the question with NULL is cheaper than
            // teaching the assembler which of its two callers it is serving.
            'SELECT `id`, `event_type`, `event_date`, `narrative`, `fanout_count`,'
            . ' NULL AS `source_garden_event_id`,'
            . ' NULL AS `height_mm`, NULL AS `diameter_mm`, NULL AS `weight_g`,'
            . ' NULL AS `count_qty`, `duration_min`'
            . ' FROM `garden_event`'
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `garden_id` = :garden_id'
            . ' ORDER BY `event_date`, `recorded_at`, `id`',
            $this->bind(['garden_id' => $gardenId])
        );
    }

    /**
     * The last few events on ONE planting, for the field screen.
     *
     * Not timeline(): that is the full log with six LEFT JOINs and no limit,
     * which is the right read for a plant page and the wrong one for a screen
     * hit forty times in a walk around a garden (QR-TAGS-SPEC Section 6.3).
     * What the field screen answers is "did I already water this today?", and
     * that needs a type and a date and nothing else.
     *
     * @return list<array<string,mixed>>
     */
    public function recentForPlanting(int $plantingId, int $limit = 6): array
    {
        return $this->db->all(
            'SELECT `id`, `event_type`, `event_date`, `quantity_delta`'
            . ' FROM `plant_event` WHERE ' . $this->scoped('`planting_id` = :planting_id')
            . ' ORDER BY `event_date` DESC, `recorded_at` DESC, `id` DESC'
            . ' LIMIT ' . (int) $limit,
            $this->bind(['planting_id' => $plantingId])
        );
    }

    /**
     * Every plant event in a date range, for the calendar grid (Phase 9).
     *
     * ONE STATEMENT AND ONE JOIN. The grid draws a chip per event and needs
     * only a date, a type and a name for it; timeline()'s six LEFT JOINs
     * exist to print the fertiliser on one plant's page, which a chip does
     * not have room for.
     *
     * THE FANNED-OUT COPIES ARE LEFT OUT, the same way gardenSeriesMarkers()
     * leaves them out and for the same reason: watering a zone writes one
     * derived plant_event per living plant in it (handoff Section 4.7), so a
     * calendar that read them would draw one Tuesday watering as forty chips.
     * calendarGardenEvents() reads the side that has one row per thing that
     * actually happened. `source_garden_event_id` is what tells them apart.
     *
     * @return list<array<string,mixed>>
     */
    public function calendarEvents(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT e.`id`, e.`planting_id`, e.`event_type`, e.`event_date`, e.`narrative`,'
            . ' p.`label`, pt.`category`, pt.`type`'
            . ' FROM `plant_event` e'
            . ' JOIN `planting` p ON p.`id` = e.`planting_id`'
            . ' JOIN `plant_type` pt ON pt.`id` = p.`plant_type_id`'
            . ' WHERE e.`user_id` = :' . self::SCOPE
            . ' AND e.`source_garden_event_id` IS NULL'
            . ' AND e.`event_date` BETWEEN :from AND :to'
            . ' ORDER BY e.`event_date`, e.`id`',
            $this->bind(['from' => $from, 'to' => $to])
        );
    }

    /**
     * The garden actions in the same range: the other half of what happened.
     *
     * @return list<array<string,mixed>>
     */
    public function calendarGardenEvents(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT e.`id`, e.`garden_id`, e.`event_type`, e.`event_date`, e.`narrative`,'
            . ' e.`fanout_count`, g.`name` AS garden_name'
            . ' FROM `garden_event` e'
            . ' JOIN `garden` g ON g.`id` = e.`garden_id`'
            . ' WHERE e.`user_id` = :' . self::SCOPE
            . ' AND e.`event_date` BETWEEN :from AND :to'
            . ' ORDER BY e.`event_date`, e.`id`',
            $this->bind(['from' => $from, 'to' => $to])
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
            . ' e.height_mm, e.diameter_mm,'
            . ' e.narrative, e.source_garden_event_id, e.split_planting_id,'
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

    // -- Summaries for the analysis document (Phase 5 handoff Section 3.1) --

    /**
     * Every planting's events in a window, rolled up per planting and type.
     *
     * Measured 2026-08-31: the raw event log is 2.26 MB of a five-year
     * account's 3.31 MB `/export/claude.json`, and 4,500 rows of it are 150
     * plantings watered thirty times each. A recommendation needs "watered 12
     * times over 340 minutes, last on the 20th", not thirty rows saying the
     * same thing (`deploy.md` Section 0.9).
     *
     * A derived event -- one fanned out from a garden-wide action -- is
     * counted in `derived` as well as in `events`, so a reader can tell what
     * the gardener did to that plant from what reached it through the zone
     * (handoff Section 4.7). The rows are not excluded: a plant that only
     * ever got water from a zone was still watered.
     *
     * ONE statement however many plantings and however long the season
     * (hosting Section 9). Ordered so a caller can group it in one pass.
     *
     * @return list<array<string,mixed>>
     */
    public function summaryByPlanting(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT `planting_id`, `event_type`, COUNT(*) AS events,'
            . ' MIN(`event_date`) AS first_date, MAX(`event_date`) AS last_date,'
            . ' SUM(`source_garden_event_id` IS NOT NULL) AS derived,'
            . ' SUM(`duration_min`) AS duration_min, SUM(`weight_g`) AS weight_g,'
            . ' SUM(`count_qty`) AS count_qty, SUM(`quantity_delta`) AS quantity_delta'
            . ' FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE
            . '   AND `event_date` BETWEEN :from AND :to'
            . ' GROUP BY `planting_id`, `event_type`'
            . ' ORDER BY `planting_id`, `event_type`',
            $this->bind(['from' => $from, 'to' => $to])
        );
    }

    /**
     * What the gardener actually wrote, most recent first and bounded.
     *
     * The counts above are the shape of a season; the narratives are the only
     * part of the log a person composed, and they are where "the squash went
     * down overnight" lives. They are the one thing worth sending verbatim,
     * so they are sent verbatim and capped by count rather than summarised.
     *
     * @return list<array<string,mixed>>
     */
    public function narrativesInWindow(string $from, string $to, int $limit): array
    {
        return $this->db->all(
            'SELECT `planting_id`, `event_type`, `event_date`, `narrative`'
            . ' FROM `plant_event`'
            . ' WHERE `user_id` = :' . self::SCOPE
            . "   AND `event_date` BETWEEN :from AND :to AND `narrative` <> ''"
            . '   AND `narrative` IS NOT NULL'
            . ' ORDER BY `event_date` DESC, `id` DESC LIMIT ' . (int) $limit,
            $this->bind(['from' => $from, 'to' => $to])
        );
    }

    /**
     * Garden-level actions in a window, rolled up per garden and type.
     *
     * The same reason as `summaryByPlanting()`, and the same trap it avoids:
     * a mulch or an amendment that never fanned out to a plant exists only
     * here, so a document built from plant events alone would be missing the
     * half of the season that happened to the beds.
     *
     * @return list<array<string,mixed>>
     */
    public function gardenSummaryInWindow(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT ge.garden_id, ge.event_type, COUNT(*) AS events,'
            . ' MIN(ge.event_date) AS first_date, MAX(ge.event_date) AS last_date,'
            . ' SUM(ge.duration_min) AS duration_min,'
            . ' GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR \', \') AS products'
            . ' FROM `garden_event` ge'
            . ' LEFT JOIN `user_list_item` l ON l.id = ge.ref_list_item_id'
            . ' WHERE ge.user_id = :' . self::SCOPE
            . '   AND ge.event_date BETWEEN :from AND :to'
            . ' GROUP BY ge.garden_id, ge.event_type'
            . ' ORDER BY ge.garden_id, ge.event_type',
            $this->bind(['from' => $from, 'to' => $to])
        );
    }

    /**
     * The pests and the treatments actually named, per planting.
     *
     * `summaryByPlanting()` can say a plant was treated four times; only the
     * list item says what for and with what, and "aphids, four times, neem"
     * is the whole of what a recommendation needs.
     *
     * @return list<array<string,mixed>>
     */
    public function pestsInWindow(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT e.planting_id, e.event_type, l.name AS ref_name, COUNT(*) AS events,'
            . ' MAX(e.event_date) AS last_date'
            . ' FROM `plant_event` e'
            . ' JOIN `user_list_item` l ON l.id = e.ref_list_item_id'
            . ' WHERE e.user_id = :' . self::SCOPE
            . '   AND e.event_date BETWEEN :from AND :to'
            . '   AND e.event_type IN (:observed, :treated)'
            . ' GROUP BY e.planting_id, e.event_type, l.name'
            . ' ORDER BY e.planting_id',
            $this->bind([
                'from'     => $from,
                'to'       => $to,
                'observed' => EventType::PEST_OBSERVED,
                'treated'  => EventType::PEST_TREATED,
            ])
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
