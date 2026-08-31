<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;

/**
 * Weather reads and location bookkeeping.
 *
 * Global by design: one row per distinct ZIP among active users, shared by
 * every user at that ZIP (weather.md Section 7.2 -- attach the location to
 * the site, not to the individual plant, so a hundred plants share one series
 * and one API call).
 *
 * Nothing here calls an API. Pages read these tables and nothing else
 * (weather.md Section 3, rule 2).
 */
final class WeatherRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findLocation(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `weather_location` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * One location per distinct set of coordinates. backfill_from starts at
     * the earliest date anything is planted there; it is pushed back when a
     * user backdates a planting, and the nightly run fetches the gap
     * (handoff Section 8.1).
     */
    public function ensureLocation(
        string $label,
        string $zip,
        float $latitude,
        float $longitude,
        string $timezone,
        string $backfillFrom,
    ): int {
        $this->db->run(
            'INSERT INTO `weather_location` (label, zip, latitude, longitude, timezone,'
            . ' backfill_from, is_active, created_at)'
            . ' VALUES (:label, :zip, :lat, :lon, :tz, :backfill, 1, UTC_TIMESTAMP())'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `is_active` = 1,'
            . ' `backfill_from` = LEAST(`backfill_from`, VALUES(`backfill_from`))',
            [
                'label'    => \substr($label, 0, 120),
                'zip'      => $zip,
                'lat'      => \round($latitude, 5),
                'lon'      => \round($longitude, 5),
                'tz'       => $timezone,
                'backfill' => $backfillFrom,
            ]
        );
        return $this->db->insertId();
    }

    /**
     * Pull backfill_from back when a planting is backdated past it. The
     * nightly run picks the gap up; nothing fetches on the request path.
     *
     * @return bool whether the window actually moved
     */
    public function extendBackfill(int $locationId, string $earliestDate): bool
    {
        return $this->db->run(
            'UPDATE `weather_location` SET `backfill_from` = :from'
            . ' WHERE `id` = :id AND `backfill_from` > :from_cmp',
            ['from' => $earliestDate, 'id' => $locationId, 'from_cmp' => $earliestDate]
        )->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function activeLocations(): array
    {
        return $this->db->all('SELECT * FROM `weather_location` WHERE `is_active` = 1 ORDER BY `id`');
    }

    /**
     * The MOTD matrix: the last N observed days and the next N forecast days
     * for one location, in two statements.
     *
     * @return array{recent:list<array<string,mixed>>,forecast:list<array<string,mixed>>}
     */
    public function motd(int $locationId, string $today, int $recentDays = 3, int $forecastDays = 3): array
    {
        $recent = $this->db->all(
            'SELECT * FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` < :today'
            . ' ORDER BY `obs_date` DESC LIMIT ' . (int) $recentDays,
            ['location_id' => $locationId, 'today' => $today]
        );

        $forecast = $this->db->all(
            'SELECT * FROM `weather_forecast`'
            . ' WHERE `location_id` = :location_id AND `forecast_date` >= :today'
            . ' ORDER BY `forecast_date` LIMIT ' . (int) $forecastDays,
            ['location_id' => $locationId, 'today' => $today]
        );

        return ['recent' => \array_reverse($recent), 'forecast' => $forecast];
    }

    /**
     * The daily series over a planting's in-ground period, for the plant
     * report. One statement (weather.md Section 7.2).
     *
     * @return list<array<string,mixed>>
     */
    public function series(int $locationId, string $from, string $to): array
    {
        return $this->db->all(
            'SELECT `obs_date`, `temp_max_c`, `temp_min_c`, `temp_mean_c`, `precip_mm`,'
            . ' `precip_hours`, `et0_mm`, `water_balance_mm`, `rh_mean_pct`, `soil_moist_0_7`,'
            . ' `soil_temp_0_7_c`, `weather_code`, `source_model`, `is_provisional`'
            . ' FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` BETWEEN :from AND :to'
            . ' ORDER BY `obs_date`',
            ['location_id' => $locationId, 'from' => $from, 'to' => $to]
        );
    }

    /**
     * Which dates inside a covered range are missing. A report renders the
     * gap and says so rather than pretending (handoff Section 8.1).
     */
    public function gapCount(int $locationId, string $from, string $to): int
    {
        $held = (int) $this->db->value(
            'SELECT COUNT(*) FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` BETWEEN :from AND :to',
            ['location_id' => $locationId, 'from' => $from, 'to' => $to],
            0
        );
        $expected = (int) \max(0, (\strtotime($to) - \strtotime($from)) / 86400 + 1);
        return \max(0, $expected - $held);
    }

    /**
     * One chunk of the weather CSV export (handoff Section 13.3).
     *
     * Weather is global by design -- one series per ZIP, shared by everyone
     * at that ZIP -- so it does not extend Repository and cannot be scoped by
     * user_id. The scope is the caller's own weather_location_id, which is
     * the same scope the MOTD and the plant report already read through.
     *
     * Keyset on obs_date, which is the natural key's second half.
     *
     * @return list<array<string,mixed>>
     */
    public function exportChunk(int $locationId, string $afterDate, int $limit): array
    {
        return $this->db->all(
            'SELECT `obs_date`, `temp_max_c`, `temp_min_c`, `temp_mean_c`, `precip_mm`,'
            . ' `precip_hours`, `et0_mm`, `water_balance_mm`, `radiation_mj`, `sunshine_s`,'
            . ' `daylight_s`, `rh_mean_pct`, `rh_min_pct`, `vpd_max_kpa`, `wind_max_kmh`,'
            . ' `gust_max_kmh`, `soil_moist_0_7`, `soil_temp_0_7_c`, `weather_code`,'
            . ' `source_model`, `is_provisional`'
            . ' FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` > :after_date'
            . ' ORDER BY `obs_date` LIMIT ' . (int) $limit,
            ['location_id' => $locationId, 'after_date' => $afterDate]
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeAlerts(int $locationId): array
    {
        return $this->db->all(
            'SELECT * FROM `weather_alert`'
            . ' WHERE `location_id` = :location_id AND `is_active` = 1'
            . '   AND (`expires` IS NULL OR `expires` > UTC_TIMESTAMP())'
            . ' ORDER BY `onset`',
            ['location_id' => $locationId]
        );
    }

    /**
     * The four numbers that are the whole health picture (weather.md 3.2):
     * last successful run per location, newest obs_date held, missing dates
     * inside the covered range, and the last non-200 status seen.
     *
     * @return list<array<string,mixed>>
     */
    public function health(): array
    {
        return $this->db->all(
            'SELECT l.id, l.label, l.zip, l.timezone, l.backfill_from,'
            . ' (SELECT MAX(d.obs_date) FROM `weather_daily` d WHERE d.location_id = l.id) AS newest_obs,'
            . ' (SELECT COUNT(*) FROM `weather_daily` d WHERE d.location_id = l.id) AS days_held,'
            . ' (SELECT MAX(f.forecast_date) FROM `weather_forecast` f WHERE f.location_id = l.id) AS newest_forecast,'
            . ' (SELECT MAX(r.started_at) FROM `weather_sync_run` r'
            . "    WHERE r.location_id = l.id AND r.outcome = 'ok') AS last_ok,"
            . ' (SELECT r2.http_status FROM `weather_sync_run` r2'
            . '    WHERE r2.location_id = l.id AND r2.http_status IS NOT NULL AND r2.http_status <> 200'
            . '    ORDER BY r2.started_at DESC LIMIT 1) AS last_bad_status,'
            . ' (SELECT r3.error_text FROM `weather_sync_run` r3'
            . "    WHERE r3.location_id = l.id AND r3.outcome <> 'ok'"
            . '    ORDER BY r3.started_at DESC LIMIT 1) AS last_error'
            . ' FROM `weather_location` l WHERE l.is_active = 1 ORDER BY l.id'
        );
    }

    /**
     * The attribution line, generated from the data rather than hard-coded,
     * which keeps it honest (weather.md Section 10).
     *
     * @return list<string> the distinct source models in a date range
     */
    public function sourceModels(int $locationId, string $from, string $to): array
    {
        $values = $this->db->column(
            'SELECT DISTINCT `source_model` FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` BETWEEN :from AND :to',
            ['location_id' => $locationId, 'from' => $from, 'to' => $to]
        );
        return \array_map(\strval(...), $values);
    }

    /**
     * The same series, rolled up a week at a time (Phase 5 handoff Section
     * 3.1: "summarise the weather into weekly rows before sending").
     *
     * Measured 2026-08-31: a five-year account's daily weather is 827 KB of
     * `/export/claude.json`, roughly 230,000 tokens on its own, and almost
     * none of it is signal -- what a season review needs from June is how hot
     * and how dry June was, not 30 rows of it. Weekly rows are 1/35th of the
     * bytes and carry the same answer. `deploy.md` Section 0.9 has the
     * numbers.
     *
     * Weeks are anchored to `$from` rather than to a calendar week, by
     * DATEDIFF and integer division. YEARWEEK would work on both engines but
     * its mode argument is a footgun, and a week that starts on the day the
     * window starts is easier to read back against the covered range than one
     * that starts on an arbitrary Monday.
     *
     * One statement, whatever the range (hosting Section 9).
     *
     * @return list<array<string,mixed>>
     */
    public function weeklySummary(int $locationId, string $from, string $to): array
    {
        return $this->db->all(
            'SELECT MIN(`obs_date`) AS week_start, MAX(`obs_date`) AS week_end,'
            . ' COUNT(*) AS days,'
            . ' ROUND(AVG(`temp_max_c`), 1) AS temp_max_c_mean,'
            . ' MAX(`temp_max_c`) AS temp_max_c_high,'
            . ' ROUND(AVG(`temp_min_c`), 1) AS temp_min_c_mean,'
            . ' MIN(`temp_min_c`) AS temp_min_c_low,'
            . ' ROUND(SUM(`precip_mm`), 1) AS precip_mm,'
            . ' ROUND(SUM(`et0_mm`), 1) AS et0_mm,'
            . ' ROUND(SUM(`precip_mm`) - SUM(`et0_mm`), 1) AS water_balance_mm,'
            . ' ROUND(AVG(`rh_mean_pct`), 0) AS rh_mean_pct,'
            . ' SUM(`is_provisional`) AS provisional_days'
            . ' FROM `weather_daily`'
            . ' WHERE `location_id` = :location_id AND `obs_date` BETWEEN :from AND :to'
            . ' GROUP BY FLOOR(DATEDIFF(`obs_date`, :from_group) / 7)'
            . ' ORDER BY week_start',
            [
                'location_id' => $locationId,
                'from'        => $from,
                'to'          => $to,
                // With emulation off a named placeholder cannot be reused
                // (hosting Section 7), and this one is needed twice.
                'from_group'  => $from,
            ]
        );
    }
}
