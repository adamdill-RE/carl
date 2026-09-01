<?php

/**
 * Layered charts: the plant as the subject of its own report (Phase 12).
 *
 * weather.md Section 7.3 is the authority on charting and it has said the same
 * thing since before any chart existed: **weather is context, not the
 * subject.** On a plant-performance chart it belongs "as a muted background
 * band or a secondary axis, never competing with the performance line for
 * attention". Phases 4 to 11 had it exactly the other way round -- three
 * weather panels with the plant reduced to identical triangles -- because
 * until size (migration 024) the plant had almost no number of its own.
 *
 * The drawing is in `assets/js/charts.js` and there is no JavaScript runner
 * in this repository (hosting Section 3: no Composer, no npm, no build step),
 * so what a test can hold is the DOCUMENT the drawing reads and the MARKUP it
 * attaches to. That is where the failures that matter live anyway:
 *
 *  1. **The statement count.** The whole design of this endpoint is one
 *     statement for weather and one for events, whatever the range. Phase 12
 *     adds five columns and a hundred derived numbers and must add no
 *     statement -- and the way that breaks is a helpful lookup inside the
 *     loop that builds the series.
 *  2. **The spine.** A plant measured this morning has no weather row: the
 *     archive's last day is yesterday for a living plant. Drawing the series
 *     on the weather's dates alone silently drops today's measurement, which
 *     is the one the gardener just took.
 *  3. **What counts as a harvest.** `count_qty` carries how many germinated
 *     and how many were culled as well as how many were picked, and
 *     `duration_min` is on any event that has one. A yield line that adds up
 *     dead seedlings is wrong in a way that looks like a good season.
 *  4. **The GDD base.** weather.md Section 7.1: a single stored GDD
 *     assumption is wrong for every crop it was not chosen for. It is
 *     computed at read time and its base is named, so the curve never claims
 *     to be about this particular plant.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Reports\Series;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Repo\WeatherRepository;
use Carl\Support\Clock;
use Carl\Support\Units;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const CHART_PASSPHRASE = 'chart-test-passphrase';

$owner = (new UserRepository($db))->createWithTemporaryPassword(
    'charter' . $suffix,
    'charter' . $suffix . '@example.test',
    'Charter',
    new Password($app->config()->int('auth.bcrypt_cost', 11)),
    'user'
);

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => 'charter' . $suffix, 'password' => $owner['temporary_password']]);
$client->post('/password/reset',
    ['password' => CHART_PASSPHRASE, 'password_confirm' => CHART_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Chart Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Chart Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, (int) $owner['id']);
$plantings = new PlantingRepository($db, (int) $owner['id']);
$events = new EventRepository($db, (int) $owner['id'], $plantings);
$weather = new WeatherRepository($db);
$units = $app->units();

$gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Chart Bed' . $suffix])[0]['id'];
$gardenRows = $gardens->rows($gardenId);
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');

// The user's local today, never the server's (tests/check_test_clocks.php).
$today = $app->clock()->todayFor(
    (string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']])
);
$sownOn = (string) Clock::addDays($today, -30);

// A weather location of this file's OWN, not the one onboarding picked.
//
// Every fixture in this suite onboards with ZIP 76692, and a weather_location
// is keyed by the place rather than by the account -- so the rows sitting
// against the shared location are whatever the other case files put there.
// That is fine for "is there weather", which is all any earlier test needed,
// and useless for "is a day of 30 C over 10 C exactly ten degree-days": an
// INSERT IGNORE against a location somebody else has already filled asserts
// somebody else's numbers. GDD accumulated in Fahrenheit degrees against a
// Celsius base is 1.8 times off and entirely plausible on a page, so the
// figure has to be exact, so the fixture has to be this file's alone.
//
// The coordinates are unique per run and the row is INACTIVE: `uq_coords`
// refuses a second location at one point on earth, and a location the sync
// would pick up is a fixture that reaches out to a weather API from a test.
$db->run(
    'INSERT INTO `weather_location` (`label`, `zip`, `latitude`, `longitude`, `timezone`,'
    . ' `elevation_m`, `backfill_from`, `is_active`, `created_at`)'
    . " VALUES (:l, '76692', :lat, :lon, 'America/Chicago', 180, :b, 0, UTC_TIMESTAMP())",
    [
        'l'   => 'Chart Test ' . $suffix,
        'lat' => 31.0 + (\hexdec(\substr($suffix, 0, 4)) % 90000) / 100000,
        'lon' => -97.0 - (\hexdec(\substr($suffix, 4, 4)) % 90000) / 100000,
        'b'   => (string) Clock::addDays($today, -400),
    ]
);
$locationId = (int) $db->value(
    'SELECT `id` FROM `weather_location` WHERE `label` = :l', ['l' => 'Chart Test ' . $suffix]
);
$db->run('UPDATE `user` SET `weather_location_id` = :w WHERE `id` = :i',
    ['w' => $locationId, 'i' => $owner['id']]);

// Weather for the whole in-ground period EXCEPT today, which is what the
// archive really holds: `to` is yesterday for a living plant, so today is the
// day the union spine exists for.
for ($back = 30; $back >= 1; $back--) {
    $date = (string) Clock::addDays($today, -$back);
    $db->run(
        'INSERT INTO `weather_daily` (`location_id`, `obs_date`, `temp_max_c`, `temp_min_c`,'
        . ' `temp_mean_c`, `precip_mm`, `et0_mm`, `weather_code`, `source_model`,'
        . ' `is_provisional`, `fetched_at`)'
        . " VALUES (:l, :d, 30, 10, 20, :p, 4, 1, 'era5_seamless', 0, UTC_TIMESTAMP())",
        ['l' => $locationId, 'd' => $date, 'p' => $back % 5 === 0 ? 10.0 : 0.0]
    );
}

$plantingId = $plantings->insert([
    'plant_type_id'    => $plantTypeId,
    'garden_id'        => $gardenId,
    'garden_row_id'    => (int) $gardenRows[0]['id'],
    'label'            => 'Charted',
    'start_method'     => 'direct_sow',
    'start_date'       => $sownOn,
    'in_ground_date'   => $sownOn,
    'quantity_initial' => 10,
    'quantity_live'    => 10,
    'state'            => PlantingState::PLANTED,
    'state_changed_at' => \gmdate('Y-m-d H:i:s'),
]);

$series = new Series($plantings, $events, $gardens, $weather, $units);

/** The document as the browser sees it. */
$document = static function () use ($series, $plantingId, $locationId, $today): array {
    return $series->forPlanting($plantingId, $locationId, $today);
};

