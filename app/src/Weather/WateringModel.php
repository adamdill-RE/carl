<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Domain\EventType;
use Carl\Domain\KcCurve;
use Carl\Domain\PlantingState;
use Carl\Domain\SoilType;
use Carl\Domain\WaterMethod;
use Carl\Support\Clock;
use Throwable;

/**
 * The watering recommendation (handoff Section 11), run by
 * `bin/weather_sync.php --recommend` after the weather step.
 *
 * **It fetches nothing.** Everything it needs is already in the database:
 * `weather_daily.et0_mm` and `precip_mm` from the archive, the forecast from
 * `weather_forecast`, TAW and MAD from the garden's soil type, and the Kc
 * curve from `plant_type`. Phase 3 must not add an Open-Meteo call, and the
 * limit that actually bit during development was the hourly one, which no
 * amount of caching would have helped (Phase 3 handoff Section 1.3).
 *
 * The model is FAO-56's checkbook, simplified for a gardener:
 *
 *     D = clamp(D_prev + ET0 x Kc - rain_eff - irrigation, 0, TAW)
 *
 * D is a recursion, so each row stores the deficit it arrived at and the
 * next night reads it back. A cron that missed some nights is caught up by
 * walking the missing days forward; a first run seeds itself from a bounded
 * window rather than the whole season.
 */
final class WateringModel
{
    public const TIER_WATER  = 'water';
    public const TIER_LIKELY = 'likely';
    public const TIER_SKIP   = 'skip';

    /** Rain beyond this in a day runs off rather than filling the root zone. */
    private const RAIN_CAP_MM = 25.0;

    /** The fraction of capped rain that reaches the root zone. */
    private const RAIN_EFFICIENCY = 0.8;

    /** On clay, a downpour -- an inch in under an hour -- mostly runs off. */
    private const RAIN_EFFICIENCY_RUNOFF = 0.5;

    /** Mulch cuts evaporation from the soil surface. */
    private const MULCH_ET_FACTOR = 0.85;
    private const MULCH_LOOKBACK_DAYS = 60;

    /** Forecast Tmax at or above this is a heat day (handoff Section 11). */
    private const HEAT_C = 35.0;

    /** How far back a first run reconstructs the balance from. */
    private const SEED_DAYS = 30;

    private Database $db;

    /** @var list<string> */
    private array $log = [];

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @return array{places:int,rows:int,failures:int,log:list<string>}
     */
    public function run(?int $onlyUserId = null): array
    {
        $startedAt = $this->app->clock()->utcStamp();
        $places = $this->places($onlyUserId);
        $rows = 0;
        $failures = 0;

        foreach ($places as $place) {
            try {
                $rows += $this->computeFor($place);
            } catch (Throwable $e) {
                // One garden's failure must not stop the rest: the next run
                // picks it up, because the walk is derived from what is
                // stored rather than from a cursor.
                $failures++;
                $this->note($place['key'] . ' failed: ' . $e->getMessage());
            }
        }

        $this->recordRun($startedAt, \count($places), $rows, $failures);

        return ['places' => \count($places), 'rows' => $rows, 'failures' => $failures,
                'log' => $this->log];
    }

    /**
     * Every garden and container with something living in it, with the one
     * statement that also brings back the soil and the weather location.
     *
     * Indoor gardens are left out on purpose. ET0 is an outdoor number; a
     * seed tray under a window has no relationship to it, and a
     * recommendation computed from one would be confidently wrong.
     *
     * @return list<array<string,mixed>>
     */
    private function places(?int $onlyUserId): array
    {
        $filter = $onlyUserId !== null ? ' AND p.user_id = :user_id' : '';
        $params = $onlyUserId !== null ? ['user_id' => $onlyUserId, 'ended' => PlantingState::ENDED]
                                       : ['ended' => PlantingState::ENDED];

        $gardens = $this->db->all(
            "SELECT CONCAT('g:', g.id) AS `key`, 'garden' AS kind, g.id AS place_id,"
            . ' g.user_id, g.name, g.soil_type, u.weather_location_id, u.timezone'
            . ' FROM `garden` g'
            . ' JOIN `user` u ON u.id = g.user_id'
            . ' JOIN `planting` p ON p.garden_id = g.id AND p.state <> :ended'
            . '   AND p.quantity_live > 0'
            . ' WHERE g.is_active = 1 AND g.is_indoor = 0'
            . '   AND u.weather_location_id IS NOT NULL' . $filter
            . ' GROUP BY g.id, g.user_id, g.name, g.soil_type, u.weather_location_id, u.timezone',
            $params
        );

        $containers = $this->db->all(
            "SELECT CONCAT('c:', c.id) AS `key`, 'container' AS kind, c.id AS place_id,"
            . ' c.user_id, c.name, c.soil_type, u.weather_location_id, u.timezone'
            . ' FROM `container` c'
            . ' JOIN `user` u ON u.id = c.user_id'
            . ' JOIN `planting` p ON p.container_id = c.id AND p.state <> :ended'
            . '   AND p.quantity_live > 0'
            . ' WHERE c.is_active = 1 AND u.weather_location_id IS NOT NULL' . $filter
            . ' GROUP BY c.id, c.user_id, c.name, c.soil_type, u.weather_location_id, u.timezone',
            $params
        );

        // A container is always evaluated with the container TAW, whatever
        // soil it happens to name (handoff Section 11).
        foreach ($containers as $i => $container) {
            $containers[$i]['soil_type'] = 'container';
        }

        return \array_merge($gardens, $containers);
    }

