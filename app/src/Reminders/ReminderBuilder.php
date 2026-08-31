<?php

declare(strict_types=1);

namespace Carl\Reminders;

use Carl\Core\Database;
use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Domain\ReminderKind;
use Carl\Support\Clock;
use Carl\Weather\AlertPoller;

/**
 * The eleven reminder kinds of handoff Section 12, computed for a batch of
 * users at once.
 *
 * **Set-based, not per user.** This is the first job that loops over users,
 * and hosting Section 9's arithmetic is real: the database is on separate
 * hardware and every statement costs a 0.81 ms round trip, so a hundred users
 * at eight statements each is most of a second spent on nothing but latency
 * (Phase 3 handoff Sections 1.4 and 4.4). Seven statements fetch everything
 * for the whole batch; the rules themselves are then arithmetic in PHP, which
 * costs nothing.
 *
 * **Silence is the default.** A kind that has nothing to say says nothing. An
 * empty digest trains people to ignore a full one.
 */
final class ReminderBuilder
{
    /** How long after a mulch, a sow or a note before a nudge (Section 12). */
    private const INACTIVITY_DAYS = 7;

    /** Forecast Tmax at or above this is a heat day (handoff Section 11). */
    private const HEAT_C = 35.0;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param list<array<string,mixed>> $users rows of `user`, already filtered
     *        to the ones due a digest
     * @param array<int,string> $todayByUser each user's own local calendar day
     * @return array<int,list<array{planting_id:?int,kind:string,due_date:string,
     *                              title:string,body:string}>> keyed by user_id
     */
    public function build(array $users, array $todayByUser): array
    {
        $out = [];
        foreach ($users as $user) {
            $out[(int) $user['id']] = [];
        }
        if ($users === []) {
            return $out;
        }

        $data = $this->gather($users, $todayByUser);

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $today = $todayByUser[$userId] ?? \gmdate('Y-m-d');
            $hasRegion = $user['region_id'] !== null && ($user['research_status'] ?? '') === 'researched';

            $found = [];
            foreach ([
                $this->hardeningCountdown($userId, $today, $data),
                $this->transplantWindow($userId, $today, $user, $data),
                $this->startSeedsBy($userId, $today, $user, $data),
                $this->firstHarvestExpected($userId, $today, $data),
                $this->harvestWindowClosing($userId, $today, $data),
                $this->frostWatch($userId, $today, $user, $data),
                $this->heatWatch($userId, $today, $user, $data),
                $this->pestScouting($userId, $today, $user, $data),
                $this->watering($userId, $today, $data),
                $this->inactivity($userId, $today, $data),
                $this->researchDiff($userId, $today, $user, $data),
            ] as $group) {
                foreach ($group as $reminder) {
                    // Section 9.4: a kind that needs a researched region is
                    // suppressed for a user who has none -- with the one-line
                    // explanation, not silently.
                    if (!$hasRegion && ReminderKind::needsRegion($reminder['kind'])) {
                        continue;
                    }
                    $found[] = $reminder;
                }
            }

            $out[$userId] = $found;
        }

