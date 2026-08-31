<?php

/**
 * The weather sync's own rules, tested against a stub provider so the suite
 * neither flakes on a third party nor spends the per-IP quota the nightly job
 * depends on -- hourly as well as daily (weather.md Section 8.1).
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Core\HttpClient;
use Carl\Core\HttpResult;
use Carl\Support\Clock;
use Carl\Weather\OpenMeteoClient;
use Carl\Weather\WeatherProvider;
use Carl\Weather\WeatherSync;

$db = $app->db();

/** A provider that answers from canned data and records what it was asked. */
$provider = new class implements WeatherProvider {
    /** @var list<array{from:string,to:string}> */
    public array $archiveCalls = [];
    public int $forecastCalls = 0;
    public ?HttpResult $archiveOverride = null;

    public function archive(float $lat, float $lon, string $tz, string $from, string $to): HttpResult
    {
        $this->archiveCalls[] = ['from' => $from, 'to' => $to];
        if ($this->archiveOverride !== null) {
            return $this->archiveOverride;
        }

        $daily = ['time' => []];
        foreach (OpenMeteoClient::ARCHIVE_DAILY as $variable) {
            $daily[$variable] = [];
        }

        $cursor = $from;
        $day = 0;
        while ($cursor <= $to && $day < 400) {
            $daily['time'][] = $cursor;
            foreach (OpenMeteoClient::ARCHIVE_DAILY as $variable) {
                $daily[$variable][] = match ($variable) {
                    'temperature_2m_max' => 30.0 + ($day % 5),
                    'temperature_2m_min' => 18.0 + ($day % 3),
                    'precipitation_sum'  => $day % 4 === 0 ? 5.0 : 0.0,
                    'et0_fao_evapotranspiration' => 6.0,
                    'weather_code'       => 3,
                    // null is a legitimate value and must survive as NULL.
                    'vapour_pressure_deficit_max' => null,
                    default              => 1.0,
                };
            }
            $cursor = (string) Clock::addDays($cursor, 1);
            $day++;
        }

        return (new HttpResult('stub://archive', 200, '{}', 0.01, null))
            ->withJson(['daily' => $daily, 'elevation' => 183.0]);
    }

    public function forecast(float $lat, float $lon, string $tz): HttpResult
    {
        $this->forecastCalls++;

        $today = \gmdate('Y-m-d');
        $daily = ['time' => []];
        foreach (OpenMeteoClient::FORECAST_DAILY as $variable) {
            $daily[$variable] = [];
        }

        // Three days back and three forward, so both halves are exercised.
        for ($offset = -3; $offset <= 3; $offset++) {
            $daily['time'][] = (string) Clock::addDays($today, $offset);
            foreach (OpenMeteoClient::FORECAST_DAILY as $variable) {
                $daily[$variable][] = match ($variable) {
                    'temperature_2m_max' => 99.0,   // deliberately distinctive
                    'temperature_2m_min' => 11.0,
                    'precipitation_probability_max' => 40,
                    'weather_code' => 61,
                    default => 2.0,
                };
            }
        }

        $hourly = ['time' => [], 'soil_moisture_0_to_1cm' => [], 'soil_moisture_1_to_3cm' => [],
                   'soil_moisture_3_to_9cm' => [], 'soil_temperature_0cm' => []];
        foreach ($daily['time'] as $date) {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourly['time'][] = $date . 'T' . \str_pad((string) $hour, 2, '0', \STR_PAD_LEFT) . ':00';
                $hourly['soil_moisture_0_to_1cm'][] = 0.10;
                $hourly['soil_moisture_1_to_3cm'][] = 0.20;
                $hourly['soil_moisture_3_to_9cm'][] = 0.30;
                $hourly['soil_temperature_0cm'][] = 25.0;
            }
        }

        return (new HttpResult('stub://forecast', 200, '{}', 0.01, null))
            ->withJson(['daily' => $daily, 'hourly' => $hourly]);
    }
};

$today = \gmdate('Y-m-d');

