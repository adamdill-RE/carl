<?php

declare(strict_types=1);

namespace Carl\Weather;

use Carl\Core\Config;
use Carl\Core\HttpClient;
use Carl\Core\HttpResult;

/**
 * The Open-Meteo contract (weather.md Section 4).
 *
 * The response is column-oriented -- parallel arrays indexed by position --
 * so the parser zips them by index and asserts every array is the same length
 * as `time` before iterating. A silently short array produces silently wrong
 * rows, which is worse than a failed run.
 */
final class OpenMeteoClient
{
    /** weather.md Section 5.1: store all of these from day one. */
    public const ARCHIVE_DAILY = [
        'temperature_2m_max', 'temperature_2m_min', 'temperature_2m_mean',
        'precipitation_sum', 'precipitation_hours', 'et0_fao_evapotranspiration',
        'shortwave_radiation_sum', 'sunshine_duration', 'daylight_duration',
        'relative_humidity_2m_mean', 'relative_humidity_2m_min',
        'vapour_pressure_deficit_max', 'wind_speed_10m_max', 'wind_gusts_10m_max',
        'weather_code', 'soil_moisture_0_to_7cm_mean', 'soil_temperature_0_to_7cm_mean',
    ];

    /** The forecast columns of handoff Section 5.7. */
    public const FORECAST_DAILY = [
        'temperature_2m_max', 'temperature_2m_min', 'precipitation_sum',
        'precipitation_probability_max', 'precipitation_hours',
        'et0_fao_evapotranspiration', 'relative_humidity_2m_mean',
        'wind_speed_10m_max', 'weather_code',
    ];

    /**
     * The forecast endpoint has no daily soil aggregate, so the shallow
     * hourly layers are fetched and averaged per day (handoff Section 8.2).
     */
    public const FORECAST_HOURLY = [
        'soil_moisture_0_to_1cm', 'soil_moisture_1_to_3cm', 'soil_moisture_3_to_9cm',
        'soil_temperature_0cm',
    ];

    public function __construct(private HttpClient $http, private Config $config)
    {
    }

    public function archive(float $latitude, float $longitude, string $timezone, string $from, string $to): HttpResult
    {
        return $this->http->getJsonWithRetry(
            $this->config->string('weather.archive_url'),
            [
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'start_date' => $from,
                'end_date'   => $to,
                // Required when daily is used; daily buckets are then
                // local-calendar days (weather.md Section 4.1).
                'timezone'  => $timezone,
                'daily'     => \implode(',', self::ARCHIVE_DAILY),
                // 'land' is correct for a growing site.
                'cell_selection' => 'land',
                // No temperature_unit / wind_speed_unit / precipitation_unit:
                // everything arrives SI and converts at display (Section 6.3).
            ],
            $this->config->int('weather.retry_delay', 30),
        );
    }

    public function forecast(float $latitude, float $longitude, string $timezone): HttpResult
    {
        return $this->http->getJsonWithRetry(
            $this->config->string('weather.forecast_url'),
            [
                'latitude'      => $latitude,
                'longitude'     => $longitude,
                'timezone'      => $timezone,
                'forecast_days' => $this->config->int('weather.forecast_days', 7),
                // past_days rows fill yesterday's hole while the archive's
                // five-day lag catches up (handoff Section 8.2).
                'past_days'     => $this->config->int('weather.past_days', 7),
                'daily'         => \implode(',', self::FORECAST_DAILY),
                'hourly'        => \implode(',', self::FORECAST_HOURLY),
                'cell_selection' => 'land',
            ],
            $this->config->int('weather.retry_delay', 30),
        );
    }

    /**
     * Zip the parallel arrays by index.
     *
     * @param array<string,mixed> $block the `daily` object from a response
     * @param list<string> $variables
     * @return list<array<string,mixed>> one row per date, with a 'time' key
     * @throws \RuntimeException when an array is not the same length as time
     */
    public static function zipDaily(array $block, array $variables): array
    {
        $time = $block['time'] ?? null;
        if (!\is_array($time)) {
            throw new \RuntimeException('The response has no daily time array.');
        }
        $count = \count($time);

        foreach ($variables as $variable) {
            if (!\array_key_exists($variable, $block)) {
                continue;   // the provider may not carry every variable
            }
            if (!\is_array($block[$variable]) || \count($block[$variable]) !== $count) {
                throw new \RuntimeException(
                    'Daily array "' . $variable . '" has '
                    . (\is_array($block[$variable]) ? \count($block[$variable]) : 'no')
                    . ' values against ' . $count . ' dates. Refusing to write partial rows.'
                );
            }
        }

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $row = ['time' => (string) $time[$i]];
            foreach ($variables as $variable) {
                // null is a legitimate value, not an error, and must not be
                // coerced to 0: a null ET0 and a zero ET0 mean opposite
                // things (weather.md Section 4.2).
                $row[$variable] = \array_key_exists($variable, $block)
                    ? ($block[$variable][$i] ?? null)
                    : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Average hourly values into one number per local calendar day.
     *
     * @param array<string,mixed> $block the `hourly` object
     * @param list<string> $variables averaged together into one series
     * @return array<string,float|null> date => mean
     */
    public static function hourlyDailyMean(array $block, array $variables): array
    {
        $time = $block['time'] ?? null;
        if (!\is_array($time)) {
            return [];
        }

        $sums = [];
        $counts = [];
        foreach ($time as $index => $stamp) {
            $date = \substr((string) $stamp, 0, 10);
            foreach ($variables as $variable) {
                $series = $block[$variable] ?? null;
                if (!\is_array($series) || !\array_key_exists($index, $series)) {
                    continue;
                }
                $value = $series[$index];
                if ($value === null) {
                    continue;
                }
                $sums[$date] = ($sums[$date] ?? 0.0) + (float) $value;
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($sums as $date => $sum) {
            $out[$date] = $counts[$date] > 0 ? $sum / $counts[$date] : null;
        }
        return $out;
    }
}
