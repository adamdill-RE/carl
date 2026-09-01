<?php

declare(strict_types=1);

namespace Carl\Reports;

use Carl\Core\HttpException;
use Carl\Domain\EventType;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\WeatherRepository;
use Carl\Support\Attribution;
use Carl\Support\Clock;
use Carl\Support\Units;

/**
 * The data behind a report: the weather over a subject's covered dates, and
 * the subject's own events, in a shape a chart, a page and a PDF can all
 * read (handoff Section 13.1).
 *
 * Three things this file exists to guarantee:
 *
 *  1. **One statement for weather and one for events**, however many days or
 *     events there are. `tests/cases/11_reports_test.php` asserts it, because
 *     "it looks like one query" is not a measurement.
 *  2. **The scope is the repository's.** Nothing here writes SQL. The
 *     planting and the events come back through the user-scoped base class,
 *     so a series endpoint cannot leak another account's plant any more than
 *     a page can (handoff Section 5, Phase 4 handoff Section 4.1).
 *  3. **One definition of the covered range.** The plant page, the JSON
 *     endpoint and the PDF all ask this class, so they cannot disagree about
 *     which days a plant's weather covers.
 *
 * Values leave here in the user's display units, converted by Units --
 * weather.md Section 6.3 puts that conversion in one helper, and shipping
 * Celsius to a browser that has to convert it means a second copy of the
 * formula written in a second language.
 */
final class Series
{
    /**
     * A chart is not the place to discover that a plant has been in the
     * ground for nine years. Above this the range is clamped to the most
     * recent window and the document says so, so the caller can tell the
     * reader rather than silently drawing a partial picture.
     */
    public const MAX_DAYS = 1100;

    /**
     * The base temperature growing degree days accumulate above, in Celsius.
     *
     * Ten degrees is the warm-season default and it is a DEFAULT, not a fact
     * about any particular plant -- weather.md Section 7.1 says a single
     * stored GDD assumption is wrong for every crop it was not chosen for. It
     * is a constant here rather than a column because the research tables
     * carry no per-crop base yet, and it is printed beside the curve so that
     * the curve never claims to be more specific than it is.
     */
    public const GDD_BASE_C = 10.0;

    public function __construct(
        private PlantingRepository $plantings,
        private EventRepository $events,
        private GardenRepository $gardens,
        private WeatherRepository $weather,
        private Units $units,
    ) {
    }