/** A fresh location per test, so nothing leaks between them. */
$makeLocation = static function (string $backfillFrom) use ($db): array {
    $lat = \round(20 + (\random_int(0, 900000) / 100000), 5);
    $lon = \round(-120 + (\random_int(0, 900000) / 100000), 5);
    $db->run(
        'INSERT INTO `weather_location` (label, zip, latitude, longitude, timezone,'
        . ' backfill_from, is_active, created_at)'
        . " VALUES ('Test', '00000', :lat, :lon, 'UTC', :backfill, 1, UTC_TIMESTAMP())",
        ['lat' => $lat, 'lon' => $lon, 'backfill' => $backfillFrom]
    );
    $id = $db->insertId();
    return (array) $db->one('SELECT * FROM `weather_location` WHERE `id` = :id', ['id' => $id]);
};

$t->group('Chunking and the fetch plan');

$t->test('a range inside one year is one chunk', function ($t): void {
    $t->same([['2026-03-01', '2026-08-31']], WeatherSync::chunkByYear('2026-03-01', '2026-08-31'));
});

$t->test('a multi-year backfill is chunked by calendar year', function ($t): void {
    $chunks = WeatherSync::chunkByYear('2024-06-15', '2026-02-10');
    $t->same([
        ['2024-06-15', '2024-12-31'],
        ['2025-01-01', '2025-12-31'],
        ['2026-01-01', '2026-02-10'],
    ], $chunks);
});

$t->test('an inverted range asks for nothing', function ($t): void {
    $t->same([], WeatherSync::chunkByYear('2026-08-31', '2026-01-01'));
});

$t->group('The archive run');

$t->test('a first run fetches the backfill and the revision window',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $provider->archiveCalls = [];
    $location = $makeLocation((string) Clock::addDays($today, -40));

    $sync = new WeatherSync($app, $provider);
    $written = $sync->syncArchive($location);

    $t->ok($written > 0, 'rows were written');
    $t->ok(\count($provider->archiveCalls) >= 2, 'the gap and the window are separate calls');

    // The revision window is always the last thing asked for, and always ends
    // yesterday (weather.md Section 6.2).
    $last = \end($provider->archiveCalls);
    $t->same((string) Clock::addDays($today, -1), $last['to']);
    $t->same((string) Clock::addDays($today, -14), $last['from']);

    $held = (int) $db->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0
    );
    $t->same(40, $held, 'every day from backfill_from to yesterday is held');
});

$t->test('a null variable is stored as NULL, never coerced to zero',
    function ($t) use ($app, $provider, $makeLocation, $db, $today): void {
    $location = $makeLocation((string) Clock::addDays($today, -5));
    (new WeatherSync($app, $provider))->syncArchive($location);

    $row = $db->one(
        'SELECT `vpd_max_kpa`, `et0_mm` FROM `weather_daily` WHERE `location_id` = :id LIMIT 1',
        ['id' => $location['id']]
    );
    $t->same(null, $row['vpd_max_kpa'], 'a null ET stays null');
    $t->ok($row['et0_mm'] !== null, 'a real value is still stored');
});

$t->test('the water balance is computed by the database, not by PHP',
    function ($t) use ($app, $provider, $makeLocation, $db, $today): void {
    $location = $makeLocation((string) Clock::addDays($today, -5));
    (new WeatherSync($app, $provider))->syncArchive($location);

    $row = $db->one(
        'SELECT `precip_mm`, `et0_mm`, `water_balance_mm` FROM `weather_daily`'
        . ' WHERE `location_id` = :id AND `precip_mm` > 0 LIMIT 1',
        ['id' => $location['id']]
    );
    $t->ok($row !== null, 'a day with rain exists');
    $expected = (float) $row['precip_mm'] - (float) $row['et0_mm'];
    $t->ok(\abs((float) $row['water_balance_mm'] - $expected) < 0.01,
        'the VIRTUAL column agrees with precip minus ET0');
});

$t->test('a second run re-fetches only the revision window',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -40));
    $sync = new WeatherSync($app, $provider);
    $sync->syncArchive($location);

    $before = (int) $db->value('SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0);

    $provider->archiveCalls = [];
    $location = (array) $db->one('SELECT * FROM `weather_location` WHERE `id` = :id',
        ['id' => $location['id']]);
    $sync->syncArchive($location);

    $t->same(1, \count($provider->archiveCalls), 'only the revision window is re-fetched');

    $after = (int) $db->value('SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0);
    $t->same($before, $after, 'the run is idempotent: no duplicate rows');
});

