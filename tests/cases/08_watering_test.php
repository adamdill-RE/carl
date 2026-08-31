<?php

/**
 * The watering model (handoff Section 11).
 *
 * The arithmetic is tested directly, because a checkbook model that is wrong
 * by a factor is wrong in a way nobody notices until a bed dies; and the
 * whole chain is then run end to end against known weather, because the
 * factor is usually somewhere in the plumbing rather than in the formula.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\KcCurve;
use Carl\Domain\ListType;
use Carl\Domain\SoilType;
use Carl\Domain\WaterMethod;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Repo\WateringRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;
use Carl\Weather\WateringModel;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('Soil holds what Section 11 says it holds');

$t->test('TAW and MAD match the table, and MAD is half of TAW', function ($t): void {
    foreach (['clay' => [60, 30], 'loam' => [50, 25], 'sandy' => [35, 17],
              'raised_bed_mix' => [45, 22], 'container' => [20, 10]] as $soil => [$taw, $mad]) {
        $t->same($taw, SoilType::taw($soil), $soil . ' TAW');
        $t->same($mad, SoilType::mad($soil), $soil . ' MAD');
    }
});

$t->group('The Kc curve');

$t->test('it holds at kc_ini, rises through development, holds at kc_mid, falls to kc_end',
    function ($t): void {
    // A tomato-shaped curve: 30 initial, 40 development, 40 mid, 25 late.
    $curve = new KcCurve(0.6, 1.15, 0.8, 30, 40, 40, 25);

    $t->same(0.6, $curve->at(0));
    $t->same(0.6, $curve->at(30), 'the initial stage holds to its last day');
    $t->same(0.875, $curve->at(50), 'halfway through development is halfway between');
    $t->same(1.15, $curve->at(70));
    $t->same(1.15, $curve->at(110), 'mid-season holds');
    $t->same(0.982, $curve->at(122), 'and then falls toward kc_end');
    $t->same(0.8, $curve->at(135));
    $t->same(0.8, $curve->at(400), 'past the curve it stays at the end rate');
});

$t->test('a type with no curve loaded is the reference crop, not zero', function ($t): void {
    $curve = KcCurve::fromPlantType([]);
    $t->same(1.0, $curve->at(0));
    $t->same(1.0, $curve->at(200));
    // Zero would silently mean "this plant uses no water", which reads as a
    // confident skip rather than as missing data.
    $t->ok($curve->at(50) > 0.0);
});

$t->group('Rain that soaks in, and rain that runs off');

$t->test('rain is capped at 25 mm and discounted to 80 per cent', function ($t): void {
    $t->same(8.0, WateringModel::effectiveRain(10.0, 4.0, 'loam'));
    $t->same(20.0, WateringModel::effectiveRain(60.0, 6.0, 'loam'), 'capped at 25 before the factor');
    $t->same(0.0, WateringModel::effectiveRain(0.0, 0.0, 'loam'));
    $t->same(0.0, WateringModel::effectiveRain(null, null, 'loam'));
});

$t->test('an hour of rain on clay mostly runs off', function ($t): void {
    $t->same(10.0, WateringModel::effectiveRain(20.0, 1.0, 'clay'), 'clay, one hour: half of it');
    $t->same(16.0, WateringModel::effectiveRain(20.0, 3.0, 'clay'), 'clay, three hours: it soaks in');
    $t->same(16.0, WateringModel::effectiveRain(20.0, 1.0, 'sandy'), 'sand takes it either way');
});

$t->group('How much water a logged watering applied');

$t->test('a configured flow rate is used in preference to any assumption', function ($t): void {
    $depth = WaterMethod::depth(30, 'Drip line', '12 mm/h');
    $t->same(6.0, $depth['mm']);
    $t->contains('12.0 mm/h you configured', $depth['basis']);

    $t->same(12.7, WaterMethod::depth(60, 'Drip', '0.5 in/h')['mm']);
    $t->same(5.0, WaterMethod::depth(30, 'Drip', '10')['mm'], 'a bare number reads as mm/h');
});

$t->test('an implausible flow rate falls back rather than emptying the checkbook',
    function ($t): void {
    // "50" meaning gallons per hour would be 50 mm/h, which is a season's
    // water in an afternoon. More likely the wrong unit than a real rate.
    $t->same(null, WaterMethod::parseFlowRate('50 mm/h'));
    $t->same(null, WaterMethod::parseFlowRate('0'));
    $t->same(null, WaterMethod::parseFlowRate('lots'));
    $t->same(null, WaterMethod::parseFlowRate(null));
});

$t->test('without a flow rate, the depth comes from what the method is called',
    function ($t): void {
    $t->same(3.0, WaterMethod::depth(10, 'Watering can', null)['mm']);
    $t->same(6.0, WaterMethod::depth(10, 'Garden hose', null)['mm']);
    $t->same(5.0, WaterMethod::depth(10, 'Oscillating sprinkler', null)['mm']);
    $t->same(9.0, WaterMethod::depth(30, 'Hand watering', null)['mm']);
    $t->same(0.0, WaterMethod::depth(0, 'Hose', null)['mm'], 'no duration, no water');
});

$t->test('an unrecognised method takes the lowest assumption, and says so',
    function ($t): void {
    $depth = WaterMethod::depth(10, 'The thing by the shed', null);
    $t->same(3.0, $depth['mm'], 'under-estimating irrigation errs toward watering');
    $t->contains('not recognised', $depth['basis']);
});

$t->group('The tier');

$makeForecast = static fn (?float $prob, ?float $mm, ?float $tmax): array => [
    'precip_prob_pct' => $prob, 'precip_mm' => $mm, 'temp_max_c' => $tmax,
];

$t->test('at or past the allowed depletion with a dry sky, water',
    function ($t) use ($makeForecast): void {
    $t->same('water', WateringModel::tier(25.0, 25.0, $makeForecast(10.0, 0.0, 28.0), null));
    $t->same('water', WateringModel::tier(40.0, 25.0, null, null), 'no forecast is not a reason to skip');
});

$t->test('past the depletion but heavy rain is coming, do not water',
    function ($t) use ($makeForecast): void {
    // 80% chance of 30 mm against a 25 mm deficit: the sky is about to do it.
    $t->same('likely', WateringModel::tier(25.0, 25.0, $makeForecast(80.0, 30.0, 28.0), null));
});

$t->test('heat brings the deficit forward', function ($t) use ($makeForecast): void {
    // Only 40% of the allowed depletion, which alone would be a skip.
    $t->same('skip', WateringModel::tier(10.0, 25.0, $makeForecast(0.0, 0.0, 30.0), null));
    $t->same('water', WateringModel::tier(10.0, 25.0, $makeForecast(0.0, 0.0, 35.0), null),
        '35 C takes a plant past wilting before the checkbook is empty');
});

$t->test('getting dry with rain probable inside 48 hours is a likely, not a water',
    function ($t) use ($makeForecast): void {
    $t->same('likely', WateringModel::tier(12.0, 25.0,
        $makeForecast(10.0, 0.0, 28.0), $makeForecast(70.0, 8.0, 28.0)));

    // A 30% chance of a millimetre is not a reason to put the hose away.
    $t->same('skip', WateringModel::tier(12.0, 25.0,
        $makeForecast(10.0, 0.0, 28.0), $makeForecast(30.0, 1.0, 28.0)));
});

$t->test('a full root zone is a skip whatever the sky is doing',
    function ($t) use ($makeForecast): void {
    $t->same('skip', WateringModel::tier(0.0, 25.0, $makeForecast(0.0, 0.0, 30.0), null));
    $t->same('skip', WateringModel::tier(5.0, 25.0, $makeForecast(90.0, 20.0, 30.0), null));
});

$t->group('The whole chain, against known weather');

$repo = new UserRepository($db);
$username = 'waterer' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Waterer',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
// $_SESSION is a superglobal, so a Client built after another test's client
// would otherwise inherit whoever it left signed in.
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'watering-test-passphrase', 'password_confirm' => 'watering-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Waterer', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Dry Bed ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$locationId = (int) ($db->value(
    'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $userId]
) ?? 0);
$timezone = (string) $db->value(
    'SELECT timezone FROM `weather_location` WHERE id = :id', ['id' => $locationId]
);
$today = $app->clock()->todayFor($timezone);

$gardens = new GardenRepository($db, $userId);
$beds = $gardens->where('`name` = :n', ['n' => 'Dry Bed ' . $suffix]);
if ($beds === []) {
    throw new RuntimeException('onboarding did not create the test garden; the rest cannot run');
}
$gardenId = (int) $beds[0]['id'];
$placeKey = 'g:' . $gardenId;
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

/** Put a known day of weather in the archive. */
$putWeather = static function (string $date, float $et0, float $rain, float $rainHours) use ($db, $locationId): void {
    $db->run(
        'INSERT INTO `weather_daily` (location_id, obs_date, et0_mm, precip_mm, precip_hours,'
        . " source_model, is_provisional, fetched_at)"
        . " VALUES (:loc, :date, :et0, :rain, :hours, 'best_match', 0, UTC_TIMESTAMP())"
        . ' ON DUPLICATE KEY UPDATE `et0_mm` = VALUES(`et0_mm`), `precip_mm` = VALUES(`precip_mm`),'
        . " `precip_hours` = VALUES(`precip_hours`), `source_model` = 'best_match'",
        ['loc' => $locationId, 'date' => $date, 'et0' => $et0, 'rain' => $rain, 'hours' => $rainHours]
    );
};

