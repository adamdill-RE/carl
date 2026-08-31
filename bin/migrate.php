<?php
/**
 * Apply pending migrations from the command line.
 *
 * On the production account this file cannot be reached -- there is no shell
 * (hosting Section 3) -- so /setup?key= is the route that actually runs
 * migrations there (hosting Section 6.3). This entry point exists for local
 * development and for CI, which applies every migration twice and asserts
 * the second run reports "up to date" (hosting Section 10).
 *
 *   php bin/migrate.php            apply pending
 *   php bin/migrate.php --status   list applied and pending, apply nothing
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(404);
    exit;
}

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$migrator = new Carl\Core\Migrator($app->db(), $app->root() . '/db/migrations');
$statusOnly = \in_array('--status', $argv, true);

try {
    $applied = $migrator->applied();
    $pending = $migrator->pending();
} catch (Throwable $e) {
    \fwrite(\STDERR, "migrate: " . $e->getMessage() . "\n");
    exit(1);
}

\printf("applied: %d\n", \count($applied));
foreach ($pending as $migration) {
    \printf("pending: %s (%s)\n", $migration['filename'], $migration['kind']);
}

if ($statusOnly) {
    exit($pending === [] ? 0 : 1);
}

if ($pending === []) {
    echo "up to date\n";
    exit(0);
}

try {
    foreach ($migrator->migrate() as $done) {
        \printf("applied %s: %d statements in %d ms\n", $done['filename'], $done['statements'], $done['ms']);
    }
} catch (Throwable $e) {
    \fwrite(\STDERR, "migrate: " . $e->getMessage() . "\n");
    exit(1);
}

echo "up to date\n";
