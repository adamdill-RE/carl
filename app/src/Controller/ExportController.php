<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\ListType;
use Carl\Support\Attribution;
use Carl\Support\Csv;

/**
 * CSV export of the signed-in user's own data (handoff Section 13.3).
 *
 * Three rules shape this file:
 *
 *  1. **Every cell goes through Csv::field().** A leading `=`, `+`, `-` or
 *     `@` makes a spreadsheet offer to run the cell, and the person it runs
 *     on is the person the export was made for (hosting Section 8.5).
 *  2. **Rows are read a chunk at a time and written out as they arrive.**
 *     memory_limit is 128M and this runs under the web SAPI against a 30 s
 *     max_execution_time (hosting Section 4), so nothing accumulates.
 *  3. **Scoping is the repository's, not this file's.** Every read below
 *     goes through a repository that puts user_id in the WHERE clause. An
 *     export that assembled its own SQL is exactly how a scoping bug gets
 *     in (handoff Section 5).
 */
final class ExportController extends Controller
{
    /** Rows per statement. Handoff Section 13.3. */
    private const CHUNK = 1000;

    public function index(Request $request): Response
    {
        return $this->render('export/index', [
            'hasWeather' => $this->user()->weatherLocationId !== null,
        ]);
    }

    public function plantsCsv(Request $request): Response
    {
        $plantings = $this->plantings();

        return $this->csv('carl-plants-' . $this->today() . '.csv', static function () use ($plantings): iterable {
            yield Csv::BOM . Csv::line([
                'planting_id', 'category', 'type', 'plant_family', 'latin_name', 'label',
                'start_method', 'start_date', 'in_ground_date', 'ended_at', 'state',
                'quantity_initial', 'quantity_live', 'garden', 'row', 'container',
                'seed_source', 'nursery', 'default_water_method', 'hardening_schedule',
                'germinated_at', 'hardening_started_at', 'hardening_days',
                'trellis_used', 'collar_used', 'seeds_per_collar',
                'initial_height_in', 'initial_width_in',
                'dtm_days_min', 'dtm_days_max', 'dtm_counted_from',
                'yield_weight_g', 'yield_count', 'notes',
            ]);

            $afterId = 0;
            do {
                $rows = $plantings->exportChunk($afterId, self::CHUNK);
                foreach ($rows as $row) {
                    $afterId = (int) $row['id'];
                    yield Csv::line([
                        $row['id'], $row['category'], $row['type'], $row['plant_family'],
                        $row['latin_name'], $row['label'],
                        $row['start_method'], $row['start_date'], $row['in_ground_date'],
                        $row['ended_at'], $row['state'],
                        $row['quantity_initial'], $row['quantity_live'],
                        $row['garden_name'], $row['row_name'], $row['container_name'],
                        $row['seed_source_name'], $row['nursery_name'],
                        $row['water_method_name'], $row['hardening_schedule_name'],
                        $row['germinated_at'], $row['hardening_started_at'], $row['hardening_days'],
                        (int) $row['trellis_used'], (int) $row['collar_used'], $row['seeds_per_collar'],
                        $row['initial_height_in'], $row['initial_width_in'],
                        $row['dtm_days_min'], $row['dtm_days_max'], $row['dtm_counted_from'],
                        $row['yield_weight_g'], $row['yield_count_qty'], $row['notes'],
                    ]);
                }
            } while (\count($rows) === self::CHUNK);
        });
    }

