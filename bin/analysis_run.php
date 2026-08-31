<?php
/**
 * Answer the queued analyses (Phase 5 handoff Section 3.1).
 *
 * This is the only place in Carl that calls the Anthropic API. Pages write
 * `analysis` rows and return; this reads them, calls, and stores the answer,
 * with bounded retries and a run row every time (Phase 3 handoff Section 5).
 *
 * cPanel Cron Jobs, hourly. Redirect the output, like every other job here:
 * cPanel emails the account on every run otherwise.
 *
 *   0 * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/analysis_run.php >/dev/null 2>&1
 *
 * The exact crontab line is in docs/deploy.md Section 7, where it can be
 * copied without a comment delimiter getting in the way.
 *
 * The cron clock is US Eastern (Phase 3 handoff Section 1.1) and nothing here
 * reasons about the hour, so the zone does not matter: it answers whatever is
 * due. Hourly rather than nightly because the wait is a person's wait -- they
 * pressed a button and are waiting for a reply.
 *
 * Browser fallback, the same shape the other jobs use. It passes a wall-clock
 * budget, because that form runs under the web SAPI's 30 s ceiling:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/analysis-run?key=<cron_key>" >/dev/null 2>&1
 *
 *   php bin/analysis_run.php              answer what is due
 *   php bin/analysis_run.php --limit=1    one, for a first run
 *   php bin/analysis_run.php --status     what is waiting; call nothing
 *   php bin/analysis_run.php --verbose    print each request's outcome
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$limit = null;
$verbose = false;
$statusOnly = false;

foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--verbose' || $argument === '-v') {
        $verbose = true;
    } elseif ($argument === '--status') {
        $statusOnly = true;
    } elseif (\preg_match('/^--limit=(\d+)$/', $argument, $m) === 1) {
        $limit = (int) $m[1];
    } else {
        \fwrite(\STDERR, "unknown argument: {$argument}\n");
        exit(2);
    }
}

$analyst = $app->analyst();

if ($statusOnly) {
    $health = $analyst->health();
    \printf("provider: %s\n", $analyst->describeDriver());
    \printf("waiting %d, answered %d, failed %d\n",
        $health['queued'], $health['done'], $health['failed']);
    if ($health['oldest_queued'] !== null) {
        \printf("oldest waiting: %s UTC\n", $health['oldest_queued']);
    }
    exit($health['failed'] > 0 ? 1 : 0);
}

$started = \microtime(true);

try {
    // No wall-clock budget: the CLI has no max_execution_time and one
    // analysis is a long single request. The browser twin passes its own.
    $summary = $analyst->drain($limit, 0.0);
} catch (Throwable $e) {
    \fwrite(\STDERR, 'analysis_run: ' . $e->getMessage() . "\n");
    exit(1);
}

\printf(
    "analysis_run %s: %d considered, %d answered, %d failed, %.1f s\n",
    $summary['model'] === '' ? 'none' : $summary['model'],
    $summary['considered'],
    $summary['completed'],
    $summary['failed'],
    \microtime(true) - $started,
);

if ($verbose || $summary['failed'] > 0) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

// "No key yet" is not a failure: it is the documented state before the owner
// adds one, and the requests are still waiting.
exit($summary['failed'] > 0 ? 1 : 0);