    /** @param array<string,mixed> $place @return int rows written */
    private function computeFor(array $place): int
    {
        $timezone = (string) ($place['timezone'] ?? 'UTC');
        $today = $this->app->clock()->todayFor($timezone);
        $taw = (float) SoilType::taw($place['soil_type'] === null ? null : (string) $place['soil_type']);
        $mad = (float) SoilType::mad($place['soil_type'] === null ? null : (string) $place['soil_type']);

        $plantings = $this->plantings($place);
        if ($plantings === []) {
            return 0;
        }

        [$startDate, $deficit] = $this->resume((string) $place['key'], $today);
        if ($startDate > $today) {
            return 0;
        }

        $locationId = (int) $place['weather_location_id'];
        $weather = $this->weatherByDate($locationId, (string) Clock::addDays($startDate, -1), $today);
        $forecast = $this->forecastByDate($locationId, $today);
        $irrigation = $this->irrigationByDate($place, (string) Clock::addDays($startDate, -1), $today);
        $mulchedUntil = $this->mulchedUntil($place);

        $written = 0;
        $cursor = $startDate;
        $tier = self::TIER_SKIP;
        $rows = [];

        while ($cursor <= $today) {
            // The balance carried into today is yesterday's water, which is
            // the last day the archive actually observed.
            $yesterday = (string) Clock::addDays($cursor, -1);
            $day = $weather[$yesterday] ?? null;

            $kc = $this->gardenKc($plantings, $yesterday);
            $et0 = $day === null ? null : self::floatOrNull($day['et0_mm']);
            $mulched = $mulchedUntil !== null && $yesterday <= $mulchedUntil;
            $etLoss = ($et0 ?? 0.0) * $kc * ($mulched ? self::MULCH_ET_FACTOR : 1.0);

            $rainEff = $day === null ? 0.0 : self::effectiveRain(
                self::floatOrNull($day['precip_mm']),
                self::floatOrNull($day['precip_hours']),
                (string) $place['soil_type'],
            );

            $applied = $irrigation[$yesterday]['mm'] ?? 0.0;

            $deficit = \max(0.0, \min($taw, $deficit + $etLoss - $rainEff - $applied));

            $tomorrow = $forecast[$cursor] ?? null;
            $dayAfter = $forecast[(string) Clock::addDays($cursor, 1)] ?? null;

            $tier = self::tier($deficit, $mad, $tomorrow, $dayAfter);
            $reason = $this->reason($tier, $deficit, $mad, $etLoss, $rainEff, $applied, $mulched,
                $tomorrow, $irrigation[$yesterday]['basis'] ?? null, $et0 === null);

            $rows[] = $this->row($place, $cursor, $tier, $deficit, $taw, $mad, $kc,
                $et0, $rainEff, $applied, $reason);
            $written++;
            $cursor = (string) Clock::addDays($cursor, 1);
        }

        // One statement, not one per day. A normal night writes a single row
        // anyway, but a first run seeds thirty and a caught-up cron can write
        // more -- and at 0.81 ms a round trip that is latency spent on
        // nothing (hosting Section 9, Phase 3 handoff Section 1.4).
        $this->store($rows);

        $this->note(\sprintf('%s (%s): %s, deficit %.1f of %.0f mm',
            $place['name'], $place['key'], $tier ?? 'skip', $deficit, $taw));

        return $written;
    }

