<?php

declare(strict_types=1);

namespace Carl\Research;

use Carl\Core\Database;
use Carl\Support\Clock;
use RuntimeException;
use ZipArchive;

/**
 * The research import route (handoff Section 9.3).
 *
 * A dataset is one zip of up to seven CSVs, produced by Claude and uploaded
 * by the admin. The whole set validates or nothing is written. On confirm the
 * upserts run in dependency order inside one transaction -- which is only
 * possible because they are pure DML (hosting Section 7).
 *
 * Idempotent by construction: every row upserts on its natural key, so
 * uploading the same zip twice converges to the same state and the page says
 * so rather than pretending it did something.
 */
final class ResearchImporter
{
    /** What the blank template ships as, and what a new dataset should say. */
    public const TEMPLATE_VERSION = 2;

    /**
     * Every version this build can still read.
     *
     * Phase 6 added `companions.csv` and took the template to 2. A version 1
     * zip stays valid, because the only difference between them is a file
     * that is optional in both -- and refusing one would strand every
     * dataset already produced, including the one in this repository.
     */
    public const READABLE_TEMPLATE_VERSIONS = [1, 2];

    /** The only entry names accepted. Anything else fails the zip. */
    private const FILES = [
        'manifest.csv', 'regions.csv', 'plant_types.csv', 'plant_region.csv',
        'pests.csv', 'pest_region.csv', 'region_guidance.csv', 'companions.csv',
    ];

    /** Header rows, exact and in order (research-template README). */
    private const HEADERS = [
        'manifest.csv' => ['key', 'value'],
        'regions.csv' => [
            'region_key', 'country', 'state', 'county', 'label', 'usda_zone', 'region_scheme',
            'region_code', 'last_frost_avg', 'last_frost_early', 'last_frost_late',
            'first_frost_avg', 'first_frost_early', 'first_frost_late', 'growing_season_days',
            'frost_stations', 'research_status', 'confidence', 'source', 'notes',
        ],
        'plant_types.csv' => [
            'category', 'type', 'plant_family', 'latin_name', 'lifecycle', 'is_tree',
            'dtm_days_min', 'dtm_days_max', 'dtm_counted_from', 'spacing_in', 'seed_depth_in',
            'germ_days_min', 'germ_days_max', 'germ_soil_temp_f_min', 'germ_soil_temp_f_max',
            'sun', 'kc_ini', 'kc_mid', 'kc_end', 'stage_days_ini', 'stage_days_dev',
            'stage_days_mid', 'stage_days_late', 'typical_start_method',
            'weeks_before_transplant_to_start', 'hardening_days_default', 'heat_tolerant',
            'confidence', 'source', 'notes',
        ],
        'plant_region.csv' => [
            'region_key', 'category', 'type', 'season', 'window_start', 'window_end', 'method',
            'recommended', 'dtm_days_min_override', 'dtm_days_max_override', 'confidence',
            'source', 'regional_notes',
        ],
        'pests.csv' => ['pest_key', 'name', 'kind', 'description', 'signs', 'treatments', 'source'],
        'pest_region.csv' => [
            'region_key', 'pest_key', 'active_start', 'active_end', 'affects_categories',
            'gdd_base_f', 'gdd_threshold', 'gdd_biofix', 'confidence', 'source', 'regional_notes',
        ],
        'region_guidance.csv' => [
            'region_key', 'topic', 'applies_to_categories', 'show_from', 'show_to',
            'guidance', 'confidence', 'source',
        ],
        // Global, like plant_types.csv, and keyed on CATEGORY rather than on
        // (category, type): nothing anybody has written says basil suits a
        // Roma but not a Celebrity. `pest_region.affects_categories` already
        // works this way.
        'companions.csv' => [
            'category', 'other_category', 'relationship', 'reason', 'confidence', 'source',
        ],
    ];

    private const ENUMS = [
        'lifecycle'            => ['annual', 'perennial'],
        'dtm_counted_from'     => ['seed', 'transplant'],
        'sun'                  => ['full', 'partial', 'shade'],
        'typical_start_method' => ['indoor', 'direct', 'transplant'],
        'confidence'           => ['verified', 'approx', 'generic'],
        'research_status'      => ['researched', 'generic', 'none'],
        'season'               => ['spring', 'summer', 'fall', 'winter'],
        'method'               => ['seed', 'transplant'],
        'kind'                 => ['pest', 'disease', 'disorder'],
        'relationship'         => ['good', 'bad'],
        'topic'                => ['season', 'soil', 'water', 'shade', 'mulch', 'seed_start',
                                   'hardening', 'frost', 'other'],
    ];

    public function __construct(
        private Database $db,
        private int $maxEntryBytes = 5_242_880,
        private int $chunkRows = 200,
    ) {
    }

    /**
     * Read and validate a zip completely. Writes nothing.
     */
    public function validate(string $zipPath, string $originalName): ImportResult
    {
        $result = new ImportResult();
        $result->filename = $originalName;

        $contents = \file_get_contents($zipPath);
        if ($contents === false) {
            $result->fail('The uploaded file could not be read.');
            return $result;
        }
        $result->sha256 = \hash('sha256', $contents);

        $files = $this->extract($zipPath, $result);
        if (!$result->ok()) {
            return $result;
        }

        $this->readManifest($files, $result);
        if (!$result->ok()) {
            return $result;
        }

        foreach (self::FILES as $name) {
            if ($name === 'manifest.csv') {
                continue;
            }
            $result->files[$name] = ['present' => isset($files[$name]), 'rows' => 0,
                                     'new' => 0, 'changed' => 0, 'same' => 0];
            if (!isset($files[$name])) {
                continue;
            }
            $rows = $this->parseCsv($name, $files[$name], $result);
            $result->rows[$name] = $rows;
            $result->files[$name]['rows'] = \count($rows);
        }

        if (!$result->ok()) {
            return $result;
        }

        $this->validateRows($result);
        if (!$result->ok()) {
            return $result;
        }

        $this->countChanges($result);
        $this->checkAlreadyImported($result);
        $this->checkDatasetVersion($result);

        return $result;
    }

