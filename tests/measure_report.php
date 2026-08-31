<?php
/**
 * Measure one PDF report the way the server builds one: in a fresh process,
 * doing nothing else (handoff Section 13.2 -- "measure in Phase 4").
 *
 *   php tests/measure_report.php <user_id> <planting_id>
 *
 * Prints one line of JSON. `11_reports_test.php` runs it as a child process
 * and asserts the budget; a human can run it directly to see the numbers.
 *
 * **Why a separate process.** The figure that matters is resident memory, not
 * `memory_get_peak_usage()`: GD allocates its pixel buffers outside the Zend
 * allocator, so five open 1920x1440 images move the process by 53 MB and move
 * PHP's own counter by nothing (measured 2026-08-31, `deploy.md` Section 0.7).
 * And resident memory is a high-water mark that a long-lived process never
 * gives back -- inside a test suite that has already churned through GD, the
 * delta around one more report reads zero however much it used. A process
 * that boots, builds one report and exits is the only place the number is
 * real, and it is also exactly what a web request is.
 */

declare(strict_types=1);

use Carl\Reports\PdfBuilder;
use Carl\Reports\Series;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PhotoRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\WeatherRepository;
use Carl\Support\Photos;
use Carl\Support\ProcessMemory;
use Carl\Support\Tokens;

/** @var Carl\Core\App $app */
$app = require \dirname(__DIR__) . '/app/bootstrap.php';

$userId = (int) ($argv[1] ?? 0);
$plantingId = (int) ($argv[2] ?? 0);
if ($userId <= 0 || $plantingId <= 0) {
    \fwrite(\STDERR, "usage: php tests/measure_report.php <user_id> <planting_id>\n");
    exit(2);
}

$db = $app->db();
$plantings = new PlantingRepository($db, $userId);
$planting = $plantings->findWithDetail($plantingId);
if ($planting === null) {
    \fwrite(\STDERR, "planting {$plantingId} is not user {$userId}'s\n");
    exit(2);
}

$events = new EventRepository($db, $userId, $plantings);
$photoRows = (new PhotoRepository($db, $userId))->forPlanting($plantingId);
$photos = new Photos(
    $app->varPath('photos'),
    $app->config()->int('photos.max_bytes', 2097152),
    $app->config()->int('photos.max_megapixels', 40),
    $app->config()->int('photos.long_edge', 1920),
    $app->config()->int('photos.thumb_edge', 320),
    $app->config()->int('photos.jpeg_quality', 85),
);
$builder = new PdfBuilder($photos, $app->units(), new Tokens($app->publicPath() . '/assets/css/tokens.css'));
$series = new Series(
    $plantings, $events, new GardenRepository($db, $userId), new WeatherRepository($db), $app->units()
);

$locationId = $db->value('SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $userId]);
$today = \gmdate('Y-m-d');

/**
 * Three chart canvases at the size a phone posts them: a 380 CSS-pixel chart
 * on a 3x screen is 1140 device pixels wide, and that is the biggest thing
 * the route decodes apart from a photograph.
 */
$charts = [];
for ($c = 0; $c < 3; $c++) {
    $canvas = \imagecreatetruecolor(1140, 720);
    \imagefilledrectangle($canvas, 0, 0, 1140, 720, (int) \imagecolorallocate($canvas, 255, 255, 255));
    for ($x = 0; $x < 1140; $x += 3) {
        \imageline($canvas, $x, 700, $x, (int) (360 - \sin($x / 60) * 200),
            (int) \imagecolorallocate($canvas, 30, 90, 160));
    }
    \ob_start();
    \imagepng($canvas);
    $charts[] = (string) \ob_get_clean();
    \imagedestroy($canvas);
}

// Everything above is fixture. The baseline is taken here, so what is
// measured below is the report and only the report.
\gc_collect_cycles();
$baseline = ProcessMemory::peakBytes();
$started = \microtime(true);

$chartJpegs = [];
foreach ($charts as $png) {
    $jpeg = $photos->chartJpeg($png);
    if ($jpeg !== null) {
        $chartJpegs[] = $jpeg;
    }
}
unset($charts);

$pdf = $builder->plant(
    $series->forPlantingRow($planting, $locationId === null ? null : (int) $locationId, $today),
    $planting,
    (new ReferenceRepository($db))->researchCard((int) $planting['plant_type_id'], null),
    $events->timeline($plantingId),
    $photoRows,
    $plantings->yieldSummary($plantingId),
    $chartJpegs,
    $userId,
    $today
);

echo \json_encode([
    'seconds'         => \round(\microtime(true) - $started, 3),
    'peak_bytes'      => ProcessMemory::peakBytes(),
    'baseline_bytes'  => $baseline,
    'growth_bytes'    => ProcessMemory::peakBytes() - $baseline,
    'php_heap_bytes'  => \memory_get_peak_usage(true),
    'resident_is_real' => ProcessMemory::isReal(),
    'photos_on_file'  => \count($photoRows),
    'photos_in_report' => \min(\count($photoRows), PdfBuilder::MAX_PHOTOS),
    'charts'          => \count($chartJpegs),
    'pdf_bytes'       => \strlen($pdf),
]), "\n";
