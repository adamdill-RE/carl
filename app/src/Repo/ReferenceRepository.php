<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;
use Carl\Support\Clock;

/**
 * Global reference data: research, plants, pests, guidance.
 *
 * This does NOT extend Repository, and that is deliberate. Handoff Section
 * 0.5: reference data is global and read-only to users, so there is no
 * user_id to scope on and no scoping to enforce. Everything here is a read
 * except the research importer's writes.
 */
final class ReferenceRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findRegion(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `region` WHERE `id` = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findRegionByKey(string $regionKey): ?array
    {
        return $this->db->one('SELECT * FROM `region` WHERE `region_key` = :key', ['key' => $regionKey]);
    }

    /** Region resolution is region_key = 'US-' + county FIPS (handoff 8.3). */
    public function regionIdForCounty(?string $countyFips): ?int
    {
        if ($countyFips === null || $countyFips === '') {
            return null;
        }
        $id = $this->db->value(
            'SELECT `id` FROM `region` WHERE `region_key` = :key',
            ['key' => 'US-' . $countyFips]
        );
        return $id === null ? null : (int) $id;
    }

    /**
     * Record that somebody lives in a county nobody has researched yet, so
     * the admin queue has a row to show (handoff Section 9.4). Idempotent.
     */
    public function noteUnresearchedCounty(string $countyFips, ?string $state, ?string $countyName): int
    {
        $key = 'US-' . $countyFips;
        $this->db->run(
            'INSERT INTO `region` (region_key, country, state, county, label, research_status,'
            . ' first_seen_at, created_at, updated_at)'
            . ' VALUES (:key, :country, :state, :county, :label, :status,'
            . ' UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [
                'key'     => $key,
                'country' => 'US',
                'state'   => $state,
                'county'  => $countyName,
                'label'   => \trim(($countyName ?? $key) . ($state !== null ? ', ' . $state : '')),
                'status'  => 'none',
            ]
        );
        return $this->db->insertId();
    }

    /**
     * The plant list for a plant form.
     *
     * With a researched region, types carrying a plant_region row come first
     * and the recommended ones are marked. Without one, the user still gets
     * the whole global catalog with nothing marked -- DTM is global, so the
     * countdowns still work (handoff Section 9.4).
     *
     * @return list<array<string,mixed>>
     */
    public function plantTypesForRegion(?int $regionId, ?string $today = null): array
    {
        if ($regionId === null) {
            return $this->db->all(
                'SELECT pt.*, 0 AS in_region, 0 AS recommended, NULL AS season,'
                . ' NULL AS window_start, NULL AS window_end, NULL AS region_method,'
                . ' NULL AS regional_notes, NULL AS region_confidence'
                . ' FROM `plant_type` pt ORDER BY pt.category, pt.type'
            );
        }

        // One statement: the region overlay is a LEFT JOIN, not a second read.
        return $this->db->all(
            'SELECT pt.*,'
            . ' CASE WHEN pr.id IS NULL THEN 0 ELSE 1 END AS in_region,'
            . ' COALESCE(pr.recommended, 0) AS recommended,'
            . ' pr.season, pr.window_start, pr.window_end, pr.method AS region_method,'
            . ' pr.regional_notes, pr.confidence AS region_confidence,'
            . ' pr.dtm_days_min_override, pr.dtm_days_max_override'
            . ' FROM `plant_type` pt'
            . ' LEFT JOIN `plant_region` pr'
            . '   ON pr.plant_type_id = pt.id AND pr.region_id = :region_id'
            . ' ORDER BY in_region DESC, recommended DESC, pt.category, pt.type',
            ['region_id' => $regionId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findPlantType(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `plant_type` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * The research card shown on every plant form and plant report
     * (handoff Section 9.1): the global plant values plus this region's
     * windows, with the source and confidence that justify each.
     *
     * @return array{plant:?array<string,mixed>,regions:list<array<string,mixed>>}
     */
    public function researchCard(int $plantTypeId, ?int $regionId): array
    {
        $plant = $this->findPlantType($plantTypeId);
        if ($plant === null || $regionId === null) {
            return ['plant' => $plant, 'regions' => []];
        }

        $regions = $this->db->all(
            'SELECT * FROM `plant_region` WHERE `plant_type_id` = :plant_id AND `region_id` = :region_id'
            . " ORDER BY FIELD(`season`, 'spring', 'summer', 'fall', 'winter')",
            ['plant_id' => $plantTypeId, 'region_id' => $regionId]
        );

        return ['plant' => $plant, 'regions' => $regions];
    }

    /**
     * MOTD guidance lines for today and the categories this user grows
     * (handoff Section 4.2). Filtering by date happens in PHP because the
     * windows are recurring MM-DD strings that can wrap the new year.
     *
     * @param list<string> $categories
     * @return list<array<string,mixed>>
     */
    public function guidanceFor(?int $regionId, array $categories, string $today): array
    {
        if ($regionId === null) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT * FROM `region_guidance` WHERE `region_id` = :region_id ORDER BY `topic`',
            ['region_id' => $regionId]
        );

        $lowered = \array_map(\strtolower(...), $categories);
        $out = [];

        foreach ($rows as $row) {
            if (!Clock::inRecurringWindow($today, (string) $row['show_from'], (string) $row['show_to'])) {
                continue;
            }
            $applies = \trim((string) $row['applies_to_categories']);
            if ($applies !== '') {
                // Multi-valued cells are ';'-separated; empty means all.
                $wanted = \array_map(
                    static fn (string $c): string => \strtolower(\trim($c)),
                    \explode(';', $applies)
                );
                if (\array_intersect($wanted, $lowered) === []) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> pests active now for these categories */
    public function activePests(?int $regionId, array $categories, string $today): array
    {
        if ($regionId === null) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT pr.*, p.name, p.kind, p.signs, p.treatments FROM `pest_region` pr'
            . ' JOIN `pest` p ON p.id = pr.pest_id'
            . ' WHERE pr.region_id = :region_id ORDER BY p.name',
            ['region_id' => $regionId]
        );

        $lowered = \array_map(\strtolower(...), $categories);
        $out = [];
        foreach ($rows as $row) {
            if (!Clock::inRecurringWindow($today, $row['active_start'], $row['active_end'])) {
                continue;
            }
            $affects = \trim((string) ($row['affects_categories'] ?? ''));
            if ($affects !== '') {
                $wanted = \array_map(
                    static fn (string $c): string => \strtolower(\trim($c)),
                    \explode(';', $affects)
                );
                if (\array_intersect($wanted, $lowered) === []) {
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The admin queue (handoff Section 9.4): every distinct county among
     * users whose region is missing or unresearched, with user count, when it
     * was first seen, and a zip and place to name it by.
     *
     * @return list<array<string,mixed>>
     */
    public function regionsNeedingResearch(): array
    {
        return $this->db->all(
            'SELECT u.county_fips,'
            . ' COUNT(*) AS user_count,'
            . ' MIN(u.created_at) AS first_seen_at,'
            . ' MIN(u.zip) AS sample_zip,'
            . ' MAX(z.county_name) AS county_name,'
            . ' MAX(z.state) AS state,'
            . ' MAX(r.id) AS region_id,'
            . ' MAX(r.research_status) AS research_status,'
            . ' MAX(r.label) AS region_label'
            . ' FROM `user` u'
            . ' LEFT JOIN `zcta` z ON z.zip = u.zip'
            . ' LEFT JOIN `region` r ON r.id = u.region_id'
            . ' WHERE u.county_fips IS NOT NULL'
            . "   AND (u.region_id IS NULL OR r.research_status <> 'researched')"
            . ' GROUP BY u.county_fips'
            . ' ORDER BY user_count DESC, first_seen_at',
        );
    }

    /** @return list<array<string,mixed>> */
    public function allRegions(): array
    {
        return $this->db->all(
            'SELECT r.*, (SELECT COUNT(*) FROM `user` u WHERE u.region_id = r.id) AS user_count'
            . ' FROM `region` r ORDER BY r.research_status, r.label'
        );
    }

    /** @return list<array<string,mixed>> */
    public function imports(int $limit = 25): array
    {
        return $this->db->all(
            'SELECT i.*, u.username AS imported_by_name FROM `research_import` i'
            . ' LEFT JOIN `user` u ON u.id = i.imported_by'
            . ' ORDER BY i.imported_at DESC LIMIT ' . (int) $limit
        );
    }

    /** @return array{plant_type:int,plant_region:int,pest:int,pest_region:int,region:int,region_guidance:int} */
    public function counts(): array
    {
        $row = $this->db->one(
            'SELECT'
            . ' (SELECT COUNT(*) FROM `plant_type`) AS plant_type,'
            . ' (SELECT COUNT(*) FROM `plant_region`) AS plant_region,'
            . ' (SELECT COUNT(*) FROM `pest`) AS pest,'
            . ' (SELECT COUNT(*) FROM `pest_region`) AS pest_region,'
            . ' (SELECT COUNT(*) FROM `region`) AS region,'
            . ' (SELECT COUNT(*) FROM `region_guidance`) AS region_guidance'
        );
        return [
            'plant_type'      => (int) ($row['plant_type'] ?? 0),
            'plant_region'    => (int) ($row['plant_region'] ?? 0),
            'pest'            => (int) ($row['pest'] ?? 0),
            'pest_region'     => (int) ($row['pest_region'] ?? 0),
            'region'          => (int) ($row['region'] ?? 0),
            'region_guidance' => (int) ($row['region_guidance'] ?? 0),
        ];
    }
}
