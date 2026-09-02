<?php

declare(strict_types=1);

namespace Carl\Planting;

use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Domain\ReminderKind;
use Carl\Reminders\ReminderBuilder;
use Carl\Support\Clock;

/**
 * The garden's dated facts, on a grid (Phase 9).
 *
 * **This is the digest read forward in time.** `Reminders\ReminderBuilder`
 * answers "is today the day to say something", and it answers it for today
 * only -- seven days out, on the day, once per season. A calendar asks the
 * other question: WHEN IS IT. The two must not disagree, so every date here
 * is derived by the same arithmetic the digest uses, from the same research
 * values, and three of the helpers are literally the digest's own public
 * static ones. Where a rule needed re-stating rather than re-using, a test in
 * `22_calendar_test.php` pins the two to the same answer for the same
 * planting; that is the guard against the two drifting.
 *
 * **Nothing here reads or writes.** Like `Succession`, which it sits beside
 * for the same reason: the controller hands it rows it has already fetched,
 * so drawing a month costs no statement beyond the ones the page already
 * makes. A calculator that opened its own connection could not be handed the
 * digest's rows either, which is what would make sharing the arithmetic
 * expensive rather than free.
 *
 * **Two kinds of entry, and the difference is visible on the page.** A
 * `logged` entry is a row in `plant_event` or `garden_event`: it happened,
 * somebody typed it, and it is never in the future. Everything else is
 * PROJECTED -- computed from days-to-maturity, a hardening duration, or a
 * recurring window in the research -- and is a guess with a date on it. The
 * view marks them differently and the upcoming table says so in words,
 * because "first harvest 17 October" read as a promise is how a gardener
 * comes to think Carl was wrong about their tomatoes.
 */
final class Calendar
{
    /** The pseudo-kind for something that actually happened. */
    public const LOGGED = 'logged';

    /** How far ahead the table below the grid looks by default. */
    public const UPCOMING_DAYS = 90;

    /** Most rows the upcoming table will draw, however long the season is. */
    public const UPCOMING_LIMIT = 60;

    /**
     * Everything dated in [$from, $to], newest rule last.
     *
     * @param list<array<string,mixed>>          $plantings   with pt.* research columns
     * @param list<array<string,mixed>>          $events      plant_event rows, already in range
     * @param list<array<string,mixed>>          $gardenEvents garden_event rows, already in range
     * @param array<string,mixed>|null           $region      the `region` row, or null
     * @param list<array<string,mixed>>          $windows     `plant_region` joined to `plant_type`
     * @param list<array<string,mixed>>          $pests       `pest_region` joined to `pest`
     *
     * @return list<array{date:string,kind:string,label:string,title:string,detail:string,
     *                    planting_id:?int,projected:bool}>
     */
    public static function build(
        string $from,
        string $to,
        array $plantings,
        array $events,
        array $gardenEvents,
        ?array $region,
        array $windows,
        array $pests,
    ): array {
        $entries = [];

        foreach (self::logged($events, $gardenEvents) as $entry) {
            $entries[] = $entry;
        }
        foreach (self::perPlanting($plantings, $windows) as $entry) {
            $entries[] = $entry;
        }
        foreach (self::sowingDates($from, $to, $windows) as $entry) {
            $entries[] = $entry;
        }
        foreach (self::transplantWindows($from, $to, $plantings, $windows) as $entry) {
            $entries[] = $entry;
        }
        foreach (self::frostDates($from, $to, $region) as $entry) {
            $entries[] = $entry;
        }
        foreach (self::pestWindows($from, $to, $plantings, $pests) as $entry) {
            $entries[] = $entry;
        }

        // Range-check once, at the end, rather than in six places. Every rule
        // above is free to compute a date and let this throw it away, which
        // is what keeps each of them to its own arithmetic.
        $inRange = [];
        foreach ($entries as $entry) {
            if ($entry['date'] >= $from && $entry['date'] <= $to) {
                $inRange[] = $entry;
            }
        }

        \usort($inRange, static function (array $a, array $b): int {
            return [$a['date'], ReminderKind::priority($a['kind']), $a['title']]
               <=> [$b['date'], ReminderKind::priority($b['kind']), $b['title']];
        });

        return $inRange;
    }