$t->test('a garden with a plant in it gets a row per day, ending today',
    function ($t) use ($client, $db, $app, $userId, $gardenId, $gardens, $plantTypeId, $today,
                      $placeKey, $putWeather, $locationId): void {
    $row = $gardens->rows($gardenId)[0];
    $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => (string) Clock::addDays($today, -20), 'quantity_initial' => '6',
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $row['id'],
    ]);

    // 5 mm of ET0 a day and no rain at all, for the whole seed window.
    $db->run('DELETE FROM `weather_daily` WHERE location_id = :id', ['id' => $locationId]);
    for ($back = 40; $back >= 1; $back--) {
        $putWeather((string) Clock::addDays($today, -$back), 5.0, 0.0, 0.0);
    }
    $db->run('DELETE FROM `watering_recommendation` WHERE place_key = :k', ['k' => $placeKey]);

    $summary = (new WateringModel($app))->run($userId);
    $t->ok($summary['places'] >= 1);
    $t->same(0, $summary['failures']);

    $latest = $db->one(
        'SELECT * FROM `watering_recommendation` WHERE place_key = :k ORDER BY for_date DESC LIMIT 1',
        ['k' => $placeKey]
    );
    $t->ok($latest !== null, 'the model wrote a row');
    $t->same($today, (string) $latest['for_date'], 'the newest row is for today');
    $t->same(50, (int) $latest['taw_mm'], 'loam');
    $t->same(25, (int) $latest['mad_mm']);
});

