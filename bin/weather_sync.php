<?php
/**
 * The nightly weather job (weather.md Section 3).
 *
 * cPanel Cron Jobs, once daily. Redirect the output -- cPanel emails the
 * account on every run otherwise, and a nightly job that mails 365 times a
 * year trains everyone to ignore it:
 *
 *   /usr/local/bin/php -q /home/reshiftmanager/carl-app/bin/weather_sync.php >/dev/null 2>&1
 *
 * The PHP CLI path is unverified on this host; /diag reports which of the
 * candidates exists. If none resolves, the browser fallback is:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/weather-sync?key=<cron_key>" >/dev/null 2>&1
 *
 * The curl form runs under the web SAPI and inherits the 30 s ceiling, so the
 * job chunks either way and the two forms stay interchangeable.
 *
 *   php bin/weather_sync.php                 archive + forecast
 *   php bin/weather_sync.php --archive       historical only
 *   php bin/weather_sync.php --forecast      forecast only
 *   php bin/weather_sync.php --recommend     the watering model, no fetching
 *   php bin/weather_sync.php --location=3    one location
 *   php bin/weather_sync.php --verbose       print the run log
 *
 * --recommend is a SEPARATE cron entry, scheduled after this one, and it
 * calls no API at all: it reads weather_daily and weather_forecast, which
 * the run above has already filled, and computes (handoff Section 11). Phase
 * 3 must not add an Open-Meteo call -- the limit that bit during development
 * was the hourly one (Phase 3 handoff Section 1.3).
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$kinds = [];
$locationId = null;
$verbose = false;
$recommend = false;

foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--archive') {
        $kinds[] = 'archive';
    } elseif ($argument === '--forecast') {
        $kinds[] = 'forecast';
    } elseif ($argument === '--recommend') {
        $recommend = true;
    } elseif ($argument === '--verbose' || $argument === '-v') {
        $verbose = true;
    } elseif (\preg_match('/^--location=(\d+)$/', $argument, $m) === 1) {
        $locationId = (int) $m[1];
    } else {
        \fwrite(\STDERR, "unknown argument: {$argument}\n");
        exit(2);
    }
}

$started = \microtime(true);

// --recommend on its own runs only the model. Given alongside a fetch kind,
// it runs after it, which is also the order the two cron entries are in.
if ($recommend && $kinds === []) {
    try {
        $model = new Carl\Weather\WateringModel($app);
        $summary = $model->run();
    } catch (Throwable $e) {
        \fwrite(\STDERR, 'weather_sync --recommend: ' . $e->getMessage() . "\n");
        exit(1);
    }

    \printf(
        "weather_sync recommend: %d gardens and containers, %d rows, %d failures, %.1f s\n",
        $summary['places'],
        $summary['rows'],
        $summary['failures'],
        \microtime(true) - $started,
    );

    if ($verbose) {
        foreach ($summary['log'] as $line) {
            echo '  ' . $line . "\n";
        }
    }

    exit($summary['failures'] > 0 ? 1 : 0);
}

if ($kinds === []) {
    $kinds = ['archive', 'forecast'];
}

try {
    $sync = new Carl\Weather\WeatherSync($app);
    $summary = $sync->run($kinds, $locationId);
} catch (Throwable $e) {
    \fwrite(\STDERR, 'weather_sync: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($recommend) {
    try {
        $model = new Carl\Weather\WateringModel($app);
        $recommendation = $model->run();
        $summary['log'] = \array_merge($summary['log'], $recommendation['log']);
        $summary['failures'] += $recommendation['failures'];
    } catch (Throwable $e) {
        \fwrite(\STDERR, 'weather_sync --recommend: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

\printf(
    "weather_sync %s: %d locations, %d rows, %d failures, %.1f s\n",
    \implode('+', $kinds),
    $summary['locations'],
    $summary['rows'],
    $summary['failures'],
    \microtime(true) - $started,
);

if ($verbose) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

// A non-zero exit is what a monitoring wrapper would key on; the run table is
// what /status reads.
exit($summary['failures'] > 0 ? 1 : 0);