    /**
     * The month drawn as weeks of days, Sunday first.
     *
     * Weeks are whole, so the grid begins in the previous month and ends in
     * the next one. Those days are marked rather than blanked: a frost date
     * on 1 March is worth seeing from the February page, and a blank cell
     * hides it for no reason a reader would recognise.
     *
     * @return list<list<array{date:string,in_month:bool}>>
     */
    public static function grid(string $month): array
    {
        $first = self::firstOfMonth($month);
        $days = (int) \date('t', (int) \strtotime($first . ' 00:00:00 UTC'));
        $last = \substr($first, 0, 8) . \sprintf('%02d', $days);

        $lead = (int) \date('w', (int) \strtotime($first . ' 00:00:00 UTC'));
        $trail = 6 - (int) \date('w', (int) \strtotime($last . ' 00:00:00 UTC'));

        $cursor = (string) Clock::addDays($first, -$lead);
        $end = (string) Clock::addDays($last, $trail);

        $weeks = [];
        $week = [];
        while ($cursor <= $end) {
            $week[] = [
                'date'     => $cursor,
                'in_month' => \substr($cursor, 0, 7) === \substr($first, 0, 7),
            ];
            if (\count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
            $cursor = (string) Clock::addDays($cursor, 1);
        }
        if ($week !== []) {
            $weeks[] = $week;
        }
        return $weeks;
    }

    /** The first and last date the grid for this month draws. */
    /** @return array{0:string,1:string} */
    public static function gridRange(string $month): array
    {
        $weeks = self::grid($month);
        $first = $weeks[0][0]['date'];
        $lastWeek = $weeks[\count($weeks) - 1];
        return [$first, $lastWeek[\count($lastWeek) - 1]['date']];
    }

    /** A YYYY-MM shifted by whole months, for the previous/next links. */
    public static function shiftMonth(string $month, int $months): string
    {
        $first = self::firstOfMonth($month);
        $year = (int) \substr($first, 0, 4);
        $index = (int) \substr($first, 5, 2) - 1 + $months;
        $year += \intdiv($index, 12);
        $index %= 12;
        if ($index < 0) {
            $index += 12;
            $year--;
        }
        return \sprintf('%04d-%02d', $year, $index + 1);
    }

    /** "September 2026", for a heading -- the screen's and the sheet's. */
    public static function monthName(string $month): string
    {
        return \date('F Y', (int) \strtotime(self::firstOfMonth($month) . ' 00:00:00 UTC'));
    }

    /** A YYYY-MM from user input, or the month $today falls in. */
    public static function normaliseMonth(?string $month, string $today): string
    {
        if (\is_string($month) && \preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return $month;
        }
        return \substr($today, 0, 7);
    }

    /**
     * Entries keyed by date, for the grid to look up a cell in constant time.
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string,list<array<string,mixed>>>
     */
    public static function byDate(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[(string) $entry['date']][] = $entry;
        }
        return $out;
    }