$t->test('the deficit climbs with ET0 and is clamped at the soil capacity',
    function ($t) use ($db, $placeKey): void {
    $rows = $db->all(
        'SELECT for_date, deficit_mm, tier FROM `watering_recommendation`'
        . ' WHERE place_key = :k ORDER BY for_date',
        ['k' => $placeKey]
    );
    $t->ok(\count($rows) > 20, 'a seeded run walks a window, not one day');

    $previous = -1.0;
    foreach ($rows as $row) {
        $deficit = (float) $row['deficit_mm'];
        $t->ok($deficit >= $previous - 0.001, 'the deficit never falls on a rainless day');
        $t->ok($deficit <= 50.0, 'and never exceeds TAW');
        $previous = $deficit;
    }

    $t->same('water', (string) $rows[\count($rows) - 1]['tier'],
        'twenty rainless days on loam is a water');
});

$t->test('the reason text carries the numbers, as Section 11 asks',
    function ($t) use ($db, $placeKey): void {
    $reason = (string) $db->value(
        'SELECT reason_text FROM `watering_recommendation` WHERE place_key = :k'
        . ' ORDER BY for_date DESC LIMIT 1',
        ['k' => $placeKey]
    );
    $t->contains('Deficit', $reason);
    $t->contains('mm', $reason);
    $t->contains('Water today', $reason);
});