// ========================================================================
// 1. The spine
// ========================================================================

$t->group('The spine the plant is drawn on');

$t->test('a plant with no numbers of its own is still drawn on the weather days',
    function ($t) use ($document): void {
    $doc = $document();
    $plant = $doc['plant'];

    $t->same(\count($doc['days']), \count($plant['dates']),
        'nothing logged, so the spine is exactly the weather');
    $t->same(false, $plant['has']['height'], 'nothing to offer as a growth curve');
    $t->same(false, $plant['has']['yield']);
    $t->same(true, $plant['has']['weather'], 'but there is weather to draw');
});

$t->test('a measurement taken TODAY lands on the chart, though the archive stops at yesterday',
    function ($t) use ($client, $plantingId, $today, $document, $db): void {
    // This is the whole reason there are two spines. `to` is yesterday for a
    // living plant (Series::coveredRange) because today is not over and the
    // archive holds no observation for it -- so a series drawn on the weather
    // dates alone drops the measurement the gardener took an hour ago.
    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '30', 'size_unit' => 'in',
    ]);

    $doc = $document();
    $plant = $doc['plant'];

    $t->ok($doc['range']['to'] < $today, 'the weather range really does stop before today');
    $t->same($today, $plant['dates'][\count($plant['dates']) - 1],
        'and the spine reaches today anyway');
    $t->same(\count($doc['days']) + 1, \count($plant['dates']), 'by exactly one day');

    $last = \count($plant['dates']) - 1;
    $t->same(30.0, $plant['height'][$last], 'with the measurement on it');
    $t->same(null, $plant['temp_max'][$last],
        'and no weather invented for a day the archive has not got');
});

$t->test('every array the browser reads is the same length as the spine',
    function ($t) use ($document): void {
    // charts.js indexes them all against `dates` without checking. An array
    // one short is an off-by-one that draws a plausible chart of the wrong
    // days rather than an error anybody sees.
    $plant = $document()['plant'];
    $length = \count($plant['dates']);

    foreach (['height', 'diameter', 'yield', 'yield_count', 'yield_cumulative',
              'water_min', 'temp_max', 'temp_min', 'rain', 'et0', 'balance',
              'gdd', 'provisional'] as $key) {
        $t->same($length, \count($plant[$key]), $key . ' is spine-length');
    }
});

// ========================================================================
// 2. What counts as what
// ========================================================================

$t->group('A harvest line that adds up dead seedlings is wrong in a flattering direction');

