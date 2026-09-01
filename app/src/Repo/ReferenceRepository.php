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
                'SELECT pt.*, 0 AS in_region, 0 AS recommended, 0 AS season_count,'
                . ' NULL AS earliest_window, NULL AS dtm_days_min_override,'
                . ' NULL AS dtm_days_max_override'
                . ' FROM `plant_type` pt ORDER BY pt.category, pt.type'
            );
        }

        // One statement, and one row per plant: the overlay is a LEFT JOIN,
        // but a plant with both a spring and a fall window has TWO
        // plant_region rows, so without the aggregate the dropdown would list
        // it twice. The seasons themselves belong on the research card, which
        // reads them separately.
        return $this->db->all(
            'SELECT pt.*,'
            . ' MAX(CASE WHEN pr.id IS NULL THEN 0 ELSE 1 END) AS in_region,'
            . ' MAX(COALESCE(pr.recommended, 0)) AS recommended,'
            . ' COUNT(pr.id) AS season_count,'
            . ' MIN(pr.window_start) AS earliest_window,'
            . ' MIN(pr.dtm_days_min_override) AS dtm_days_min_override,'
            . ' MAX(pr.dtm_days_max_override) AS dtm_days_max_override'
            . ' FROM `plant_type` pt'
            . ' LEFT JOIN `plant_region` pr'
            . '   ON pr.plant_type_id = pt.id AND pr.region_id = :region_id'
            . ' GROUP BY pt.id'
            . ' ORDER BY in_region DESC, recommended DESC, pt.category, pt.type',
            ['region_id' => $regionId]
        );
    }

    /**
     * Every sowing window this region has, for the succession planner
     * (handoff Section 15, built in Phase 6).
     *
     * Sowing windows only. A `transplant` row is the extension saying when to
     * move a seedling OUT, which is a different question from when to open
     * another seed packet -- and a `NULL` method predates the distinction, so
     * it is kept.
     *
     * One statement for the whole region. The planner draws every crop on one
     * page, so a per-crop query would be one round trip per row of the table
     * (hosting Section 9).
     *
     * @return list<array<string,mixed>>
     */
    public function sowingWindows(int $regionId): array
    {
        return $this->db->all(
            'SELECT pr.*, pt.category, pt.type, pt.plant_family, pt.lifecycle,'
            . ' pt.dtm_days_min, pt.dtm_days_max, pt.dtm_counted_from,'
            . ' pt.typical_start_method, pt.is_tree'
            . ' FROM `plant_region` pr JOIN `plant_type` pt ON pt.id = pr.plant_type_id'
            . " WHERE pr.region_id = :region AND (pr.method IS NULL OR pr.method = 'seed')"
            . ' AND pr.window_start IS NOT NULL'
            // A tree is not a succession crop, and neither is a perennial:
            // sowing another round of asparagus every fortnight is not a
            // thing anybody does.
            . " AND pt.is_tree = 0 AND pt.lifecycle = 'annual'"
            . ' ORDER BY pr.recommended DESC, pt.category, pt.type, pr.window_start',
            ['region' => $regionId]
        );
    }

    /** @return array<string,mixed>|null */
    /**
     * The research rows for a given set of plant types, and the regional
     * overrides in force for one region -- "the research values in force"
     * of handoff Section 13.3, for the plants a user actually grows rather
     * than for the whole catalogue.
     *
     * Two statements whatever the number of plant types, which is what keeps
     * the JSON export from costing one round trip per planting.
     *
     * @param list<int> $plantTypeIds
     * @return array{plants:list<array<string,mixed>>,regions:list<array<string,mixed>>}
     */
    public function researchFor(array $plantTypeIds, ?int $regionId): array
    {
        if ($plantTypeIds === []) {
            return ['plants' => [], 'regions' => []];
        }

        $names = [];
        $params = [];
        foreach (\array_values(\array_unique($plantTypeIds)) as $i => $id) {
            $names[] = ':t' . $i;
            $params['t' . $i] = $id;
        }
        $in = '(' . \implode(', ', $names) . ')';

        $plants = $this->db->all(
            'SELECT * FROM `plant_type` WHERE `id` IN ' . $in . ' ORDER BY `category`, `type`',
            $params
        );

        if ($regionId === null) {
            return ['plants' => $plants, 'regions' => []];
        }

        // The placeholders cannot be reused across two statements' worth of
        // binding either -- these are fresh names for the second (hosting
        // Section 7).
        $names = [];
        $regionParams = ['region_id' => $regionId];
        foreach (\array_values(\array_unique($plantTypeIds)) as $i => $id) {
            $names[] = ':r' . $i;
            $regionParams['r' . $i] = $id;
        }

        $regions = $this->db->all(
            'SELECT * FROM `plant_region`'
            . ' WHERE `region_id` = :region_id AND `plant_type_id` IN (' . \implode(', ', $names) . ')'
            . " ORDER BY `plant_type_id`, FIELD(`season`, 'spring', 'summer', 'fall', 'winter')",
            $regionParams
        );

        return ['plants' => $plants, 'regions' => $regions];
    }

    public function findPlantType(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `plant_type` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * The research card shown on every plant form and plant report
     * (handoff Section 9.1): the global plant values plus this region's
     * windows, with the source and confidence that justify each.
     *
     * @return array{plant:?array<string,mixed>,regions:list<array<string,mixed>>,
     *               companions:list<array<string,mixed>>}
     */
    public function researchCard(int $plantTypeId, ?int $regionId): array
    {
        $plant = $this->findPlantType($plantTypeId);
        if ($plant === null) {
            return ['plant' => null, 'regions' => [], 'companions' => []];
        }
        // Companions are global, so an unresearched county still gets them:
        // they are a fact about the plants, not about the place.
        if ($regionId === null) {
            return ['plant' => $plant, 'regions' => [],
                    'companions' => $this->companionsFor((string) $plant['category'])];
        }

        $regions = $this->db->all(
            'SELECT * FROM `plant_region` WHERE `plant_type_id` = :plant_id AND `region_id` = :region_id'
            . " ORDER BY FIELD(`season`, 'spring', 'summer', 'fall', 'winter')",
            ['plant_id' => $plantTypeId, 'region_id' => $regionId]
        );

        return ['plant' => $plant, 'regions' => $regions,
                'companions' => $this->companionsFor((string) $plant['category'])];
    }

    /**
     * The companion pairings for one category (Phase 6, handoff Section 14 v2).
     *
     * The pair is unordered and stored once, so this reads BOTH columns and
     * flips the row where the match came from the second -- the caller wants
     * "the other plant", not "column two".
     *
     * One statement. The OR is why `plant_companion` indexes both columns:
     * without the second index half of every lookup is a table scan.
     *
     * @return list<array{other:string,relationship:string,reason:?string,
     *                    confidence:?string,source:?string}>
     */
    public function companionsFor(string $category): array
    {
        if (\trim($category) === '') {
            return [];
        }

        // Two names for one value: with emulation off a named placeholder
        // cannot be reused in a statement (hosting Section 7).
        $rows = $this->db->all(
            'SELECT * FROM `plant_companion`'
            . ' WHERE `category` = :one OR `other_category` = :two'
            . " ORDER BY `relationship` DESC, FIELD(`confidence`, 'verified', 'approx', 'generic'),"
            . ' `category`, `other_category`',
            ['one' => $category, 'two' => $category]
        );

        $out = [];
        foreach ($rows as $row) {
            $mine = \strcasecmp((string) $row['category'], $category) === 0;
            $out[] = [
                'other'        => (string) ($mine ? $row['other_category'] : $row['category']),
                'relationship' => (string) $row['relationship'],
                'reason'       => $row['reason'] === null ? null : (string) $row['reason'],
                'confidence'   => $row['confidence'] === null ? null : (string) $row['confidence'],
                'source'       => $row['source'] === null ? null : (string) $row['source'],
            ];
        }
        return $out;
    }

    /**
     * Every pairing in the catalogue, for the reference screen.
     *
     * One statement, and the whole table: it is one row per stated pair
     * across every crop, which is tens of rows rather than thousands.
     *
     * @return list<array<string,mixed>>
     */
    public function allCompanions(): array
    {
        return $this->db->all(
            'SELECT * FROM `plant_companion`'
            . " ORDER BY `relationship` DESC, FIELD(`confidence`, 'verified', 'approx', 'generic'),"
            . ' `category`, `other_category`'
        );
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

    /**
     * Every window this region carries, joined to the plant it is about.
     *
     * sowingWindows() is next door and is NOT this: it answers the succession
     * planner\'s question and so drops transplant rows, trees and perennials.
     * A calendar draws "the transplant window opens" as well as "sow by", so
     * it needs the unfiltered set -- one statement for a table that is a few
     * hundred rows for a whole county.
     *
     * @return list<array<string,mixed>>
     */
    public function windowsForRegion(?int $regionId): array
    {
        if ($regionId === null) {
            return [];
        }
        return $this->db->all(
            'SELECT pr.*, pt.category, pt.type, pt.plant_family,'
            . ' pt.dtm_days_min, pt.dtm_days_max, pt.dtm_counted_from,'
            . ' pt.weeks_before_transplant_to_start'
            . ' FROM `plant_region` pr JOIN `plant_type` pt ON pt.id = pr.plant_type_id'
            . ' WHERE pr.region_id = :region_id'
            . ' ORDER BY pt.category, pt.type, pr.window_start',
            ['region_id' => $regionId]
        );
    }

    /**
     * Every pest window this region carries, whether or not it is open today.
     *
     * activePests() answers "what should the MOTD say this morning" and
     * filters to today; a calendar is asking when the window OPENS, which is
     * by definition a date that is not today.
     *
     * @return list<array<string,mixed>>
     */
    public function pestWindowsForRegion(?int $regionId): array
    {
        if ($regionId === null) {
            return [];
        }
        return $this->db->all(
            'SELECT pr.*, p.name, p.kind, p.signs, p.pest_key FROM `pest_region` pr'
            . ' JOIN `pest` p ON p.id = pr.pest_id'
            . ' WHERE pr.region_id = :region_id ORDER BY pr.active_start, p.name',
            ['region_id' => $regionId]
        );
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
     * The pest and disease reference screen (Phase 9).
     *
     * ONE STATEMENT FOR THE WHOLE CATALOGUE, and the whole catalogue is the
     * right read: it is seventy-odd rows of prose, the screen is a reference
     * somebody scrolls and searches rather than pages, and filtering by
     * category cannot be done in SQL anyway -- `affects_categories` is a
     * semicolon-separated cell (research-template/README.md), so matching
     * "Tomato" against it in SQL would also match "Tomatillo" through a LIKE.
     * The kind and the text search go in the statement; the category is a
     * PHP intersection, the same way guidanceFor() and activePests() do it.
     *
     * @param array{kind?:string,search?:string,category?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function pestCatalogue(array $filters = []): array
    {
        $predicates = [];
        $params = [];

        $kind = (string) ($filters['kind'] ?? '');
        if (\in_array($kind, ['pest', 'disease', 'disorder'], true)) {
            $predicates[] = '`kind` = :kind';
            $params['kind'] = $kind;
        }

        $search = \trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Four names for one value: emulation is off, so a placeholder
            // cannot be reused within a statement (hosting Section 7).
            $predicates[] = '(`name` LIKE :s1 OR `also_called` LIKE :s2'
                . ' OR `latin_name` LIKE :s3 OR `signs` LIKE :s4)';
            $like = '%' . $search . '%';
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
            $params['s4'] = $like;
        }

        $rows = $this->db->all(
            'SELECT * FROM `pest`'
            . ($predicates === [] ? '' : ' WHERE ' . \implode(' AND ', $predicates))
            . " ORDER BY FIELD(`kind`, 'pest', 'disease', 'disorder'), `name`",
            $params
        );

        $category = \strtolower(\trim((string) ($filters['category'] ?? '')));
        if ($category === '') {
            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            $affects = \array_filter(\array_map(
                static fn (string $c): string => \strtolower(\trim($c)),
                \explode(';', (string) ($row['affects_categories'] ?? ''))
            ));
            // An empty cell means "anything", which is true of slugs and of
            // frost and is the difference between a useful filter and one
            // that hides the things that matter most.
            if ($affects === [] || \in_array($category, $affects, true)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** How many pest rows came with Carl rather than from a county dataset. */
    public function builtinPestCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM `pest` WHERE `is_builtin` = 1', [], 0);
    }

    /**
     * Put every catalogue entry in front of every account (Phase 9).
     *
     * The set-based twin of `ListRepository::syncPestsFromReference()`, which
     * does one account at a time and is what keeps a NEW account current.
     * This is what runs after the catalogue itself changes -- a corrected
     * entry, a re-applied seed file, or a county dataset that added a pest --
     * and it is here rather than on ListRepository because it crosses every
     * account, which is exactly what that class exists to make impossible.
     *
     * TWO STATEMENTS AND THE ORDER MATTERS, the same order and for the same
     * reason as `023_pest_catalog.php`: adopt the rows an account typed for
     * itself that happen to name a catalogue entry, and only then insert what
     * is missing. Reversed, the insert would collide with the unique key on
     * (user_id, list_type, name) and the account would keep its orphan row
     * forever.
     *
     * @return int rows the insert created
     */
    public function syncPestListsForAllUsers(): int
    {
        $listType = \Carl\Domain\ListType::PEST_DISEASE;

        $this->db->run(
            'UPDATE `user_list_item` u JOIN `pest` p ON p.`name` = u.`name`'
            . ' SET u.`pest_id` = p.`id`, u.`updated_at` = UTC_TIMESTAMP()'
            . ' WHERE u.`list_type` = :list_type AND u.`pest_id` IS NULL',
            ['list_type' => $listType]
        );

        return $this->db->run(
            'INSERT INTO `user_list_item`'
            . ' (`user_id`, `list_type`, `name`, `pest_id`, `is_active`, `sort_order`,'
            . '  `created_at`, `updated_at`)'
            . ' SELECT u.`id`, :list_type, p.`name`, p.`id`, 1, 0,'
            . '        UTC_TIMESTAMP(), UTC_TIMESTAMP()'
            . ' FROM `user` u CROSS JOIN `pest` p'
            . ' WHERE NOT EXISTS ('
            . '   SELECT 1 FROM `user_list_item` x'
            . '   WHERE x.`user_id` = u.`id` AND x.`list_type` = :existing_type'
            . '     AND x.`pest_id` = p.`id`'
            . ' )',
            ['list_type' => $listType, 'existing_type' => $listType]
        )->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function findPestByKey(string $pestKey): ?array
    {
        return $this->db->one('SELECT * FROM `pest` WHERE `pest_key` = :key', ['key' => $pestKey]);
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