    /**
     * Where to start the walk, and with what deficit.
     *
     * Yesterday's row is the normal answer, and that is the whole point of
     * storing deficit_mm. A gap -- a cron that stopped, or a first run -- is
     * walked forward from a bounded window rather than from the start of the
     * season (Phase 3 handoff Section 4.2).
     *
     * @return array{0:string,1:float} the first date to compute, and D_prev
     */
    private function resume(string $placeKey, string $today): array
    {
        $row = $this->db->one(
            'SELECT `for_date`, `deficit_mm` FROM `watering_recommendation`'
            . ' WHERE `place_key` = :key ORDER BY `for_date` DESC LIMIT 1',
            ['key' => $placeKey]
        );

        $earliest = (string) Clock::addDays($today, -self::SEED_DAYS);

        if ($row === null || (string) $row['for_date'] < $earliest) {
            // Start dry-neutral: a full root zone is the only defensible
            // assumption when there is no history, and thirty days of real
            // weather corrects it long before it reaches today.
            return [$earliest, 0.0];
        }

        return [(string) Clock::addDays((string) $row['for_date'], 1), (float) $row['deficit_mm']];
    }

    /**
     * The living plantings in this place, with the curve numbers.
     *
     * @param array<string,mixed> $place
     * @return list<array{anchor:string,curve:KcCurve}>
     */
    private function plantings(array $place): array
    {
        $column = $place['kind'] === 'container' ? 'container_id' : 'garden_id';

        $rows = $this->db->all(
            'SELECT p.start_date, p.in_ground_date, pt.kc_ini, pt.kc_mid, pt.kc_end,'
            . ' pt.stage_days_ini, pt.stage_days_dev, pt.stage_days_mid, pt.stage_days_late'
            . ' FROM `planting` p JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE p.`' . $column . '` = :place_id AND p.state <> :ended AND p.quantity_live > 0',
            ['place_id' => (int) $place['place_id'], 'ended' => PlantingState::ENDED]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                // Days since it went in the ground, or since it was started
                // while it is still a seedling (handoff Section 11).
                'anchor' => (string) ($row['in_ground_date'] ?? $row['start_date']),
                'curve'  => KcCurve::fromPlantType($row),
            ];
        }
        return $out;
    }

    /**
     * The garden's Kc is the max across its living plantings (handoff
     * Section 11): the bed is watered as one, so it has to satisfy the
     * thirstiest thing in it.
     *
     * @param list<array{anchor:string,curve:KcCurve}> $plantings
     */
    private function gardenKc(array $plantings, string $onDate): float
    {
        $max = 0.0;
        foreach ($plantings as $planting) {
            $age = Clock::daysBetween($planting['anchor'], $onDate);
            if ($age === null || $age < 0) {
                continue;   // not planted yet on that date
            }
            $max = \max($max, $planting['curve']->at($age));
        }
        return $max > 0.0 ? \round($max, 2) : 0.0;
    }

    /**
     * rain_eff = min(precip, 25) x f, with f = 0.8 -- dropping to 0.5 on
     * clay when the rain fell in an hour or less, because that is runoff
     * rather than infiltration (handoff Section 11).
     */
    public static function effectiveRain(?float $precipMm, ?float $precipHours, ?string $soilType): float
    {
        if ($precipMm === null || $precipMm <= 0) {
            return 0.0;
        }
        $capped = \min($precipMm, self::RAIN_CAP_MM);
        $factor = ($soilType === 'clay' && $precipHours !== null && $precipHours <= 1.0)
            ? self::RAIN_EFFICIENCY_RUNOFF
            : self::RAIN_EFFICIENCY;

        return \round($capped * $factor, 2);
    }

    /**
     * The tier (handoff Section 11).
     *
     * @param array<string,mixed>|null $tomorrow  the forecast for the day itself
     * @param array<string,mixed>|null $dayAfter  the day after, for "within 48 h"
     */
    public static function tier(float $deficit, float $mad, ?array $tomorrow, ?array $dayAfter): string
    {
        $probability = self::floatOrNull($tomorrow['precip_prob_pct'] ?? null);
        $forecastRain = self::floatOrNull($tomorrow['precip_mm'] ?? null);
        $tmax = self::floatOrNull($tomorrow['temp_max_c'] ?? null);

        // Water: the deficit has reached the allowed depletion and the sky is
        // not going to fix it.
        if ($deficit >= $mad) {
            $rainUnlikely = $probability === null || $probability < 50.0;
            $rainTooLittle = $forecastRain === null || $forecastRain < $deficit;
            if ($rainUnlikely || $rainTooLittle) {
                return self::TIER_WATER;
            }
        }

        // Water: heat brings the deficit forward. A 35 C day takes a plant
        // past wilting well before the checkbook says it is empty.
        if ($tmax !== null && $tmax >= self::HEAT_C && $deficit >= 0.4 * $mad) {
            return self::TIER_WATER;
        }

        // Likely: it is getting dry, but meaningful rain is probable inside
        // 48 hours, so it is worth waiting a day before dragging a hose out.
        if ($deficit >= 0.4 * $mad && self::meaningfulRainSoon($tomorrow, $dayAfter)) {
            return self::TIER_LIKELY;
        }

        return self::TIER_SKIP;
    }

    /**
     * @param array<string,mixed>|null $tomorrow
     * @param array<string,mixed>|null $dayAfter
     */
    private static function meaningfulRainSoon(?array $tomorrow, ?array $dayAfter): bool
    {
        foreach ([$tomorrow, $dayAfter] as $day) {
            if ($day === null) {
                continue;
            }
            $probability = self::floatOrNull($day['precip_prob_pct'] ?? null);
            $amount = self::floatOrNull($day['precip_mm'] ?? null);
            // "Meaningful": more likely than not, and enough to matter. A
            // 30% chance of a millimetre is not a reason to skip watering.
            if ($probability !== null && $probability >= 50.0 && ($amount ?? 0.0) >= 5.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * One sentence with the numbers in it (handoff Section 11), including
     * whatever the model had to assume about how much water a method
     * applies, so the user can correct it.
     *
     * @param array<string,mixed>|null $tomorrow
     */
    private function reason(
        string $tier,
        float $deficit,
        float $mad,
        float $etLoss,
        float $rainEff,
        float $applied,
        bool $mulched,
        ?array $tomorrow,
        ?string $irrigationBasis,
        bool $weatherMissing,
    ): string {
        $parts = [\sprintf('Deficit %.0f mm of an allowed %.0f', $deficit, $mad)];

        if ($rainEff > 0) {
            $parts[] = \sprintf('%.0f mm of rain soaked in', $rainEff);
        }
        if ($applied > 0) {
            $parts[] = \sprintf('you watered about %.0f mm', $applied);
        }
        if ($etLoss > 0) {
            $parts[] = \sprintf('%.1f mm lost to the crop%s', $etLoss, $mulched ? ' through mulch' : '');
        }

        $sentence = \implode('; ', $parts) . '.';

        $probability = self::floatOrNull($tomorrow['precip_prob_pct'] ?? null);
        $amount = self::floatOrNull($tomorrow['precip_mm'] ?? null);
        $tmax = self::floatOrNull($tomorrow['temp_max_c'] ?? null);

        if ($tmax !== null && $tmax >= self::HEAT_C) {
            $sentence .= \sprintf(' %s forecast today, which pulls water through faster.',
                $this->app->units()->temperature($tmax));
        } elseif ($probability !== null) {
            $sentence .= \sprintf(' %.0f%% chance of rain today%s.',
                $probability,
                ($amount !== null && $amount > 0) ? \sprintf(', around %s', $this->app->units()->rain($amount)) : '');
        }

        $sentence .= match ($tier) {
            self::TIER_WATER  => ' Water today.',
            self::TIER_LIKELY => ' Probably water, unless the forecast rain arrives.',
            default           => ' No need to water today.',
        };

        if ($irrigationBasis !== null) {
            $sentence .= ' (' . $irrigationBasis . ' -- correct the flow rate under Lists if that is wrong.)';
        }
        if ($weatherMissing) {
            $sentence .= ' Yesterday\'s weather has not arrived yet, so this carries forward unchanged.';
        }

        return \substr($sentence, 0, 500);
    }

    // -- Reads -------------------------------------------------------------

    /** @return array<string,array<string,mixed>> keyed by obs_date */
    private function weatherByDate(int $locationId, string $from, string $to): array
    {
        $rows = $this->db->all(
            'SELECT `obs_date`, `et0_mm`, `precip_mm`, `precip_hours` FROM `weather_daily`'
            . ' WHERE `location_id` = :id AND `obs_date` BETWEEN :from AND :to',
            ['id' => $locationId, 'from' => $from, 'to' => $to]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['obs_date']] = $row;
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> keyed by forecast_date */
    private function forecastByDate(int $locationId, string $from): array
    {
        $rows = $this->db->all(
            'SELECT `forecast_date`, `temp_max_c`, `precip_mm`, `precip_prob_pct`'
            . ' FROM `weather_forecast` WHERE `location_id` = :id AND `forecast_date` >= :from',
            ['id' => $locationId, 'from' => $from]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['forecast_date']] = $row;
        }
        return $out;
    }

    /**
     * Irrigation depth per day for this place.
     *
     * The double-counting trap (Phase 3 handoff Section 4.2): watering a
     * garden zone writes one `garden_event` AND a derived `plant_event` for
     * every living plant in the zone's rows, each carrying
     * source_garden_event_id. Adding both would multiply one watering by the
     * number of plants in the bed.
     *
     * So the garden events are read directly -- each is one application --
     * and only plant events with no source_garden_event_id are considered
     * alongside them. Of those, the deepest on a day counts once: watering
     * six plants in a bed by hand is one irrigation of the bed, not six.
     *
     * @param array<string,mixed> $place
     * @return array<string,array{mm:float,basis:?string}> keyed by event_date
     */
    private function irrigationByDate(array $place, string $from, string $to): array
    {
        $out = [];

        if ($place['kind'] === 'garden') {
            // A zone watering usually names the zone rather than a method,
            // because the zone already knows its own -- and the zone's method
            // is where a drip line's flow rate lives (handoff Section 11). So
            // the event's method wins if it has one, and the zone's is the
            // fallback rather than the generic assumption.
            $gardenEvents = $this->db->all(
                'SELECT ge.event_date, ge.duration_min,'
                . ' COALESCE(l.name, zl.name) AS method_name,'
                . ' COALESCE(l.attr_1, zl.attr_1) AS flow_rate'
                . ' FROM `garden_event` ge'
                . ' LEFT JOIN `user_list_item` l ON l.id = ge.ref_list_item_id'
                . ' LEFT JOIN `water_zone` z ON z.id = ge.water_zone_id'
                . ' LEFT JOIN `user_list_item` zl ON zl.id = z.water_method_id'
                . ' WHERE ge.garden_id = :place_id AND ge.event_type = :watered'
                . '   AND ge.event_date BETWEEN :from AND :to',
                ['place_id' => (int) $place['place_id'], 'watered' => EventType::WATERED,
                 'from' => $from, 'to' => $to]
            );

            foreach ($gardenEvents as $event) {
                $depth = WaterMethod::depth(
                    (int) ($event['duration_min'] ?? 0),
                    $event['method_name'] === null ? null : (string) $event['method_name'],
                    $event['flow_rate'] === null ? null : (string) $event['flow_rate'],
                );
                $date = (string) $event['event_date'];
                $out[$date]['mm'] = ($out[$date]['mm'] ?? 0.0) + $depth['mm'];
                $out[$date]['basis'] ??= $depth['basis'];
            }
        }

        $column = $place['kind'] === 'container' ? 'container_id' : 'garden_id';
        $plantEvents = $this->db->all(
            'SELECT e.event_date, e.duration_min,'
            . ' COALESCE(l.name, dl.name) AS method_name,'
            . ' COALESCE(l.attr_1, dl.attr_1) AS flow_rate'
            . ' FROM `plant_event` e'
            . ' JOIN `planting` p ON p.id = e.planting_id'
            . ' LEFT JOIN `user_list_item` l ON l.id = e.ref_list_item_id'
            // The planting's default water method, for a watering logged
            // without naming one.
            . ' LEFT JOIN `user_list_item` dl ON dl.id = p.default_water_method_id'
            . ' WHERE p.`' . $column . '` = :place_id AND e.event_type = :watered'
            . '   AND e.source_garden_event_id IS NULL'
            . '   AND e.event_date BETWEEN :from AND :to',
            ['place_id' => (int) $place['place_id'], 'watered' => EventType::WATERED,
             'from' => $from, 'to' => $to]
        );

        $byDate = [];
        foreach ($plantEvents as $event) {
            $depth = WaterMethod::depth(
                (int) ($event['duration_min'] ?? 0),
                $event['method_name'] === null ? null : (string) $event['method_name'],
                $event['flow_rate'] === null ? null : (string) $event['flow_rate'],
            );
            $date = (string) $event['event_date'];
            if (!isset($byDate[$date]) || $depth['mm'] > $byDate[$date]['mm']) {
                $byDate[$date] = $depth;
            }
        }

        foreach ($byDate as $date => $depth) {
            $out[$date]['mm'] = ($out[$date]['mm'] ?? 0.0) + $depth['mm'];
            $out[$date]['basis'] ??= $depth['basis'];
        }

        foreach ($out as $date => $entry) {
            $out[$date] = ['mm' => \round($entry['mm'], 2), 'basis' => $entry['basis'] ?? null];
        }

        return $out;
    }

    /**
     * The last date a mulch still counts, or null. Any `mulched` event in the
     * last 60 days scales ET by 0.85 (handoff Section 11).
     *
     * @param array<string,mixed> $place
     */
    private function mulchedUntil(array $place): ?string
    {
        $latest = null;

        if ($place['kind'] === 'garden') {
            $latest = $this->db->value(
                'SELECT MAX(`event_date`) FROM `garden_event`'
                . ' WHERE `garden_id` = :place_id AND `event_type` = :mulched',
                ['place_id' => (int) $place['place_id'], 'mulched' => EventType::MULCHED]
            );
        }

        $column = $place['kind'] === 'container' ? 'container_id' : 'garden_id';
        $plantLatest = $this->db->value(
            'SELECT MAX(e.event_date) FROM `plant_event` e'
            . ' JOIN `planting` p ON p.id = e.planting_id'
            . ' WHERE p.`' . $column . '` = :place_id AND e.event_type = :mulched',
            ['place_id' => (int) $place['place_id'], 'mulched' => EventType::MULCHED]
        );

        foreach ([$latest, $plantLatest] as $candidate) {
            if (\is_string($candidate) && ($latest === null || !\is_string($latest) || $candidate > $latest)) {
                $latest = $candidate;
            }
        }

        return \is_string($latest) ? Clock::addDays($latest, self::MULCH_LOOKBACK_DAYS) : null;
    }

    // -- Writes ------------------------------------------------------------

    /** The columns of a watering_recommendation row, in order. */
    private const COLUMNS = [
        'user_id', 'garden_id', 'container_id', 'place_key', 'for_date', 'tier',
        'deficit_mm', 'taw_mm', 'mad_mm', 'kc', 'et0_mm', 'rain_eff_mm',
        'irrigation_mm', 'reason_text', 'computed_at',
    ];

    /**
     * @param array<string,mixed> $place
     * @return list<mixed> positional, matching self::COLUMNS
     */
    private function row(
        array $place,
        string $forDate,
        string $tier,
        float $deficit,
        float $taw,
        float $mad,
        float $kc,
        ?float $et0,
        float $rainEff,
        float $irrigation,
        string $reason,
    ): array {
        return [
            (int) $place['user_id'],
            $place['kind'] === 'garden' ? (int) $place['place_id'] : null,
            $place['kind'] === 'container' ? (int) $place['place_id'] : null,
            (string) $place['key'],
            $forDate,
            $tier,
            \round($deficit, 2),
            (int) \round($taw),
            (int) \round($mad),
            \round($kc, 2),
            $et0,
            \round($rainEff, 2),
            \round($irrigation, 2),
            $reason,
            $this->app->clock()->utcStamp(),
        ];
    }

    /** @param list<list<mixed>> $rows */
    private function store(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        foreach (\array_chunk($rows, $this->app->config()->int('weather.upsert_chunk_rows', 200)) as $chunk) {
            $this->db->upsertChunk(
                'watering_recommendation',
                self::COLUMNS,
                $chunk,
                // Everything but the identity: a re-run for a day already
                // computed replaces the answer rather than adding a row.
                \array_slice(self::COLUMNS, 5)
            );
        }
    }

    private function recordRun(string $startedAt, int $places, int $rows, int $failures): void
    {
        $this->db->run(
            'INSERT INTO `weather_sync_run`'
            . " (location_id, kind, started_at, finished_at, rows_upserted, outcome, error_text)"
            . " VALUES (NULL, 'recommend', :started_at, UTC_TIMESTAMP(), :rows, :outcome, :error)",
            [
                'started_at' => $startedAt,
                'rows'       => $rows,
                'outcome'    => $failures === 0 ? 'ok' : ($rows > 0 ? 'partial' : 'failed'),
                'error'      => $failures === 0
                    ? null
                    : \substr($failures . ' of ' . $places . ' places failed: '
                        . \implode(' | ', $this->log), 0, 500),
            ]
        );
    }

    private function note(string $message): void
    {
        $this->log[] = $message;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
