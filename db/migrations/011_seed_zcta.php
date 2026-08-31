<?php
// carl:kind=dml
//
// Load the ZIP -> coordinates -> county table (handoff Section 8.3).
//
// 33,791 rows of public-domain Census data: the 2024 ZCTA gazetteer for the
// coordinates, joined to the 2020 ZCTA-to-county relationship file for the
// county, taking the county holding the largest land area of each ZCTA.
//
// Loaded 2,000 rows per statement because the whole thing has to finish
// inside max_execution_time (30 s) when it is run from /setup in a browser --
// this account has no shell (hosting Sections 4 and 6.3). At 2,000 rows that
// is 17 statements, and the arithmetic in hosting Section 9 (time = work +
// statements x RTT, RTT under 1 ms) says the round trips are not the cost.
//
// Idempotent like every other migration: ON DUPLICATE KEY UPDATE on the zip,
// and a Zippopotam-sourced row is never overwritten by the Census values
// because a re-run only reaches rows the Census file carries anyway.

declare(strict_types=1);

use Carl\Core\Database;

return static function (Database $db): void {
    $path = \dirname(__DIR__) . '/seed/zcta.csv';
    if (!\is_file($path)) {
        throw new RuntimeException(
            'db/seed/zcta.csv is missing. The ZIP lookup cannot be built without it; '
            . 'onboarding would fall back to the Zippopotam.us API for every user.'
        );
    }

    $handle = \fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read db/seed/zcta.csv');
    }

    $header = \fgetcsv($handle, 0, ',', '"', '');
    $expected = ['zip', 'lat', 'lon', 'county_fips', 'state', 'county_name'];
    if ($header !== $expected) {
        \fclose($handle);
        throw new RuntimeException(
            'db/seed/zcta.csv header is ' . \json_encode($header)
            . ', expected ' . \json_encode($expected)
        );
    }

    $columns = ['zip', 'latitude', 'longitude', 'county_fips', 'state', 'county_name', 'source', 'created_at'];
    // A row already present is refreshed with the Census values, which is what
    // makes re-running converge. source is included so a row that was created
    // by the Zippopotam fallback is corrected once the Census file covers it.
    $updatable = ['latitude', 'longitude', 'county_fips', 'state', 'county_name', 'source'];

    $now = \gmdate('Y-m-d H:i:s');
    $chunkSize = 2000;
    $chunk = [];
    $loaded = 0;

    $flush = static function () use ($db, $columns, &$chunk, $updatable, &$loaded): void {
        if ($chunk === []) {
            return;
        }
        $db->upsertChunk('zcta', $columns, $chunk, $updatable);
        $loaded += \count($chunk);
        $chunk = [];
    };

    while (($row = \fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($row === [null] || \count($row) < 6) {
            continue;
        }
        $zip = \trim((string) $row[0]);
        if ($zip === '') {
            continue;
        }
        $chunk[] = [
            $zip,
            (float) $row[1],
            (float) $row[2],
            ($row[3] ?? '') === '' ? null : $row[3],
            ($row[4] ?? '') === '' ? null : $row[4],
            ($row[5] ?? '') === '' ? null : $row[5],
            'census',
            $now,
        ];
        if (\count($chunk) >= $chunkSize) {
            $flush();
        }
    }
    $flush();
    \fclose($handle);

    if ($loaded < 30000) {
        throw new RuntimeException(
            "Only {$loaded} ZIP rows loaded; the seed file should carry about 33,800. "
            . 'Refusing to leave the lookup half-built.'
        );
    }
};