    /**
     * Plant events and garden events in one file, told apart by a scope
     * column.
     *
     * Only a zone watering fans out to plant rows (handoff Section 4.7), so
     * an export of plant_event alone would quietly drop every garden-level
     * mulch, fertilise and pest record -- data the user entered, in an export
     * of the user's own data.
     */
    public function eventsCsv(Request $request): Response
    {
        $events = $this->events();

        return $this->csv('carl-events-' . $this->today() . '.csv', static function () use ($events): iterable {
            yield Csv::BOM . Csv::line([
                'scope', 'event_id', 'event_date', 'recorded_at', 'event_type',
                'planting_id', 'category', 'type', 'plant_label',
                'garden', 'row', 'container', 'water_zone',
                'quantity_delta', 'duration_min', 'weight_g', 'count_qty', 'unit',
                'reference', 'reference_2', 'derived_from_garden_event',
                'fanned_out_to_plants', 'narrative',
            ]);

            $afterId = 0;
            do {
                $rows = $events->exportChunk($afterId, self::CHUNK);
                foreach ($rows as $row) {
                    $afterId = (int) $row['id'];
                    yield Csv::line([
                        'plant', $row['id'], $row['event_date'], $row['recorded_at'],
                        $row['event_type'], $row['planting_id'], $row['category'], $row['type'],
                        $row['plant_label'],
                        $row['garden_name'], $row['row_name'], $row['container_name'], null,
                        $row['quantity_delta'], $row['duration_min'], $row['weight_g'],
                        $row['count_qty'], $row['unit'],
                        $row['ref_name'], $row['ref_name_2'], $row['source_garden_event_id'],
                        null, $row['narrative'],
                    ]);
                }
            } while (\count($rows) === self::CHUNK);

            $afterId = 0;
            do {
                $rows = $events->gardenExportChunk($afterId, self::CHUNK);
                foreach ($rows as $row) {
                    $afterId = (int) $row['id'];
                    yield Csv::line([
                        'garden', $row['id'], $row['event_date'], $row['recorded_at'],
                        $row['event_type'], null, null, null, null,
                        $row['garden_name'], $row['row_names'], null, $row['zone_name'],
                        null, $row['duration_min'], null, null, null,
                        $row['ref_name'], $row['ref_name_2'], null,
                        $row['fanout_count'], $row['narrative'],
                    ]);
                }
            } while (\count($rows) === self::CHUNK);
        });
    }

    /**
     * The daily series for the user's own weather location, in SI as stored
     * (weather.md Section 6.3 -- store SI, convert at display; an export is
     * for analysis, so it gets the stored numbers and says so in the header
     * names).
     */
    public function weatherCsv(Request $request): Response
    {
        $locationId = $this->user()->weatherLocationId;
        $weather = $this->weather();

        // One list, used for both the header and each row, so the two cannot
        // drift into a file whose columns are labelled wrongly.
        $columns = [
            'obs_date', 'temp_max_c', 'temp_min_c', 'temp_mean_c', 'precip_mm',
            'precip_hours', 'et0_mm', 'water_balance_mm', 'radiation_mj',
            'sunshine_s', 'daylight_s', 'rh_mean_pct', 'rh_min_pct', 'vpd_max_kpa',
            'wind_max_kmh', 'gust_max_kmh', 'soil_moist_0_7', 'soil_temp_0_7_c',
            'weather_code', 'source_model', 'is_provisional',
        ];

        return $this->csv('carl-weather-' . $this->today() . '.csv',
            static function () use ($weather, $locationId, $columns): iterable {
                yield Csv::BOM . Csv::line($columns);

                if ($locationId === null) {
                    return;
                }

                // Before any obs_date this table can hold: the keyset walk
                // needs a floor, and a DATE column has no NULL-safe '>'.
                $afterDate = '0001-01-01';
                do {
                    $rows = $weather->exportChunk($locationId, $afterDate, self::CHUNK);
                    foreach ($rows as $row) {
                        $afterDate = (string) $row['obs_date'];
                        yield Csv::line(\array_map(
                            static fn (string $column): mixed => $row[$column] ?? null,
                            $columns
                        ));
                    }
                } while (\count($rows) === self::CHUNK);
            });
    }

