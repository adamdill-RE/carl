<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
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
     * @param callable():iterable<string> $producer
     */
    private function csv(string $filename, callable $producer): Response
    {
        return Response::streamed($producer, 'text/csv; charset=utf-8', $filename);
    }
}
