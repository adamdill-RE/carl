<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\Database;
use Carl\Domain\DripLine;
use Carl\Domain\EventType;
use Carl\Domain\WaterMethod;

/**
 * What was put down, by hand or by a zone, as a depth per day (Phase 18).
 *
 * The one writer of "how much water did that logged watering apply". The
 * nightly checkbook (WateringModel) read this arithmetic from its own two
 * statements until Phase 18; the report pages read nothing, and a garden's
 * "water balance" was rain minus ET0 with every watering the gardener had
 * logged left out of it -- which is the number a person then checks against
 * the emitters they typed onto the zone, and finds wrong. So the reading and
 * the arithmetic moved here, and the model, the plant page, the garden page
 * and the MOTD all ask the same question of the same class.
 *
 * ONE STATEMENT per subject, whatever the range. The garden's own events and
 * the hand-logged plant events come back in one UNION, in one shape, and the
 * emitter figures, the method's flow rate and the garden's dimensions ride
 * along on the row rather than costing a lookup each.
 *
 * THE DOUBLE-COUNTING TRAP (Phase 3 handoff Section 4.2). Watering a zone
 * writes one `garden_event` AND a derived `plant_event` for every living
 * plant in the zone's rows, each carrying source_garden_event_id. Adding
 * both would multiply one watering by the number of plants in the bed. So a
 * garden's ledger reads its garden events directly -- each is one
 * application -- and only the plant events with NO source alongside them;
 * of those, the deepest on a day counts once, because watering six plants
 * in a bed by hand is one irrigation of the bed, not six. A single plant's
 * ledger is the other way up: the zone waterings that reached it (through
 * the derived row) plus its own hand waterings.
 *
 * Global by necessity, like WateringRepository::forUsersOnDate(): the model
 * walks every user's places in one job, so the user id is a parameter and
 * every statement carries it.
 */
final class IrrigationLedger
{
    /** A zone or garden watering: one application of the bed. */
    public const KIND_APPLICATION = 'application';

    /** A watering logged against a plant with no zone behind it. */
    public const KIND_HAND = 'hand';

    public function __construct(private Database $db)
    {
    }

    /** The event types that move the checkbook, and so invalidate a stored row. */
    public static function affectsBalance(string $eventType): bool
    {
        return $eventType === EventType::WATERED || $eventType === EventType::MULCHED;
    }

    /**
     * A garden's waterings in a date range: its own events and the hand
     * waterings of the plants in it.
     *
     * @return list<array<string,mixed>>
     */
    public function forGarden(int $userId, int $gardenId, string $from, string $to): array
    {
        return $this->rows(
            $userId, $from, $to,
            'ge.garden_id = :garden_id',
            'p.garden_id = :garden_id',
            ['garden_id' => $gardenId]
        );
    }

    /**
     * A container has no zones, so only the hand waterings of the plants in
     * it.
     *
     * @return list<array<string,mixed>>
     */
    public function forContainer(int $userId, int $containerId, string $from, string $to): array
    {
        return $this->rows(
            $userId, $from, $to,
            null,
            'p.container_id = :container_id',
            ['container_id' => $containerId]
        );
    }

    /**
     * One plant's waterings: the zone waterings that reached it, through the
     * derived row each fan-out wrote, and its own hand waterings.
     *
     * @return list<array<string,mixed>>
     */
    public function forPlanting(int $userId, int $plantingId, string $from, string $to): array
    {
        return $this->rows(
            $userId, $from, $to,
            'ge.id IN (SELECT pe.source_garden_event_id FROM `plant_event` pe'
            . ' WHERE pe.planting_id = :planting_id AND pe.source_garden_event_id IS NOT NULL)',
            'e.planting_id = :planting_id',
            ['planting_id' => $plantingId]
        );
    }

    /**
     * Everything one user logged on one day, for the MOTD's "logged today"
     * line under each place's recommendation. The stored row is the balance
     * at the START of the day (WateringModel), so a watering logged this
     * morning is not in it and cannot be; this is how the page still says it
     * was heard.
     *
     * @return array<string,list<array<string,mixed>>> keyed by place key ('g:12', 'c:7')
     */
    public function loggedOn(int $userId, string $date): array
    {
        $out = [];
        foreach ($this->rows($userId, $date, $date, '1 = 1', '1 = 1', []) as $row) {
            if ($row['place_key'] === null) {
                continue;
            }
            $out[(string) $row['place_key']][] = $row;
        }
        return $out;
    }