$t->test('a hole left by a stopped cron is filled on the next run',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -60));
    $sync = new WeatherSync($app, $provider);
    $sync->syncArchive($location);

    // Delete a stretch well outside the revision window, as a cron outage
    // would have left it.
    $db->run(
        'DELETE FROM `weather_daily` WHERE `location_id` = :id AND `obs_date` > :from',
        ['id' => $location['id'], 'from' => (string) Clock::addDays($today, -50)]
    );

    $location = (array) $db->one('SELECT * FROM `weather_location` WHERE `id` = :id',
        ['id' => $location['id']]);
    $sync->syncArchive($location);

    $held = (int) $db->value('SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0);
    $t->same(60, $held, 'the gap healed without a cursor to remember where it was');
});

$t->test('a failed fetch writes a run row and nothing else',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -5));
    $provider->archiveOverride = (new HttpResult('stub://archive', 429, '{}', 0.01, null))
        ->withError('Provider error: Daily API request limit exceeded.');

    (new WeatherSync($app, $provider))->syncArchive($location);
    $provider->archiveOverride = null;

    $rows = (int) $db->value('SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0);
    $t->same(0, $rows, 'a partial row set is worse than no rows');

    $run = $db->one(
        'SELECT * FROM `weather_sync_run` WHERE `location_id` = :id ORDER BY `id` DESC LIMIT 1',
        ['id' => $location['id']]
    );
    $t->same('failed', $run['outcome']);
    $t->same(429, (int) $run['http_status']);
    $t->contains('limit exceeded', (string) $run['error_text']);
});

$t->test('a response whose arrays disagree with time is refused outright',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -5));

    $daily = ['time' => ['2026-08-01', '2026-08-02', '2026-08-03']];
    foreach (OpenMeteoClient::ARCHIVE_DAILY as $variable) {
        $daily[$variable] = [1.0, 2.0, 3.0];
    }
    $daily['temperature_2m_max'] = [1.0];   // silently short

    $provider->archiveOverride = (new HttpResult('stub://archive', 200, '{}', 0.01, null))
        ->withJson(['daily' => $daily]);

    // The whole run for this location fails rather than writing wrong rows.
    (new WeatherSync($app, $provider))->run(['archive'], (int) $location['id']);
    $provider->archiveOverride = null;

    $rows = (int) $db->value('SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id',
        ['id' => $location['id']], 0);
    $t->same(0, $rows);

    $run = $db->one(
        'SELECT * FROM `weather_sync_run` WHERE `location_id` = :id ORDER BY `id` DESC LIMIT 1',
        ['id' => $location['id']]
    );
    $t->same('failed', $run['outcome']);
    $t->contains('Refusing to write partial rows', (string) $run['error_text']);
});

$t->group('The forecast run');

$t->test('forward days become forecast rows and past days fill weather_daily',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -2));
    (new WeatherSync($app, $provider))->syncForecast($location);

    $forecast = (int) $db->value(
        'SELECT COUNT(*) FROM `weather_forecast` WHERE `location_id` = :id',
        ['id' => $location['id']], 0
    );
    $t->same(4, $forecast, 'today and the three days ahead');

    $past = (int) $db->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id AND `source_model` = :model',
        ['id' => $location['id'], 'model' => WeatherSync::FORECAST_PAST], 0
    );
    $t->same(3, $past, 'the three past days keep yesterday from being a hole');
});

$t->test('hourly soil layers are averaged into one number per day',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -2));
    (new WeatherSync($app, $provider))->syncForecast($location);

    $value = $db->value(
        'SELECT `soil_moist_0_7` FROM `weather_forecast` WHERE `location_id` = :id LIMIT 1',
        ['id' => $location['id']]
    );
    // (0.10 + 0.20 + 0.30) / 3
    $t->ok(\abs((float) $value - 0.2) < 0.001, 'got ' . $value);
});