    /**
     * Apply a validated result. One transaction, pure DML, upserts in
     * dependency order, 200 rows per statement (handoff Section 9.3 step 5).
     *
     * @return array<string,int> rows written per file
     */
    public function apply(ImportResult $result, int $adminUserId): array
    {
        if (!$result->ok()) {
            throw new RuntimeException('Refusing to apply an import that did not validate.');
        }

        return $this->db->transaction(function () use ($result, $adminUserId): array {
            $written = [];
            $now = \gmdate('Y-m-d H:i:s');

            // 1. Regions first: everything region-keyed needs their ids.
            $written['regions.csv'] = $this->upsertRegions($result, $now);
            $regionIds = $this->regionIdsByKey($this->allRegionKeys($result));

            // 2. The global catalogs.
            $written['plant_types.csv'] = $this->upsertPlantTypes($result, $now);
            $written['pests.csv'] = $this->upsertPests($result, $now);

            $plantIds = $this->plantTypeIdsByKey($result);
            $pestIds = $this->pestIdsByKey($result);

            // 3. The region-specific overlays, which need all three id maps.
            $written['plant_region.csv'] = $this->upsertPlantRegion($result, $regionIds, $plantIds, $now);
            $written['pest_region.csv'] = $this->upsertPestRegion($result, $regionIds, $pestIds, $now);
            $written['region_guidance.csv'] = $this->upsertGuidance($result, $regionIds, $now);
            $written['companions.csv'] = $this->upsertCompanions($result, $now);

            // 4. Mark the manifest's regions researched (Section 9.3 step 5).
            $this->markResearched($result, $now);

            $this->db->run(
                'INSERT INTO `research_import`'
                . ' (dataset_version, region_keys, filename, sha256, imported_by, imported_at,'
                . '  row_counts, status)'
                . ' VALUES (:version, :regions, :filename, :sha, :by, :at, :counts, :status)',
                [
                    'version'  => $result->datasetVersion,
                    'regions'  => \implode(';', $result->regionKeys),
                    'filename' => \substr($result->filename, 0, 190),
                    'sha'      => $result->sha256,
                    'by'       => $adminUserId,
                    'at'       => $now,
                    'counts'   => \json_encode($written),
                    'status'   => 'applied',
                ]
            );

            return $written;
        });
    }

    // -- Extraction -------------------------------------------------------

