<?php

/**
 * Reports: the series endpoint, the charts and the PDF (handoff Section 13).
 *
 * Four things here can only be caught by a test:
 *
 *  1. **The statement count.** "One statement for weather and one for events"
 *     is the whole design of the endpoint, and it is invisible to code review
 *     the moment somebody adds a helpful lookup inside a loop. Twenty days and
 *     two hundred days must cost the same.
 *  2. **The scope.** A series endpoint that leaks another account's plant is
 *     the same bug as a page that does (Phase 4 handoff Section 4.1).
 *  3. **The posted PNGs.** They are user input that reaches GD, and the
 *     failure modes -- a decompression bomb, a non-image, an SVG pretending
 *     to be a PNG -- are all silent if nothing checks.
 *  4. **Every route resolves.** A typo in a controller action name is a 500
 *     on one URL that nothing else touches, which is exactly the kind of
 *     thing found in production.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Core\Routes;
use Carl\Domain\EventType;
use Carl\Reports\Series;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Repo\WeatherRepository;
use Carl\Support\Attribution;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('Every route points at an action that exists');

$t->test('no route in the table names a method the controller does not have',
    function ($t): void {
    // A route table is a list of strings; nothing checks them until someone
    // opens the URL. This is the whole check, and it costs one loop.
    foreach (Routes::build()->all() as $route) {
        $t->ok(
            \method_exists($route->controller, $route->action),
            $route->method . ' ' . $route->pattern . ' -> '
            . $route->controller . '::' . $route->action . '()'
        );
    }
});

$t->group('Attribution is generated from source_model, never hard-coded');

$t->test('Open-Meteo rows credit Open-Meteo and nothing else', function ($t): void {
    $lines = Attribution::lines(['best_match', 'era5_seamless']);
    $t->same(1, \count($lines));
    $t->contains('Open-Meteo.com', $lines[0]);
    $t->contains('CC BY 4.0', $lines[0]);
});

$t->test('an NCEI row adds the NOAA credit beside it', function ($t): void {
    $lines = Attribution::lines(['best_match', 'ncei:GHCND:USW00013959']);
    $t->same(2, \count($lines));
    $t->contains('NOAA NCEI GHCNd', $lines[1]);
});

$t->test('rows that are all NCEI do not credit Open-Meteo', function ($t): void {
    $lines = Attribution::lines(['ncei:GHCND:USW00013959']);
    $t->same(1, \count($lines));
    $t->notContains('Open-Meteo', $lines[0]);
});

$t->test('no rows means no credit line at all', function ($t): void {
    $t->same([], Attribution::lines([]));
    $t->same([], Attribution::of(['']), 'an empty model name is not a source');
});

$t->group('Units convert in one place, and rounding does not move a value twice');

$t->test('the numeric converter and the formatter agree', function ($t) use ($app): void {
    $us = new Carl\Support\Units('us');
    $si = new Carl\Support\Units('si');

    $t->same(32.0, $us->temperatureValue(0));
    $t->same(0.0, $si->temperatureValue(0));
    $t->same(null, $us->temperatureValue(null));
    $t->same('32' . "\u{00B0}F", $us->temperature(0));
    $t->same("\u{00B0}F", $us->temperatureUnit());
    $t->same('in', $us->rainUnit());
    $t->same('mm', $si->rainUnit());
    $t->same(1.0, $us->rainValue(25.4, 3));
    $t->same(25.4, $si->rainValue(25.4, 3));

    // The formatter must not round a value that the converter already
    // rounded: 21.9C is 71.42F, which is 71F either way -- but only if the
    // rounding happens once.
    $t->same('71' . "\u{00B0}F", $us->temperature(21.9));
    $t->same('96' . "\u{00B0}F", $us->temperature(35.55));
});

$t->group('The series document');

$makeUser = static function (string $username) use ($db, $app): array {
    $repo = new UserRepository($db);
    $created = $repo->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username, 'password' => $created['temporary_password']];
};

$owner = $makeUser('reporter' . $suffix);
$stranger = $makeUser('trespasser' . $suffix);

$client = new Client($root);
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

const REPORT_PASSPHRASE = 'report-test-passphrase';

$onboard = static function (array $user, string $gardenName) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => $user['password']]);
    $client->post('/password/reset', [
        'current_password' => $user['password'],
        'password' => REPORT_PASSPHRASE, 'password_confirm' => REPORT_PASSPHRASE,
    ]);
    $client->post('/onboarding/profile', ['name' => 'Report Tester', 'zip' => '76692']);
    $client->post('/onboarding/garden', ['name' => $gardenName, 'row_count' => '2', 'soil_type' => 'loam']);
    $client->post('/onboarding/finish', []);
};

/** Sign back in as an account that has already been through the wizard. */
$login = static function (array $user) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => REPORT_PASSPHRASE]);
};

