<?php

declare(strict_types=1);

namespace Carl\Repo;

/**
 * Gardens, their rows, water zones and containers (handoff Sections 4.6, 5.4).
 */
final class GardenRepository extends Repository
{
    public const INDOOR_NAME = 'Indoor Garden';

    protected function table(): string
    {
        return 'garden';
    }

    protected function writable(): array
    {
        return ['name', 'is_indoor', 'ns_ft', 'ew_ft', 'row_count', 'row_orientation',
                'soil_type', 'notes', 'is_active'];
    }

    /** @return list<array<string,mixed>> */
    public function activeGardens(): array
    {
        return $this->where('`is_active` = 1', [], '`is_indoor` DESC, `name`');
    }

    /**
     * Every account gets an Indoor Garden at signup: it is the default
     * location for indoor seed starts (handoff Section 4.1).
     */
    public function ensureIndoorGarden(): int
    {
        $existing = $this->db->value(
            'SELECT id FROM `garden` WHERE ' . $this->scoped('`is_indoor` = 1'),
            $this->bind([])
        );
        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->insert([
            'name'      => self::INDOOR_NAME,
            'is_indoor' => 1,
            'row_count' => 0,
            'soil_type' => 'container',
            'notes'     => 'Created automatically. Indoor seed starts default here.',
        ]);
    }

    // -- Rows ------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function rows(int $gardenId): array
    {
        return $this->db->all(
            'SELECT r.* FROM `garden_row` r'
            . ' WHERE r.user_id = :' . self::SCOPE . ' AND r.garden_id = :garden_id'
            . ' ORDER BY r.ordinal',
            $this->bind(['garden_id' => $gardenId])
        );
    }