$t->test('a cull carries a count and never reaches the yield series',
    function ($t) use ($client, $plantingId, $today, $document): void {
    // `count_qty` is how many germinated, how many died and how many were
    // culled as well as how many were picked (LogController::eventData). Four
    // culled seedlings on a harvest line is four tomatoes that never existed.
    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::CULLED, 'event_date' => (string) Clock::addDays($today, -5),
        'quantity' => '4', 'cull_reason_new' => 'Crowded',
    ]);

    $plant = $document()['plant'];
    $t->same(0, \array_sum($plant['yield_count']), 'nothing picked yet');
    $t->same(false, $plant['has']['yield'], 'and no harvest layer offered');
});

$t->test('a harvest does reach it, weighed and counted, in the account\'s units',
    function ($t) use ($client, $plantingId, $today, $document, $units): void {
    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::YIELDED, 'event_date' => (string) Clock::addDays($today, -4),
        'yield_weight' => '1', 'yield_weight_unit' => 'lb', 'yield_count' => '6',
    ]);

    $plant = $document()['plant'];
    $t->same(true, $plant['has']['yield']);
    $t->same(6, \array_sum($plant['yield_count']), 'six fruit');
    $t->same('lb', $plant['units']['weight'], 'and pounds, not grams');

    $last = \count($plant['yield_cumulative']) - 1;
    $t->ok(\abs($plant['yield_cumulative'][$last] - 1.0) < 0.01,
        'a pound picked is a pound to date, not 453.6 of anything');
});

$t->test('two harvests on one day are one day\'s harvest, and the total keeps climbing',
    function ($t) use ($client, $plantingId, $today, $document): void {
    $day = (string) Clock::addDays($today, -3);
    foreach (['2', '3'] as $ounces) {
        $client->post('/log/' . $plantingId, [
            'event_type' => EventType::YIELDED, 'event_date' => $day,
            'yield_weight' => $ounces, 'yield_weight_unit' => 'oz',
        ]);
    }

    $plant = $document()['plant'];
    $index = \array_search($day, $plant['dates'], true);
    $t->ok($index !== false, 'the day is on the spine');
    // Five ounces is 0.3125 lb; two bars stacked on one x would be a lie
    // about how many times the plant was picked.
    $t->ok(\abs($plant['yield'][$index] - 0.3125) < 0.001,
        'both picks add up into one day: ' . $plant['yield'][$index]);

    $running = $plant['yield_cumulative'];
    for ($i = 1; $i < \count($running); $i++) {
        $t->ok($running[$i] >= $running[$i - 1] - 0.0001,
            'a harvest to date never goes down');
    }
});

$t->test('watering minutes are watering, and a hardening duration is not',
    function ($t) use ($client, $plantingId, $today, $document): void {
    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::WATERED, 'event_date' => (string) Clock::addDays($today, -2),
        'duration_min' => '20',
    ]);
    // A duration posted with an action that has no duration must not become a
    // watering: the field is read for every event (LogController::eventData)
    // and only `watered` means minutes with a hose.
    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::NOTE, 'event_date' => (string) Clock::addDays($today, -2),
        'duration_min' => '999', 'narrative' => 'Not a watering',
    ]);

    $plant = $document()['plant'];
    $t->same(20, \array_sum($plant['water_min']), 'twenty minutes, not a thousand and nineteen');
    $t->same(true, $plant['has']['water']);
});

// ========================================================================
// 3. Growing degree days
// ========================================================================

$t->group('Growing degree days, computed at read time and never stored');

$t->test('the base is named, so the curve never claims to be about this crop',
    function ($t) use ($document, $units): void {
    // weather.md Section 7.1: a stored GDD column bakes in one crop's base and
    // is wrong for every other. The base is a constant with a comment and it
    // is PRINTED, which is what keeps it honest.
    $plant = $document()['plant'];
    $t->same($units->temperature(Series::GDD_BASE_C, 0), $plant['units']['gdd_base']);
    $t->same(10.0, Series::GDD_BASE_C, 'ten degrees C is the warm-season default');
});

$t->test('it accumulates, and a day of 30/10 against a base of 10 is ten degree-days',
    function ($t) use ($document): void {
    // The fixture is 30 C max and 10 C min every day: mean 20, base 10, so
    // exactly 10 GDD a day. A GDD accumulated in Fahrenheit degrees against a
    // Celsius base is 1.8 times off and looks entirely plausible on a page,
    // which is why the number is asserted and not just the shape.
    $plant = $document()['plant'];
    $gdd = $plant['gdd'];

    for ($i = 1; $i < \count($gdd); $i++) {
        $t->ok($gdd[$i] >= $gdd[$i - 1], 'an accumulation never goes down');
    }

    $weatherDays = 0;
    foreach ($plant['temp_max'] as $value) {
        if ($value !== null) {
            $weatherDays++;
        }
    }
    $t->same((float) ($weatherDays * 10), $gdd[\count($gdd) - 1],
        'ten a day across ' . $weatherDays . ' days with weather');
});