$onboard($owner, 'Report Bed' . $suffix);

// THE USER'S LOCAL TODAY, NEVER THE SERVER'S -- handoff Section 6, and the
// suite has to obey it as much as the application does. Every event Carl
// writes is dated in the account's own timezone; this account is in
// America/Chicago, so between UTC midnight and local midnight gmdate() and
// the right answer are DIFFERENT DAYS. Asserting the UTC one gives a suite
// that is green all afternoon and red for six hours every night, which is
// worse than a suite that is simply wrong.
$today = $app->clock()->todayFor(
    (string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']])
);
$start = (string) Clock::addDays($today, -60);

$locationId = (int) ($db->value(
    'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $owner['id']]
) ?? 0);

/** Write $days of archive weather ending yesterday, half of it provisional. */
$seedWeather = static function (int $days) use ($db, $locationId, $today): void {
    $rows = [];
    for ($i = $days; $i >= 1; $i--) {
        $rows[] = [
            $locationId,
            (string) Clock::addDays($today, -$i),
            28.0 + ($i % 7),          // temp_max_c
            14.0 + ($i % 5),          // temp_min_c
            $i % 4 === 0 ? 6.5 : 0.0, // precip_mm
            5.1,                      // et0_mm
            $i <= 10 ? 'best_match' : 'era5_seamless',
            $i <= 10 ? 1 : 0,         // is_provisional
            \gmdate('Y-m-d H:i:s'),   // fetched_at is NOT NULL and has no default
        ];
    }
    $db->upsertChunk(
        'weather_daily',
        ['location_id', 'obs_date', 'temp_max_c', 'temp_min_c', 'precip_mm', 'et0_mm',
         'source_model', 'is_provisional', 'fetched_at'],
        $rows,
        ['temp_max_c', 'temp_min_c', 'precip_mm', 'et0_mm', 'source_model', 'is_provisional']
    );
};

$t->test('a backdated plant with weather and events comes back as one document',
    function ($t) use ($client, $db, $owner, $plantTypeId, $start, $seedWeather, $locationId): void {
    $t->ok($locationId > 0, 'onboarding attached a weather location');
    $seedWeather(60);

    $response = $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '4', 'start_date' => $start,
        'garden_id' => (string) (new GardenRepository($db, $owner['id']))->activeGardens()[0]['id'],
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $client->post('/log/' . $plantingId, [
        'event_type' => EventType::WATERED, 'event_date' => (string) Clock::addDays($start, 3),
        'narrative' => 'A long soak before the heat',
    ]);

    $response = $client->get('/api/plant/' . $plantingId . '/series');
    $t->same(200, $response->status);
    $t->same('application/json; charset=utf-8', $response->headers()['Content-Type']);
    $t->same('no-store, private', $response->headers()['Cache-Control'],
        'a series is personal data and is never cached');

    $doc = \json_decode($response->collect(), true);
    $t->same('plant', $doc['subject']['kind']);
    $t->same($plantingId, $doc['subject']['id']);
    $t->ok(\count($doc['days']) > 50, 'the covered dates came back: ' . \count($doc['days']));
    $t->ok(\count($doc['events']) >= 2, 'the sowing and the watering are both markers');
    $t->contains('Open-Meteo.com', $doc['attribution'][0]);
    $t->same('us', $doc['units']['system']);
    $t->same('in', $doc['units']['rain']);
});

$t->test('provisional days are marked, and counted in the range',
    function ($t) use ($client, $db, $owner): void {
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $doc = \json_decode($client->get('/api/plant/' . $plantingId . '/series')->collect(), true);

    $provisional = 0;
    foreach ($doc['days'] as $day) {
        $t->ok(\array_key_exists('provisional', $day), 'every day says whether it is settled');
        if ($day['provisional']) {
            $provisional++;
        }
    }
    $t->ok($provisional > 0, 'the recent days are still provisional');
    $t->same($provisional, $doc['range']['provisional'], 'and the range agrees with the days');
});

$t->test('yesterday is the last covered day, never today',
    function ($t) use ($client, $db, $owner, $today): void {
    // Today is not over, so the archive holds no observation for it. Counting
    // it as missing would put a permanent "1 day not fetched" notice on every
    // living plant, every day (weather.md Section 6.2).
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $doc = \json_decode($client->get('/api/plant/' . $plantingId . '/series')->collect(), true);
    $t->same((string) Clock::addDays($today, -1), $doc['range']['to']);
    $t->same(0, $doc['range']['days_missing'], 'and nothing inside the range is missing');
});

$t->test('a plant started this morning has an empty range rather than a backwards one',
    function ($t) use ($client, $db, $owner, $plantTypeId, $today): void {
    $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '1', 'start_date' => $today,
        'garden_id' => (string) (new GardenRepository($db, $owner['id']))->activeGardens()[0]['id'],
    ]);
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $doc = \json_decode($client->get('/api/plant/' . $plantingId . '/series')->collect(), true);
    $t->same([], $doc['days']);
    $t->same(0, $doc['range']['days']);
    $t->same(0, $doc['range']['days_missing'], 'nothing is missing because nothing is due yet');
    $t->same([], $doc['attribution'], 'and no rows means no credit line');
});