    /**
     * Rows for several gardens at once, so a page listing every garden costs
     * one statement instead of one per garden (hosting Section 9).
     *
     * @param list<int> $gardenIds
     * @return array<int,list<array<string,mixed>>> keyed by garden_id
     */
    public function rowsForGardens(array $gardenIds): array
    {
        $out = [];
        foreach ($gardenIds as $id) {
            $out[$id] = [];
        }
        if ($gardenIds === []) {
            return $out;
        }

        $params = [];
        $in = self::inClause($gardenIds, 'g', $params);
        $rows = $this->db->all(
            'SELECT r.* FROM `garden_row` r'
            . ' WHERE r.user_id = :' . self::SCOPE . ' AND r.garden_id ' . $in
            . ' ORDER BY r.garden_id, r.ordinal',
            $this->bind($params)
        );
        foreach ($rows as $row) {
            $out[(int) $row['garden_id']][] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findRow(int $rowId): ?array
    {
        return $this->db->one(
            'SELECT * FROM `garden_row` WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind(['id' => $rowId])
        );
    }

    /**
     * Create rows 1..n for a garden, keeping any that already exist so a
     * rename survives an edit that only changes the count.
     */
    public function syncRows(int $gardenId, int $rowCount): void
    {
        $existing = $this->rows($gardenId);
        $byOrdinal = [];
        foreach ($existing as $row) {
            $byOrdinal[(int) $row['ordinal']] = $row;
        }

        $now = $this->now();
        $toCreate = [];
        for ($ordinal = 1; $ordinal <= $rowCount; $ordinal++) {
            if (isset($byOrdinal[$ordinal])) {
                continue;
            }
            $toCreate[] = [$this->userId, $gardenId, $ordinal, 'Row ' . $ordinal, 'high', $now, $now];
        }
        if ($toCreate !== []) {
            $this->db->upsertChunk(
                'garden_row',
                ['user_id', 'garden_id', 'ordinal', 'name', 'sun_exposure', 'created_at', 'updated_at'],
                $toCreate,
                ['name']
            );
        }

        // Rows above the new count go only if nothing is planted in them --
        // deleting a row with history would take the history with it.
        $surplus = [];
        foreach ($byOrdinal as $ordinal => $row) {
            if ($ordinal > $rowCount) {
                $surplus[] = (int) $row['id'];
            }
        }
        if ($surplus !== []) {
            $params = [];
            $in = self::inClause($surplus, 'r', $params);
            $this->db->run(
                'DELETE FROM `garden_row` WHERE `user_id` = :' . self::SCOPE . ' AND `id` ' . $in
                . ' AND `id` NOT IN (SELECT `garden_row_id` FROM `planting`'
                . '   WHERE `garden_row_id` IS NOT NULL AND `user_id` = :' . self::SCOPE . '2)',
                $this->bind($params + [self::SCOPE . '2' => $this->userId])
            );
        }
    }

    /** @param array<string,mixed> $data */
    public function updateRow(int $rowId, array $data): int
    {
        $allowed = \array_intersect_key($data, \array_flip(['name', 'sun_exposure', 'shade_cloth_id', 'notes']));
        if ($allowed === []) {
            return 0;
        }
        $allowed['updated_at'] = $this->now();

        $assignments = [];
        foreach (\array_keys($allowed) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }

        return $this->db->run(
            'UPDATE `garden_row` SET ' . \implode(', ', $assignments)
            . ' WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind($allowed + ['id' => $rowId])
        )->rowCount();
    }

    // -- Water zones -----------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function zones(int $gardenId): array
    {
        return $this->db->all(
            'SELECT z.*, l.name AS method_name FROM `water_zone` z'
            . ' LEFT JOIN `user_list_item` l ON l.id = z.water_method_id'
            . ' WHERE z.user_id = :' . self::SCOPE . ' AND z.garden_id = :garden_id'
            . ' ORDER BY z.name',
            $this->bind(['garden_id' => $gardenId])
        );
    }

    /** @return list<int> the garden_row ids a zone covers */
    public function zoneRowIds(int $zoneId): array
    {
        $ids = $this->db->column(
            'SELECT zr.garden_row_id FROM `water_zone_row` zr'
            . ' JOIN `water_zone` z ON z.id = zr.water_zone_id'
            . ' WHERE z.user_id = :' . self::SCOPE . ' AND zr.water_zone_id = :zone_id',
            $this->bind(['zone_id' => $zoneId])
        );
        return \array_map(\intval(...), $ids);
    }

    /**
     * Create a zone, or -- the same name in the same garden -- replace what
     * it carries. Saving a zone twice is how it is edited.
     *
     * @param array{emitter_gph:?float,emitter_spacing_in:?float,line_spacing_in:?float,
     *              efficiency_pct:int} $emitter what the zone puts down (Phase 14,
     *              migration 025); see Carl\Domain\DripLine
     */
    public function createZone(int $gardenId, string $name, ?int $methodId, array $emitter = []): int
    {
        $now = $this->now();
        $this->db->run(
            'INSERT INTO `water_zone` (user_id, garden_id, name, water_method_id,'
            . ' emitter_gph, emitter_spacing_in, line_spacing_in, efficiency_pct, created_at, updated_at)'
            . ' VALUES (:user_id, :garden_id, :name, :method_id,'
            . ' :emitter_gph, :emitter_spacing_in, :line_spacing_in, :efficiency_pct, :created_at, :updated_at)'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`),'
            . ' `water_method_id` = VALUES(`water_method_id`),'
            . ' `emitter_gph` = VALUES(`emitter_gph`),'
            . ' `emitter_spacing_in` = VALUES(`emitter_spacing_in`),'
            . ' `line_spacing_in` = VALUES(`line_spacing_in`),'
            . ' `efficiency_pct` = VALUES(`efficiency_pct`),'
            . ' `updated_at` = VALUES(`updated_at`)',
            [
                'user_id'            => $this->userId,
                'garden_id'          => $gardenId,
                'name'               => \substr($name, 0, 80),
                'method_id'          => $methodId,
                'emitter_gph'        => $emitter['emitter_gph'] ?? null,
                'emitter_spacing_in' => $emitter['emitter_spacing_in'] ?? null,
                'line_spacing_in'    => $emitter['line_spacing_in'] ?? null,
                'efficiency_pct'     => (int) ($emitter['efficiency_pct'] ?? \Carl\Domain\DripLine::DEFAULT_EFFICIENCY_PCT),
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );
        return $this->db->insertId();
    }

    /** @param list<int> $rowIds */
    public function setZoneRows(int $zoneId, array $rowIds): void
    {
        $this->db->run(
            'DELETE zr FROM `water_zone_row` zr JOIN `water_zone` z ON z.id = zr.water_zone_id'
            . ' WHERE z.user_id = :' . self::SCOPE . ' AND zr.water_zone_id = :zone_id',
            $this->bind(['zone_id' => $zoneId])
        );
        if ($rowIds === []) {
            return;
        }
        $rows = [];
        foreach ($rowIds as $rowId) {
            $rows[] = [$zoneId, $rowId];
        }
        $this->db->upsertChunk('water_zone_row', ['water_zone_id', 'garden_row_id'], $rows, []);
    }

    public function deleteZone(int $zoneId): int
    {
        return $this->db->run(
            'DELETE FROM `water_zone` WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind(['id' => $zoneId])
        )->rowCount();
    }

    // -- Containers ------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function containers(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM `container` WHERE `user_id` = :' . self::SCOPE;
        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }
        return $this->db->all($sql . ' ORDER BY `name`', $this->bind([]));
    }

    public function findContainer(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM `container` WHERE `user_id` = :' . self::SCOPE . ' AND `id` = :id',
            $this->bind(['id' => $id])
        );
    }

    public function ensureContainer(string $name, ?string $size = null, ?string $description = null, ?string $soilType = null): int
    {
        $name = \trim($name);
        if ($name === '') {
            return 0;
        }
        $now = $this->now();
        $this->db->run(
            'INSERT INTO `container` (user_id, name, size, description, soil_type, is_active, created_at, updated_at)'
            . ' VALUES (:user_id, :name, :size, :description, :soil_type, 1, :created_at, :updated_at)'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `is_active` = 1',
            [
                'user_id'     => $this->userId,
                'name'        => \substr($name, 0, 120),
                'size'        => $size === '' ? null : $size,
                'description' => $description === '' ? null : $description,
                'soil_type'   => $soilType === '' ? null : $soilType,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        return $this->db->insertId();
    }

    /**
     * Occupancy hint on row selection: a nudge, never a block (handoff 4.3).
     *
     * @param int|null $gardenId one garden, or null for every row this user
     *        has -- the plant form offers rows from every garden at once and
     *        filters them in the browser, so it needs the whole map. Either
     *        way it is one statement (hosting Section 9).
     * @return array<int,array{living:int,plantings:int}> keyed by garden_row_id
     */
    public function livingCountByRow(?int $gardenId = null): array
    {
        $rows = $this->db->all(
            'SELECT `garden_row_id`, SUM(`quantity_live`) AS living, COUNT(*) AS plantings'
            . ' FROM `planting`'
            . ' WHERE `user_id` = :' . self::SCOPE
            . ($gardenId !== null ? ' AND `garden_id` = :garden_id' : '')
            . '   AND `garden_row_id` IS NOT NULL AND `state` <> :ended'
            . ' GROUP BY `garden_row_id`',
            $this->bind($gardenId !== null
                ? ['garden_id' => $gardenId, 'ended' => 'ended']
                : ['ended' => 'ended'])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['garden_row_id']] = [
                'living'    => (int) $row['living'],
                'plantings' => (int) $row['plantings'],
            ];
        }
        return $out;
    }
}