$t->test('rain refills the checkbook', function ($t) use ($db, $app, $userId, $placeKey, $today, $putWeather): void {
    // 30 mm over six hours yesterday: capped at 25, times 0.8, is 20 mm back.
    $putWeather((string) Clock::addDays($today, -1), 5.0, 30.0, 6.0);
    $db->run('DELETE FROM `watering_recommendation` WHERE place_key = :k AND for_date = :d',
        ['k' => $placeKey, 'd' => $today]);

    (new WateringModel($app))->run($userId);

    $row = $db->one(
        'SELECT * FROM `watering_recommendation` WHERE place_key = :k AND for_date = :d',
        ['k' => $placeKey, 'd' => $today]
    );
    $t->same(20.0, (float) $row['rain_eff_mm'], '25 mm capped, then 80 per cent of it');
    $t->ok((float) $row['deficit_mm'] < 50.0, 'the rain came off the deficit');
});

$t->test('a zone watering is counted once, not once per plant it reached',
    function ($t) use ($client, $db, $app, $userId, $gardenId, $gardens, $plantTypeId, $today,
                      $placeKey, $putWeather): void {
    // Five more plants in the row, so a zone watering fans out to six.
    $row = $gardens->rows($gardenId)[0];
    for ($i = 0; $i < 5; $i++) {
        $client->post('/plants', [
            'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
            'start_date' => (string) Clock::addDays($today, -10), 'quantity_initial' => '3',
            'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $row['id'],
        ]);
    }

    // A zone covering that row, watered for ten minutes with a hose.
    $client->post('/gardens/' . $gardenId . '/zones', [
        'zone_name' => 'Zone A', 'water_method_new' => 'Garden hose',
        'zone_rows' => [(string) $row['id']],
    ]);
    $zoneId = (int) ($db->value(
        'SELECT id FROM `water_zone` WHERE garden_id = :g ORDER BY id DESC LIMIT 1',
        ['g' => $gardenId]
    ) ?? 0);
    $t->ok($zoneId > 0, 'the zone was created');

    $yesterday = (string) Clock::addDays($today, -1);
    $client->post('/gardens/' . $gardenId . '/actions', [
        'event_type' => EventType::WATERED, 'water_zone_id' => (string) $zoneId,
        'duration_min' => '10', 'event_date' => $yesterday,
    ]);

    $fanout = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE source_garden_event_id IS NOT NULL'
        . ' AND user_id = :u AND event_date = :d',
        ['u' => $userId, 'd' => $yesterday], 0
    );
    $t->ok($fanout >= 6, 'the zone watering did fan out to every plant in the row');

    // Dry yesterday, so the only water in the balance is the irrigation.
    $putWeather($yesterday, 5.0, 0.0, 0.0);
    $db->run('DELETE FROM `watering_recommendation` WHERE place_key = :k AND for_date = :d',
        ['k' => $placeKey, 'd' => $today]);

    (new WateringModel($app))->run($userId);

    $reco = $db->one(
        'SELECT * FROM `watering_recommendation` WHERE place_key = :k AND for_date = :d',
        ['k' => $placeKey, 'd' => $today]
    );

    // A hose for ten minutes is 6 mm. Counted once per plant it would be 36.
    $t->same(6.0, (float) $reco['irrigation_mm'],
        'one application of the bed, not one per plant in it');
    $t->contains('mm per 10 min', (string) $reco['reason_text'],
        'and the assumption is stated so it can be corrected');
});