    /**
     * The depth per day, with the rule from the class comment: applications
     * add up, hand waterings on one day count once at the deepest.
     *
     * @param list<array<string,mixed>> $rows from any of the readers above
     * @return array<string,array{mm:float,basis:?string,basis_kind:?string}> keyed by event_date
     */
    public static function byDate(array $rows): array
    {
        $out = [];
        $hand = [];

        foreach ($rows as $row) {
            $depth = self::depthOf($row);
            $date = (string) $row['event_date'];

            if ((string) $row['kind'] === self::KIND_APPLICATION) {
                $out[$date]['mm'] = ($out[$date]['mm'] ?? 0.0) + $depth['mm'];
                $out[$date]['basis'] ??= $depth['basis'];
                $out[$date]['basis_kind'] ??= $depth['basis_kind'];
                continue;
            }

            if (!isset($hand[$date]) || $depth['mm'] > $hand[$date]['mm']) {
                $hand[$date] = $depth;
            }
        }

        foreach ($hand as $date => $depth) {
            $out[$date]['mm'] = ($out[$date]['mm'] ?? 0.0) + $depth['mm'];
            $out[$date]['basis'] ??= $depth['basis'];
            $out[$date]['basis_kind'] ??= $depth['basis_kind'];
        }

        foreach ($out as $date => $entry) {
            $out[$date] = [
                'mm'         => \round($entry['mm'], 2),
                'basis'      => $entry['basis'] ?? null,
                'basis_kind' => $entry['basis_kind'] ?? null,
            ];
        }
        \ksort($out);

        return $out;
    }

    /**
     * One row's depth.
     *
     * The zone's own emitter figures win when it has them (Phase 14): a zone
     * that says "0.5 gph every 12 inches" knows its depth to a decimal, which
     * neither a method name nor a typed mm/h does, and it is the figure the
     * gardener entered for exactly this. The method -- the event's own if it
     * named one, else the zone's -- is the fallback, and its guess is stated
     * in the basis so it can be corrected.
     *
     * @param array<string,mixed> $row
     * @return array{mm:float,basis:string,basis_kind:string,label:string}
     */
    public static function depthOf(array $row): array
    {
        $minutes = (int) ($row['duration_min'] ?? 0);
        $zoneName = \trim((string) ($row['zone_name'] ?? ''));
        $methodName = $row['method_name'] === null ? null : (string) $row['method_name'];

        $zoneDepth = ($row['emitter_gph'] ?? null) === null
            ? null
            : DripLine::depth($minutes, ['name' => $zoneName] + $row, DripLine::rowSpacingIn($row));

        if ($zoneDepth !== null) {
            return [
                'mm'         => $zoneDepth['mm'],
                'basis'      => $zoneDepth['basis'],
                'basis_kind' => 'zone',
                'label'      => $zoneName !== '' ? $zoneName : ($methodName ?? 'the zone'),
            ];
        }

        $depth = WaterMethod::depth(
            $minutes,
            $methodName,
            $row['flow_rate'] === null ? null : (string) $row['flow_rate'],
        );

        return [
            'mm'         => $depth['mm'],
            'basis'      => $depth['basis'],
            'basis_kind' => 'method',
            'label'      => $zoneName !== '' ? $zoneName : ($methodName ?? 'by hand'),
        ];
    }

