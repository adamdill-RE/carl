<?php
/**
 * National Weather Service active alerts (handoff Section 8.4).
 *
 * cPanel Cron Jobs, every three hours. Redirect the output -- cPanel emails
 * the account on every run otherwise:
 *
 *   20 <every 3 hours> * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/alerts_poll.php >/dev/null 2>&1
 *
 * The exact crontab line is in docs/deploy.md Section 7. The cron clock is
 * US Eastern (Phase 3 handoff Section 1.1), but nothing here reasons about
 * the hour, so the zone does not matter.
 *
 * Browser fallback, the same shape the weather job uses:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/alerts-poll?key=<cron_key>" >/dev/null 2>&1
 *
 *   php bin/alerts_poll.php                 every active location
 *   php bin/alerts_poll.php --location=3    one location
 *   php bin/alerts_poll.php --verbose       print the run log
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$locationId = null;
$verbose = false;

foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--verbose' || $argument === '-v') {
        $verbose = true;
    } elseif (\preg_match('/^--location=(\d+)$/', $argument, $m) === 1) {
        $locationId = (int) $m[1];
    } else {
        \fwrite(\STDERR, "unknown argument: {$argument}\n");
        exit(2);
    }
}

$started = \microtime(true);

try {
    $poller = new Carl\Weather\AlertPoller($app);
    $summary = $poller->run($locationId);
} catch (Throwable $e) {
    \fwrite(\STDERR, 'alerts_poll: ' . $e->getMessage() . "\n");
    exit(1);
}

// An alert that arrives after this morning's digest has gone is no use
// tomorrow morning (handoff Section 8.4). The digest decides who has already
// had theirs; a user who has not gets it in the digest instead, because one
// email beats two.
$urgent = 0;
if ($summary['new_ids'] !== []) {
    try {
        $urgent = (new Carl\Reminders\Digest($app))->sendUrgentAlerts($summary['new_ids']);
    } catch (Throwable $e) {
        \fwrite(\STDERR, 'alerts_poll: urgent mail not queued: ' . $e->getMessage() . "\n");
    }
}

\printf(
    "alerts_poll: %d locations, %d polled, %d active, %d new, %d closed, %d failures, %.1f s\n",
    $summary['locations'],
    $summary['polled'],
    $summary['stored'],
    $summary['new'],
    $summary['closed'],
    $summary['failures'],
    \microtime(true) - $started,
);

if ($urgent > 0) {
    \printf("  %d urgent alert email%s queued\n", $urgent, $urgent === 1 ? '' : 's');
}

if ($verbose) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

exit($summary['failures'] > 0 ? 1 : 0);
