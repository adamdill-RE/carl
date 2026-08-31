<?php
/**
 * Test entry point.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php --strict   notices and deprecations become failures
 *   php tests/run.php --strict cases/migrator_test.php   run one file
 */

declare(strict_types=1);

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

require __DIR__ . '/Harness.php';
require __DIR__ . '/Client.php';

$strict = \in_array('--strict', $argv, true);
$only = [];
foreach (\array_slice($argv, 1) as $argument) {
    if (!\str_starts_with($argument, '--')) {
        $only[] = $argument;
    }
}

$harness = new Carl\Tests\Harness($strict);

$files = $only !== []
    ? \array_map(static fn (string $f): string => __DIR__ . '/' . \ltrim($f, '/'), $only)
    : (array) \glob(__DIR__ . '/cases/*_test.php');

\sort($files);

foreach ($files as $file) {
    if (!\is_file((string) $file)) {
        \fwrite(\STDERR, "no such test file: {$file}\n");
        exit(1);
    }
    (static function (string $__file, Carl\Tests\Harness $t, Carl\Core\App $app): void {
        require $__file;
    })((string) $file, $harness, $app);
}

exit($harness->report());
