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
            'sources'     => $sources,
            'attribution' => Attribution::lines($sources),
        ];
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