    /**
     * `/export/claude.json` (handoff Section 13.3): one document per user,
     * shaped for pasting into a Claude conversation. This is the bridge to
     * the v2 "Recommendations" feature.
     *
     * It inherits three of the four rules above and deliberately not the
     * fourth:
     *
     *  - **User-scoped**, through the same repositories. Same reason.
     *  - **Keyset-paginated and chunked** on the three tables with no natural
     *    size bound: plantings, events and weather. Gardens, rows, zones and
     *    containers are not chunked, because a person builds them by hand and
     *    there are single figures of each; a keyset walk over four rows is
     *    ceremony, not safety.
     *  - **NOT formula-injection guarded.** Csv::field() prefixes a cell that
     *    begins `=`, `+`, `-` or `@` with an apostrophe so a spreadsheet does
     *    not run it. That is a spreadsheet problem and JSON has no formulas:
     *    running these values through it would silently rewrite every
     *    negative number in the file -- `quantity_delta` is negative on every
     *    cull -- and hand the reader corrupted data to reason about. The
     *    guard is absent on purpose. Do not "fix" it.
     *
     * The document is written incrementally rather than assembled and
     * encoded once. A user with five years of weather and a few hundred
     * plantings is a few hundred thousand rows of nothing in particular, and
     * memory_limit is 128M (hosting Section 4): building the array first
     * would hold the whole thing twice, once as PHP values and once as the
     * encoded string.
     */
    public function claudeJson(Request $request): Response
    {
        $user = $this->user();
        $today = $this->today();

        $plantings = $this->plantings();
        $events = $this->events();
        $gardens = $this->gardens();
        $lists = $this->lists();
        $weather = $this->weather();
        $reference = $this->reference();

        $region = $user->regionId !== null ? $reference->findRegion($user->regionId) : null;
        $location = $user->weatherLocationId !== null
            ? $weather->findLocation($user->weatherLocationId) : null;

        $units = $this->app->units();

        return Response::streamed(
            function () use (
                $user, $today, $plantings, $events, $gardens, $lists, $weather,
                $reference, $region, $location, $units
            ): iterable {
                yield '{"carl":' . self::json([
                    'document'     => 'carl-export',
                    'version'      => 1,
                    'generated_at' => \gmdate('c'),
                    'generated_for_local_date' => $today,
                    // What a reader needs to know before believing a number.
                    'read_me' => [
                        'One gardener\'s own records. Every date is that gardener\'s local'
                            . ' calendar day, not UTC.',
                        'Weather is stored SI as it arrives from the archive: temperatures in'
                            . ' Celsius, depths in millimetres, wind in km/h. The gardener reads'
                            . ' the site in ' . ($units->isUs() ? 'US units' : 'SI units')
                            . '; convert for them, not for yourself.',
                        'water_balance_mm is precip_mm minus et0_mm. A negative run of days is'
                            . ' the soil drying out.',
                        'is_provisional means the archive may still revise that day: it re-reads'
                            . ' roughly the last two weeks.',
                        'An event with a source_garden_event_id was derived from a garden-wide'
                            . ' action, not logged against that plant on its own. Counting both'
                            . ' is double counting.',
                        'Decimal columns arrive as JSON strings ("14.02"), which is how the'
                            . ' database returns them. They are exact; ZIP and FIPS codes are'
                            . ' strings for a different reason and must stay that way.',
                        'research.plants and research.regions are the values in force for this'
                            . ' gardener\'s region. Each carries a confidence: verified, approx'
                            . ' or generic. A generic value is a catalogue default, not a'
                            . ' measurement for this county.',
                    ],
                ]);

                yield ',"gardener":' . self::json([
                    'name'          => $user->name,
                    'timezone'      => $user->timezone,
                    'zip'           => $user->zip,
                    'county_fips'   => $user->countyFips,
                    'display_units' => $units->isUs() ? 'us' : 'si',
                    // The whole region row, not three fields of it: the frost
                    // dates and the growing season length are the facts a
                    // recommendation is actually built on, and leaving them
                    // out would make the document look complete while being
                    // useless for the thing it exists to feed.
                    'region' => $region === null ? null : self::row($region),
                ]);

                // Bounded by hand: a person builds a handful of gardens.
                yield ',"gardens":[';
                $separator = '';
                foreach ($gardens->activeGardens() as $garden) {
                    $gardenId = (int) $garden['id'];
                    yield $separator . self::json(self::row($garden) + [
                        'rows'  => \array_map(self::row(...), $gardens->rows($gardenId)),
                        'zones' => \array_map(self::row(...), $gardens->zones($gardenId)),
                    ]);
                    $separator = ',';
                }
                yield ']';

                yield ',"containers":' . self::json(
                    \array_map(self::row(...), $gardens->containers(false))
                );
                // The gardener's own vocabulary -- their seed sources, soils,
                // fertilisers, water methods. Without it every ref_name in the
                // event log is a word with no category behind it.
                yield ',"lists":' . self::json(\array_map(
                    static fn (array $items): array => \array_map(self::row(...), $items),
                    $lists->manyTypes(ListType::all())
                ));

                // Unbounded from here on: keyset walks, a chunk at a time.
                $plantTypeIds = [];
                yield ',"plantings":[';
                $separator = '';
                $afterId = 0;
                do {
                    $rows = $plantings->exportChunk($afterId, self::CHUNK);
                    foreach ($rows as $row) {
                        $afterId = (int) $row['id'];
                        $plantTypeIds[(int) $row['plant_type_id']] = true;
                        yield $separator . self::json(self::row($row));
                        $separator = ',';
                    }
                } while (\count($rows) === self::CHUNK);
                yield ']';

                yield ',"plant_events":[';
                $separator = '';
                $afterId = 0;
                do {
                    $rows = $events->exportChunk($afterId, self::CHUNK);
                    foreach ($rows as $row) {
                        $afterId = (int) $row['id'];
                        yield $separator . self::json(self::row($row));
                        $separator = ',';
                    }
                } while (\count($rows) === self::CHUNK);
                yield ']';

                yield ',"garden_events":[';
                $separator = '';
                $afterId = 0;
                do {
                    $rows = $events->gardenExportChunk($afterId, self::CHUNK);
                    foreach ($rows as $row) {
                        $afterId = (int) $row['id'];
                        yield $separator . self::json(self::row($row));
                        $separator = ',';
                    }
                } while (\count($rows) === self::CHUNK);
                yield ']';

                yield ',"weather":{"location":' . self::json($location === null ? null : [
                    'label'     => $location['label'],
                    'zip'       => $location['zip'],
                    'latitude'  => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'timezone'  => $location['timezone'],
                ]);
                yield ',"units":{"temperature":"C","precipitation":"mm","et0":"mm","wind":"km/h"}';
                yield ',"days":[';
                $separator = '';
                $models = [];
                if ($location !== null) {
                    // A DATE column has no NULL-safe '>', so the walk needs a
                    // floor below any obs_date the table can hold.
                    $afterDate = '0001-01-01';
                    do {
                        $rows = $weather->exportChunk((int) $location['id'], $afterDate, self::CHUNK);
                        foreach ($rows as $row) {
                            $afterDate = (string) $row['obs_date'];
                            $models[(string) $row['source_model']] = true;
                            yield $separator . self::json($row);
                            $separator = ',';
                        }
                    } while (\count($rows) === self::CHUNK);
                }
                yield ']}';

                // Last, because it needs the plant type ids the walk above
                // collected -- which is also why it is two statements rather
                // than one per planting.
                $research = $reference->researchFor(\array_keys($plantTypeIds), $user->regionId);
                yield ',"research":' . self::json($research);

                // weather.md Section 10: the credit travels with the data,
                // generated from source_model on the rows actually included.
                yield ',"attribution":' . self::json(Attribution::lines(\array_keys($models)));
                yield '}';
            },
            'application/json; charset=utf-8',
            'carl-for-claude-' . $today . '.json'
        );
    }

    /**
     * One database row, cleaned for a document a person will paste somewhere.
     *
     * `user_id` goes. It is this user's own id on every row, so it is not a
     * leak -- it is noise, repeated once per row, in a file whose whole
     * purpose is to be read by something that charges by the token.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function row(array $row): array
    {
        unset($row['user_id']);
        return $row;
    }

    /**
     * One value, encoded.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE, because json_encode returns false on a
     * byte sequence it cannot read and false concatenated into a stream is an
     * empty string -- which would produce a document that is silently missing
     * a row and still parses. A narrative typed on a phone with a broken
     * keyboard map is not worth a corrupt export.
     */
    private static function json(mixed $value): string
    {
        $encoded = \json_encode(
            $value,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $encoded === false ? 'null' : $encoded;
    }

    /**
     * @param callable():iterable<string> $producer
     */
    private function csv(string $filename, callable $producer): Response
    {
        return Response::streamed($producer, 'text/csv; charset=utf-8', $filename);
    }
}
