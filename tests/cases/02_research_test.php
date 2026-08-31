<?php

/**
 * The research import route (handoff Section 9.3), driven with the real
 * first dataset from research-template/populated/.
 *
 * This runs before the flow test because it is what puts plant types in the
 * database -- exactly as on a real install, where the admin's first act after
 * /setup is to import a dataset.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Research\ResearchImporter;

$db = $app->db();
$zipPath = $app->root() . '/research-template/populated/research_US-48217_2026-08-30.1.zip';
$importer = new ResearchImporter($db);

$t->group('Dataset version comparison');

$t->test('a newer counter beats an older one on the same day', function ($t): void {
    $t->ok(ResearchImporter::compareVersions('2026-08-30.2', '2026-08-30.1') > 0);
    $t->ok(ResearchImporter::compareVersions('2026-08-30.1', '2026-08-30.2') < 0);
    $t->same(0, ResearchImporter::compareVersions('2026-08-30.1', '2026-08-30.1'));
});

$t->test('the counter is compared numerically, not as text', function ($t): void {
    // "10" sorts before "9" as a string; it must not here.
    $t->ok(ResearchImporter::compareVersions('2026-08-30.10', '2026-08-30.9') > 0);
});

$t->test('a later date wins regardless of counter', function ($t): void {
    $t->ok(ResearchImporter::compareVersions('2026-09-01.1', '2026-08-30.9') > 0);
});

$t->group('Importing the first dataset');

$t->test('the shipped dataset zip exists', function ($t) use ($zipPath): void {
    $t->ok(\is_file($zipPath), 'research-template/populated/research_US-48217_2026-08-30.1.zip');
});

$t->test('it validates completely', function ($t) use ($importer, $zipPath, $db): void {
    $result = $importer->validate($zipPath, 'research_US-48217_2026-08-30.1.zip');

    $alreadyHeld = $db->value(
        "SELECT `dataset_version` FROM `region` WHERE `region_key` = 'US-48217'"
    );

    if (\is_string($alreadyHeld) && $alreadyHeld !== '') {
        // Re-importing the same version is refused, and that refusal IS the
        // idempotence guarantee (handoff Section 9.3 step 3).
        $t->ok(!$result->ok(), 'a version that is not newer is refused');
        $t->contains('not newer', \implode(' ', $result->errors));
        return;
    }

    if (!$result->ok()) {
        throw new RuntimeException("validation failed:\n  " . \implode("\n  ", $result->firstErrors()));
    }

    $t->same('2026-08-30.1', $result->datasetVersion);
    $t->same(['US-48217'], $result->regionKeys);
    $t->same(1, $result->files['regions.csv']['rows']);
    $t->same(30, $result->files['plant_types.csv']['rows']);
    $t->same(58, $result->files['plant_region.csv']['rows']);
    $t->same(16, $result->files['pests.csv']['rows']);
    $t->same(10, $result->files['region_guidance.csv']['rows']);
});

$t->test('applying it writes every file in dependency order', function ($t) use ($importer, $zipPath, $db): void {
    $existing = (int) $db->value('SELECT COUNT(*) FROM `plant_type`', [], 0);
    if ($existing > 0) {
        $t->ok(true, 'reference data already loaded by an earlier run');
        return;
    }

    $result = $importer->validate($zipPath, 'research_US-48217_2026-08-30.1.zip');
    $written = $importer->apply($result, 1);

    $t->same(1, $written['regions.csv']);
    $t->same(30, $written['plant_types.csv']);
    $t->same(58, $written['plant_region.csv']);
    $t->same(16, $written['pests.csv']);
    $t->same(10, $written['region_guidance.csv']);
});

$t->test('the region is marked researched and carries its version', function ($t) use ($db): void {
    $region = $db->one("SELECT * FROM `region` WHERE `region_key` = 'US-48217'");
    $t->ok($region !== null, 'the Hill County region exists');
    $t->same('researched', $region['research_status']);
    $t->same('2026-08-30.1', $region['dataset_version']);
    $t->same('03-18', $region['last_frost_avg']);
    $t->same('11-20', $region['first_frost_avg']);
});

$t->test('a plant carries its agronomic values and its citation', function ($t) use ($db): void {
    $plant = $db->one("SELECT * FROM `plant_type` WHERE `category` = 'Tomato' AND `type` = 'Celebrity'");
    $t->ok($plant !== null, 'Tomato / Celebrity was imported');
    $t->same('Solanaceae', $plant['plant_family']);
    $t->same('transplant', $plant['dtm_counted_from']);
    $t->same(65, (int) $plant['dtm_days_min']);
    $t->same(1, (int) $plant['heat_tolerant']);
    $t->ok((string) $plant['source'] !== '', 'the citation survived the import');
});

$t->test('the region overlay carries the season windows', function ($t) use ($db): void {
    $rows = $db->all(
        'SELECT pr.* FROM `plant_region` pr'
        . ' JOIN `region` r ON r.id = pr.region_id'
        . ' JOIN `plant_type` pt ON pt.id = pr.plant_type_id'
        . " WHERE r.region_key = 'US-48217' AND pt.category = 'Tomato' AND pt.type = 'Roma'"
        . ' ORDER BY pr.season'
    );
    $t->same(2, \count($rows), 'Roma has a spring and a fall window');
    $seasons = \array_map(static fn (array $r): string => (string) $r['season'], $rows);
    \sort($seasons);
    $t->same(['fall', 'spring'], $seasons);
});

$t->test('guidance is stored with its window and confidence', function ($t) use ($db): void {
    $row = $db->one(
        'SELECT g.* FROM `region_guidance` g JOIN `region` r ON r.id = g.region_id'
        . " WHERE r.region_key = 'US-48217' AND g.topic = 'shade' LIMIT 1"
    );
    $t->ok($row !== null, 'the shade-cloth guidance line imported');
    $t->same('06-15', $row['show_from']);
    $t->contains('shade cloth', \strtolower((string) $row['guidance']));
    $t->contains('Tomato', (string) $row['applies_to_categories']);
});

$t->group('The importer refuses bad input');

$makeZip = static function (array $files) use ($app): string {
    $path = \tempnam(\sys_get_temp_dir(), 'carlzip') . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    return $path;
};

$goodManifest = "key,value\ntemplate_version,1\ndataset_version,2099-01-01.1\n"
    . "region_keys,US-48217\nproduced_on,2099-01-01\nproduced_by,test\nnotes,\n";

$t->test('a zip with an unexpected file is refused', function ($t) use ($importer, $makeZip, $goodManifest): void {
    $path = $makeZip(['manifest.csv' => $goodManifest, 'evil.php' => '<?php echo 1;']);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('not one of the', \implode(' ', $result->errors));
});

$t->test('a wrong template_version is refused with a clear reason', function ($t) use ($importer, $makeZip): void {
    $manifest = "key,value\ntemplate_version,2\ndataset_version,2099-01-01.1\nregion_keys,US-48217\n";
    $path = $makeZip(['manifest.csv' => $manifest]);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('template_version', \implode(' ', $result->errors));
});

$t->test('a header that does not match the template is refused', function ($t) use ($importer, $makeZip, $goodManifest): void {
    $path = $makeZip([
        'manifest.csv' => $goodManifest,
        'pests.csv' => "pest_key,name,kind\nx,X,pest\n",
    ]);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('header does not match', \implode(' ', $result->errors));
});

$t->test('a plant_region row for an unknown plant is refused', function ($t) use ($importer, $makeZip, $goodManifest): void {
    $header = 'region_key,category,type,season,window_start,window_end,method,recommended,'
        . "dtm_days_min_override,dtm_days_max_override,confidence,source,regional_notes\n";
    $path = $makeZip([
        'manifest.csv' => $goodManifest,
        'plant_region.csv' => $header
            . "US-48217,Fictional,Nothing,spring,03-01,04-01,seed,Y,,,verified,test,\n",
    ]);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('is not in plant_types.csv', \implode(' ', $result->errors));
});

$t->test('a malformed MM-DD window is refused', function ($t) use ($importer, $makeZip, $goodManifest): void {
    $header = 'region_key,topic,applies_to_categories,show_from,show_to,guidance,confidence,source' . "\n";
    $path = $makeZip([
        'manifest.csv' => $goodManifest,
        'region_guidance.csv' => $header . "US-48217,soil,,2026-06-15,09-01,Do a thing,verified,test\n",
    ]);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('expected MM-DD', \implode(' ', $result->errors));
});

$t->test('an invalid enum is refused with the allowed values', function ($t) use ($importer, $makeZip, $goodManifest): void {
    $header = 'pest_key,name,kind,description,signs,treatments,source' . "\n";
    $path = $makeZip([
        'manifest.csv' => $goodManifest,
        'pests.csv' => $header . "gremlin,Gremlin,mammal,,,,test\n",
    ]);
    $result = $importer->validate($path, 'bad.zip');
    \unlink($path);
    $t->ok(!$result->ok());
    $t->contains('expected one of', \implode(' ', $result->errors));
});

$t->test('re-uploading the exact same file is recognised, not repeated', function ($t) use ($importer, $zipPath, $db): void {
    $seen = $db->value(
        'SELECT 1 FROM `research_import` WHERE `sha256` = :sha',
        ['sha' => \hash('sha256', (string) \file_get_contents($zipPath))]
    );
    if ($seen === null) {
        $t->ok(true, 'this dataset has not been applied through the importer in this database');
        return;
    }
    $result = $importer->validate($zipPath, 'again.zip');
    $t->ok($result->alreadyImported, 'the importer recognised the sha256');
    $t->contains('already imported', \implode(' ', $result->warnings));
});

$t->group('Reading the catalog back');

$t->test('a plant with two seasons appears once in the list, not twice',
    function ($t) use ($app, $db): void {
    // plant_region is keyed on (region, plant, season), so a LEFT JOIN
    // without an aggregate multiplies the plant by its season count and the
    // dropdown lists it twice.
    $reference = new Carl\Repo\ReferenceRepository($db);
    $regionId = $reference->regionIdForCounty('48217');
    if ($regionId === null) {
        $t->ok(true, 'no Hill County region in this database');
        return;
    }

    $seasons = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_region` pr JOIN `plant_type` pt ON pt.id = pr.plant_type_id'
        . " WHERE pr.region_id = :r AND pt.category = 'Tomato' AND pt.type = 'Roma'",
        ['r' => $regionId], 0
    );
    $t->ok($seasons >= 2, 'Roma really does have more than one season window');

    $rows = $reference->plantTypesForRegion($regionId, '2026-08-31');
    $ids = \array_column($rows, 'id');
    $t->same(\count($ids), \count(\array_unique($ids)), 'one row per plant type');

    $romas = \array_filter($rows, static fn (array $r): bool
        => $r['category'] === 'Tomato' && $r['type'] === 'Roma');
    $t->same(1, \count($romas));
});

$t->test('a plant recommended in any season is marked recommended',
    function ($t) use ($db): void {
    $reference = new Carl\Repo\ReferenceRepository($db);
    $regionId = $reference->regionIdForCounty('48217');
    if ($regionId === null) {
        $t->ok(true, 'no Hill County region in this database');
        return;
    }
    $rows = $reference->plantTypesForRegion($regionId, '2026-08-31');
    $roma = null;
    foreach ($rows as $row) {
        if ($row['category'] === 'Tomato' && $row['type'] === 'Roma') {
            $roma = $row;
        }
    }
    $t->ok($roma !== null, 'Roma is in the list');
    // Roma is recommended=N in spring and Y in fall; the aggregate takes the Y.
    $t->same(1, (int) $roma['recommended']);
    $t->same(1, (int) $roma['in_region']);
});

$t->test('without a region the whole catalog is offered with nothing marked',
    function ($t) use ($db): void {
    $reference = new Carl\Repo\ReferenceRepository($db);
    $rows = $reference->plantTypesForRegion(null, '2026-08-31');
    $total = (int) $db->value('SELECT COUNT(*) FROM `plant_type`', [], 0);
    $t->same($total, \count($rows), 'every plant is still selectable');
    foreach ($rows as $row) {
        $t->same(0, (int) $row['recommended']);
        $t->same(0, (int) $row['in_region']);
    }
});