$t->test('a forecast row never overwrites an archive row, even a provisional one',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    // weather.md Section 6.2: the reanalysis supersedes the forecast-derived
    // figure. The arrow points one way.
    $location = $makeLocation((string) Clock::addDays($today, -10));
    $sync = new WeatherSync($app, $provider);
    $sync->syncArchive($location);

    $yesterday = (string) Clock::addDays($today, -1);
    $before = $db->one(
        'SELECT `temp_max_c`, `source_model`, `is_provisional` FROM `weather_daily`'
        . ' WHERE `location_id` = :id AND `obs_date` = :d',
        ['id' => $location['id'], 'd' => $yesterday]
    );
    $t->same('best_match', $before['source_model']);
    $t->same(1, (int) $before['is_provisional'], 'a day inside the window is provisional');

    $location = (array) $db->one('SELECT * FROM `weather_location` WHERE `id` = :id',
        ['id' => $location['id']]);
    $sync->syncForecast($location);

    $after = $db->one(
        'SELECT `temp_max_c`, `source_model` FROM `weather_daily`'
        . ' WHERE `location_id` = :id AND `obs_date` = :d',
        ['id' => $location['id'], 'd' => $yesterday]
    );
    $t->same('best_match', $after['source_model'], 'the archive row survived');
    $t->equals($before['temp_max_c'], $after['temp_max_c'],
        'the distinctive forecast value (99) did not land');
});

$t->test('the archive does overwrite a forecast-derived row',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -10));
    $sync = new WeatherSync($app, $provider);

    // Forecast first, so yesterday is a forecast_past placeholder.
    $sync->syncForecast($location);
    $yesterday = (string) Clock::addDays($today, -1);
    $placeholder = $db->one(
        'SELECT `source_model`, `temp_max_c` FROM `weather_daily`'
        . ' WHERE `location_id` = :id AND `obs_date` = :d',
        ['id' => $location['id'], 'd' => $yesterday]
    );
    $t->same(WeatherSync::FORECAST_PAST, $placeholder['source_model']);

    $location = (array) $db->one('SELECT * FROM `weather_location` WHERE `id` = :id',
        ['id' => $location['id']]);
    $sync->syncArchive($location);

    $settled = $db->one(
        'SELECT `source_model`, `temp_max_c` FROM `weather_daily`'
        . ' WHERE `location_id` = :id AND `obs_date` = :d',
        ['id' => $location['id'], 'd' => $yesterday]
    );
    $t->same('best_match', $settled['source_model'], 'ERA5 replaced the placeholder');
});

$t->test('the three-day forecast hash is stored for the MOTD re-post rule',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -2));
    (new WeatherSync($app, $provider))->syncForecast($location);

    $hash = $db->value('SELECT `forecast_hash` FROM `weather_location` WHERE `id` = :id',
        ['id' => $location['id']]);
    $t->ok(\is_string($hash) && \strlen($hash) === 64, 'a sha256 was stored');
});

$t->group('Provisional settling and pruning');

$t->test('rows older than the settle window stop being provisional',
    function ($t) use ($app, $provider, $makeLocation, $today, $db): void {
    $location = $makeLocation((string) Clock::addDays($today, -30));
    $sync = new WeatherSync($app, $provider);
    $sync->syncArchive($location);
    $sync->settleProvisional();

    $stillProvisional = (int) $db->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE `location_id` = :id AND `is_provisional` = 1',
        ['id' => $location['id']], 0
    );
    // Ten days of settle window, and yesterday is the newest row held.
    $t->ok($stillProvisional <= 11 && $stillProvisional >= 9,
        'about ten days remain provisional, got ' . $stillProvisional);
});

$t->test('the run log is pruned rather than growing forever',
    function ($t) use ($app, $provider, $db): void {
    $db->run(
        "INSERT INTO `weather_sync_run` (location_id, kind, started_at, outcome)"
        . " VALUES (NULL, 'archive', (UTC_TIMESTAMP() - INTERVAL 200 DAY), 'ok')"
    );
    $pruned = (new WeatherSync($app, $provider))->prune();
    $t->ok($pruned >= 1, 'the ancient row went');
});

$t->group('Rate limiting is respected, not fought');

$t->test('a quota message is recognised however it arrives', function ($t): void {
    // Measured 2026-08-31: an hourly limit came back with this reason.
    $t->ok(HttpClient::isQuota('Provider error: Hourly API request limit exceeded. '
        . 'Please try again in the next hour.'));
    $t->ok(HttpClient::isQuota('Provider error: Daily API request limit exceeded.'));
    $t->ok(HttpClient::isQuota('HTTP 429'));
    $t->ok(!HttpClient::isQuota('Could not resolve host'));
    $t->ok(!HttpClient::isQuota(null));
});