$t->test('the recursion reads yesterday back rather than recomputing the season',
    function ($t) use ($db, $app, $userId, $placeKey, $today, $putWeather): void {
    $before = (int) $db->value(
        'SELECT COUNT(*) FROM `watering_recommendation` WHERE place_key = :k', ['k' => $placeKey], 0
    );
    $statementsBefore = $db->statementCount();

    // Everything through today is already stored, so a re-run has nothing to
    // walk: the newest row is today's, and the loop starts tomorrow.
    (new WateringModel($app))->run($userId);

    $after = (int) $db->value(
        'SELECT COUNT(*) FROM `watering_recommendation` WHERE place_key = :k', ['k' => $placeKey], 0
    );
    $t->same($before, $after, 'a second run the same day adds nothing');
    $t->ok($db->statementCount() - $statementsBefore < 40,
        'and costs a handful of statements, not one per day of the season');
});

$t->test('a container is evaluated on its own, with the container capacity',
    function ($t) use ($client, $db, $app, $userId, $plantTypeId, $today): void {
    $client->post('/lists', [
        'list_type' => 'containers', 'name' => 'Half barrel ' . \bin2hex(\random_bytes(3)),
        'size' => '20 gal',
    ]);
    $containerId = (int) ($db->value(
        'SELECT id FROM `container` WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $userId]
    ) ?? 0);
    $t->ok($containerId > 0, 'the container was created');

    $client->post('/plants', [
        'start_method' => 'nursery_transplant', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => (string) Clock::addDays($today, -10), 'quantity_initial' => '1',
        'container_id' => (string) $containerId,
    ]);

    (new WateringModel($app))->run($userId);

    $reco = $db->one(
        'SELECT * FROM `watering_recommendation` WHERE place_key = :k AND for_date = :d',
        ['k' => 'c:' . $containerId, 'd' => $today]
    );
    $t->ok($reco !== null, 'the container got its own recommendation');
    $t->same(20, (int) $reco['taw_mm'], 'the container TAW, not the garden it sits beside');
    $t->same(10, (int) $reco['mad_mm']);
    $t->same($containerId, (int) $reco['container_id']);
    $t->same(null, $reco['garden_id']);
});

$t->test('an indoor garden gets no recommendation at all', function ($t) use ($db, $userId): void {
    $indoorId = (int) ($db->value(
        'SELECT id FROM `garden` WHERE user_id = :u AND is_indoor = 1 LIMIT 1', ['u' => $userId]
    ) ?? 0);
    $t->ok($indoorId > 0, 'every account gets an Indoor Garden');
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `watering_recommendation` WHERE place_key = :k',
        ['k' => 'g:' . $indoorId], 0
    ), 'ET0 is an outdoor number and an indoor answer would be confidently wrong');
});

$t->group('Reading it back');

$t->test('the MOTD reads the stored row and computes nothing',
    function ($t) use ($db, $userId, $today, $placeKey): void {
    $repo = new WateringRepository($db, $userId);
    $rows = $repo->forDate($today);
    $t->ok($rows !== [], 'there is something to show');

    $keys = \array_map(static fn (array $r): string => (string) $r['place_key'], $rows);
    $t->ok(\in_array($placeKey, $keys, true));
    foreach ($rows as $row) {
        $t->ok($row['place_name'] !== null, 'each row names its garden or container');
    }
});

$t->test('a second account sees none of it', function ($t) use ($db, $today): void {
    $other = (int) ($db->value(
        'SELECT id FROM `user` WHERE username LIKE :like ORDER BY id DESC LIMIT 1',
        ['like' => 'exporter%']
    ) ?? 0);
    if ($other === 0) {
        $t->ok(true, 'no second account in this database to check against');
        return;
    }
    $rows = (new WateringRepository($db, $other))->forDate($today);
    foreach ($rows as $row) {
        $t->same($other, (int) $row['user_id']);
    }
    $t->ok(true);
});

$t->test('the main menu renders the recommendation', function ($t) use ($client): void {
    $response = $client->get('/');
    $t->same(200, $response->status);
    $t->contains('Watering', $response->body);
    $t->contains('Deficit', $response->body);
    // No inline style attribute may appear: the CSP silently refuses them
    // (Phase 3 handoff Section 1.5).
    $t->notContains('style="', $response->body);
});