    /**
     * @return array<string,mixed> the document; see docs/CARL-HANDOFF.md 13.1
     * @throws HttpException 404 when the planting is not this user's
     */
    public function forPlanting(int $plantingId, ?int $locationId, string $today): array
    {
        $planting = $this->plantings->findWithDetail($plantingId);      // 1 statement, user-scoped
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }
        return $this->forPlantingRow($planting, $locationId, $today);
    }

    /**
     * The same, for a caller that has already loaded the planting -- the
     * plant page has, and looking it up twice to draw a chart of it would be
     * a statement spent proving something already proved.
     *
     * The row MUST have come back through PlantingRepository, which is where
     * the user scope lives. Nothing here re-checks ownership, because nothing
     * here could: a row is a row.
     *
     * @param array<string,mixed> $planting from PlantingRepository::findWithDetail()
     * @return array<string,mixed>
     */
    public function forPlantingRow(array $planting, ?int $locationId, string $today): array
    {
        [$from, $to, $clamped] = $this->coveredRange(
            (string) ($planting['in_ground_date'] ?? $planting['start_date']),
            $planting['ended_at'] !== null ? (string) $planting['ended_at'] : null,
            $today
        );

        $markers = $this->events->seriesMarkers((int) $planting['id']);  // 1 statement, user-scoped

        return $this->assemble(
            [
                'kind'             => 'plant',
                'id'               => (int) $planting['id'],
                'title'            => self::plantTitle($planting),
                'label'            => $planting['label'],
                'category'         => $planting['category'],
                'type'             => $planting['type'],
                'state'            => $planting['state'],
                'where'            => self::placeOf($planting),
                'start_date'       => $planting['start_date'],
                'in_ground_date'   => $planting['in_ground_date'],
                'ended_at'         => $planting['ended_at'],
                'quantity_initial' => (int) $planting['quantity_initial'],
                'quantity_live'    => (int) $planting['quantity_live'],
            ],
            $from,
            $to,
            $clamped,
            $locationId,
            $markers
        );
    }

    /**
     * The same document for a whole garden. The events are the garden's own
     * actions -- watering a zone, mulching, a pest treatment -- not the
     * fanned-out per-plant copies of them, which would draw the same day
     * once per living plant (handoff Section 4.7).
     *
     * @return array<string,mixed>
     * @throws HttpException 404 when the garden is not this user's
     */
    public function forGarden(int $gardenId, ?int $locationId, string $today): array
    {
        $garden = $this->gardens->find($gardenId);                      // 1 statement, user-scoped
        if ($garden === null) {
            throw HttpException::notFound('That is not one of your gardens.');
        }

        // A garden's covered range starts when the first thing was planted in
        // it, not when the row was created: an empty garden built in January
        // for an April planting has no April weather to answer for.
        $first = $this->plantings->earliestStartDateInGarden($gardenId);  // 1 statement, user-scoped
        [$from, $to, $clamped] = $this->coveredRange(
            $first ?? \substr((string) $garden['created_at'], 0, 10),
            null,
            $today
        );

        $markers = $this->events->gardenSeriesMarkers($gardenId);       // 1 statement, user-scoped

        return $this->assemble(
            [
                'kind'      => 'garden',
                'id'        => (int) $garden['id'],
                'title'     => (string) $garden['name'],
                'is_indoor' => (int) $garden['is_indoor'] === 1,
                'row_count' => (int) $garden['row_count'],
                'soil_type' => $garden['soil_type'],
            ],
            $from,
            $to,
            $clamped,
            $locationId,
            $markers
        );
    }

    /**
     * The weather half, shared by both. One statement, whatever the range.
     *
     * The gap count and the attribution are both derived from the rows this
     * already holds rather than asked for separately. Two more statements
     * would answer the same two questions the rows in hand answer -- and the
     * plant page used to spend them (series + gapCount + sourceModels).
     *
     * @param array<string,mixed> $subject
     * @param list<array<string,mixed>> $markers
     * @return array<string,mixed>
     */
    private function assemble(
        array $subject,
        string $from,
        string $to,
        bool $clamped,
        ?int $locationId,
        array $markers,
    ): array {
        $rows = $locationId === null || $from > $to
            ? []
            : $this->weather->series($locationId, $from, $to);          // 1 statement

        $days = [];
        $models = [];
        $provisional = 0;

        // Totals accumulate in SI, the unit the rows are stored in, and are
        // converted once at the end (weather.md Section 6.3). Summing display
        // units would round every day before adding it up.
        $rainMm = 0.0;
        $et0Mm = 0.0;
        $hottestC = null;
        $coldestC = null;

        foreach ($rows as $row) {
            $model = (string) $row['source_model'];
            $models[$model] = true;
            $isProvisional = (int) $row['is_provisional'] === 1;
            if ($isProvisional) {
                $provisional++;
            }

            $rainMm += (float) ($row['precip_mm'] ?? 0);
            $et0Mm += (float) ($row['et0_mm'] ?? 0);
            if ($row['temp_max_c'] !== null && ($hottestC === null || (float) $row['temp_max_c'] > $hottestC)) {
                $hottestC = (float) $row['temp_max_c'];
            }
            if ($row['temp_min_c'] !== null && ($coldestC === null || (float) $row['temp_min_c'] < $coldestC)) {
                $coldestC = (float) $row['temp_min_c'];
            }

            $days[] = [
                'date'        => (string) $row['obs_date'],
                'temp_max'    => $this->units->temperatureValue($row['temp_max_c'], 1),
                'temp_min'    => $this->units->temperatureValue($row['temp_min_c'], 1),
                'rain'        => $this->units->rainValue($row['precip_mm'], 3),
                'et0'         => $this->units->rainValue($row['et0_mm'], 3),
                'balance'     => $this->units->rainValue($row['water_balance_mm'], 3),
                'code'        => $row['weather_code'] === null ? null : (int) $row['weather_code'],
                'provisional' => $isProvisional,
            ];
        }

        $sources = \array_keys($models);
        $expected = self::daysInclusive($from, $to);

        return [
            'subject' => $subject,
            'range'   => [
                'from'         => $from,
                'to'           => $to,
                'days'         => $expected,
                'days_held'    => \count($days),
                'days_missing' => \max(0, $expected - \count($days)),
                'provisional'  => $provisional,
                'clamped'      => $clamped,
                'max_days'     => self::MAX_DAYS,
            ],
            'units' => [
                'system'      => $this->units->isUs() ? 'us' : 'si',
                'temperature' => $this->units->temperatureUnit(),
                'rain'        => $this->units->rainUnit(),
            ],
            // Preformatted, because all three readers -- the page, the PDF and
            // anyone reading the JSON -- want the same sentence, and Units is
            // the one place that decides how a depth is written.
            'totals' => [
                'rain'        => $this->units->rain($rainMm),
                'et0'         => $this->units->rain($et0Mm),
                'balance'     => $this->units->rain($rainMm - $et0Mm),
                'temp_range'  => $this->units->temperatureRange($hottestC, $coldestC),
            ],
            'days'        => $days,
            'events'      => $this->markers($markers),
            // The subject's OWN numbers, on their own spine. See subject().
            'plant'       => $this->subject($markers, $days),
            'sources'     => $sources,
            'attribution' => Attribution::lines($sources),
        ];
    }

    /**
     * The subject's own measured numbers, ready to draw.
     *
     * WHY THIS EXISTS. weather.md Section 7.3 is the authority on charting and
     * it says weather is CONTEXT, NOT THE SUBJECT: on a plant-performance
     * chart it belongs as a muted band or a secondary axis, "never competing
     * with the performance line for attention". Phases 4 to 12 had it the
     * other way round -- three weather panels, with the plant reduced to
     * identical triangles that said only that something happened -- because
     * until Phase 13 the plant had almost no number of its own to draw. Size
     * (migration 024) is what changed that.
     *
     * TWO SPINES, DELIBERATELY. `days` is the weather spine: one entry per day
     * the archive holds, which is what the three weather panels and the
     * days_held / days_missing counts have always meant. This spine is the
     * UNION of that and the dates the plant has numbers on, because a plant
     * measured this morning has no weather row yet -- `to` is yesterday for a
     * living subject (coveredRange below), so a same-day measurement would
     * simply not be drawn. Two spines rather than one is the cost of not
     * changing what days_missing means to the page, the PDF and the tests that
     * count it.
     *
     * Everything here is derived from rows already in hand: no statement is
     * spent, which is what 11_reports_test.php asserts about this class.
     *
     * @param list<array<string,mixed>> $markers
     * @param list<array<string,mixed>> $days
     * @return array<string,mixed>
     */
    private function subject(array $markers, array $days): array
    {
        // Sums, not last-wins: two harvests on one day are one day's harvest
        // and two waterings are one day's watering. A SIZE is the exception --
        // measuring the same plant twice in a day is a correction, not two
        // plants, so the later reading wins.
        $height = [];
        $diameter = [];
        $yieldG = [];
        $yieldCount = [];
        $waterMin = [];

        foreach ($markers as $row) {
            $date = (string) $row['event_date'];

            if (($row['height_mm'] ?? null) !== null) {
                $height[$date] = (float) $row['height_mm'];
            }
            if (($row['diameter_mm'] ?? null) !== null) {
                $diameter[$date] = (float) $row['diameter_mm'];
            }
            if (($row['weight_g'] ?? null) !== null) {
                $yieldG[$date] = ($yieldG[$date] ?? 0.0) + (float) $row['weight_g'];
            }
            // Only a HARVEST's count is a harvest. `count_qty` also carries how
            // many germinated and how many were culled (LogController::
            // eventData), and adding those to a yield line would draw six dead
            // seedlings as six tomatoes.
            if ((string) $row['event_type'] === EventType::YIELDED
                && ($row['count_qty'] ?? null) !== null) {
                $yieldCount[$date] = ($yieldCount[$date] ?? 0) + (int) $row['count_qty'];
            }
            if ((string) $row['event_type'] === EventType::WATERED
                && ($row['duration_min'] ?? null) !== null) {
                $waterMin[$date] = ($waterMin[$date] ?? 0) + (int) $row['duration_min'];
            }
        }

        $weatherByDate = [];
        foreach ($days as $day) {
            $weatherByDate[(string) $day['date']] = $day;
        }

        $spine = \array_keys(
            $weatherByDate + $height + $diameter + $yieldG + $yieldCount + $waterMin
        );
        \sort($spine);

        $out = [
            'dates'            => \array_values($spine),
            'height'           => [],
            'diameter'         => [],
            'yield'            => [],
            'yield_count'      => [],
            'yield_cumulative' => [],
            'water_min'        => [],
            // Weather, projected onto the same spine, so the browser never has
            // to join two lists by date to lay one over the other. A spine day
            // the archive has no row for is null, and Chart.js spans the gap
            // rather than drawing a cliff to zero.
            'temp_max'         => [],
            'temp_min'         => [],
            'rain'             => [],
            'et0'              => [],
            'balance'          => [],
            'gdd'              => [],
            'provisional'      => [],
        ];

        // Growing degree days, accumulated across the spine.
        //
        // Computed HERE and never stored: weather.md Section 7.1 is explicit
        // that a stored GDD column bakes in one crop's base temperature and is
        // wrong for every other crop. The base is named on the chart for the
        // same reason -- an unlabelled GDD curve is a claim about this
        // particular plant that nothing in the research tables backs.
        $accumulated = 0.0;
        $runningYield = 0.0;

        foreach ($spine as $date) {
            $day = $weatherByDate[$date] ?? null;

            $out['height'][] = isset($height[$date])
                ? $this->units->sizeValue($height[$date], 1) : null;
            $out['diameter'][] = isset($diameter[$date])
                ? $this->units->sizeValue($diameter[$date], 1) : null;

            // A harvest line is ZERO on a day with no harvest, not null: the
            // bars are a record of picking, and a gap in them reads as a day
            // that was not covered rather than a day nothing was picked.
            $picked = $yieldG[$date] ?? 0.0;
            $runningYield += $picked;
            $out['yield'][]            = $this->units->weightValue($picked, 3);
            $out['yield_count'][]      = $yieldCount[$date] ?? 0;
            $out['yield_cumulative'][] = $this->units->weightValue($runningYield, 3);
            $out['water_min'][]        = $waterMin[$date] ?? 0;

            $out['temp_max'][]    = $day === null ? null : $day['temp_max'];
            $out['temp_min'][]    = $day === null ? null : $day['temp_min'];
            $out['rain'][]        = $day === null ? null : $day['rain'];
            $out['et0'][]         = $day === null ? null : $day['et0'];
            $out['balance'][]     = $day === null ? null : $day['balance'];
            $out['provisional'][] = $day !== null && $day['provisional'] === true;

            // A day missing either half of the pair is skipped rather than
            // guessed at -- the same rule ReminderBuilder::gddCrossing()
            // follows, and for the same reason.
            $maxC = $this->toCelsius($day === null ? null : $day['temp_max']);
            $minC = $this->toCelsius($day === null ? null : $day['temp_min']);
            if ($maxC !== null && $minC !== null) {
                $accumulated += \max(0.0, (($maxC + $minC) / 2) - self::GDD_BASE_C);
            }
            $out['gdd'][] = \round($accumulated, 1);
        }

        // What the plant actually HAS. A picker offering "Height" for a plant
        // nobody has measured is a menu of empty charts, so the browser is
        // told which layers have something in them.
        $out['has'] = [
            'height'   => $height !== [],
            'diameter' => $diameter !== [],
            'yield'    => $yieldG !== [] || $yieldCount !== [],
            'water'    => $waterMin !== [],
            'weather'  => $days !== [],
        ];
        $out['units'] = [
            'size'     => $this->units->sizeUnit(),
            'weight'   => $this->units->weightUnit(),
            'gdd_base' => $this->units->temperature(self::GDD_BASE_C, 0),
        ];

        return $out;
    }

    /**
     * A display temperature back to Celsius, for the one calculation that
     * cannot be done in display units.
     *
     * GDD is a sum of degrees ABOVE A BASE, and a Fahrenheit degree is not a
     * Celsius degree: accumulating one against the other gives a number 1.8
     * times off, which is not obviously wrong on a page. The days have already
     * been converted for the chart by the time this runs, so this converts one
     * back rather than carrying a second copy of every temperature through the
     * assembler.
     */
    private function toCelsius(int|float|null $shown): ?float
    {
        if ($shown === null) {
            return null;
        }
        return $this->units->isUs() ? ((float) $shown - 32) * 5 / 9 : (float) $shown;
    }

    /**
     * Events as chart markers: one entry per event, carrying enough to label
     * a point and nothing more. The narrative is trimmed here rather than in
     * the browser -- it is the one field with no length bound, and a plant
     * with two hundred notes should not send two hundred paragraphs to draw
     * two hundred dots.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function markers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $narrative = \trim((string) ($row['narrative'] ?? ''));
            $out[] = [
                'id'      => (int) $row['id'],
                'date'    => (string) $row['event_date'],
                'type'    => (string) $row['event_type'],
                'label'   => EventType::label((string) $row['event_type']),
                'note'    => $narrative === '' ? null : self::clip($narrative, 120),
                'derived' => ($row['source_garden_event_id'] ?? null) !== null,
            ];
        }
        return $out;
    }

    /**
     * The dates a report covers.
     *
     * `to` is yesterday, not today, for a subject still going: today is not
     * over, so the archive holds no observation for it and weather_forecast
     * is where it lives (weather.md Section 6.2). Counting today as missing
     * would put a permanent "1 day has not been fetched yet" notice on every
     * living plant, every day, which teaches people to ignore the notice that
     * means something.
     *
     * An empty range comes back with `to` before `from` and `days` 0 -- which
     * is what a plant started this morning genuinely has. Rounding it up to
     * one day would claim an observation that does not exist yet.
     *
     * @return array{0:string,1:string,2:bool} from, to, and whether MAX_DAYS clamped it
     */
    private function coveredRange(string $start, ?string $ended, string $today): array
    {
        $from = Clock::parseDate(\substr($start, 0, 10)) ?? $today;
        $to = $ended !== null
            ? (Clock::parseDate(\substr($ended, 0, 10)) ?? $today)
            : (Clock::addDays($today, -1) ?? $today);

        if ($to < $from) {
            return [$from, $to, false];
        }

        if (self::daysInclusive($from, $to) > self::MAX_DAYS) {
            $clampedFrom = Clock::addDays($to, -(self::MAX_DAYS - 1));
            if ($clampedFrom !== null) {
                return [$clampedFrom, $to, true];
            }
        }

        return [$from, $to, false];
    }

    private static function daysInclusive(string $from, string $to): int
    {
        $between = Clock::daysBetween($from, $to);
        return $between === null ? 0 : \max(0, $between + 1);
    }

    /** @param array<string,mixed> $planting */
    public static function plantTitle(array $planting): string
    {
        $title = \trim(((string) ($planting['category'] ?? '')) . ' ' . ((string) ($planting['type'] ?? '')));
        $label = \trim((string) ($planting['label'] ?? ''));
        return $label === '' ? $title : $title . ' (' . $label . ')';
    }

    /** @param array<string,mixed> $planting */
    public static function placeOf(array $planting): string
    {
        $container = \trim((string) ($planting['container_name'] ?? ''));
        if ($container !== '') {
            return $container;
        }
        return \trim(
            ((string) ($planting['garden_name'] ?? '')) . ' ' . ((string) ($planting['row_name'] ?? ''))
        );
    }

    /** Cut on a character count, not a word: a note may have no spaces at all. */
    private static function clip(string $text, int $limit): string
    {
        if (\mb_strlen($text) <= $limit) {
            return $text;
        }
        return \rtrim(\mb_substr($text, 0, $limit - 1)) . "\u{2026}";
    }
}