    // ==================================================================
    // What happened
    // ==================================================================

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $gardenEvents
     * @return list<array<string,mixed>>
     */
    private static function logged(array $events, array $gardenEvents): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = [
                'date'        => (string) $event['event_date'],
                'kind'        => self::LOGGED,
                'label'       => EventType::label((string) $event['event_type']),
                'title'       => EventType::label((string) $event['event_type'])
                    . ' -- ' . self::name($event),
                'detail'      => (string) ($event['narrative'] ?? ''),
                'planting_id' => (int) $event['planting_id'],
                'projected'   => false,
            ];
        }
        foreach ($gardenEvents as $event) {
            $out[] = [
                'date'        => (string) $event['event_date'],
                'kind'        => self::LOGGED,
                'label'       => EventType::label((string) $event['event_type']),
                'title'       => EventType::label((string) $event['event_type'])
                    . ' -- ' . (string) ($event['garden_name'] ?? 'the garden'),
                'detail'      => (string) ($event['narrative'] ?? ''),
                // A garden action belongs to no planting, so a plant filter
                // drops it the same way it drops a frost date.
                'planting_id' => null,
                'projected'   => false,
            ];
        }
        return $out;
    }

    // ==================================================================
    // What is coming
    // ==================================================================

    /**
     * The dates a planting carries on its own row: hardening finishing, and
     * the two ends of the days-to-maturity window.
     *
     * @param list<array<string,mixed>> $plantings
     * @param list<array<string,mixed>> $windows
     * @return list<array<string,mixed>>
     */
    private static function perPlanting(array $plantings, array $windows): array
    {
        $overrides = self::overridesByType($windows);
        $out = [];

        foreach ($plantings as $planting) {
            $name = self::name($planting);
            $plantingId = (int) $planting['id'];
            $ended = (string) $planting['state'] === PlantingState::ENDED;

            if (!$ended
                && $planting['hardening_started_at'] !== null
                && $planting['hardening_days'] !== null) {
                $due = Clock::addDays((string) $planting['hardening_started_at'],
                    (int) $planting['hardening_days']);
                if ($due !== null) {
                    $out[] = [
                        'date'        => $due,
                        'kind'        => ReminderKind::HARDENING_COUNTDOWN,
                        'label'       => 'Transplant',
                        'title'       => $name . ' ready to transplant',
                        'detail'      => 'Hardening started ' . $planting['hardening_started_at']
                            . ' for ' . (int) $planting['hardening_days'] . ' days.',
                        'planting_id' => $plantingId,
                        'projected'   => true,
                    ];
                }
            }

            // An ended planting has no future left to project. Its logged
            // events stay on the grid, which is the whole of what a finished
            // row still has to say.
            if ($ended) {
                continue;
            }

            $anchor = ReminderBuilder::dtmAnchor($planting);
            if ($anchor === null) {
                continue;
            }

            $typeId = (int) $planting['plant_type_id'];
            $min = self::dtm($planting, $overrides[$typeId] ?? [], 'min');
            $max = self::dtm($planting, $overrides[$typeId] ?? [], 'max');

            if ($min !== null) {
                $date = Clock::addDays($anchor, $min);
                if ($date !== null) {
                    $out[] = [
                        'date'        => $date,
                        'kind'        => ReminderKind::FIRST_HARVEST_EXPECTED,
                        'label'       => 'Harvest',
                        'title'       => $name . ' should be ready',
                        'detail'      => 'Counted ' . $min . ' days from ' . $anchor
                            . '. Days to maturity is a guide, not a promise.',
                        'planting_id' => $plantingId,
                        'projected'   => true,
                    ];
                }
            }

            if ($max !== null) {
                $date = Clock::addDays($anchor, $max);
                if ($date !== null) {
                    $out[] = [
                        'date'        => $date,
                        'kind'        => ReminderKind::HARVEST_WINDOW_CLOSING,
                        'label'       => 'Harvest',
                        'title'       => $name . ': end of the expected window',
                        'detail'      => 'Counted ' . $max . ' days from ' . $anchor . '.',
                        'planting_id' => $plantingId,
                        'projected'   => true,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * "Sow indoors by" for the types this region recommends.
     *
     * The digest's `start_seeds_by` rule, without its "14 or 7 days out"
     * gate: the gate decides when to SPEAK and a calendar is being read, not
     * spoken to. Scoped to recommended types for the digest's own reason --
     * every type in the catalogue would be a wall of chips nobody reads.
     *
     * @param list<array<string,mixed>> $windows
     * @return list<array<string,mixed>>
     */
    private static function sowingDates(string $from, string $to, array $windows): array
    {
        $out = [];
        foreach ($windows as $window) {
            if ((int) ($window['recommended'] ?? 0) !== 1) {
                continue;
            }
            $weeks = (int) ($window['weeks_before_transplant_to_start'] ?? 0);
            if ($weeks <= 0 || !Clock::isMonthDay($window['window_start'] ?? null)) {
                continue;
            }

            foreach (self::occurrences($from, $to, (string) $window['window_start'], $weeks * 7) as $date) {
                $out[] = [
                    'date'        => $date,
                    'kind'        => ReminderKind::START_SEEDS_BY,
                    'label'       => 'Sow',
                    'title'       => 'Sow ' . $window['type'] . ' indoors by now',
                    'detail'      => $window['type'] . ' wants ' . $weeks . ' weeks indoors before your '
                        . $window['season'] . ' window opens on ' . $window['window_start'] . '.',
                    'planting_id' => null,
                    'projected'   => true,
                ];
            }
        }
        return $out;
    }

    /**
     * When this area's transplant window opens and closes, for types the user
     * actually has seedlings of.
     *
     * @param list<array<string,mixed>> $plantings
     * @param list<array<string,mixed>> $windows
     * @return list<array<string,mixed>>
     */
    private static function transplantWindows(string $from, string $to, array $plantings, array $windows): array
    {
        $waiting = [];
        foreach ($plantings as $planting) {
            if (\in_array((string) $planting['state'],
                [PlantingState::SEED_STARTED, PlantingState::HARDENING], true)) {
                $waiting[(int) $planting['plant_type_id']][] = $planting;
            }
        }
        if ($waiting === []) {
            return [];
        }

        $out = [];
        foreach ($windows as $window) {
            // A sowing window says nothing about moving a seedling out.
            if ((string) ($window['method'] ?? '') === 'seed') {
                continue;
            }
            $typeId = (int) $window['plant_type_id'];
            foreach ($waiting[$typeId] ?? [] as $planting) {
                $name = self::name($planting);
                foreach ([['window_start', 'opens'], ['window_end', 'closes']] as [$column, $verb]) {
                    if (!Clock::isMonthDay($window[$column] ?? null)) {
                        continue;
                    }
                    foreach (self::occurrences($from, $to, (string) $window[$column], 0) as $date) {
                        $out[] = [
                            'date'        => $date,
                            'kind'        => ReminderKind::TRANSPLANT_WINDOW,
                            'label'       => 'Transplant',
                            'title'       => 'Transplant window ' . $verb . ' for ' . $name,
                            'detail'      => 'Your area\'s ' . $window['season'] . ' window for '
                                . $window['type'] . ' runs ' . $window['window_start']
                                . ' to ' . $window['window_end'] . '.',
                            'planting_id' => (int) $planting['id'],
                            'projected'   => true,
                        ];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The two dates that bracket a growing season.
     *
     * The AVERAGE date only, with the early/late pair in the detail rather
     * than as three chips: an average frost date is already a probability
     * wearing a date, and drawing all three would read as three events.
     *
     * @param array<string,mixed>|null $region
     * @return list<array<string,mixed>>
     */
    private static function frostDates(string $from, string $to, ?array $region): array
    {
        if ($region === null) {
            return [];
        }

        $out = [];
        $fields = [
            ['last_frost_avg', 'last_frost_early', 'last_frost_late',
             'Average last frost', 'After this, tender plants can normally go out.'],
            ['first_frost_avg', 'first_frost_early', 'first_frost_late',
             'Average first frost', 'The season stops paying out around here.'],
        ];

        foreach ($fields as [$avg, $early, $late, $title, $why]) {
            if (!Clock::isMonthDay($region[$avg] ?? null)) {
                continue;
            }
            $spread = Clock::isMonthDay($region[$early] ?? null) && Clock::isMonthDay($region[$late] ?? null)
                ? ' Recorded as early as ' . $region[$early] . ' and as late as ' . $region[$late] . '.'
                : '';
            foreach (self::occurrences($from, $to, (string) $region[$avg], 0) as $date) {
                $out[] = [
                    'date'        => $date,
                    'kind'        => ReminderKind::FROST_WATCH,
                    'label'       => 'Frost',
                    'title'       => $title,
                    'detail'      => $why . $spread,
                    'planting_id' => null,
                    'projected'   => true,
                ];
            }
        }
        return $out;
    }

    /**
     * The day each pest's season opens, for pests that touch something grown.
     *
     * The OPENING only. A pest window is three months wide and a chip on
     * every one of its ninety days is a calendar that says "spider mites"
     * ninety times; the date worth knowing is the one the row cover has to be
     * on by. The GDD kinds are deliberately absent: a degree-day crossing has
     * no date until the weather has happened, which is exactly why the digest
     * computes it from the record rather than the calendar.
     *
     * @param list<array<string,mixed>> $plantings
     * @param list<array<string,mixed>> $pests
     * @return list<array<string,mixed>>
     */
    private static function pestWindows(string $from, string $to, array $plantings, array $pests): array
    {
        $grown = [];
        foreach ($plantings as $planting) {
            if ((string) $planting['state'] !== PlantingState::ENDED) {
                $grown[\strtolower(\trim((string) $planting['category']))] = true;
            }
        }
        if ($grown === []) {
            return [];
        }

        $out = [];
        foreach ($pests as $pest) {
            if (!Clock::isMonthDay($pest['active_start'] ?? null)) {
                continue;
            }
            // Semicolon-separated, and an empty cell means "everything"
            // (research-template/README.md).
            $affects = \array_filter(\array_map(
                static fn (string $c): string => \strtolower(\trim($c)),
                \explode(';', (string) ($pest['affects_categories'] ?? ''))
            ));
            if ($affects !== [] && \array_intersect($affects, \array_keys($grown)) === []) {
                continue;
            }

            foreach (self::occurrences($from, $to, (string) $pest['active_start'], 0) as $date) {
                $out[] = [
                    'date'        => $date,
                    'kind'        => ReminderKind::PEST_SCOUTING,
                    'label'       => 'Pests',
                    'title'       => $pest['name'] . ' season starts',
                    'detail'      => \trim((string) ($pest['signs'] ?? ''))
                        . ($pest['active_end'] !== null
                            ? ' Active here until ' . $pest['active_end'] . '.' : ''),
                    'planting_id' => null,
                    'projected'   => true,
                ];
            }
        }
        return $out;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Every calendar date in [$from, $to] on which a recurring MM-DD falls,
     * optionally offset backwards by a number of days.
     *
     * A range can span a new year, so this walks the years it touches rather
     * than resolving one occurrence. `ReminderBuilder::nextOccurrence()`
     * answers "the next one from today", which is the digest's question and
     * not this one -- a calendar being paged back to last March needs the one
     * that has already gone.
     *
     * @return list<string>
     */
    private static function occurrences(string $from, string $to, string $monthDay, int $backDays): array
    {
        if (!Clock::isMonthDay($monthDay)) {
            return [];
        }
        $out = [];
        $firstYear = (int) \substr($from, 0, 4) - 1;
        $lastYear = (int) \substr($to, 0, 4) + 1;
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $date = Clock::recurringOn($monthDay, $year);
            if ($date === null) {
                continue;
            }
            if ($backDays !== 0) {
                $date = Clock::addDays($date, -$backDays);
                if ($date === null) {
                    continue;
                }
            }
            if ($date >= $from && $date <= $to) {
                $out[] = $date;
            }
        }
        return $out;
    }

    /**
     * Days to maturity, with the region's override winning over the global
     * catalogue value -- the same rule the digest applies, for the same
     * reason: a tomato that takes 75 days in the catalogue and 90 in a
     * short-season county is two different plants for every date drawn here.
     *
     * @param array<string,mixed> $planting
     * @param list<array<string,mixed>> $windows the rows for this plant type
     */
    private static function dtm(array $planting, array $windows, string $end): ?int
    {
        foreach ($windows as $window) {
            $override = $window['dtm_days_' . $end . '_override'] ?? null;
            if ($override !== null) {
                return (int) $override;
            }
        }
        $global = $planting['dtm_days_' . $end] ?? null;
        return $global === null ? null : (int) $global;
    }

    /**
     * @param list<array<string,mixed>> $windows
     * @return array<int,list<array<string,mixed>>>
     */
    private static function overridesByType(array $windows): array
    {
        $out = [];
        foreach ($windows as $window) {
            $out[(int) $window['plant_type_id']][] = $window;
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function name(array $row): string
    {
        $label = \trim((string) ($row['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        return \trim(((string) ($row['category'] ?? '')) . ' ' . ((string) ($row['type'] ?? '')));
    }

    private static function firstOfMonth(string $month): string
    {
        return \substr($month, 0, 7) . '-01';
    }
}
