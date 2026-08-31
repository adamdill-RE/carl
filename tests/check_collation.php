<?php
/**
 * Hosting Section 2.2: collation defaults differ between the engine cPanel
 * names and the one the application actually talks to, so utf8mb4_unicode_ci
 * is named on every table -- and asserted in a test, which is this file.
 */

declare(strict_types=1);

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$db = $app->db();
$schema = $app->config()->string('db.name');

$rows = $db->all(
    'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES'
    . ' WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type'
    . ' ORDER BY TABLE_NAME',
    ['schema' => $schema, 'type' => 'BASE TABLE']
);

if ($rows === []) {
    \fwrite(\STDERR, "No tables found in {$schema}; migrations did not run.\n");
    exit(1);
}

$bad = [];
foreach ($rows as $row) {
    if ((string) $row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
        $bad[] = $row['TABLE_NAME'] . ' = ' . $row['TABLE_COLLATION'];
    }
}

if ($bad !== []) {
    \fwrite(\STDERR, "Tables not on utf8mb4_unicode_ci:\n");
    foreach ($bad as $line) {
        \fwrite(\STDERR, '  - ' . $line . "\n");
    }
    exit(1);
}

// Hosting Section 2.2 / weather.md Section 6.1: prefer VIRTUAL generated
// columns. A STORED one would reintroduce the error 1215 trap the moment
// someone adds a cascade to a column it reads.
$stored = $db->all(
    'SELECT TABLE_NAME, COLUMN_NAME, EXTRA FROM information_schema.COLUMNS'
    . ' WHERE TABLE_SCHEMA = :schema AND EXTRA LIKE :pattern',
    ['schema' => $schema, 'pattern' => '%STORED GENERATED%']
);

if ($stored !== []) {
    \fwrite(\STDERR, "STORED generated columns found (use VIRTUAL, hosting Section 2.2):\n");
    foreach ($stored as $row) {
        \fwrite(\STDERR, '  - ' . $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . "\n");
    }
    exit(1);
}

\printf("%d tables, all utf8mb4_unicode_ci, no STORED generated columns\n", \count($rows));