$t->test('a day the archive has not got adds nothing rather than a guess',
    function ($t) use ($document): void {
    $plant = $document()['plant'];
    $last = \count($plant['dates']) - 1;
    $t->same(null, $plant['temp_max'][$last], 'today has no observation');
    $t->same($plant['gdd'][$last - 1], $plant['gdd'][$last],
        'so today adds nothing to the total');
});

// ========================================================================
// 4. It still costs what it cost
// ========================================================================

$t->group('Five more columns and a hundred derived numbers, and no more statements');

$t->test('the whole document is still one statement for weather and one for events',
    function ($t) use ($series, $plantingId, $locationId, $today, $db): void {
    $before = $db->statementCount();
    $doc = $series->forPlanting($plantingId, $locationId, $today);
    $spent = $db->statementCount() - $before;

    $t->same(3, $spent, 'one for the planting, one for the weather, one for the events');
    $t->ok(\count($doc['plant']['dates']) > 25,
        'over a spine of ' . \count($doc['plant']['dates']) . ' days');
});

// ========================================================================
// 5. What the page hands the script
// ========================================================================

$t->group('The markup the drawing attaches to');

$t->test('the plant report carries the panels, the pickers and the PDF fields',
    function ($t) use ($client, $plantingId): void {
    $response = $client->get('/plants/' . $plantingId);
    $t->same(200, $response->status);
    $body = $response->body;

    $t->contains('data-series-url', $body, 'the chart block is on the page');
    $t->contains('data-chart="build"', $body, 'the layered panel');
    $t->contains('data-chart="compare"', $body, 'and the scatter');
    $t->contains('data-chart-pick="plant"', $body, 'the subject picker');
    $t->contains('data-chart-pick="weather"', $body, 'and the context picker');
    $t->contains('data-chart-pick="lag"', $body, 'with the lag window the scatter needs');

    // The three the PDF posts are still drawn, and still posted. Losing
    // either half is a report with no pictures in it and no error anywhere.
    foreach (['temp', 'rain', 'et0'] as $key) {
        $t->contains('data-chart="' . $key . '"', $body, $key . ' is still drawn');
        $t->contains('name="chart_' . $key . '"', $body, $key . ' is still posted');
    }

    $t->contains('assets/vendor/chart.umd.js', $body, 'and the vendored library is loaded');
});

$t->test('a plant with measurements but no weather still gets a chart',
    function ($t) use ($client, $plantings, $plantTypeId, $gardenId, $today, $db, $owner): void {
    // Before Phase 12 the whole block was gated on `days !== []`, so a plant
    // in a county nobody has fetched weather for had no chart at all -- even
    // once it had a growth curve of its own, which needs no weather.
    $db->run('UPDATE `user` SET `weather_location_id` = NULL WHERE `id` = :i',
        ['i' => $owner['id']]);

    $id = $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $gardenId,
        'label'            => 'No weather',
        'start_method'     => 'direct_sow',
        'start_date'       => (string) Clock::addDays($today, -10),
        'in_ground_date'   => (string) Clock::addDays($today, -10),
        'quantity_initial' => 1,
        'quantity_live'    => 1,
        'state'            => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '12', 'size_unit' => 'in',
    ]);

    $response = $client->get('/plants/' . $id);
    $t->same(200, $response->status);
    $t->contains('data-series-url', $response->body,
        'a growth curve is a chart even with no weather behind it');
    $t->notContains('Total rain', $response->body,
        'but the weather totals are not printed for weather nobody has');
});

$t->test('and a plant with nothing at all gets no chart and no broken block',
    function ($t) use ($client, $plantings, $plantTypeId, $gardenId, $today): void {
    $id = $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $gardenId,
        'label'            => 'Nothing at all',
        'start_method'     => 'indoor_seed',
        'start_date'       => $today,
        'quantity_initial' => 1,
        'quantity_live'    => 1,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    $response = $client->get('/plants/' . $id);
    $t->same(200, $response->status, 'the page is still a page');
    $t->notContains('data-series-url', $response->body, 'with no empty chart on it');
    $t->notContains('chart.umd.js', $response->body, 'and no 200 KB library fetched to draw it');
});