    /**
     * The one statement. Two halves in one UNION: the garden's events, each
     * an application, and the plant events with no source, each a hand
     * watering. Either half can be switched off with a null predicate.
     *
     * The columns are the same in both halves, on purpose: depthOf() reads
     * one shape, and a NULL emitter figure on a hand watering is simply "no
     * zone", which is what it is.
     *
     * With emulation off a named placeholder cannot appear twice in one
     * statement (hosting Section 7), so each half binds its own copy of
     * every parameter, suffixed _g and _p; a caller's scope fragment is
     * rewritten the same way.
     *
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(
        int $userId,
        string $from,
        string $to,
        ?string $gardenScope,
        ?string $plantScope,
        array $params,
    ): array {
        $parts = [];
        $bound = [];
        $shared = $params + [
            'user_id' => $userId,
            'watered' => EventType::WATERED,
            'from'    => $from,
            'to'      => $to,
        ];

        if ($gardenScope !== null) {
            $parts[] = "SELECT '" . self::KIND_APPLICATION . "' AS kind,"
                . " CONCAT('g:', ge.garden_id) AS place_key,"
                . ' ge.event_date, ge.duration_min, ge.recorded_at,'
                . ' z.name AS zone_name, z.emitter_gph, z.emitter_spacing_in, z.line_spacing_in,'
                . ' z.efficiency_pct,'
                . ' COALESCE(l.name, zl.name) AS method_name,'
                . ' COALESCE(l.attr_1, zl.attr_1) AS flow_rate,'
                . ' g.row_count, g.ns_ft, g.ew_ft, g.row_orientation'
                . ' FROM `garden_event` ge'
                . ' JOIN `garden` g ON g.id = ge.garden_id'
                . ' LEFT JOIN `water_zone` z ON z.id = ge.water_zone_id'
                . ' LEFT JOIN `user_list_item` l ON l.id = ge.ref_list_item_id'
                . ' LEFT JOIN `user_list_item` zl ON zl.id = z.water_method_id'
                . ' WHERE ge.user_id = :user_id AND ge.event_type = :watered'
                . '   AND ge.event_date BETWEEN :from AND :to'
                . '   AND (' . $gardenScope . ')';
        }

        if ($plantScope !== null) {
            $parts[] = "SELECT '" . self::KIND_HAND . "' AS kind,"
                . " CASE WHEN p.container_id IS NOT NULL THEN CONCAT('c:', p.container_id)"
                . "      WHEN p.garden_id IS NOT NULL THEN CONCAT('g:', p.garden_id)"
                . '      ELSE NULL END AS place_key,'
                . ' e.event_date, e.duration_min, e.recorded_at,'
                . ' NULL AS zone_name, NULL AS emitter_gph, NULL AS emitter_spacing_in,'
                . ' NULL AS line_spacing_in, NULL AS efficiency_pct,'
                // The planting's default water method, for a watering logged
                // without naming one.
                . ' COALESCE(l.name, dl.name) AS method_name,'
                . ' COALESCE(l.attr_1, dl.attr_1) AS flow_rate,'
                . ' NULL AS row_count, NULL AS ns_ft, NULL AS ew_ft, NULL AS row_orientation'
                . ' FROM `plant_event` e'
                . ' JOIN `planting` p ON p.id = e.planting_id'
                . ' LEFT JOIN `user_list_item` l ON l.id = e.ref_list_item_id'
                . ' LEFT JOIN `user_list_item` dl ON dl.id = p.default_water_method_id'
                . ' WHERE e.user_id = :user_id AND e.event_type = :watered'
                . '   AND e.source_garden_event_id IS NULL'
                . '   AND e.event_date BETWEEN :from AND :to'
                . '   AND (' . $plantScope . ')';
        }

        if ($parts === []) {
            return [];
        }

        // Suffix every placeholder per half, longest names first so that
        // :from is not rewritten inside :from_x by a later pass.
        $names = \array_keys($shared);
        \usort($names, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $suffixes = $gardenScope !== null ? ['_g', '_p'] : ['_p'];
        foreach ($parts as $i => $sql) {
            $suffix = $suffixes[$i];
            foreach ($names as $name) {
                $sql = \preg_replace('/:' . \preg_quote($name, '/') . '(?![A-Za-z0-9_])/', ':' . $name . $suffix, $sql);
                $bound[$name . $suffix] = $shared[$name];
            }
            $parts[$i] = (string) $sql;
        }

        // Applications before hand waterings on the same day, so the first
        // basis a day records is the zone's, as the model always said it.
        $sql = \count($parts) === 1
            ? $parts[0] . ' ORDER BY event_date, recorded_at'
            : '(' . \implode(') UNION ALL (', $parts) . ') ORDER BY event_date, kind, recorded_at';

        return $this->db->all($sql, $bound);
    }
}