        return $out;
    }

    /**
     * Everything every rule needs, in seven statements for the whole batch.
     *
     * The region ids and weather location ids are read off the user rows the
     * caller already has rather than asked for again: two more statements
     * would be two more round trips for values that are already in memory
     * (hosting Section 9).
     *
     * @param list<array<string,mixed>> $users
     * @param array<int,string> $todayByUser
     * @return array<string,mixed>
     */
    private function gather(array $users, array $todayByUser): array
    {
        $userIds = [];
        $regionIds = [];
        $locationIds = [];
        foreach ($users as $user) {
            $userIds[] = (int) $user['id'];
            if ($user['region_id'] !== null) {
                $regionIds[(int) $user['region_id']] = true;
            }
            if ($user['weather_location_id'] !== null) {
                $locationIds[(int) $user['weather_location_id']] = true;
            }
        }

        $params = [];
        $in = self::inClause($userIds, 'u', $params);

        // 1. Every living planting, with the research values each rule reads
        //    and whether it has ever yielded.
        $plantings = $this->db->all(
            'SELECT p.id, p.user_id, p.plant_type_id, p.label, p.start_date, p.in_ground_date,'
            . ' p.state, p.quantity_live, p.hardening_started_at, p.hardening_days,'
            . ' pt.category, pt.type, pt.dtm_days_min, pt.dtm_days_max, pt.dtm_counted_from,'
            . ' pt.heat_tolerant, pt.weeks_before_transplant_to_start,'
            . " EXISTS (SELECT 1 FROM `plant_event` y WHERE y.planting_id = p.id"
            . "         AND y.event_type = '" . EventType::YIELDED . "') AS has_yielded"
            . ' FROM `planting` p JOIN `plant_type` pt ON pt.id = p.plant_type_id'
            . ' WHERE p.user_id ' . $in . ' AND p.state <> :ended AND p.quantity_live > 0',
            $params + ['ended' => PlantingState::ENDED]
        );

        $plantingsByUser = [];
        foreach ($plantings as $row) {
            $plantingsByUser[(int) $row['user_id']][] = $row;
        }

        // 2. The region rows, and 3. the region windows, for the regions in
        //    this batch.
        $regionParams = [];
        $regionIn = self::inClause(\array_keys($regionIds), 'r', $regionParams);

        $regions = $this->db->all(
            'SELECT * FROM `region` WHERE `id` ' . $regionIn, $regionParams
        );
        $regionById = [];
        foreach ($regions as $region) {
            $regionById[(int) $region['id']] = $region;
        }

        $windows = $this->db->all(
            'SELECT pr.*, pt.category, pt.type, pt.weeks_before_transplant_to_start'
            . ' FROM `plant_region` pr JOIN `plant_type` pt ON pt.id = pr.plant_type_id'
            . ' WHERE pr.region_id ' . $regionIn,
            $regionParams
        );
        $windowsByRegion = [];
        $windowsByRegionType = [];
        foreach ($windows as $window) {
            $windowsByRegion[(int) $window['region_id']][] = $window;
            $windowsByRegionType[(int) $window['region_id']][(int) $window['plant_type_id']][] = $window;
        }

        // 4. Pest windows for the same regions.
        $pests = $this->db->all(
            'SELECT pr.*, pest.name, pest.signs FROM `pest_region` pr'
            . ' JOIN `pest` pest ON pest.id = pr.pest_id'
            . ' WHERE pr.region_id ' . $regionIn,
            $regionParams
        );
        $pestsByRegion = [];
        foreach ($pests as $pest) {
            $pestsByRegion[(int) $pest['region_id']][] = $pest;
        }

        // 5. Active NWS alerts, and 6. tomorrow's forecast, for the weather
        //    locations in this batch.
        $locationParams = [];
        $locationIn = self::inClause(\array_keys($locationIds), 'l', $locationParams);

        $alerts = $this->db->all(
            'SELECT * FROM `weather_alert` WHERE `location_id` ' . $locationIn
            . ' AND `is_active` = 1 AND (`expires` IS NULL OR `expires` > UTC_TIMESTAMP())',
            $locationParams
        );
        $alertsByLocation = [];
        foreach ($alerts as $alert) {
            $alertsByLocation[(int) $alert['location_id']][] = $alert;
        }

        $forecast = $this->db->all(
            'SELECT `location_id`, `forecast_date`, `temp_max_c`, `precip_prob_pct`, `precip_mm`'
            . ' FROM `weather_forecast` WHERE `location_id` ' . $locationIn,
            $locationParams
        );
        $forecastByLocation = [];
        foreach ($forecast as $day) {
            $forecastByLocation[(int) $day['location_id']][(string) $day['forecast_date']] = $day;
        }

        // 7. Today's watering recommendation, which the model already stored.
        $wateringParams = $params;
        $watering = $this->db->all(
            'SELECT r.*, COALESCE(g.name, c.name) AS place_name FROM `watering_recommendation` r'
            . ' LEFT JOIN `garden` g ON g.id = r.garden_id'
            . ' LEFT JOIN `container` c ON c.id = r.container_id'
            . ' WHERE r.user_id ' . $in . " AND r.tier <> 'skip'",
            $wateringParams
        );
        $wateringByUser = [];
        foreach ($watering as $row) {
            $wateringByUser[(int) $row['user_id']][] = $row;
        }

        // 8. Last activity per user, for the inactivity nudge.
        $activityParams = $params;
        $activity = $this->db->all(
            'SELECT `user_id`, MAX(`event_date`) AS last_date FROM `plant_event`'
            . ' WHERE `user_id` ' . $in . ' GROUP BY `user_id`',
            $activityParams
        );
        $lastActivity = [];
        foreach ($activity as $row) {
            $lastActivity[(int) $row['user_id']] = (string) $row['last_date'];
        }

        // 9. The reminders already written for the two kinds that are
        //    "once", so they are not written again every morning.
        $existingParams = $params;
        $existing = $this->db->all(
            'SELECT `user_id`, `subject_key`, `kind`, MAX(`created_at`) AS last_created'
            . ' FROM `reminder` WHERE `user_id` ' . $in . ' AND `kind` IN (:diff, :idle)'
            . ' GROUP BY `user_id`, `subject_key`, `kind`',
            $existingParams + ['diff' => ReminderKind::RESEARCH_DIFF, 'idle' => ReminderKind::INACTIVITY]
        );
        $alreadySaid = [];
        foreach ($existing as $row) {
            $alreadySaid[(int) $row['user_id']][(string) $row['kind']][(string) $row['subject_key']]
                = (string) $row['last_created'];
        }

        return [
            'plantings'    => $plantingsByUser,
            'regions'      => $regionById,
            'windows'      => $windowsByRegion,
            'windowsByType' => $windowsByRegionType,
            'pests'        => $pestsByRegion,
            'alerts'       => $alertsByLocation,
            'forecast'     => $forecastByLocation,
            'watering'     => $wateringByUser,
            'lastActivity' => $lastActivity,
            'alreadySaid'  => $alreadySaid,
            'todayByUser'  => $todayByUser,
        ];
    }

    // -- The eleven kinds ---------------------------------------------------

    /**
     * hardening_countdown: started + duration - today, daily while > 0, and
     * "transplant due" at 0 (handoff Section 12).
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function hardeningCountdown(int $userId, string $today, array $data): array
    {
        $out = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            if ($planting['hardening_started_at'] === null || $planting['hardening_days'] === null) {
                continue;
            }
            $due = Clock::addDays((string) $planting['hardening_started_at'],
                (int) $planting['hardening_days']);
            if ($due === null) {
                continue;
            }
            $left = Clock::daysBetween($today, $due);
            if ($left === null || $left < 0) {
                continue;
            }

            $name = self::name($planting);
            $out[] = self::make($planting, ReminderKind::HARDENING_COUNTDOWN, $today,
                $left === 0
                    ? $name . ' is ready to transplant'
                    : $name . ': transplant in ' . $left . ' day' . ($left === 1 ? '' : 's'),
                $left === 0
                    ? 'Hardening finished today. Move it out when the weather allows.'
                    : 'Hardening started ' . $planting['hardening_started_at']
                      . ' for ' . $planting['hardening_days'] . ' days; due ' . $due . '.'
            );
        }
        return $out;
    }

    /**
     * transplant_window: the user has seedlings of a type whose region window
     * opens in 7 days, opens today, or closes in 7 days.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function transplantWindow(int $userId, string $today, array $user, array $data): array
    {
        $regionId = (int) ($user['region_id'] ?? 0);
        if ($regionId === 0) {
            return [];
        }

        $out = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            if (!\in_array((string) $planting['state'],
                [PlantingState::SEED_STARTED, PlantingState::HARDENING], true)) {
                continue;
            }

            foreach ($data['windowsByType'][$regionId][(int) $planting['plant_type_id']] ?? [] as $window) {
                if ((string) ($window['method'] ?? '') === 'seed') {
                    continue;   // a sowing window says nothing about moving a seedling out
                }

                $opens = self::daysUntilMonthDay($today, $window['window_start'] ?? null);
                $closes = self::daysUntilMonthDay($today, $window['window_end'] ?? null);
                $name = self::name($planting);

                if ($opens === 7 || $opens === 0) {
                    $out[] = self::make($planting, ReminderKind::TRANSPLANT_WINDOW, $today,
                        $opens === 0
                            ? 'Transplant window opens today for ' . $name
                            : 'Transplant window opens in a week for ' . $name,
                        'Your area\'s ' . $window['season'] . ' window for '
                        . $window['type'] . ' runs ' . $window['window_start']
                        . ' to ' . $window['window_end'] . '.'
                    );
                } elseif ($closes === 7) {
                    $out[] = self::make($planting, ReminderKind::TRANSPLANT_WINDOW, $today,
                        'Transplant window closes in a week for ' . $name,
                        'Your area\'s ' . $window['season'] . ' window for '
                        . $window['type'] . ' ends ' . $window['window_end'] . '.'
                    );
                }
            }
        }
        return $out;
    }

    /**
     * start_seeds_by: the region's window_start minus the type's
     * weeks_before_transplant_to_start, at 14 and 7 days out.
     *
     * Scoped to the types the region actually recommends. Every type in the
     * catalog would be a wall of text nobody reads, and "recommended" is the
     * research's own answer to "what is worth growing here".
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function startSeedsBy(int $userId, string $today, array $user, array $data): array
    {
        $regionId = (int) ($user['region_id'] ?? 0);
        if ($regionId === 0) {
            return [];
        }

        $out = [];
        foreach ($data['windows'][$regionId] ?? [] as $window) {
            if ((int) ($window['recommended'] ?? 0) !== 1) {
                continue;
            }
            $weeks = (int) ($window['weeks_before_transplant_to_start'] ?? 0);
            if ($weeks <= 0 || !\is_string($window['window_start'] ?? null)) {
                continue;
            }

            $windowStart = self::nextOccurrence($today, (string) $window['window_start']);
            if ($windowStart === null) {
                continue;
            }
            $sowBy = Clock::addDays($windowStart, -$weeks * 7);
            if ($sowBy === null) {
                continue;
            }

            $days = Clock::daysBetween($today, $sowBy);
            if ($days !== 14 && $days !== 7) {
                continue;
            }

            $out[] = [
                'planting_id' => null,
                'subject_key' => 'pt:' . (int) $window['plant_type_id'],
                'kind'        => ReminderKind::START_SEEDS_BY,
                'due_date'    => $today,
                'title'       => 'Start ' . $window['type'] . ' seeds within ' . $days . ' days',
                'body'        => 'To transplant in your area\'s window from ' . $window['window_start']
                    . ', ' . $window['type'] . ' wants ' . $weeks . ' weeks indoors first, which means '
                    . 'sowing by ' . $sowBy . '.',
            ];
        }
        return $out;
    }

    /**
     * first_harvest_expected: anchor + dtm_days_min, 7 days out and on the day.
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function firstHarvestExpected(int $userId, string $today, array $data): array
    {
        $out = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            $anchor = self::dtmAnchor($planting);
            $min = $planting['dtm_days_min'];
            if ($anchor === null || $min === null) {
                continue;
            }
            $date = Clock::addDays($anchor, (int) $min);
            if ($date === null) {
                continue;
            }
            $days = Clock::daysBetween($today, $date);
            if ($days !== 7 && $days !== 0) {
                continue;
            }

            $name = self::name($planting);
            $out[] = self::make($planting, ReminderKind::FIRST_HARVEST_EXPECTED, $today,
                $days === 0
                    ? $name . ' should be ready about now'
                    : $name . ' should be ready in a week',
                'Counted ' . $min . ' days from ' . $anchor . '. Days to maturity is a guide, not a '
                . 'promise -- go and look.'
            );
        }
        return $out;
    }

    /**
     * harvest_window_closing: anchor + dtm_days_max + 14, if nothing has been
     * harvested from it yet.
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function harvestWindowClosing(int $userId, string $today, array $data): array
    {
        $out = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            if ((int) ($planting['has_yielded'] ?? 0) === 1) {
                continue;
            }
            $anchor = self::dtmAnchor($planting);
            $max = $planting['dtm_days_max'];
            if ($anchor === null || $max === null) {
                continue;
            }
            $date = Clock::addDays($anchor, (int) $max + 14);
            if ($date === null || $date !== $today) {
                continue;
            }

            $name = self::name($planting);
            $out[] = self::make($planting, ReminderKind::HARVEST_WINDOW_CLOSING, $today,
                'Nothing harvested yet from ' . $name,
                'It passed its expected window two weeks ago (' . $max . ' days from ' . $anchor
                . '). Worth a look, and worth logging a yield or a cull either way.'
            );
        }
        return $out;
    }

    /**
     * frost_watch: the region's first_frost_early minus 14 days, and any
     * active NWS freeze or frost alert.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function frostWatch(int $userId, string $today, array $user, array $data): array
    {
        $out = [];

        $region = $data['regions'][(int) ($user['region_id'] ?? 0)] ?? null;
        if ($region !== null && \is_string($region['first_frost_early'] ?? null)) {
            $firstFrost = self::nextOccurrence($today, (string) $region['first_frost_early']);
            if ($firstFrost !== null && Clock::daysBetween($today, $firstFrost) === 14) {
                $out[] = [
                    'planting_id' => null,
                    'subject_key' => '-',
                    'kind'        => ReminderKind::FROST_WATCH,
                    'due_date'    => $today,
                    'title'       => 'First frost is about two weeks away',
                    'body'        => 'The earliest first frost recorded for your area is '
                        . $region['first_frost_early'] . ' (' . $firstFrost . '). Time to plan covers, '
                        . 'and to decide what comes in.',
                ];
            }
        }

        $locationId = (int) ($user['weather_location_id'] ?? 0);
        foreach ($data['alerts'][$locationId] ?? [] as $alert) {
            $event = (string) $alert['event'];
            if (!AlertPoller::isUrgentToAGarden($event) || \stripos($event, 'heat') !== false) {
                continue;
            }
            $out[] = [
                'planting_id' => null,
                'subject_key' => 'a:' . (int) $alert['id'],
                'kind'        => ReminderKind::FROST_WATCH,
                'due_date'    => $today,
                'title'       => $event . ' is in force for your area',
                'body'        => (string) ($alert['headline'] ?? $event)
                    . ($alert['expires'] !== null ? ' Until ' . $alert['expires'] . ' UTC.' : ''),
            ];
        }

        return $out;
    }

    /**
     * heat_watch: forecast Tmax at or above 35 C tomorrow, and the user grows
     * something that is not marked heat tolerant.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function heatWatch(int $userId, string $today, array $user, array $data): array
    {
        $locationId = (int) ($user['weather_location_id'] ?? 0);
        $tomorrow = (string) Clock::addDays($today, 1);
        $day = $data['forecast'][$locationId][$tomorrow] ?? null;
        $tmax = $day === null || $day['temp_max_c'] === null ? null : (float) $day['temp_max_c'];

        $sensitive = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            if ((int) ($planting['heat_tolerant'] ?? 0) === 0) {
                $sensitive[(string) $planting['type']] = true;
            }
        }

        if ($tmax === null || $tmax < self::HEAT_C || $sensitive === []) {
            return [];
        }

        $names = \array_keys($sensitive);
        return [[
            'planting_id' => null,
            'subject_key' => '-',
            'kind'        => ReminderKind::HEAT_WATCH,
            'due_date'    => $today,
            'title'       => 'Heat tomorrow: ' . \round($tmax) . ' C forecast',
            'body'        => 'Shade cloth and a deep watering before the heat, not during it. '
                . 'Not marked heat tolerant in your garden: ' . \implode(', ', \array_slice($names, 0, 8))
                . (\count($names) > 8 ? ' and others' : '') . '.',
        ]];
    }

    /**
     * pest_scouting: a pest whose regional active window opens today, for a
     * category the user actually grows (calendar for now; GDD is v2).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function pestScouting(int $userId, string $today, array $user, array $data): array
    {
        $regionId = (int) ($user['region_id'] ?? 0);
        if ($regionId === 0) {
            return [];
        }

        $grown = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            $grown[\strtolower((string) $planting['category'])] = true;
        }
        if ($grown === []) {
            return [];
        }

        $out = [];
        foreach ($data['pests'][$regionId] ?? [] as $pest) {
            if (!\is_string($pest['active_start'] ?? null)
                || self::daysUntilMonthDay($today, (string) $pest['active_start']) !== 0) {
                continue;
            }

            // Semicolon, not comma: the research template's multi-valued
            // cells are semicolon-separated so a category can contain a
            // comma (research-template/README.md), and ReferenceRepository
            // already splits them that way. Splitting on a comma here would
            // match nothing and the pest reminder would never fire.
            $affects = \array_filter(\array_map(
                static fn (string $c): string => \strtolower(\trim($c)),
                \explode(';', (string) ($pest['affects_categories'] ?? ''))
            ));

            if ($affects !== [] && \array_intersect($affects, \array_keys($grown)) === []) {
                continue;
            }

            $out[] = [
                'planting_id' => null,
                'subject_key' => 'pest:' . (int) $pest['pest_id'],
                'kind'        => ReminderKind::PEST_SCOUTING,
                'due_date'    => $today,
                'title'       => 'Start watching for ' . $pest['name'],
                'body'        => ((string) ($pest['signs'] ?? '') !== ''
                        ? 'What to look for: ' . $pest['signs'] . ' '
                        : '')
                    . 'Active in your area from ' . $pest['active_start']
                    . ($pest['active_end'] !== null ? ' to ' . $pest['active_end'] : '') . '.',
            ];
        }
        return $out;
    }

    /**
     * watering: today's stored tier is water or likely. The model computed it
     * overnight; nothing is recomputed here (handoff Section 11).
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function watering(int $userId, string $today, array $data): array
    {
        $out = [];
        foreach ($data['watering'][$userId] ?? [] as $row) {
            if ((string) $row['for_date'] !== $today) {
                continue;
            }
            $out[] = [
                'planting_id' => null,
                'subject_key' => (string) $row['place_key'],
                'kind'        => ReminderKind::WATERING,
                'due_date'    => $today,
                'title'       => (string) $row['tier'] === 'water'
                    ? 'Water ' . $row['place_name'] . ' today'
                    : $row['place_name'] . ' will probably want water',
                'body'        => (string) $row['reason_text'],
            ];
        }
        return $out;
    }

    /**
     * inactivity: nothing logged in seven days. One nudge, then silent until
     * activity resumes (handoff Section 12).
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function inactivity(int $userId, string $today, array $data): array
    {
        $last = $data['lastActivity'][$userId] ?? null;
        if ($last === null) {
            return [];   // a brand new account is not inactive, it is new
        }

        $idle = Clock::daysBetween($last, $today);
        if ($idle === null || $idle < self::INACTIVITY_DAYS) {
            return [];
        }

        // One nudge: if the last one was sent after the last activity, the
        // person has already been asked and has not logged anything since.
        // Saying it again every morning is how a channel gets muted.
        $saidAt = $data['alreadySaid'][$userId][ReminderKind::INACTIVITY]['-'] ?? null;
        if ($saidAt !== null && \substr($saidAt, 0, 10) > $last) {
            return [];
        }

        return [[
            'planting_id' => null,
            'subject_key' => '-',
            'kind'        => ReminderKind::INACTIVITY,
            'due_date'    => $today,
            'title'       => 'Nothing logged for ' . $idle . ' days',
            'body'        => 'Your last entry was ' . $last . '. A watering or a note is enough to '
                . 'keep the record honest -- and this is the only nudge you will get until you do.',
        ]];
    }

    /**
     * research_diff: a planting's own dates fall outside the window the
     * research gives for its type and region. Said once per planting.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function researchDiff(int $userId, string $today, array $user, array $data): array
    {
        $regionId = (int) ($user['region_id'] ?? 0);
        if ($regionId === 0) {
            return [];
        }

        $out = [];
        foreach ($data['plantings'][$userId] ?? [] as $planting) {
            $subject = 'p:' . (int) $planting['id'];
            if (isset($data['alreadySaid'][$userId][ReminderKind::RESEARCH_DIFF][$subject])) {
                continue;
            }

            $windows = $data['windowsByType'][$regionId][(int) $planting['plant_type_id']] ?? [];
            if ($windows === []) {
                continue;
            }

            $planted = (string) ($planting['in_ground_date'] ?? $planting['start_date']);
            $inAny = false;
            $described = [];
            foreach ($windows as $window) {
                $described[] = $window['season'] . ' ' . $window['window_start']
                    . ' to ' . $window['window_end'];
                if (Clock::inRecurringWindow($planted, $window['window_start'], $window['window_end'])) {
                    $inAny = true;
                    break;
                }
            }
            if ($inAny) {
                continue;
            }

            $out[] = self::make($planting, ReminderKind::RESEARCH_DIFF, $today,
                self::name($planting) . ' went in outside your area\'s window',
                'Planted ' . $planted . '; the research for your area gives '
                . \implode('; ', \array_slice($described, 0, 3))
                . '. Nothing is wrong -- gardeners beat the calendar all the time -- but the '
                . 'countdowns will be less reliable. Said once.'
            );
        }
        return $out;
    }

    // -- Helpers ------------------------------------------------------------

    /** @param array<string,mixed> $planting */
    private static function name(array $planting): string
    {
        $label = (string) ($planting['label'] ?? '');
        return $label !== '' ? $label : (string) $planting['type'];
    }

    /**
     * @param array<string,mixed> $planting
     * @return array<string,mixed>
     */
    private static function make(
        array $planting,
        string $kind,
        string $dueDate,
        string $title,
        string $body,
    ): array {
        return [
            'planting_id' => (int) $planting['id'],
            'subject_key' => 'p:' . (int) $planting['id'],
            'kind'        => $kind,
            'due_date'    => $dueDate,
            'title'       => $title,
            'body'        => $body,
        ];
    }

    /**
     * The date a DTM countdown is measured from: transplant or seed,
     * whichever the type says (handoff Section 9.1).
     *
     * @param array<string,mixed> $planting
     */
    private static function dtmAnchor(array $planting): ?string
    {
        $anchor = (string) $planting['dtm_counted_from'] === 'transplant'
            ? $planting['in_ground_date']
            : $planting['start_date'];
        return \is_string($anchor) ? $anchor : null;
    }

    /**
     * Days from today until the next occurrence of a recurring MM-DD, or null.
     * Returns 0 when it is today.
     */
    public static function daysUntilMonthDay(string $today, ?string $monthDay): ?int
    {
        $next = self::nextOccurrence($today, $monthDay);
        return $next === null ? null : Clock::daysBetween($today, $next);
    }

    /**
     * A recurring MM-DD resolved to the next calendar date on or after today.
     *
     * "02-15" read in December is next February, not last -- which is the
     * whole reason this is not a subtraction.
     */
    public static function nextOccurrence(string $today, ?string $monthDay): ?string
    {
        if (!Clock::isMonthDay($monthDay)) {
            return null;
        }
        $year = (int) \substr($today, 0, 4);
        $thisYear = Clock::recurringOn((string) $monthDay, $year);
        if ($thisYear !== null && $thisYear >= $today) {
            return $thisYear;
        }
        return Clock::recurringOn((string) $monthDay, $year + 1);
    }

    /**
     * With emulation off a named placeholder cannot be reused, so each value
     * gets its own name (hosting Section 7).
     *
     * @param list<int> $ids
     * @param array<string,mixed> $params
     */
    private static function inClause(array $ids, string $prefix, array &$params): string
    {
        if ($ids === []) {
            return 'IN (0)';
        }
        $names = [];
        foreach (\array_values($ids) as $i => $id) {
            $name = $prefix . $i;
            $names[] = ':' . $name;
            $params[$name] = $id;
        }
        return 'IN (' . \implode(', ', $names) . ')';
    }
}
