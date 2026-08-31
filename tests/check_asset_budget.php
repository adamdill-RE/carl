<?php
/**
 * Hosting Section 9: RESM ships about 27 KB gzipped against a 150 KB target,
 * so the client shell is not the bottleneck on a 2 s first paint over 3G.
 * Carl keeps the same budget. Vendored libraries that are loaded only on
 * report pages (Chart.js) are excluded -- they are not part of the shell.
 */

declare(strict_types=1);

$root = \dirname(__DIR__) . '/public/assets';
$budgetBytes = 150 * 1024;

$total = 0;
$rows = [];

foreach (['css', 'js'] as $kind) {
    $directory = $root . '/' . $kind;
    if (!\is_dir($directory)) {
        continue;
    }
    foreach ((array) \glob($directory . '/*.' . $kind) as $file) {
        $contents = (string) \file_get_contents((string) $file);
        $gzipped = \strlen((string) \gzencode($contents, 6));
        $total += $gzipped;
        $rows[] = \sprintf('  %-28s %6d B raw  %6d B gz', \basename((string) $file), \strlen($contents), $gzipped);
    }
}

$serviceWorker = \dirname(__DIR__) . '/public/sw.js';
if (\is_file($serviceWorker)) {
    $contents = (string) \file_get_contents($serviceWorker);
    $gzipped = \strlen((string) \gzencode($contents, 6));
    $total += $gzipped;
    $rows[] = \sprintf('  %-28s %6d B raw  %6d B gz', 'sw.js', \strlen($contents), $gzipped);
}

\sort($rows);
echo \implode("\n", $rows), "\n";
\printf("shell total: %d B gzipped (budget %d B)\n", $total, $budgetBytes);

if ($total > $budgetBytes) {
    \fwrite(\STDERR, "Client shell is over budget (hosting Section 9).\n");
    exit(1);
}
