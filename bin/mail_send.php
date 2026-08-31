<?php
/**
 * Drain the mail outbox (handoff Section 5.8, Phase 3 handoff Section 4.1).
 *
 * Nothing sends inline in a request. Pages write email_outbox rows; this
 * sends them, with bounded retries and a run row every time.
 *
 * cPanel Cron Jobs, every ten minutes -- minute field "slash-10", written
 * that way here only because the digits would close this comment block.
 * Redirect the output: cPanel emails the account on every run otherwise, and
 * a job that mails about mail is a loop nobody enjoys.
 *
 *   <minute: every 10> * * * /usr/local/bin/ea-php82 -q /home/reshiftmanager/carl-app/bin/mail_send.php >/dev/null 2>&1
 *
 * The exact crontab line is in docs/deploy.md Section 7, where it can be
 * copied without a comment delimiter getting in the way.
 *
 * The cron clock is US Eastern (Phase 3 handoff Section 1.1), but nothing
 * here reasons about the hour, so the zone does not matter: it drains
 * whatever is due.
 *
 * Browser fallback, the same shape the weather job uses:
 *
 *   /usr/bin/curl -s "https://www.reshiftmanager.com/carl/tasks/mail-send?key=<cron_key>" >/dev/null 2>&1
 *
 *   php bin/mail_send.php              send what is due
 *   php bin/mail_send.php --limit=5    fewer, for a first run
 *   php bin/mail_send.php --status     what is waiting; send nothing
 *   php bin/mail_send.php --verbose    print each message's outcome
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

$outbox = $app->outbox();

if ($statusOnly) {
    $health = $outbox->health();
    \printf("driver: %s\n", $outbox->describeDriver());
    \printf("queued %d, sent %d, failed %d\n", $health['queued'], $health['sent'], $health['failed']);
    if ($health['oldest_queued'] !== null) {
        \printf("oldest queued: %s UTC\n", $health['oldest_queued']);
    }
    exit($health['failed'] > 0 ? 1 : 0);
}

$started = \microtime(true);

try {
    $summary = $outbox->drain($limit);
} catch (Throwable $e) {
    \fwrite(\STDERR, 'mail_send: ' . $e->getMessage() . "\n");
    exit(1);
}

\printf(
    "mail_send %s: %d considered, %d sent, %d failed, %.1f s\n",
    $summary['driver'],
    $summary['considered'],
    $summary['sent'],
    $summary['failed'],
    \microtime(true) - $started,
);

if ($verbose || $summary['failed'] > 0) {
    foreach ($summary['log'] as $line) {
        echo '  ' . $line . "\n";
    }
}

// A non-zero exit is what a monitoring wrapper would key on. "No driver yet"
// is not a failure: it is the documented state before handoff Section 12.1
// is done, and the messages are still waiting.
exit($summary['failed'] > 0 ? 1 : 0);