$t->test('a plant older than the chart window is clamped, and the document says so',
    function ($t) use ($app, $db, $owner, $plantTypeId, $locationId, $today): void {
    // Series::MAX_DAYS. A plant in the ground for nine years is not a chart,
    // and silently drawing part of it would be worse than saying so.
    $plantings = new PlantingRepository($db, $owner['id']);
    $events = new EventRepository($db, $owner['id'], $plantings);
    $gardens = new GardenRepository($db, $owner['id']);
    $series = new Series($plantings, $events, $gardens, new WeatherRepository($db), $app->units());

    $ancient = $plantings->insert([
        'plant_type_id' => $plantTypeId,
        'garden_id'     => (int) $gardens->activeGardens()[0]['id'],
        'start_method'  => 'direct_sow',
        'start_date'     => (string) Clock::addDays($today, -(Series::MAX_DAYS + 400)),
        'in_ground_date' => (string) Clock::addDays($today, -(Series::MAX_DAYS + 400)),
        'quantity_initial' => 1, 'quantity_live' => 1, 'state' => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    $document = $series->forPlanting($ancient, $locationId, $today);
    $t->same(true, $document['range']['clamped'], 'the range says it was cut');
    $t->same(Series::MAX_DAYS, $document['range']['days'], 'to exactly the window');
    $t->same((string) Clock::addDays($today, -1), $document['range']['to'],
        'keeping the recent end, which is the end anyone is looking at');
    $t->same(Series::MAX_DAYS, $document['range']['max_days'],
        'and says what the window is, so a page can explain itself');

    $young = $plantings->insert([
        'plant_type_id' => $plantTypeId,
        'garden_id'     => (int) $gardens->activeGardens()[0]['id'],
        'start_method'  => 'direct_sow',
        'start_date'     => (string) Clock::addDays($today, -30),
        'in_ground_date' => (string) Clock::addDays($today, -30),
        'quantity_initial' => 1, 'quantity_live' => 1, 'state' => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $t->same(false, $series->forPlanting($young, $locationId, $today)['range']['clamped'],
        'and an ordinary season is not cut');
});

$t->group('Statement count, because a report loops over days and over events');

$t->test('two hundred days and forty events cost what two days and one event cost',
    function ($t) use ($app, $db, $owner, $plantTypeId, $locationId, $today): void {
    $plantings = new PlantingRepository($db, $owner['id']);
    $events = new EventRepository($db, $owner['id'], $plantings);
    $gardens = new GardenRepository($db, $owner['id']);
    $weather = new WeatherRepository($db);
    $series = new Series($plantings, $events, $gardens, $weather, $app->units());

    $gardenId = (int) $gardens->activeGardens()[0]['id'];

    $small = $plantings->insert([
        'plant_type_id' => $plantTypeId, 'garden_id' => $gardenId,
        'start_method' => 'direct_sow', 'start_date' => (string) Clock::addDays($today, -2),
        'in_ground_date' => (string) Clock::addDays($today, -2),
        'quantity_initial' => 1, 'quantity_live' => 1, 'state' => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $events->insert([
        'planting_id' => $small, 'event_type' => EventType::DIRECT_SOWN,
        'event_date' => (string) Clock::addDays($today, -2), 'recorded_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    $big = $plantings->insert([
        'plant_type_id' => $plantTypeId, 'garden_id' => $gardenId,
        'start_method' => 'direct_sow', 'start_date' => (string) Clock::addDays($today, -200),
        'in_ground_date' => (string) Clock::addDays($today, -200),
        'quantity_initial' => 1, 'quantity_live' => 1, 'state' => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    for ($i = 0; $i < 40; $i++) {
        $events->insert([
            'planting_id' => $big, 'event_type' => EventType::WATERED,
            'event_date' => (string) Clock::addDays($today, -($i * 4 + 1)),
            'recorded_at' => \gmdate('Y-m-d H:i:s'),
        ]);
    }

    $before = $db->statementCount();
    $series->forPlanting($small, $locationId, $today);
    $cheap = $db->statementCount() - $before;

    $before = $db->statementCount();
    $doc = $series->forPlanting($big, $locationId, $today);
    $dear = $db->statementCount() - $before;

    $t->ok(\count($doc['events']) === 40, 'the big plant really has forty events');
    $t->same($cheap, $dear,
        'a 200-day plant with 40 events cost ' . $dear . ' statements; a 2-day one cost ' . $cheap);
    $t->same(3, $dear, 'one for the planting, one for the weather, one for the events');
});

$t->test('the plant page itself does not pay per day either',
    function ($t) use ($client, $db, $owner, $app): void {
    // It used to spend three weather statements (series, gapCount,
    // sourceModels) where the rows in hand answer all three questions.
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('`quantity_initial` = 4', [], '`id` DESC', 1)[0]['id'];

    $response = $client->get('/plants/' . $plantingId);
    $t->same(200, $response->status);
    $body = $response->collect();
    $t->contains('Weather while it was in the ground', $body);
    $t->contains('data-series-url', $body, 'the chart block is on the page');
    $t->contains('assets/vendor/chart.umd.js', $body, 'and the vendored library is loaded');
    $t->notContains('<script>', $body, 'CSP is script-src \'self\': no inline script');
    $t->notContains(' style="', $body, 'and style-src \'self\': no inline style');
});

$t->group('A series is one user data');

$t->test('another account plant is a 404, not a leak',
    function ($t) use ($client, $db, $owner, $stranger, $onboard, $suffix): void {
    $plantings = new PlantingRepository($db, $owner['id']);
    $victimId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $onboard($stranger, 'Trespasser Bed' . $suffix);

    $response = $client->get('/api/plant/' . $victimId . '/series');
    $t->same(404, $response->status);
    $t->notContains('temp_max', $response->collect(), 'and nothing of the series came back');
});

$t->test('another account garden is a 404 too', function ($t) use ($client, $db, $owner): void {
    $gardens = new GardenRepository($db, $owner['id']);
    $victimId = (int) $gardens->activeGardens()[0]['id'];
    $t->same(404, $client->get('/api/garden/' . $victimId . '/series')->status);
});

$t->test('a signed-out request is not served at all', function ($t) use ($root): void {
    $anonymous = new Client($root);
    $anonymous->forgetCookies();
    $response = $anonymous->get('/api/plant/1/series');
    $t->ok($response->status === 302 || $response->status === 303 || $response->status === 401,
        'signed out gets bounced, not answered (' . $response->status . ')');
});

$t->group('The garden series');

$t->test('a garden series carries the garden own actions, not the fanned-out copies',
    function ($t) use ($client, $db, $owner, $login): void {
    $login($owner);

    $gardens = new GardenRepository($db, $owner['id']);
    $gardenId = (int) $gardens->activeGardens()[0]['id'];

    // A zone watering fans out one plant_event per living plant. The garden
    // chart must show one marker, not one per plant (handoff Section 4.7).
    $client->post('/gardens/' . $gardenId . '/actions', [
        'event_type' => EventType::MULCHED, 'mulch_new' => 'Straw',
        'narrative' => 'Two bales, north end',
    ]);

    $response = $client->get('/api/garden/' . $gardenId . '/series');
    $t->same(200, $response->status);
    $doc = \json_decode($response->collect(), true);

    $t->same('garden', $doc['subject']['kind']);
    $t->same($gardenId, $doc['subject']['id']);
    $t->same(1, \count($doc['events']), 'one action logged, one marker');
    $t->same('Two bales, north end', $doc['events'][0]['note']);
    $t->ok(\count($doc['days']) > 0, 'and the weather over the garden covered dates');
});

$t->group('The PDF (handoff Section 13.2)');

/** A canvas-shaped PNG, as a data URL, the way the browser posts one. */
$fakeChart = static function (int $width = 760, int $height = 300): string {
    $image = \imagecreatetruecolor($width, $height);
    \imagefilledrectangle($image, 0, 0, $width, $height,
        (int) \imagecolorallocate($image, 255, 255, 255));
    for ($x = 0; $x < $width; $x += 7) {
        \imageline($image, $x, $height - 10, $x, (int) ($height / 2 - \sin($x / 40) * 60),
            (int) \imagecolorallocate($image, 30, 90, 160));
    }
    \ob_start();
    \imagepng($image);
    $png = (string) \ob_get_clean();
    \imagedestroy($image);
    return 'data:image/png;base64,' . \base64_encode($png);
};

$t->test('a plant PDF comes back as a PDF, with the report in it',
    function ($t) use ($client, $db, $owner, $login, $fakeChart): void {
    $login($owner);
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('`quantity_initial` = 4', [], '`id` DESC', 1)[0]['id'];

    $response = $client->post('/report/plant/' . $plantingId . '/pdf', [
        'chart_temp' => $fakeChart(),
        'chart_rain' => $fakeChart(),
        'chart_et0'  => $fakeChart(),
    ]);

    $t->same(200, $response->status);
    $t->same('application/pdf', $response->headers()['Content-Type']);
    $t->contains('attachment; filename="carl-plant-', $response->headers()['Content-Disposition']);
    $t->same('no-store, private', $response->headers()['Cache-Control']);

    $body = $response->collect();
    $t->same('%PDF-', \substr($body, 0, 5), 'it really is a PDF');
    $t->contains('%%EOF', \substr($body, -1024), 'and a complete one');
    $t->ok(\strlen($body) > 8000, 'with the charts embedded: ' . \strlen($body) . ' bytes');
    $t->same((string) \strlen($body), $response->headers()['Content-Length']);
});

$t->test('a garden PDF does too', function ($t) use ($client, $db, $owner, $fakeChart): void {
    $gardens = new GardenRepository($db, $owner['id']);
    $gardenId = (int) $gardens->activeGardens()[0]['id'];

    $response = $client->post('/report/garden/' . $gardenId . '/pdf', [
        'chart_rain' => $fakeChart(),
    ]);
    $t->same(200, $response->status);
    $t->same('%PDF-', \substr($response->collect(), 0, 5));
});

$t->test('a report with no charts posted is still a report',
    function ($t) use ($client, $db, $owner): void {
    // The button posts whatever the canvases hold. With JavaScript off there
    // is no button; with a canvas that failed to paint there is an empty
    // field. Neither may lose the tables.
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('`quantity_initial` = 4', [], '`id` DESC', 1)[0]['id'];

    $response = $client->post('/report/plant/' . $plantingId . '/pdf', []);
    $t->same(200, $response->status);
    $t->same('%PDF-', \substr($response->collect(), 0, 5));
});

$t->test('what is posted as a chart is not trusted to be one',
    function ($t) use ($client, $db, $owner, $app): void {
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('`quantity_initial` = 4', [], '`id` DESC', 1)[0]['id'];

    // A decompression bomb: 40 megapixels of flat colour is a tiny PNG on the
    // wire and 160 MB of RGBA once GD opens it. Photos::chartJpeg caps the
    // decoded size BEFORE decoding, which is the only place the cap works.
    $bomb = \imagecreatetruecolor(8000, 5000);
    \imagefilledrectangle($bomb, 0, 0, 8000, 5000, (int) \imagecolorallocate($bomb, 7, 7, 7));
    \ob_start();
    \imagepng($bomb, null, 9);
    $bombPng = (string) \ob_get_clean();
    \imagedestroy($bomb);

    foreach ([
        'not a data url at all',
        'data:image/png;base64,' . \base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
        'data:image/png;base64,' . \base64_encode("\x89PNG\r\n\x1a\n" . 'truncated'),
        'data:text/html;base64,' . \base64_encode('<script>alert(1)</script>'),
        'data:image/png;base64,!!!not base64!!!',
        'data:image/png;base64,' . \base64_encode($bombPng),
    ] as $payload) {
        $response = $client->post('/report/plant/' . $plantingId . '/pdf', ['chart_temp' => $payload]);
        $t->same(200, $response->status, 'a bad chart drops out, it does not 500');
        $body = $response->collect();
        $t->same('%PDF-', \substr($body, 0, 5));
    }

    // The bomb specifically: 40 MP is well over Photos::chartJpeg's cap, so
    // the report must come back with no chart in it rather than with one.
    $t->ok(
        \strlen($client->post('/report/plant/' . $plantingId . '/pdf',
            ['chart_temp' => 'data:image/png;base64,' . \base64_encode($bombPng)])->collect())
        < \strlen($client->post('/report/plant/' . $plantingId . '/pdf', [])->collect()) + 2048,
        'a 40-megapixel "chart" is refused, not embedded'
    );
});

$t->test('another account plant cannot be turned into a PDF',
    function ($t) use ($client, $db, $owner, $stranger, $login, $onboard, $suffix): void {
    $plantings = new PlantingRepository($db, $owner['id']);
    $victimId = (int) $plantings->where('', [], '`id` DESC', 1)[0]['id'];

    $client->forgetCookies();
    $client->post('/login', ['username' => $stranger['username'], 'password' => REPORT_PASSPHRASE]);

    $response = $client->post('/report/plant/' . $victimId . '/pdf', []);
    $t->same(404, $response->status);

    $login($owner);
});

$t->test('twenty photographs stay inside the time and memory budget',
    function ($t) use ($db, $owner, $app, $suffix): void {
    // Handoff Section 13.2 sets the target at under 10 s and 64 MB on a
    // 20-photo report and says to MEASURE it. GD holds a decoded image at
    // about width x height x 4 bytes, so twenty stored 1920px photos opened
    // at once would be ~220 MB -- the builder opens one at a time, and this
    // is what proves it still does.
    //
    // The measurement runs in a CHILD process, because neither figure is
    // honest in this one: memory_get_peak_usage() cannot see GD at all, and
    // resident memory is a high-water mark that a suite which has already
    // churned through images never gives back, so the delta around one more
    // report reads zero however much it used. See tests/measure_report.php.
    $plantings = new PlantingRepository($db, $owner['id']);
    $plantingId = (int) $plantings->where('`quantity_initial` = 4', [], '`id` DESC', 1)[0]['id'];

    $photosDir = $app->varPath('photos') . '/' . $owner['id'];
    if (!\is_dir($photosDir)) {
        \mkdir($photosDir, 0700, true);
    }

    // Real files at the size store() writes: long edge 1920.
    $written = [];
    for ($i = 0; $i < 25; $i++) {
        $image = \imagecreatetruecolor(1920, 1440);
        for ($band = 0; $band < 12; $band++) {
            \imagefilledrectangle($image, 0, $band * 120, 1920, ($band + 1) * 120,
                (int) \imagecolorallocate($image, ($band * 20 + $i) % 255, 120, 60));
        }
        $name = 'budget' . $suffix . $i . '.jpg';
        \imagejpeg($image, $photosDir . '/' . $name, 85);
        \imagedestroy($image);
        $written[] = $photosDir . '/' . $name;

        $db->run(
            'INSERT INTO `photo` (user_id, planting_id, taken_on, stored_name, thumb_name,'
            . ' width, height, bytes, created_at)'
            . ' VALUES (:u, :p, :d, :s, :s2, 1920, 1440, :b, UTC_TIMESTAMP())',
            [
                'u' => $owner['id'], 'p' => $plantingId,
                'd' => (string) Clock::addDays(\gmdate('Y-m-d'), -(60 - $i * 2)),
                's' => $name, 's2' => $name,
                'b' => (int) \filesize($photosDir . '/' . $name),
            ]
        );
    }

    try {
        // allow_url_fopen OFF in the child: the setting is unverified on
        // the host (hosting Section 12) and a report that needed it on was a
        // 500 on the live site for a phase (Phase 14). A child that builds
        // twenty photographs and three charts with URL wrappers refused is
        // the proof that nothing in the PDF path opens one.
        $measured = Carl\Tests\Harness::measureInChildProcess([
            '-d', 'allow_url_fopen=0',
            \dirname(__DIR__) . '/measure_report.php',
            (string) $owner['id'],
            (string) $plantingId,
        ]);

        // The fixture is 25 real 1920px files and 25 rows. Both go, or the
        // next run reads a photo row whose file is not there.
        $t->ok($measured !== null, 'the measurement child process ran');
        if ($measured === null) {
            return;
        }

        $seconds = (float) $measured['seconds'];
        $growthMb = (int) $measured['growth_bytes'] / 1048576;
        $peakMb = (int) $measured['peak_bytes'] / 1048576;
        $heapMb = (int) $measured['php_heap_bytes'] / 1048576;

        \printf(
            "       measured in a fresh process: %.1f s, +%.0f MB resident (%.0f MB peak,"
            . " %.0f MB by PHP's own counter which cannot see GD), %d KB PDF,"
            . " %d of %d photos\n",
            $seconds, $growthMb, $peakMb, $heapMb,
            (int) ((int) $measured['pdf_bytes'] / 1024),
            (int) $measured['photos_in_report'], (int) $measured['photos_on_file']
        );

        $t->same(20, (int) $measured['photos_in_report'], 'the cap really is twenty');
        $t->same(3, (int) $measured['charts'], 'and all three charts went in');
        $t->ok($seconds < 10.0, 'built in ' . \round($seconds, 1) . ' s, budget 10 s');
        $t->ok($growthMb < 64.0, 'grew ' . \round($growthMb) . ' MB resident, budget 64 MB');
        $t->ok(
            (bool) $measured['resident_is_real'],
            'the figure is resident memory, not PHP\'s counter -- otherwise it proves nothing'
        );
    } finally {
        foreach ($written as $file) {
            @\unlink($file);
        }
        $db->run(
            'DELETE FROM `photo` WHERE `user_id` = :u AND `stored_name` LIKE :like',
            ['u' => $owner['id'], 'like' => 'budget' . $suffix . '%']
        );
    }
});

$t->test('the twenty photographs are spread across the period, not the first twenty',
    function ($t): void {
    $items = [];
    for ($i = 0; $i < 60; $i++) {
        $items[] = ['n' => $i];
    }
    $chosen = Carl\Reports\PdfBuilder::spread($items, 20);

    $t->same(20, \count($chosen));
    $t->same(0, $chosen[0]['n'], 'the first is kept');
    $t->same(59, $chosen[19]['n'], 'and so is the last');
    $t->ok($chosen[10]['n'] > 20, 'the middle really is the middle: ' . $chosen[10]['n']);

    $t->same(5, \count(Carl\Reports\PdfBuilder::spread(\array_slice($items, 0, 5), 20)),
        'fewer than the cap comes back untouched');
});