    /** @return array<string,string> filename => contents */
    private function extract(string $zipPath, ImportResult $result): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $result->fail('That file is not a readable zip archive.');
            return [];
        }

        $files = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string) $stat['name'];

                // Directory entries and macOS resource forks are ignored;
                // everything else must be one of the seven known names.
                if (\str_ends_with($name, '/') || \str_starts_with($name, '__MACOSX/')
                    || \basename($name) === '.DS_Store') {
                    continue;
                }

                $base = \basename($name);
                if (!\in_array($base, self::FILES, true)) {
                    $result->fail('The zip contains "' . $name . '", which is not one of the '
                        . 'seven expected files. Nothing was read.');
                    continue;
                }
                if ((int) $stat['size'] > $this->maxEntryBytes) {
                    $result->fail($base . ' is ' . \round((int) $stat['size'] / 1048576, 1)
                        . ' MB uncompressed, over the 5 MB limit.');
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    $result->fail($base . ' could not be read from the zip.');
                    continue;
                }
                $files[$base] = $contents;
            }
        } finally {
            $zip->close();
        }

        if (!isset($files['manifest.csv'])) {
            $result->fail('The zip has no manifest.csv. Every dataset needs one.');
        }

        return $files;
    }

    /** @param array<string,string> $files */
    private function readManifest(array $files, ImportResult $result): void
    {
        $rows = $this->parseCsv('manifest.csv', $files['manifest.csv'], $result);
        if (!$result->ok()) {
            return;
        }

        $manifest = [];
        foreach ($rows as $row) {
            $manifest[$row['key']] = $row['value'];
        }
        $result->manifest = $manifest;

        $templateVersion = (int) ($manifest['template_version'] ?? 0);
        if (!\in_array($templateVersion, self::READABLE_TEMPLATE_VERSIONS, true)) {
            // A bump requires a code change and a migration note (Section 9.2).
            $result->fail(
                'This dataset says template_version ' . ($manifest['template_version'] ?? '(missing)')
                . '; Carl reads version ' . \implode(' and ', self::READABLE_TEMPLATE_VERSIONS)
                . '. A template bump needs a code change before the file can be imported.'
            );
            return;
        }
        $result->templateVersion = $templateVersion;

        $result->datasetVersion = \trim((string) ($manifest['dataset_version'] ?? ''));
        if ($result->datasetVersion === '') {
            $result->fail('manifest.csv has no dataset_version.');
        } elseif (\preg_match('/^\d{4}-\d{2}-\d{2}\.\d+$/', $result->datasetVersion) !== 1) {
            $result->fail('dataset_version must look like YYYY-MM-DD.n, not "'
                . $result->datasetVersion . '".');
        }

        $keys = \trim((string) ($manifest['region_keys'] ?? ''));
        $result->regionKeys = $keys === ''
            ? []
            : \array_values(\array_filter(\array_map('trim', \explode(';', $keys))));

        if ($result->regionKeys === []) {
            $result->fail('manifest.csv has no region_keys.');
        }
        foreach ($result->regionKeys as $key) {
            if (\preg_match('/^[A-Z]{2}-[A-Za-z0-9]+$/', $key) !== 1) {
                $result->fail('region_key "' . $key . '" is not in the form US-<county FIPS> '
                    . 'or <ISO2>-<admin code>.');
            }
        }
    }

    /**
     * @return list<array<string,string>>
     */
    private function parseCsv(string $name, string $contents, ImportResult $result): array
    {
        // Strip a UTF-8 BOM: spreadsheets add one and it corrupts the first
        // header cell silently.
        $contents = \preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $handle = \fopen('php://temp', 'r+b');
        if ($handle === false) {
            $result->fail('Could not open ' . $name . ' for reading.');
            return [];
        }
        \fwrite($handle, $contents);
        \rewind($handle);

        $header = \fgetcsv($handle, 0, ',', '"', '');
        if (!\is_array($header)) {
            \fclose($handle);
            $result->fail($name . ' is empty.');
            return [];
        }
        $header = \array_map(static fn ($c): string => \trim((string) $c), $header);

        $expected = self::HEADERS[$name];
        if ($header !== $expected) {
            \fclose($handle);
            $result->fail(
                $name . ' header does not match the template.' . "\n"
                . '  expected: ' . \implode(',', $expected) . "\n"
                . '  found:    ' . \implode(',', $header)
            );
            return [];
        }

        $rows = [];
        $lineNumber = 1;
        while (($row = \fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lineNumber++;
            if ($row === [null] || $row === []) {
                continue;
            }
            if (\count($row) === 1 && \trim((string) $row[0]) === '') {
                continue;
            }
            if (\count($row) !== \count($expected)) {
                $result->fail($name . ' line ' . $lineNumber . ' has ' . \count($row)
                    . ' cells, expected ' . \count($expected) . '.');
                continue;
            }
            $assoc = [];
            foreach ($expected as $index => $column) {
                // An empty cell is NULL, never zero (research-template README).
                $assoc[$column] = \trim((string) $row[$index]);
            }
            $assoc['_line'] = (string) $lineNumber;
            $rows[] = $assoc;
        }
        \fclose($handle);

        return $rows;
    }

    // -- Validation -------------------------------------------------------

    private function validateRows(ImportResult $result): void
    {
        $manifestKeys = \array_flip($result->regionKeys);

        foreach ($result->rows['regions.csv'] ?? [] as $row) {
            $where = 'regions.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'region_key');
            $this->requireCell($result, $where, $row, 'label');
            $this->checkEnum($result, $where, $row, 'research_status');
            $this->checkEnum($result, $where, $row, 'confidence');
            foreach (['last_frost_avg', 'last_frost_early', 'last_frost_late',
                      'first_frost_avg', 'first_frost_early', 'first_frost_late'] as $column) {
                $this->checkMonthDay($result, $where, $row, $column);
            }
            $this->checkInt($result, $where, $row, 'growing_season_days', 0, 400);
            if ($row['region_key'] !== '' && !isset($manifestKeys[$row['region_key']])) {
                $result->warn($where . ': region_key ' . $row['region_key']
                    . ' is not listed in the manifest region_keys.');
            }
        }

        foreach ($result->rows['plant_types.csv'] ?? [] as $row) {
            $where = 'plant_types.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'category');
            $this->requireCell($result, $where, $row, 'type');
            // plant_family is what crop-rotation warnings key on, so the
            // template makes it required (research-template README).
            $this->requireCell($result, $where, $row, 'plant_family');
            $this->requireCell($result, $where, $row, 'dtm_counted_from');
            foreach (['lifecycle', 'dtm_counted_from', 'sun', 'typical_start_method',
                      'confidence'] as $column) {
                $this->checkEnum($result, $where, $row, $column);
            }
            foreach (['is_tree', 'heat_tolerant'] as $column) {
                $this->checkYesNo($result, $where, $row, $column);
            }
            foreach (['dtm_days_min', 'dtm_days_max', 'germ_days_min', 'germ_days_max',
                      'stage_days_ini', 'stage_days_dev', 'stage_days_mid', 'stage_days_late',
                      'weeks_before_transplant_to_start', 'hardening_days_default'] as $column) {
                $this->checkInt($result, $where, $row, $column, 0, 3650);
            }
            foreach (['spacing_in', 'seed_depth_in', 'kc_ini', 'kc_mid', 'kc_end'] as $column) {
                $this->checkDecimal($result, $where, $row, $column);
            }
            if ($row['dtm_days_min'] !== '' && $row['dtm_days_max'] !== ''
                && (int) $row['dtm_days_min'] > (int) $row['dtm_days_max']) {
                $result->fail($where . ': dtm_days_min is greater than dtm_days_max.');
            }
        }

        // Every plant_region row's (category, type) must exist in
        // plant_types.csv or already be in the database (Section 9.3 step 3).
        $knownPlants = $this->knownPlantKeys($result);
        foreach ($result->rows['plant_region.csv'] ?? [] as $row) {
            $where = 'plant_region.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'region_key');
            $this->requireCell($result, $where, $row, 'category');
            $this->requireCell($result, $where, $row, 'type');
            $this->requireCell($result, $where, $row, 'season');
            $this->checkEnum($result, $where, $row, 'season');
            $this->checkEnum($result, $where, $row, 'method');
            $this->checkEnum($result, $where, $row, 'confidence');
            $this->checkYesNo($result, $where, $row, 'recommended');
            $this->checkMonthDay($result, $where, $row, 'window_start');
            $this->checkMonthDay($result, $where, $row, 'window_end');

            $key = self::plantKey($row['category'], $row['type']);
            if ($row['category'] !== '' && $row['type'] !== '' && !isset($knownPlants[$key])) {
                $result->fail($where . ': ' . $row['category'] . ' / ' . $row['type']
                    . ' is not in plant_types.csv and is not already in the database.');
            }
        }

        foreach ($result->rows['pests.csv'] ?? [] as $row) {
            $where = 'pests.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'pest_key');
            $this->requireCell($result, $where, $row, 'name');
            $this->checkEnum($result, $where, $row, 'kind');
        }

        $knownPests = $this->knownPestKeys($result);
        foreach ($result->rows['pest_region.csv'] ?? [] as $row) {
            $where = 'pest_region.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'region_key');
            $this->requireCell($result, $where, $row, 'pest_key');
            $this->checkMonthDay($result, $where, $row, 'active_start');
            $this->checkMonthDay($result, $where, $row, 'active_end');
            $this->checkMonthDay($result, $where, $row, 'gdd_biofix');
            $this->checkEnum($result, $where, $row, 'confidence');
            $this->checkDecimal($result, $where, $row, 'gdd_base_f');
            $this->checkDecimal($result, $where, $row, 'gdd_threshold');
            if ($row['pest_key'] !== '' && !isset($knownPests[$row['pest_key']])) {
                $result->fail($where . ': pest_key ' . $row['pest_key']
                    . ' is not in pests.csv and is not already in the database.');
            }
        }

        // companions.csv (Phase 6). Both categories must exist, and a pair
        // must be two different ones: a row saying basil goes well with basil
        // passes every other check and means nothing.
        $knownCategories = $this->knownCategories($result);
        $seenPairs = [];
        foreach ($result->rows['companions.csv'] ?? [] as $row) {
            $where = 'companions.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'category');
            $this->requireCell($result, $where, $row, 'other_category');
            $this->requireCell($result, $where, $row, 'relationship');
            $this->checkEnum($result, $where, $row, 'relationship');
            $this->checkEnum($result, $where, $row, 'confidence');

            $one = \strtolower($row['category']);
            $two = \strtolower($row['other_category']);

            foreach ([$one => $row['category'], $two => $row['other_category']] as $key => $shown) {
                if ($shown !== '' && !isset($knownCategories[$key])) {
                    $result->fail($where . ': category "' . $shown . '" is not in '
                        . 'plant_types.csv and is not already in the catalogue.');
                }
            }

            if ($one !== '' && $one === $two) {
                $result->fail($where . ': a category cannot be its own companion.');
                continue;
            }

            // The table reads the pair in both directions, so A-with-B and
            // B-with-A are the same row -- and stating both is how a dataset
            // ends up asserting two different reasons for one fact.
            $pair = $one < $two ? $one . '~' . $two : $two . '~' . $one;
            if (isset($seenPairs[$pair])) {
                $result->fail($where . ': this pair is already stated on line '
                    . $seenPairs[$pair] . '. Companions are read in both directions, so each '
                    . 'pair belongs in the file once.');
                continue;
            }
            $seenPairs[$pair] = $row['_line'];
        }

        foreach ($result->rows['region_guidance.csv'] ?? [] as $row) {
            $where = 'region_guidance.csv line ' . $row['_line'];
            $this->requireCell($result, $where, $row, 'region_key');
            $this->requireCell($result, $where, $row, 'guidance');
            $this->requireCell($result, $where, $row, 'show_from');
            $this->requireCell($result, $where, $row, 'show_to');
            $this->checkEnum($result, $where, $row, 'topic');
            $this->checkEnum($result, $where, $row, 'confidence');
            $this->checkMonthDay($result, $where, $row, 'show_from');
            $this->checkMonthDay($result, $where, $row, 'show_to');
        }

        // Every region_key referenced anywhere must have a row in regions.csv
        // or already exist -- otherwise the overlay has nothing to attach to.
        $knownRegions = $this->knownRegionKeys($result);
        foreach (['plant_region.csv', 'pest_region.csv', 'region_guidance.csv'] as $file) {
            foreach ($result->rows[$file] ?? [] as $row) {
                if ($row['region_key'] !== '' && !isset($knownRegions[$row['region_key']])) {
                    $result->fail($file . ' line ' . $row['_line'] . ': region '
                        . $row['region_key'] . ' is not in regions.csv and is not already known.');
                }
            }
        }
    }

    /** @param array<string,string> $row */
    private function requireCell(ImportResult $result, string $where, array $row, string $column): void
    {
        if (($row[$column] ?? '') === '') {
            $result->fail($where . ': ' . $column . ' is required.');
        }
    }

    /** @param array<string,string> $row */
    private function checkEnum(ImportResult $result, string $where, array $row, string $column): void
    {
        $value = $row[$column] ?? '';
        if ($value === '' || !isset(self::ENUMS[$column])) {
            return;
        }
        if (!\in_array(\strtolower($value), self::ENUMS[$column], true)) {
            $result->fail($where . ': ' . $column . ' is "' . $value . '", expected one of '
                . \implode(', ', self::ENUMS[$column]) . '.');
        }
    }

    /** @param array<string,string> $row */
    private function checkYesNo(ImportResult $result, string $where, array $row, string $column): void
    {
        $value = $row[$column] ?? '';
        if ($value !== '' && !\in_array(\strtoupper($value), ['Y', 'N'], true)) {
            $result->fail($where . ': ' . $column . ' is "' . $value . '", expected Y or N.');
        }
    }

    /** @param array<string,string> $row */
    private function checkMonthDay(ImportResult $result, string $where, array $row, string $column): void
    {
        $value = $row[$column] ?? '';
        if ($value !== '' && !Clock::isMonthDay($value)) {
            $result->fail($where . ': ' . $column . ' is "' . $value . '", expected MM-DD.');
        }
    }

    /** @param array<string,string> $row */
    private function checkInt(ImportResult $result, string $where, array $row, string $column, int $min, int $max): void
    {
        $value = $row[$column] ?? '';
        if ($value === '') {
            return;
        }
        if (\preg_match('/^\d+$/', $value) !== 1) {
            $result->fail($where . ': ' . $column . ' is "' . $value . '", expected a whole number.');
            return;
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            $result->fail($where . ': ' . $column . ' is ' . $number . ', outside '
                . $min . '-' . $max . '.');
        }
    }

    /** @param array<string,string> $row */
    private function checkDecimal(ImportResult $result, string $where, array $row, string $column): void
    {
        $value = $row[$column] ?? '';
        if ($value !== '' && !\is_numeric($value)) {
            $result->fail($where . ': ' . $column . ' is "' . $value . '", expected a number.');
        }
    }

    // -- Change counting and idempotence ----------------------------------

    private function checkAlreadyImported(ImportResult $result): void
    {
        $seen = $this->db->value(
            'SELECT `imported_at` FROM `research_import` WHERE `sha256` = :sha ORDER BY `id` DESC LIMIT 1',
            ['sha' => $result->sha256]
        );
        if ($seen !== null) {
            $result->alreadyImported = true;
            $result->warn('This exact file was already imported on ' . $seen
                . '. Applying it again changes nothing.');
        }
    }

    /**
     * dataset_version must be greater than the last import for each region
     * (handoff Section 9.3 step 3). String comparison is correct because the
     * format is YYYY-MM-DD.n, which sorts lexicographically -- except for the
     * counter past 9, so the counter is compared numerically.
     */
    private function checkDatasetVersion(ImportResult $result): void
    {
        foreach ($result->regionKeys as $key) {
            $previous = $this->db->value(
                'SELECT `dataset_version` FROM `region` WHERE `region_key` = :key',
                ['key' => $key]
            );
            if (!\is_string($previous) || $previous === '') {
                continue;
            }
            if (self::compareVersions($result->datasetVersion, $previous) <= 0) {
                $result->fail(
                    'Region ' . $key . ' already holds dataset_version ' . $previous
                    . '. This file is ' . $result->datasetVersion
                    . ', which is not newer. Bump the version to re-import.'
                );
            }
        }
    }

    public static function compareVersions(string $a, string $b): int
    {
        [$dateA, $counterA] = \array_pad(\explode('.', $a, 2), 2, '0');
        [$dateB, $counterB] = \array_pad(\explode('.', $b, 2), 2, '0');
        if ($dateA !== $dateB) {
            return $dateA <=> $dateB;
        }
        return (int) $counterA <=> (int) $counterB;
    }

    /**
     * Per-file counts of new vs changed rows, which is what the preview shows
     * before anything is written (handoff Section 9.3 step 4).
     */
    private function countChanges(ImportResult $result): void
    {
        $existingPlants = $this->existingPlantSignatures();
        foreach ($result->rows['plant_types.csv'] ?? [] as $row) {
            $key = self::plantKey($row['category'], $row['type']);
            $this->tally($result, 'plant_types.csv', $existingPlants[$key] ?? null,
                self::signature($row, ['dtm_days_min', 'dtm_days_max', 'spacing_in', 'kc_mid', 'source']));
        }

        $existingRegions = $this->existingRegionSignatures();
        foreach ($result->rows['regions.csv'] ?? [] as $row) {
            $this->tally($result, 'regions.csv', $existingRegions[$row['region_key']] ?? null,
                self::signature($row, ['label', 'usda_zone', 'last_frost_avg', 'first_frost_avg',
                                       'research_status']));
        }

        // The remaining files are counted as new-or-existing on their natural
        // key alone: a per-column diff of every overlay row would cost more
        // statements than the preview is worth.
        $this->tallyByKey($result, 'plant_region.csv',
            'SELECT CONCAT(r.region_key, "|", pt.category, "|", pt.type, "|", pr.season) AS k'
            . ' FROM `plant_region` pr JOIN `region` r ON r.id = pr.region_id'
            . ' JOIN `plant_type` pt ON pt.id = pr.plant_type_id',
            static fn (array $row): string => $row['region_key'] . '|' . $row['category']
                . '|' . $row['type'] . '|' . $row['season']);

        $this->tallyByKey($result, 'pests.csv',
            'SELECT `pest_key` AS k FROM `pest`',
            static fn (array $row): string => $row['pest_key']);

        $this->tallyByKey($result, 'pest_region.csv',
            'SELECT CONCAT(r.region_key, "|", p.pest_key) AS k FROM `pest_region` pr'
            . ' JOIN `region` r ON r.id = pr.region_id JOIN `pest` p ON p.id = pr.pest_id',
            static fn (array $row): string => $row['region_key'] . '|' . $row['pest_key']);

        $this->tallyByKey($result, 'region_guidance.csv',
            'SELECT CONCAT(r.region_key, "|", g.topic, "|", g.applies_to_categories, "|", g.show_from) AS k'
            . ' FROM `region_guidance` g JOIN `region` r ON r.id = g.region_id',
            static fn (array $row): string => $row['region_key'] . '|' . $row['topic']
                . '|' . $row['applies_to_categories'] . '|' . $row['show_from']);

        // The pair is unordered, so both stored directions map to one key --
        // otherwise re-importing the same file would report every row new.
        $this->tallyByKey($result, 'companions.csv',
            'SELECT CONCAT(LEAST(LOWER(`category`), LOWER(`other_category`)), "~",'
            . ' GREATEST(LOWER(`category`), LOWER(`other_category`))) AS k FROM `plant_companion`',
            static function (array $row): string {
                $one = \strtolower($row['category']);
                $two = \strtolower($row['other_category']);
                return $one < $two ? $one . '~' . $two : $two . '~' . $one;
            });
    }

    private function tally(ImportResult $result, string $file, ?string $existing, string $incoming): void
    {
        if ($existing === null) {
            $result->files[$file]['new']++;
        } elseif ($existing !== $incoming) {
            $result->files[$file]['changed']++;
        } else {
            $result->files[$file]['same']++;
        }
    }

    private function tallyByKey(ImportResult $result, string $file, string $sql, callable $keyOf): void
    {
        if (($result->rows[$file] ?? []) === []) {
            return;
        }
        $known = \array_flip(\array_map(\strval(...), $this->db->column($sql)));
        foreach ($result->rows[$file] as $row) {
            if (isset($known[$keyOf($row)])) {
                $result->files[$file]['changed']++;
            } else {
                $result->files[$file]['new']++;
            }
        }
    }

    /** @param list<string> $columns */
    private static function signature(array $row, array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $row[$column] ?? '';
        }
        return \implode('|', $parts);
    }

    /** @return array<string,string> */
    private function existingPlantSignatures(): array
    {
        $rows = $this->db->all(
            'SELECT `category`, `type`, `dtm_days_min`, `dtm_days_max`, `spacing_in`, `kc_mid`, `source`'
            . ' FROM `plant_type`'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[self::plantKey((string) $row['category'], (string) $row['type'])] = \implode('|', [
                $row['dtm_days_min'] ?? '', $row['dtm_days_max'] ?? '', $row['spacing_in'] ?? '',
                $row['kc_mid'] ?? '', $row['source'] ?? '',
            ]);
        }
        return $out;
    }

    /** @return array<string,string> */
    private function existingRegionSignatures(): array
    {
        $rows = $this->db->all(
            'SELECT `region_key`, `label`, `usda_zone`, `last_frost_avg`, `first_frost_avg`,'
            . ' `research_status` FROM `region`'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['region_key']] = \implode('|', [
                $row['label'] ?? '', $row['usda_zone'] ?? '', $row['last_frost_avg'] ?? '',
                $row['first_frost_avg'] ?? '', $row['research_status'] ?? '',
            ]);
        }
        return $out;
    }

    // -- Known-key lookups -------------------------------------------------

    /**
     * Every plant CATEGORY the catalogue knows, lower-cased, counting the
     * ones this zip is about to add.
     *
     * Companions key on the category alone, so this is the check that stands
     * in for the foreign key the table cannot have.
     *
     * @return array<string,bool>
     */
    private function knownCategories(ImportResult $result): array
    {
        $known = [];
        foreach ($this->db->column('SELECT DISTINCT `category` FROM `plant_type`') as $category) {
            $known[\strtolower((string) $category)] = true;
        }
        foreach ($result->rows['plant_types.csv'] ?? [] as $row) {
            if ($row['category'] !== '') {
                $known[\strtolower($row['category'])] = true;
            }
        }
        return $known;
    }

    /** @return array<string,bool> */
    private function knownPlantKeys(ImportResult $result): array
    {
        $known = [];
        foreach ($this->db->all('SELECT `category`, `type` FROM `plant_type`') as $row) {
            $known[self::plantKey((string) $row['category'], (string) $row['type'])] = true;
        }
        foreach ($result->rows['plant_types.csv'] ?? [] as $row) {
            $known[self::plantKey($row['category'], $row['type'])] = true;
        }
        return $known;
    }

    /** @return array<string,bool> */
    private function knownPestKeys(ImportResult $result): array
    {
        $known = [];
        foreach ($this->db->column('SELECT `pest_key` FROM `pest`') as $key) {
            $known[(string) $key] = true;
        }
        foreach ($result->rows['pests.csv'] ?? [] as $row) {
            $known[$row['pest_key']] = true;
        }
        return $known;
    }

    /** @return array<string,bool> */
    private function knownRegionKeys(ImportResult $result): array
    {
        $known = [];
        foreach ($this->db->column('SELECT `region_key` FROM `region`') as $key) {
            $known[(string) $key] = true;
        }
        foreach ($result->rows['regions.csv'] ?? [] as $row) {
            $known[$row['region_key']] = true;
        }
        return $known;
    }

    /** Case-insensitive on match, stored as written on first insert. */
    public static function plantKey(string $category, string $type): string
    {
        return \strtolower(\trim($category)) . '|' . \strtolower(\trim($type));
    }

    // -- Upserts -----------------------------------------------------------

    private function upsertRegions(ImportResult $result, string $now): int
    {
        $rows = [];
        foreach ($result->rows['regions.csv'] ?? [] as $row) {
            $rows[] = [
                $row['region_key'],
                self::nullIfEmpty($row['country']) ?? 'US',
                self::nullIfEmpty($row['state']),
                self::nullIfEmpty($row['county']),
                $row['label'],
                self::nullIfEmpty($row['usda_zone']),
                self::nullIfEmpty($row['region_scheme']),
                self::nullIfEmpty($row['region_code']),
                self::nullIfEmpty($row['last_frost_avg']),
                self::nullIfEmpty($row['last_frost_early']),
                self::nullIfEmpty($row['last_frost_late']),
                self::nullIfEmpty($row['first_frost_avg']),
                self::nullIfEmpty($row['first_frost_early']),
                self::nullIfEmpty($row['first_frost_late']),
                self::intOrNull($row['growing_season_days']),
                self::nullIfEmpty($row['frost_stations']),
                self::lowerOrDefault($row['research_status'], 'researched'),
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']),
                self::nullIfEmpty($row['notes']),
                $result->datasetVersion,
                $now, $now, $now,
            ];
        }
        return $this->writeChunks('region', [
            'region_key', 'country', 'state', 'county', 'label', 'usda_zone', 'region_scheme',
            'region_code', 'last_frost_avg', 'last_frost_early', 'last_frost_late',
            'first_frost_avg', 'first_frost_early', 'first_frost_late', 'growing_season_days',
            'frost_stations', 'research_status', 'confidence', 'source', 'notes',
            'dataset_version', 'first_seen_at', 'created_at', 'updated_at',
        ], $rows, [
            'country', 'state', 'county', 'label', 'usda_zone', 'region_scheme', 'region_code',
            'last_frost_avg', 'last_frost_early', 'last_frost_late', 'first_frost_avg',
            'first_frost_early', 'first_frost_late', 'growing_season_days', 'frost_stations',
            'research_status', 'confidence', 'source', 'notes', 'dataset_version', 'updated_at',
        ]);
    }

    private function upsertPlantTypes(ImportResult $result, string $now): int
    {
        $rows = [];
        foreach ($result->rows['plant_types.csv'] ?? [] as $row) {
            $rows[] = [
                $row['category'], $row['type'], $row['plant_family'],
                self::nullIfEmpty($row['latin_name']),
                self::lowerOrDefault($row['lifecycle'], 'annual'),
                self::yesNo($row['is_tree']),
                self::intOrNull($row['dtm_days_min']), self::intOrNull($row['dtm_days_max']),
                self::lowerOrDefault($row['dtm_counted_from'], 'seed'),
                self::decimalOrNull($row['spacing_in']), self::decimalOrNull($row['seed_depth_in']),
                self::intOrNull($row['germ_days_min']), self::intOrNull($row['germ_days_max']),
                self::intOrNull($row['germ_soil_temp_f_min']), self::intOrNull($row['germ_soil_temp_f_max']),
                self::lowerOrNull($row['sun']),
                self::decimalOrNull($row['kc_ini']), self::decimalOrNull($row['kc_mid']),
                self::decimalOrNull($row['kc_end']),
                self::intOrNull($row['stage_days_ini']), self::intOrNull($row['stage_days_dev']),
                self::intOrNull($row['stage_days_mid']), self::intOrNull($row['stage_days_late']),
                self::lowerOrNull($row['typical_start_method']),
                self::intOrNull($row['weeks_before_transplant_to_start']),
                self::intOrNull($row['hardening_days_default']),
                self::yesNo($row['heat_tolerant']),
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']), self::nullIfEmpty($row['notes']),
                $result->datasetVersion, $now, $now,
            ];
        }
        $columns = [
            'category', 'type', 'plant_family', 'latin_name', 'lifecycle', 'is_tree',
            'dtm_days_min', 'dtm_days_max', 'dtm_counted_from', 'spacing_in', 'seed_depth_in',
            'germ_days_min', 'germ_days_max', 'germ_soil_temp_f_min', 'germ_soil_temp_f_max',
            'sun', 'kc_ini', 'kc_mid', 'kc_end', 'stage_days_ini', 'stage_days_dev',
            'stage_days_mid', 'stage_days_late', 'typical_start_method',
            'weeks_before_transplant_to_start', 'hardening_days_default', 'heat_tolerant',
            'confidence', 'source', 'notes', 'dataset_version', 'created_at', 'updated_at',
        ];
        $updatable = \array_values(\array_diff($columns, ['category', 'type', 'created_at']));
        return $this->writeChunks('plant_type', $columns, $rows, $updatable);
    }

    private function upsertPests(ImportResult $result, string $now): int
    {
        $rows = [];
        foreach ($result->rows['pests.csv'] ?? [] as $row) {
            $rows[] = [
                $row['pest_key'], $row['name'],
                self::lowerOrDefault($row['kind'], 'pest'),
                self::nullIfEmpty($row['description']), self::nullIfEmpty($row['signs']),
                self::nullIfEmpty($row['treatments']), self::nullIfEmpty($row['source']),
                $result->datasetVersion, $now, $now,
            ];
        }
        return $this->writeChunks('pest',
            ['pest_key', 'name', 'kind', 'description', 'signs', 'treatments', 'source',
             'dataset_version', 'created_at', 'updated_at'],
            $rows,
            ['name', 'kind', 'description', 'signs', 'treatments', 'source',
             'dataset_version', 'updated_at']);
    }

    /**
     * @param array<string,int> $regionIds
     * @param array<string,int> $plantIds
     */
    private function upsertPlantRegion(ImportResult $result, array $regionIds, array $plantIds, string $now): int
    {
        $rows = [];
        foreach ($result->rows['plant_region.csv'] ?? [] as $row) {
            $regionId = $regionIds[$row['region_key']] ?? null;
            $plantId = $plantIds[self::plantKey($row['category'], $row['type'])] ?? null;
            if ($regionId === null || $plantId === null) {
                continue;
            }
            $rows[] = [
                $regionId, $plantId, \strtolower($row['season']),
                self::nullIfEmpty($row['window_start']), self::nullIfEmpty($row['window_end']),
                self::lowerOrNull($row['method']),
                self::yesNo($row['recommended']),
                self::intOrNull($row['dtm_days_min_override']),
                self::intOrNull($row['dtm_days_max_override']),
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']), self::nullIfEmpty($row['regional_notes']),
                $result->datasetVersion, $now, $now,
            ];
        }
        return $this->writeChunks('plant_region',
            ['region_id', 'plant_type_id', 'season', 'window_start', 'window_end', 'method',
             'recommended', 'dtm_days_min_override', 'dtm_days_max_override', 'confidence',
             'source', 'regional_notes', 'dataset_version', 'created_at', 'updated_at'],
            $rows,
            ['window_start', 'window_end', 'method', 'recommended', 'dtm_days_min_override',
             'dtm_days_max_override', 'confidence', 'source', 'regional_notes',
             'dataset_version', 'updated_at']);
    }

    /**
     * @param array<string,int> $regionIds
     * @param array<string,int> $pestIds
     */
    private function upsertPestRegion(ImportResult $result, array $regionIds, array $pestIds, string $now): int
    {
        $rows = [];
        foreach ($result->rows['pest_region.csv'] ?? [] as $row) {
            $regionId = $regionIds[$row['region_key']] ?? null;
            $pestId = $pestIds[$row['pest_key']] ?? null;
            if ($regionId === null || $pestId === null) {
                continue;
            }
            $rows[] = [
                $regionId, $pestId,
                self::nullIfEmpty($row['active_start']), self::nullIfEmpty($row['active_end']),
                self::nullIfEmpty($row['affects_categories']),
                self::decimalOrNull($row['gdd_base_f']), self::decimalOrNull($row['gdd_threshold']),
                self::nullIfEmpty($row['gdd_biofix']),
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']), self::nullIfEmpty($row['regional_notes']),
                $result->datasetVersion, $now, $now,
            ];
        }
        return $this->writeChunks('pest_region',
            ['region_id', 'pest_id', 'active_start', 'active_end', 'affects_categories',
             'gdd_base_f', 'gdd_threshold', 'gdd_biofix', 'confidence', 'source',
             'regional_notes', 'dataset_version', 'created_at', 'updated_at'],
            $rows,
            ['active_start', 'active_end', 'affects_categories', 'gdd_base_f', 'gdd_threshold',
             'gdd_biofix', 'confidence', 'source', 'regional_notes', 'dataset_version', 'updated_at']);
    }

    /**
     * companions.csv (Phase 6). Global, keyed on the two category names, and
     * stored with them in lexical order.
     *
     * Normalising the direction is what makes the unique key mean what it
     * says. The pair is unordered -- the reference reads it both ways -- so
     * without this, a dataset stating "Basil with Tomato" and a later one
     * stating "Tomato with Basil" would satisfy `uq_companion_pair` twice and
     * leave the table holding both, with whichever reason was written second
     * showing on only one of the two plants' pages. Idempotence is the rule
     * migrations and the weather sync already follow, and here it costs one
     * `if`.
     *
     * The category is stored as the dataset wrote it rather than lower-cased,
     * because it is shown on the page; the ORDER is decided case-insensitively
     * so that "basil" and "Basil" cannot become two different pairs.
     */
    private function upsertCompanions(ImportResult $result, string $now): int
    {
        $rows = [];
        foreach ($result->rows['companions.csv'] ?? [] as $row) {
            $one = $row['category'];
            $two = $row['other_category'];
            if ($one === '' || $two === '' || \strtolower($one) === \strtolower($two)) {
                continue;
            }
            if (\strtolower($one) > \strtolower($two)) {
                [$one, $two] = [$two, $one];
            }
            $rows[] = [
                $one, $two,
                self::lowerOrDefault($row['relationship'], 'good'),
                self::nullIfEmpty($row['reason']),
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']),
                $result->datasetVersion, $now, $now,
            ];
        }
        return $this->writeChunks('plant_companion',
            ['category', 'other_category', 'relationship', 'reason', 'confidence',
             'source', 'dataset_version', 'created_at', 'updated_at'],
            $rows,
            ['relationship', 'reason', 'confidence', 'source', 'dataset_version', 'updated_at']);
    }

    /** @param array<string,int> $regionIds */
    private function upsertGuidance(ImportResult $result, array $regionIds, string $now): int
    {
        $rows = [];
        foreach ($result->rows['region_guidance.csv'] ?? [] as $row) {
            $regionId = $regionIds[$row['region_key']] ?? null;
            if ($regionId === null) {
                continue;
            }
            $rows[] = [
                $regionId,
                self::lowerOrDefault($row['topic'], 'other'),
                $row['applies_to_categories'],
                $row['show_from'], $row['show_to'], $row['guidance'],
                self::lowerOrNull($row['confidence']),
                self::nullIfEmpty($row['source']),
                $result->datasetVersion, $now, $now,
            ];
        }
        return $this->writeChunks('region_guidance',
            ['region_id', 'topic', 'applies_to_categories', 'show_from', 'show_to', 'guidance',
             'confidence', 'source', 'dataset_version', 'created_at', 'updated_at'],
            $rows,
            ['show_to', 'guidance', 'confidence', 'source', 'dataset_version', 'updated_at']);
    }

    private function markResearched(ImportResult $result, string $now): void
    {
        if ($result->regionKeys === []) {
            return;
        }
        $names = [];
        $params = ['status' => 'researched', 'version' => $result->datasetVersion, 'now' => $now];
        foreach ($result->regionKeys as $i => $key) {
            $names[] = ':k' . $i;
            $params['k' . $i] = $key;
        }
        $this->db->run(
            'UPDATE `region` SET `research_status` = :status, `dataset_version` = :version,'
            . ' `updated_at` = :now'
            . ' WHERE `region_key` IN (' . \implode(', ', $names) . ')',
            $params
        );
    }

    /**
     * @param list<string> $columns
     * @param list<array<int,mixed>> $rows
     * @param list<string> $updatable
     */
    private function writeChunks(string $table, array $columns, array $rows, array $updatable): int
    {
        if ($rows === []) {
            return 0;
        }
        // 200 rows per statement: the round trips are the cost, not the bytes
        // (hosting Section 9).
        foreach (\array_chunk($rows, $this->chunkRows) as $chunk) {
            $this->db->upsertChunk($table, $columns, $chunk, $updatable);
        }
        return \count($rows);
    }

    /** @param list<string> $keys @return array<string,int> */
    private function regionIdsByKey(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach (\array_values($keys) as $i => $key) {
            $names[] = ':k' . $i;
            $params['k' . $i] = $key;
        }
        $rows = $this->db->all(
            'SELECT `id`, `region_key` FROM `region` WHERE `region_key` IN (' . \implode(', ', $names) . ')',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['region_key']] = (int) $row['id'];
        }
        return $out;
    }

    /** @return list<string> every region key the dataset touches */
    private function allRegionKeys(ImportResult $result): array
    {
        $keys = \array_flip($result->regionKeys);
        foreach (['regions.csv', 'plant_region.csv', 'pest_region.csv', 'region_guidance.csv'] as $file) {
            foreach ($result->rows[$file] ?? [] as $row) {
                if (($row['region_key'] ?? '') !== '') {
                    $keys[$row['region_key']] = true;
                }
            }
        }
        return \array_keys($keys);
    }

    /** @return array<string,int> */
    private function plantTypeIdsByKey(ImportResult $result): array
    {
        $rows = $this->db->all('SELECT `id`, `category`, `type` FROM `plant_type`');
        $out = [];
        foreach ($rows as $row) {
            $out[self::plantKey((string) $row['category'], (string) $row['type'])] = (int) $row['id'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function pestIdsByKey(ImportResult $result): array
    {
        $rows = $this->db->all('SELECT `id`, `pest_key` FROM `pest`');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['pest_key']] = (int) $row['id'];
        }
        return $out;
    }

    // -- Cell coercion ------------------------------------------------------

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private static function lowerOrNull(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : \strtolower($value);
    }

    private static function lowerOrDefault(?string $value, string $default): string
    {
        return ($value === null || $value === '') ? $default : \strtolower($value);
    }

    private static function intOrNull(?string $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function decimalOrNull(?string $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function yesNo(?string $value): int
    {
        return \strtoupper((string) $value) === 'Y' ? 1 : 0;
    }
}
