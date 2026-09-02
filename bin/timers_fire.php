<?php
/**
 * Fire the watering timers that have run their time (Phase 16).
 *
 * cPanel Cron Jobs, EVERY MINUTE. Nothing on this host can hold a connection
 * open or run except from cron (hosting Section 3), so a timer is a row with
 * an end time and this is what notices it. A run with nothing due costs one
 * statement and exits; granularity is up to a minute late, which for a
 * watering is nothing.
 *
 *   * * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/timers_fire.php >/dev/null 2>&1
 *
 * Redirect the output: cPanel emails the account on every run otherwise,
 * and 1,440 mails a day is how a cron gets deleted.
 *
 * Browser fallback, the same shape the other jobs use:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/timers-fire?key=<cron_key>" >/dev/null 2>&1
 *
 *   php bin/timers_fire.php              fire what is due
 *   php bin/timers_fire.php --verbose    print each timer's outcome
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$verbose = false;
foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--verbose' || $argument === '-v') {
        $verbose = true;
    } else {
        \fwrite(\STDERR, "unknown argument: {$argument}\n");
        exit(2);
    }
}

$started = \microtime(true);

try {
    $summary = (new Carl\Timers\TimerService($app))->fire();
} catch (Throwable $e) {
    \fwrite(\STDERR, 'timers_fire: ' . $e->getMessage() . "\n");
    exit(1);
}

// Quiet when there was nothing to do: a line a minute is noise in any log.
if ($summary['considered'] > 0 || $verbose) {
    \printf(
        "timers_fire: %d due, %d fired, %d logged, %d pushed, %d emailed, %d failures, %.1f s\n",
        $summary['considered'], $summary['fired'], $summary['logged'],
        $summary['pushed'], $summary['emailed'], $summary['failures'],
        \microtime(true) - $started,
    );
}
if ($verbose || $summary['failures'] > 0) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

exit($summary['failures'] > 0 ? 1 : 0);
