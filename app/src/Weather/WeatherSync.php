<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Core\HttpClient;
use Carl\Core\HttpResult;
use Carl\Support\Clock;
use Throwable;

/**
 * The nightly weather job (weather.md Section 3).
 *
 * Two rules make it safe to run unattended:
 *   1. Idempotent -- the natural key is (location_id, obs_date) and every
 *      write is an upsert, so a re-run after a partial failure converges.
 *   2. Never on the request path -- no page render may reach this class.
 *      A page reads weather_daily and nothing else.
 *
 * Every run writes a weather_sync_run row, success or failure, because a cron
 * job that silently stops is otherwise invisible for months.
 */
final class WeatherSync
{
    /**
     * The source_model on a row derived from the forecast endpoint's
     * past_days. It marks a row the archive is allowed to replace.
     */
    public const FORECAST_PAST = 'forecast_past';

    private Database $db;
    private WeatherProvider $client;

    /** @var list<string> */
    private array $log = [];

    public function __construct(private App $app, ?WeatherProvider $client = null)
    {
        $this->db = $app->db();
        $this->client = $client ?? new OpenMeteoClient(
            new HttpClient(
                $app->config()->string('weather.user_agent'),
                $app->config()->int('weather.http_timeout', 20),
            ),
            $app->config(),
        );
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @param list<string> $kinds  archive, forecast, or both
     * @return array{locations:int,rows:int,failures:int,log:list<string>}
     */
    public function run(array $kinds = ['archive', 'forecast'], ?int $onlyLocationId = null): array
    {
        $locations = $this->db->all(
            'SELECT * FROM `weather_location` WHERE `is_active` = 1'
            . ($onlyLocationId !== null ? ' AND `id` = :id' : '')
            . ' ORDER BY `id`',
            $onlyLocationId !== null ? ['id' => $onlyLocationId] : []
        );

        $rows = 0;
        $failures = 0;

        foreach ($locations as $location) {
            foreach ($kinds as $kind) {
                try {
                    $written = $kind === 'forecast'
                        ? $this->syncForecast($location)
                        : $this->syncArchive($location);
                    $rows += $written;
                } catch (Throwable $e) {
                    // A failure for one location must not stop the others:
                    // the gap heals on the next run because the fetch range
                    // is derived from what is missing, not from a cursor.
                    $failures++;
                    $this->note('location ' . $location['id'] . ' ' . $kind . ' failed: ' . $e->getMessage());
                    $this->recordRun((int) $location['id'], $kind, null, null, null, 0, 'failed',
                        \substr($e->getMessage(), 0, 500));
                }
            }
        }

        $this->settleProvisional();
        $this->prune();

        return ['locations' => \count($locations), 'rows' => $rows, 'failures' => $failures,
                'log' => $this->log];
    }

    /**
     * Historical: a rolling 14-day revision window ending yesterday, plus any
     * gap dates back to backfill_from, chunked by year so a long backfill
     * cannot exceed max_execution_time (weather.md Sections 2 and 6.2).
     *
     * @param array<string,mixed> $location
     */
    public function syncArchive(array $location): int
    {
        $locationId = (int) $location['id'];
        $timezone = (string) $location['timezone'];
        $today = $this->app->clock()->todayFor($timezone);
        $yesterday = (string) Clock::addDays($today, -1);

        $revisionDays = $this->app->config()->int('weather.revision_days', 14);
        $windowStart = (string) Clock::addDays($yesterday, -($revisionDays - 1));

        $ranges = [];

        // The gap first: everything from backfill_from up to the revision
        // window that is not already held, chunked by calendar year.
        $backfillFrom = (string) $location['backfill_from'];
        $oldestHeld = $this->db->value(
            'SELECT MIN(`obs_date`) FROM `weather_daily` WHERE `location_id` = :id',
            ['id' => $locationId]
        );
        $newestHeld = $this->db->value(
            'SELECT MAX(`obs_date`) FROM `weather_daily` WHERE `location_id` = :id',
            ['id' => $locationId]
        );

        if (!\is_string($oldestHeld) || $backfillFrom < $oldestHeld) {
            $gapEnd = \is_string($oldestHeld)
                ? (string) Clock::addDays($oldestHeld, -1)
                : (string) Clock::addDays($windowStart, -1);
            if ($backfillFrom <= $gapEnd) {
                foreach (self::chunkByYear($backfillFrom, $gapEnd) as $range) {
                    $ranges[] = $range;
                }
            }
        }

        // A hole between what is held and the revision window (a cron that
        // stopped for a while).
        if (\is_string($newestHeld) && $newestHeld < (string) Clock::addDays($windowStart, -1)) {
            foreach (self::chunkByYear((string) Clock::addDays($newestHeld, 1),
                     (string) Clock::addDays($windowStart, -1)) as $range) {
                $ranges[] = $range;
            }
        }

        // Then the revision window itself, always.
        $ranges[] = [$windowStart, $yesterday];

        $written = 0;
        foreach ($ranges as [$from, $to]) {
            if ($from > $to) {
                continue;
            }
            $written += $this->fetchArchiveRange($location, $from, $to);
        }

        return $written;
    }

    /** @param array<string,mixed> $location */
    private function fetchArchiveRange(array $location, string $from, string $to): int
    {
        $locationId = (int) $location['id'];
        $startedAt = \gmdate('Y-m-d H:i:s');

        $result = $this->client->archive(
            (float) $location['latitude'],
            (float) $location['longitude'],
            (string) $location['timezone'],
            $from,
            $to,
        );

        if (!$result->ok() || $result->json === null) {
            $this->recordRun($locationId, 'archive', $from, $to, $result->status, 0, 'failed',
                $result->errorText(), $startedAt);
            $this->note('archive ' . $from . '..' . $to . ' failed: ' . ($result->error ?? 'unknown'));
            return 0;
        }

        $daily = $result->json['daily'] ?? null;
        if (!\is_array($daily)) {
            $this->recordRun($locationId, 'archive', $from, $to, $result->status, 0, 'failed',
                'Response had no daily block.', $startedAt);
            return 0;
        }

        $rows = OpenMeteoClient::zipDaily($daily, OpenMeteoClient::ARCHIVE_DAILY);

        $settleBefore = (string) Clock::addDays(
            $this->app->clock()->todayFor((string) $location['timezone']),
            -$this->app->config()->int('weather.settle_days', 10)
        );

        $fetchedAt = \gmdate('Y-m-d H:i:s');
        $records = [];
        foreach ($rows as $row) {
            $obsDate = (string) $row['time'];
            $records[] = [
                $locationId,
                $obsDate,
                $row['temperature_2m_max'],
                $row['temperature_2m_min'],
                $row['temperature_2m_mean'],
                $row['precipitation_sum'],
                $row['precipitation_hours'],
                $row['et0_fao_evapotranspiration'],
                $row['shortwave_radiation_sum'],
                self::intOrNull($row['sunshine_duration']),
                self::intOrNull($row['daylight_duration']),
                $row['relative_humidity_2m_mean'],
                $row['relative_humidity_2m_min'],
                $row['vapour_pressure_deficit_max'],
                $row['wind_speed_10m_max'],
                $row['wind_gusts_10m_max'],
                $row['soil_moisture_0_to_7cm_mean'],
                $row['soil_temperature_0_to_7cm_mean'],
                self::intOrNull($row['weather_code']),
                'best_match',
                // Inside the revision window the value can still change;
                // older than settle_days it is settled (weather.md 6.2).
                $obsDate >= $settleBefore ? 1 : 0,
                $fetchedAt,
            ];
        }

        $written = $this->writeDaily($records);

        $this->recordRun($locationId, 'archive', $from, $to, $result->status, $written, 'ok',
            null, $startedAt);
        $this->note('archive ' . $from . '..' . $to . ': ' . $written . ' rows in '
            . $result->ms() . ' ms' . self::retryNote($result));

        // Elevation as the API resolved it, recorded once.
        if (isset($result->json['elevation']) && $location['elevation_m'] === null) {
            $this->db->run(
                'UPDATE `weather_location` SET `elevation_m` = :elevation WHERE `id` = :id',
                ['elevation' => (float) $result->json['elevation'], 'id' => $locationId]
            );
        }

        return $written;
    }

    /**
     * Forecast, plus the past_days rows that keep yesterday's MOTD from
     * having a hole while the archive's five-day lag catches up
     * (handoff Section 8.2).
     *
     * @param array<string,mixed> $location
     */
    public function syncForecast(array $location): int
    {
        $locationId = (int) $location['id'];
        $startedAt = \gmdate('Y-m-d H:i:s');
        $timezone = (string) $location['timezone'];
        $today = $this->app->clock()->todayFor($timezone);

        $result = $this->client->forecast(
            (float) $location['latitude'],
            (float) $location['longitude'],
            $timezone,
        );

        if (!$result->ok() || $result->json === null) {
            $this->recordRun($locationId, 'forecast', null, null, $result->status, 0, 'failed',
                $result->errorText(), $startedAt);
            $this->note('forecast failed: ' . ($result->error ?? 'unknown'));
            return 0;
        }

        $daily = $result->json['daily'] ?? null;
        if (!\is_array($daily)) {
            $this->recordRun($locationId, 'forecast', null, null, $result->status, 0, 'failed',
                'Response had no daily block.', $startedAt);
            return 0;
        }

        $rows = OpenMeteoClient::zipDaily($daily, OpenMeteoClient::FORECAST_DAILY);

        $hourly = $result->json['hourly'] ?? [];
        $soilMoisture = \is_array($hourly)
            ? OpenMeteoClient::hourlyDailyMean($hourly,
                ['soil_moisture_0_to_1cm', 'soil_moisture_1_to_3cm', 'soil_moisture_3_to_9cm'])
            : [];
        $soilTemp = \is_array($hourly)
            ? OpenMeteoClient::hourlyDailyMean($hourly, ['soil_temperature_0cm'])
            : [];

        $issuedAt = \gmdate('Y-m-d H:i:s');
        $forecastRows = [];
        $pastRows = [];

        foreach ($rows as $row) {
            $date = (string) $row['time'];

            if ($date >= $today) {
                $forecastRows[] = [
                    $locationId, $date, $issuedAt,
                    $row['temperature_2m_max'], $row['temperature_2m_min'],
                    $row['precipitation_sum'],
                    self::intOrNull($row['precipitation_probability_max']),
                    $row['precipitation_hours'],
                    $row['et0_fao_evapotranspiration'],
                    $row['relative_humidity_2m_mean'],
                    $row['wind_speed_10m_max'],
                    $soilMoisture[$date] ?? null,
                    $soilTemp[$date] ?? null,
                    self::intOrNull($row['weather_code']),
                ];
                continue;
            }

            // past_days: written into weather_daily as provisional and marked
            // source_model='forecast_past', so the archive run overwrites them
            // the moment ERA5 arrives (handoff Section 8.2).
            $pastRows[] = [
                $locationId, $date,
                $row['temperature_2m_max'], $row['temperature_2m_min'], null,
                $row['precipitation_sum'], $row['precipitation_hours'],
                $row['et0_fao_evapotranspiration'], null, null, null,
                $row['relative_humidity_2m_mean'], null, null,
                $row['wind_speed_10m_max'], null,
                $soilMoisture[$date] ?? null,
                $soilTemp[$date] ?? null,
                self::intOrNull($row['weather_code']),
                self::FORECAST_PAST,
                1,
                $issuedAt,
            ];
        }

        $written = 0;

        if ($forecastRows !== []) {
            // Overwritten each run (handoff Section 5.7).
            $this->db->run('DELETE FROM `weather_forecast` WHERE `location_id` = :id',
                ['id' => $locationId]);

            $columns = ['location_id', 'forecast_date', 'issued_at', 'temp_max_c', 'temp_min_c',
                        'precip_mm', 'precip_prob_pct', 'precip_hours', 'et0_mm', 'rh_mean_pct',
                        'wind_max_kmh', 'soil_moist_0_7', 'soil_temp_0_7_c', 'weather_code'];
            foreach (\array_chunk($forecastRows, $this->chunkSize()) as $chunk) {
                $this->db->upsertChunk('weather_forecast', $columns, $chunk,
                    \array_slice($columns, 2));
                $written += \count($chunk);
            }

            // The hash of the three-day block drives MOTD re-post when the
            // forecast changes materially (handoff Section 4.2).
            $this->updateForecastHash($locationId, $today);
        }

        if ($pastRows !== []) {
            // These exist only to fill the hole the archive's five-day lag
            // leaves. The arrow points one way: the archive overwrites them
            // when ERA5 arrives, never the reverse (handoff Section 8.2).
            $written += $this->writeDaily($pastRows, true);
        }

        $this->recordRun($locationId, 'forecast', $today, null, $result->status, $written, 'ok',
            null, $startedAt);
        $this->note('forecast: ' . $written . ' rows in ' . $result->ms() . ' ms'
            . self::retryNote($result));

        return $written;
    }

    /**
     * @param list<array<int,mixed>> $records
     * @param bool $onlyIfProvisional keep a settled archive row rather than
     *        letting a forecast-derived value overwrite it
     */
    private function writeDaily(array $records, bool $onlyIfProvisional = false): int
    {
        if ($records === []) {
            return 0;
        }

        $columns = ['location_id', 'obs_date', 'temp_max_c', 'temp_min_c', 'temp_mean_c',
                    'precip_mm', 'precip_hours', 'et0_mm', 'radiation_mj', 'sunshine_s',
                    'daylight_s', 'rh_mean_pct', 'rh_min_pct', 'vpd_max_kpa', 'wind_max_kmh',
                    'gust_max_kmh', 'soil_moist_0_7', 'soil_temp_0_7_c', 'weather_code',
                    'source_model', 'is_provisional', 'fetched_at'];

        $updatable = \array_slice($columns, 2);

        $written = 0;
        foreach (\array_chunk($records, $this->chunkSize()) as $chunk) {
            if ($onlyIfProvisional) {
                $this->upsertProvisionalOnly($columns, $chunk);
            } else {
                $this->db->upsertChunk('weather_daily', $columns, $chunk, $updatable);
            }
            $written += \count($chunk);
        }

        return $written;
    }

    /**
     * A forecast-derived row may fill a hole, or refresh an earlier
     * forecast-derived row, but must never clobber an archive value --
     * including one still inside the revision window, which is provisional
     * but is already the better number (weather.md Section 6.2).
     *
     * MySQL has no conditional ON DUPLICATE KEY, so the assignment is a CASE
     * that keeps the stored value unless it too came from a forecast.
     *
     * @param list<string> $columns
     * @param list<array<int,mixed>> $chunk
     */
    private function upsertProvisionalOnly(array $columns, array $chunk): void
    {
        $quoted = \implode(', ', \array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $tuple = '(' . \implode(', ', \array_fill(0, \count($columns), '?')) . ')';
        $values = \implode(', ', \array_fill(0, \count($chunk), $tuple));

        $assignments = [];
        foreach (\array_slice($columns, 2) as $column) {
            $assignments[] = \sprintf(
                '`%1$s` = CASE WHEN `source_model` = %2$s THEN VALUES(`%1$s`) ELSE `%1$s` END',
                $column,
                "'" . self::FORECAST_PAST . "'"
            );
        }

        $sql = 'INSERT INTO `weather_daily` (' . $quoted . ') VALUES ' . $values
            . ' ON DUPLICATE KEY UPDATE ' . \implode(', ', $assignments);

        $flat = [];
        foreach ($chunk as $row) {
            foreach ($row as $cell) {
                $flat[] = $cell;
            }
        }

        $this->db->pdo()->prepare($sql)->execute($flat);
    }

    private function updateForecastHash(int $locationId, string $today): void
    {
        $rows = $this->db->all(
            'SELECT `forecast_date`, `temp_max_c`, `temp_min_c`, `precip_mm`, `precip_prob_pct`'
            . ' FROM `weather_forecast` WHERE `location_id` = :id AND `forecast_date` >= :today'
            . ' ORDER BY `forecast_date` LIMIT 3',
            ['id' => $locationId, 'today' => $today]
        );
        $this->db->run(
            'UPDATE `weather_location` SET `forecast_hash` = :hash WHERE `id` = :id',
            ['hash' => \Carl\Controller\MenuController::forecastHash($rows), 'id' => $locationId]
        );
    }

    /**
     * Rows older than settle_days stop being provisional and are never
     * re-fetched again (weather.md Section 6.2).
     */
    public function settleProvisional(): int
    {
        $settleDays = $this->app->config()->int('weather.settle_days', 10);
        return $this->db->run(
            'UPDATE `weather_daily` SET `is_provisional` = 0'
            . ' WHERE `is_provisional` = 1 AND `obs_date` < (UTC_DATE() - INTERVAL :days DAY)'
            // A forecast-derived row is never settled: it is a placeholder
            // waiting for the archive to replace it.
            . '   AND `source_model` <> :forecast_past',
            ['days' => $settleDays, 'forecast_past' => self::FORECAST_PAST]
        )->rowCount();
    }

    /** An unpruned log table on a nightly job is a slow-growing bug. */
    public function prune(): int
    {
        $days = $this->app->config()->int('weather.run_retention_days', 90);
        return $this->db->run(
            'DELETE FROM `weather_sync_run` WHERE `started_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $days]
        )->rowCount();
    }

    private function recordRun(
        ?int $locationId,
        string $kind,
        ?string $rangeStart,
        ?string $rangeEnd,
        ?int $httpStatus,
        int $rows,
        string $outcome,
        ?string $error = null,
        ?string $startedAt = null,
    ): void {
        $this->db->run(
            'INSERT INTO `weather_sync_run`'
            . ' (location_id, kind, started_at, finished_at, range_start, range_end,'
            . '  http_status, rows_upserted, outcome, error_text)'
            . ' VALUES (:location_id, :kind, :started_at, UTC_TIMESTAMP(), :range_start,'
            . '  :range_end, :http_status, :rows, :outcome, :error)',
            [
                'location_id' => $locationId,
                'kind'        => $kind,
                'started_at'  => $startedAt ?? \gmdate('Y-m-d H:i:s'),
                'range_start' => $rangeStart,
                'range_end'   => $rangeEnd,
                'http_status' => $httpStatus,
                'rows'        => $rows,
                'outcome'     => $outcome,
                'error'       => $error,
            ]
        );
    }

    private function chunkSize(): int
    {
        return $this->app->config()->int('weather.upsert_chunk_rows', 200);
    }

    private function note(string $message): void
    {
        $this->log[] = $message;
    }

    /**
     * Split a date range into calendar years. A five-year daily backfill is
     * well under a megabyte, but memory_limit is 128M and max_execution_time
     * is 30 s, so the job chunks either way and the CLI and browser entry
     * points stay interchangeable (weather.md Sections 2 and 3.1).
     *
     * @return list<array{0:string,1:string}>
     */
    public static function chunkByYear(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        $ranges = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $yearEnd = \substr($cursor, 0, 4) . '-12-31';
            $end = $yearEnd < $to ? $yearEnd : $to;
            $ranges[] = [$cursor, $end];
            $cursor = (string) Clock::addDays($end, 1);
        }
        return $ranges;
    }

    /** A retry cost 30 seconds; saying so is what makes it visible. */
    private static function retryNote(\Carl\Core\HttpResult $result): string
    {
        return $result->attempts > 1 ? ' (after ' . $result->attempts . ' attempts)' : '';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
