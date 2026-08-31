<?php
/**
 * Reminders and the daily digest (handoff Section 12).
 *
 * cPanel Cron Jobs, HOURLY, at minute 15:
 *
 *   15 * * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/daily_digest.php >/dev/null 2>&1
 *
 * Hourly is not a mistake and the hour field is deliberately `*`. The job
 * sends to each user whose OWN local time is between 06:00 and 07:00,
 * computed from user.timezone through Clock::localHour(). Nothing in it
 * reasons about the server's zone -- which matters more here than anywhere
 * else, because the cron clock on this host is US Eastern while PHP is pinned
 * to UTC, and neither is any user's morning (Phase 3 handoff Section 1.1).
 *
 * It queues; the mail drain sends. A mail server being slow cannot make this
 * job slow, and a job that fails halfway has still stored the reminders the
 * main menu will show.
 *
 * Browser fallback:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/daily-digest?key=<cron_key>" >/dev/null 2>&1
 *
 *   php bin/daily_digest.php               everyone whose local time is 06:00
 *   php bin/daily_digest.php --user=7      one account
 *   php bin/daily_digest.php --force       ignore the hour (for a first look)
 *   php bin/daily_digest.php --verbose     print what each user got
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$userId = null;
$force = false;
$verbose = false;

foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--verbose' || $argument === '-v') {
        $verbose = true;
    } elseif ($argument === '--force') {
        $force = true;
    } elseif (\preg_match('/^--user=(\d+)$/', $argument, $m) === 1) {
        $userId = (int) $m[1];
    } else {
        \fwrite(\STDERR, "unknown argument: {$argument}\n");
        exit(2);
    }
}

$started = \microtime(true);

try {
    $digest = new Carl\Reminders\Digest($app);
    $summary = $digest->run($userId, $force);
} catch (Throwable $e) {
    \fwrite(\STDERR, 'daily_digest: ' . $e->getMessage() . "\n");
    exit(1);
}

\printf(
    "daily_digest: %d due, %d reminders, %d emails queued, %d silent, %d failures, %.1f s\n",
    $summary['due'],
    $summary['reminders'],
    $summary['queued'],
    $summary['silent'],
    $summary['failures'],
    \microtime(true) - $started,
);

if ($verbose) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

// Silence is not failure. Most hourly runs have nobody due, and most people
// have nothing to be told on most days; an empty digest trains people to
// ignore a full one (handoff Section 12).
exit($summary['failures'] > 0 ? 1 : 0);
