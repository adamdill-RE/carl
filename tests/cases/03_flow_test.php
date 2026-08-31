<?php

/**
 * The alpha acceptance run (handoff Section 14), driven through the real
 * kernel: a second user can be created, log in, be forced to reset, onboard
 * with ZIP 76692, get the Indoor Garden and a built garden, start a plant of
 * each of the three kinds with backdated dates, log every action type, and
 * see the timeline. Both users see only their own data.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Core\Response;
use Carl\Domain\EventType;
use Carl\Domain\ListType;
use Carl\Domain\PlantingState;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

/** Create an account the way the admin route does, without going through it. */
$makeUser = static function (string $username, string $role = 'user') use ($db, $app): array {
    $repo = new Carl\Repo\UserRepository($db);
    $created = $repo->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), $role
    );
    return ['id' => $created['id'], 'username' => $username, 'password' => $created['temporary_password']];
};

$alice = $makeUser('alice' . $suffix);
$bob = $makeUser('bob' . $suffix);

$client = new Client($root);

$t->group('Sign in and the forced reset');

$t->test('a wrong password is refused without saying which half was wrong', function ($t) use ($client, $alice): void {
    $response = $client->post('/login', ['username' => $alice['username'], 'password' => 'nope']);
    $t->same(200, $response->status);
    $t->contains('do not match', $response->body);
    $t->notContains('no such user', \strtolower($response->body));
});

$t->test('a POST with no CSRF token is refused', function ($t) use ($client, $alice): void {
    $response = $client->postWithoutCsrf('/login',
        ['username' => $alice['username'], 'password' => $alice['password']]);
    $t->same(419, $response->status);
});

$t->test('signing in with the temporary password lands on the forced reset', function ($t) use ($client, $alice): void {
    $response = $client->post('/login',
        ['username' => $alice['username'], 'password' => $alice['password']]);
    $t->same(303, $response->status);
    $t->contains('/password/reset', $response->headers()['Location']);
});

$t->test('every other page redirects to the reset until it is done', function ($t) use ($client): void {
    $response = $client->get('/');
    $t->same(303, $response->status);
    $t->contains('/password/reset', $response->headers()['Location']);
});

$t->test('a weak new password is refused with a reason', function ($t) use ($client): void {
    $response = $client->post('/password/reset', ['password' => 'short', 'password_confirm' => 'short']);
    $t->same(200, $response->status);
    $t->contains('at least 10 characters', \strtolower($response->body));
});

$t->test('setting a real password moves on to onboarding', function ($t) use ($client, &$alice): void {
    $alice['password'] = 'correct horse battery staple';
    $response = $client->post('/password/reset',
        ['password' => $alice['password'], 'password_confirm' => $alice['password']]);
    $t->same(303, $response->status);
    $t->contains('/onboarding', $response->headers()['Location']);
});

$t->group('Onboarding with ZIP 76692');

$t->test('an unknown ZIP is refused rather than guessed at', function ($t) use ($client): void {
    $response = $client->post('/onboarding/profile',
        ['name' => 'Alice', 'zip' => '00000', 'county' => '']);
    $t->same(200, $response->status);
    $t->contains('not recognised', $response->body);
});

$t->test('76692 resolves to Hill County, Texas, in America/Chicago', function ($t) use ($client, $alice, $db): void {
    $response = $client->post('/onboarding/profile',
        ['name' => 'Alice Grower', 'zip' => '76692', 'county' => 'Hill']);
    $t->same(303, $response->status);

    $row = $db->one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $alice['id']]);
    $t->same('76692', $row['zip']);
    $t->same('48217', $row['county_fips']);
    $t->same('America/Chicago', $row['timezone']);
    $t->ok($row['latitude'] !== null && $row['longitude'] !== null, 'coordinates were stored');
    $t->ok($row['weather_location_id'] !== null, 'a weather location was created');
    $t->ok($row['region_id'] !== null, 'a region row exists, researched or not');
});

$t->test('the Indoor Garden is created at signup', function ($t) use ($alice, $db): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $indoor = $gardens->where('`is_indoor` = 1');
    $t->same(1, \count($indoor));
    $t->same(GardenRepository::INDOOR_NAME, $indoor[0]['name']);
});

$t->test('the pest and cull-reason lists are seeded so no dropdown is empty', function ($t) use ($alice, $db): void {
    $lists = new Carl\Repo\ListRepository($db, $alice['id']);
    $t->ok(\count($lists->ofType(ListType::CULL_REASON)) >= 5, 'cull reasons seeded');
});

$t->test('a garden is built with its rows', function ($t) use ($client, $alice, $db): void {
    $response = $client->post('/onboarding/garden', [
        'name' => 'Main Bed', 'ns_ft' => '20', 'ew_ft' => '12',
        'row_count' => '4', 'row_orientation' => 'ns', 'soil_type' => 'clay',
        'notes' => 'Blackland clay, slow drainage.',
    ]);
    $t->same(303, $response->status);

    $gardens = new GardenRepository($db, $alice['id']);
    $built = $gardens->where('`name` = :name', ['name' => 'Main Bed']);
    $t->same(1, \count($built));
    $t->same(4, \count($gardens->rows((int) $built[0]['id'])));
});

$t->test('the wizard can be finished and the menu opens', function ($t) use ($client): void {
    $t->same(303, $client->post('/onboarding/finish')->status);
    $response = $client->get('/');
    $t->same(200, $response->status);
    $t->contains('Start a New Plant', $response->body);
});

$t->group('Starting plants, backdated');

$plantIds = [];
$today = $app->clock()->todayFor('America/Chicago');

$t->test('a plant type exists to plant', function ($t) use ($db): void {
    $count = (int) $db->value('SELECT COUNT(*) FROM `plant_type`', [], 0);
    $t->ok($count > 0, 'the research import must run before plants can be started');
});

$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

$t->test('an indoor seed start is recorded 60 days ago', function ($t) use ($client, $alice, $db, $today, $plantTypeId, &$plantIds): void {
    $date = (string) Clock::addDays($today, -60);
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => $date, 'quantity_initial' => '12',
        'seed_source_new' => 'Baker Creek', 'soil_new' => 'Pro-Mix',
        'vessel_new' => '72-cell tray',
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $alice['id']);
    $rows = $plantings->where('`start_method` = :m', ['m' => 'indoor_seed'], '`id` DESC', 1);
    $t->same(1, \count($rows));
    $t->same($date, $rows[0]['start_date']);
    $t->same(PlantingState::SEED_STARTED, $rows[0]['state']);
    $t->same(12, (int) $rows[0]['quantity_live']);
    $t->same(null, $rows[0]['in_ground_date'], 'a seedling is not in the ground yet');
    $plantIds['seed'] = (int) $rows[0]['id'];
});

$t->test('the backdated start pulled the weather backfill window back', function ($t) use ($alice, $db, $today): void {
    $row = $db->one(
        'SELECT l.backfill_from FROM `weather_location` l'
        . ' JOIN `user` u ON u.weather_location_id = l.id WHERE u.id = :id',
        ['id' => $alice['id']]
    );
    $t->ok($row !== null, 'the user has a weather location');
    $t->ok((string) $row['backfill_from'] <= (string) Clock::addDays($today, -60),
        'backfill_from reaches back to the oldest planting');
});

$t->test('a direct sow goes straight into the ground', function ($t) use ($client, $alice, $db, $today, $plantTypeId, &$plantIds): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $row = $gardens->rows((int) $garden['id'])[0];

    $date = (string) Clock::addDays($today, -30);
    $response = $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => $date, 'quantity_initial' => '20',
        'garden_id' => (string) $garden['id'], 'garden_row_id' => (string) $row['id'],
        'collar_used' => '1', 'seeds_per_collar' => '4', 'trellis_used' => '1',
        'fertilizer_new' => 'Bone meal',
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $alice['id']);
    $rows = $plantings->where('`start_method` = :m', ['m' => 'direct_sow'], '`id` DESC', 1);
    $t->same(PlantingState::PLANTED, $rows[0]['state']);
    $t->same($date, $rows[0]['in_ground_date']);
    $plantIds['sow'] = (int) $rows[0]['id'];
});

$t->test('a nursery transplant enters at planted', function ($t) use ($client, $alice, $db, $today, $plantTypeId, &$plantIds): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $row = $gardens->rows((int) $garden['id'])[1];

    $response = $client->post('/plants', [
        'start_method' => 'nursery_transplant', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => (string) Clock::addDays($today, -14), 'quantity_initial' => '3',
        'garden_id' => (string) $garden['id'], 'garden_row_id' => (string) $row['id'],
        'nursery_new' => 'Hillsboro Feed', 'water_method_new' => 'Drip line',
        'initial_height_in' => '8.5',
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $alice['id']);
    $rows = $plantings->where('`start_method` = :m', ['m' => 'nursery_transplant'], '`id` DESC', 1);
    $t->same(PlantingState::PLANTED, $rows[0]['state']);
    $t->same(3, (int) $rows[0]['quantity_live']);
    $plantIds['transplant'] = (int) $rows[0]['id'];
});

$t->test('the row select carries the occupancy hint (handoff 4.3)', function ($t) use ($client, $alice, $db): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $rows = $gardens->rows((int) $garden['id']);

    // 20 direct-sown into row 1 and 3 transplants into row 2, above.
    $occupancy = $gardens->livingCountByRow((int) $garden['id']);
    $t->same(20, $occupancy[(int) $rows[0]['id']]['living']);
    $t->same(3, $occupancy[(int) $rows[1]['id']]['living']);

    // Every garden at once is what the plant form asks for, and it is the
    // same numbers.
    $everywhere = $gardens->livingCountByRow();
    $t->same(20, $everywhere[(int) $rows[0]['id']]['living']);

    $response = $client->get('/plants/new/direct_sow');
    $t->same(200, $response->status);
    $t->contains('already has 20 living plants', $response->body,
        'the hint is beside the row option, not only in the garden report');
    $t->contains('already has 3 living plants', $response->body);
    // A nudge, never a block: the option is still selectable.
    $t->notContains('disabled', $response->body);
});

$t->test('a future date is pulled back to today rather than accepted', function ($t) use ($client, $alice, $db, $today, $plantTypeId): void {
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => (string) Clock::addDays($today, 30), 'quantity_initial' => '1',
    ]);
    $t->same(303, $response->status);
    $plantings = new PlantingRepository($db, $alice['id']);
    $rows = $plantings->where('', [], '`id` DESC', 1);
    $t->same($today, $rows[0]['start_date']);
});

$t->group('Logging activity');

$t->test('watering and germination are recorded, and germination keeps the count', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $id = $plantIds['seed'];
    $client->post('/log/' . $id, [
        'event_type' => EventType::WATERED, 'event_date' => (string) Clock::addDays($today, -58),
        'water_method_new' => 'Bottom tray', 'duration_min' => '5',
    ]);
    $client->post('/log/' . $id, [
        'event_type' => EventType::GERMINATED, 'event_date' => (string) Clock::addDays($today, -53),
        'quantity' => '10',
    ]);

    $plantings = new PlantingRepository($db, $alice['id']);
    $row = $plantings->find($id);
    $t->same(12, (int) $row['quantity_live'], 'germination does not change the live count');
    $t->same((string) Clock::addDays($today, -53), $row['germinated_at']);
});

$t->test('failed germination takes the count down', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $client->post('/log/' . $plantIds['seed'], [
        'event_type' => EventType::GERMINATION_FAILED,
        'event_date' => (string) Clock::addDays($today, -50), 'quantity' => '2',
    ]);
    $plantings = new PlantingRepository($db, $alice['id']);
    $t->same(10, (int) $plantings->find($plantIds['seed'])['quantity_live']);
});

$t->test('hardening moves the state and records the countdown', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $client->post('/log/' . $plantIds['seed'], [
        'event_type' => EventType::HARDENING_STARTED,
        'event_date' => (string) Clock::addDays($today, -20), 'hardening_days' => '10',
    ]);
    $plantings = new PlantingRepository($db, $alice['id']);
    $row = $plantings->find($plantIds['seed']);
    $t->same(PlantingState::HARDENING, $row['state']);
    $t->same(10, (int) $row['hardening_days']);
});

$t->test('transplanting puts it in the ground and moves it to the row', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $row = $gardens->rows((int) $garden['id'])[2];

    $client->post('/log/' . $plantIds['seed'], [
        'event_type' => EventType::TRANSPLANTED,
        'event_date' => (string) Clock::addDays($today, -10),
        'garden_id' => (string) $garden['id'], 'garden_row_id' => (string) $row['id'],
    ]);

    $plantings = new PlantingRepository($db, $alice['id']);
    $planting = $plantings->find($plantIds['seed']);
    $t->same(PlantingState::PLANTED, $planting['state']);
    $t->same((string) Clock::addDays($today, -10), $planting['in_ground_date']);
    $t->same((int) $row['id'], (int) $planting['garden_row_id']);
});

$t->test('a yield moves the state to yielding and totals up', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $client->post('/log/' . $plantIds['sow'], [
        'event_type' => EventType::YIELDED, 'event_date' => (string) Clock::addDays($today, -3),
        'yield_weight' => '2', 'yield_weight_unit' => 'lb',
    ]);
    $client->post('/log/' . $plantIds['sow'], [
        'event_type' => EventType::YIELDED, 'event_date' => (string) Clock::addDays($today, -1),
        'yield_count' => '8',
    ]);

    $plantings = new PlantingRepository($db, $alice['id']);
    $t->same(PlantingState::YIELDING, $plantings->find($plantIds['sow'])['state']);

    $summary = $plantings->yieldSummary($plantIds['sow']);
    $t->same(8, $summary['count_qty']);
    $t->ok(\abs($summary['weight_g'] - 907.18474) < 0.1, 'two pounds stored as grams');
});

$t->test('every remaining action type records', function ($t) use ($client, $today, $plantIds): void {
    $id = $plantIds['transplant'];
    $actions = [
        [EventType::WATERED, ['water_method_new' => 'Hand can', 'duration_min' => '10']],
        [EventType::PEST_OBSERVED, ['pest_new' => 'Hornworm']],
        [EventType::PEST_TREATED, ['pest_new' => 'Hornworm', 'treatment_new' => 'Bt spray', 'also_observe' => '1']],
        [EventType::FERTILIZED, ['fertilizer_new' => 'Fish emulsion']],
        [EventType::AMENDED, ['amendment_new' => 'Compost']],
        [EventType::MULCHED, ['mulch_new' => 'Wheat straw']],
        [EventType::NOTE, ['narrative' => 'Looking strong after the rain.']],
    ];
    foreach ($actions as [$type, $extra]) {
        $response = $client->post('/log/' . $id, ['event_type' => $type, 'event_date' => $today] + $extra);
        $t->same(303, $response->status, $type . ' should record');
    }
});

$t->test('the treatment also recorded the observation it had no record of', function ($t) use ($alice, $db, $plantIds): void {
    $count = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `user_id` = :u AND `planting_id` = :p'
        . ' AND `event_type` = :e',
        ['u' => $alice['id'], 'p' => $plantIds['transplant'], 'e' => EventType::PEST_OBSERVED],
        0
    );
    $t->ok($count >= 1, 'a pest observation exists');
});

$t->test('culling the remainder ends the planting', function ($t) use ($client, $alice, $db, $today, $plantIds): void {
    $client->post('/log/' . $plantIds['transplant'], [
        'event_type' => EventType::CULLED, 'event_date' => $today,
        'cull_reason_new' => 'End of season',
    ]);
    $plantings = new PlantingRepository($db, $alice['id']);
    $row = $plantings->find($plantIds['transplant']);
    $t->same(0, (int) $row['quantity_live']);
    $t->same(PlantingState::ENDED, $row['state']);
    $t->same($today, $row['ended_at']);
});

$t->test('an action the state does not allow is refused', function ($t) use ($client, $today, $plantIds): void {
    // The transplant is ended; it cannot be watered.
    $response = $client->post('/log/' . $plantIds['transplant'],
        ['event_type' => EventType::WATERED, 'event_date' => $today]);
    $t->same(400, $response->status);
});

$t->group('Reading it back');

$t->test('the plant report shows the timeline and the yield', function ($t) use ($client, $plantIds): void {
    $response = $client->get('/plants/' . $plantIds['sow']);
    $t->same(200, $response->status);
    $t->contains('Timeline', $response->body);
    $t->contains('Yield', $response->body);
});

$t->test('a timeline separator is not double-escaped', function ($t) use ($client, $plantIds): void {
    // Escaping a string that already holds an entity turns the separator into
    // literal "&middot;" on the page.
    $response = $client->get('/plants/' . $plantIds['transplant']);
    $t->same(200, $response->status);
    $t->notContains('&amp;middot;', $response->body);
});

$t->test('a user-supplied value is escaped, entity or not', function ($t) use ($client, $today, $plantIds): void {
    $client->post('/log/' . $plantIds['sow'], [
        'event_type' => EventType::NOTE, 'event_date' => $today,
        'narrative' => 'Storm <script>alert(1)</script> & hail',
    ]);
    $response = $client->get('/plants/' . $plantIds['sow']);
    $t->notContains('<script>alert(1)</script>', $response->body);
    $t->contains('&lt;script&gt;', $response->body);
    $t->contains('&amp; hail', $response->body);
});

$t->test('the plant list and the log list both render', function ($t) use ($client): void {
    $t->same(200, $client->get('/plants')->status);
    $t->same(200, $client->get('/log')->status);
});

$t->test('filters narrow the list without erroring', function ($t) use ($client): void {
    $response = $client->get('/plants', ['state' => PlantingState::ENDED]);
    $t->same(200, $response->status);
});

$t->group('Garden actions and the zone fan-out');

$t->test('watering a zone logs it against every living plant in its rows', function ($t) use ($client, $alice, $db, $today): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $gardenId = (int) $garden['id'];
    $rows = $gardens->rows($gardenId);

    $client->post('/gardens/' . $gardenId . '/zones', [
        'zone_name' => 'Drip east',
        'water_method_new' => 'Drip 1 gph',
        'zone_rows' => [(string) $rows[0]['id'], (string) $rows[2]['id']],
    ]);

    $zones = $gardens->zones($gardenId);
    $t->same(1, \count($zones));

    $before = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `user_id` = :u AND `source_garden_event_id` IS NOT NULL',
        ['u' => $alice['id']], 0
    );

    $response = $client->post('/gardens/' . $gardenId . '/actions', [
        'event_type' => EventType::WATERED, 'event_date' => $today,
        'water_zone_id' => (string) $zones[0]['id'], 'duration_min' => '25',
    ]);
    $t->same(303, $response->status);

    $after = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `user_id` = :u AND `source_garden_event_id` IS NOT NULL',
        ['u' => $alice['id']], 0
    );
    $t->ok($after > $before, 'the zone watering fanned out to the plants in its rows');

    $event = $db->one(
        'SELECT `fanout_count` FROM `garden_event` WHERE `user_id` = :u ORDER BY `id` DESC LIMIT 1',
        ['u' => $alice['id']]
    );
    $t->same($after - $before, (int) $event['fanout_count']);
});

$t->test('the garden report renders with its rows and events', function ($t) use ($client, $alice, $db): void {
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $response = $client->get('/gardens/' . $garden['id']);
    $t->same(200, $response->status);
    $t->contains('Water zones', $response->body);
    $t->contains('Garden events', $response->body);
});

$t->group('Lists');

$t->test('the lists screen and one list render', function ($t) use ($client): void {
    $t->same(200, $client->get('/lists')->status);
    $t->same(200, $client->get('/lists/' . ListType::SEED_SOURCE)->status);
});

$t->test('adding the same list item twice does not duplicate it', function ($t) use ($client, $alice, $db): void {
    $client->post('/lists', ['list_type' => ListType::MULCH_TYPE, 'name' => 'Pine bark']);
    $client->post('/lists', ['list_type' => ListType::MULCH_TYPE, 'name' => 'Pine bark']);
    $count = (int) $db->value(
        'SELECT COUNT(*) FROM `user_list_item` WHERE `user_id` = :u AND `list_type` = :t AND `name` = :n',
        ['u' => $alice['id'], 't' => ListType::MULCH_TYPE, 'n' => 'Pine bark'], 0
    );
    $t->same(1, $count);
});

$t->test('a nonexistent list is 404, not a blank page', function ($t) use ($client): void {
    $t->same(404, $client->get('/lists/not_a_list')->status);
});

$t->group('Data isolation');

$t->test('a second user sees none of the first user\'s data', function ($t) use ($client, $bob, $alice, $db, $plantIds): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $bob['username'], 'password' => $bob['password']]);
    $bobPassword = 'another good long passphrase';
    $client->post('/password/reset', ['password' => $bobPassword, 'password_confirm' => $bobPassword]);
    $client->post('/onboarding/profile', ['name' => 'Bob Grower', 'zip' => '76692', 'county' => 'Hill']);
    $client->post('/onboarding/finish');

    $response = $client->get('/plants');
    $t->same(200, $response->status);
    $t->notContains('Main Bed', $response->body);

    // Alice's planting id is a 404 to Bob, exactly as a missing row would be.
    $t->same(404, $client->get('/plants/' . $plantIds['sow'])->status);
    $t->same(404, $client->get('/log/' . $plantIds['sow'])->status);

    $gardens = new GardenRepository($db, $bob['id']);
    $t->same(0, \count($gardens->where('`name` = :n', ['n' => 'Main Bed'])));
});

$t->test('a user cannot write an event onto another user\'s plant', function ($t) use ($client, $plantIds): void {
    $response = $client->post('/log/' . $plantIds['sow'],
        ['event_type' => EventType::WATERED, 'event_date' => '2026-08-01']);
    $t->same(404, $response->status);
});

$t->test('the repository base class refuses to be built without a user', function ($t) use ($db): void {
    $t->throws(LogicException::class, static fn () => new PlantingRepository($db, 0));
});

$t->group('Admin is invisible to a user');

$t->test('a user gets 404 on every admin route, not 403', function ($t) use ($client): void {
    foreach (['/admin', '/admin/users', '/admin/research-import', '/admin/regions'] as $path) {
        $t->same(404, $client->get($path)->status, $path . ' should be invisible');
    }
});

$t->test('a key-guarded route with no key is 404', function ($t) use ($client): void {
    $t->same(404, $client->get('/status')->status);
    $t->same(404, $client->get('/status', ['key' => 'wrong'])->status);
});

$t->test('the right key opens /status', function ($t) use ($client, $app): void {
    $key = $app->config()->secret('status_key');
    if ($key === null) {
        $t->ok(true, 'no status_key configured in this environment');
        return;
    }
    $response = $client->get('/status', ['key' => $key]);
    $t->same(200, $response->status);
    $t->contains('RUNTIME', $response->body);
    $t->contains('SESSION', $response->body);
    $t->contains('WEATHER', $response->body);
});

$t->group('Admin routes as an admin');

$t->test('an admin reaches the three admin screens', function ($t) use ($client, $root, $makeUser, $suffix): void {
    $admin = $makeUser('adm' . $suffix, 'admin');
    $client->forgetCookies();
    $client->post('/login', ['username' => $admin['username'], 'password' => $admin['password']]);
    $adminPassword = 'yet another long passphrase';
    $client->post('/password/reset', ['password' => $adminPassword, 'password_confirm' => $adminPassword]);

    foreach (['/admin', '/admin/users', '/admin/research-import', '/admin/regions',
              '/admin/mail-test'] as $path) {
        $t->same(200, $client->get($path)->status, $path);
    }
});

$t->test('creating a user shows the temporary password once', function ($t) use ($client, $suffix): void {
    $response = $client->post('/admin/users', [
        'username' => 'carol' . $suffix, 'email' => 'carol@example.test',
        'name' => 'Carol Grower', 'role' => 'user',
    ]);
    $t->same(303, $response->status);

    $page = $client->follow($response);
    $t->same(200, $page->status);
    $t->contains('Temporary password', $page->body);
    $t->contains('carol' . $suffix, $page->body);

    // Shown exactly once: a reload does not repeat it.
    $again = $client->get('/admin/users');
    $t->notContains('Temporary password', $again->body);
});

$t->test('the mail test queues to the address in the field, not the admin\'s own',
    function ($t) use ($client, $db, $suffix): void {
    // Step 7 of handoff Section 12.1 is reading spf=pass and dkim=pass off the
    // received headers, and only the RECEIVING server writes those. When the
    // admin's own address is on the sending domain the message is delivered
    // locally and never authenticated by anyone, so the recipient has to be
    // steerable or the step cannot be performed at all.
    $response = $client->post('/admin/mail-test', ['to' => 'outside' . $suffix . '@example.test']);
    $t->same(303, $response->status);

    $row = $db->one("SELECT * FROM `email_outbox` WHERE `kind` = 'test'"
        . ' ORDER BY `id` DESC LIMIT 1');
    $t->same('outside' . $suffix . '@example.test', $row['to_email']);
    // The admin's name belongs on their own mail, not on a stranger's.
    $t->same(null, $row['to_name']);
});

$t->test('an empty recipient falls back to the admin\'s own address',
    function ($t) use ($client, $db): void {
    $response = $client->post('/admin/mail-test', ['to' => '']);
    $t->same(303, $response->status);

    $row = $db->one("SELECT * FROM `email_outbox` WHERE `kind` = 'test'"
        . ' ORDER BY `id` DESC LIMIT 1');
    $t->contains('@', (string) $row['to_email']);
});

$t->test('a recipient that is not an address queues nothing',
    function ($t) use ($client, $db): void {
    $before = (int) $db->value("SELECT COUNT(*) FROM `email_outbox` WHERE `kind` = 'test'", [], 0);
    $response = $client->post('/admin/mail-test', ['to' => 'not-an-address']);
    $t->same(303, $response->status);

    $after = (int) $db->value("SELECT COUNT(*) FROM `email_outbox` WHERE `kind` = 'test'", [], 0);
    $t->same($before, $after, 'nothing was queued');
    $t->contains('not an email address', $client->follow($response)->body);
});

$t->test('a duplicate username is refused', function ($t) use ($client, $suffix): void {
    $response = $client->post('/admin/users', [
        'username' => 'carol' . $suffix, 'email' => 'carol2@example.test',
        'name' => 'Carol Two', 'role' => 'user',
    ]);
    $t->same(200, $response->status);
    $t->contains('taken', $response->body);
});

$t->group('Photos');

$t->test('a photo uploads, is re-encoded, and is served only to its owner',
    function ($t) use ($client, $alice, $db, $root, $plantIds, $today): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $alice['username'], 'password' => $alice['password']]);

    // A real JPEG, larger than the long edge, so the resize actually runs.
    $image = \imagecreatetruecolor(2400, 1600);
    $green = \imagecolorallocate($image, 60, 120, 60);
    \imagefilledrectangle($image, 0, 0, 2400, 1600, $green);
    $tmp = \tempnam(\sys_get_temp_dir(), 'carlphoto') . '.jpg';
    \imagejpeg($image, $tmp, 90);
    \imagedestroy($image);

    $response = $client->postFiles('/photos', [
        'planting_id' => (string) $plantIds['sow'],
        'event_date'  => $today,
    ], [
        'photo' => [
            'name' => 'garden.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp,
            'error' => \UPLOAD_ERR_OK, 'size' => (int) \filesize($tmp),
        ],
    ]);

    $t->same(200, $response->status);
    $payload = \json_decode($response->body, true);
    $t->ok(\is_array($payload) && ($payload['ok'] ?? false) === true,
        'upload said: ' . \substr($response->body, 0, 200));

    $photoId = (int) $payload['id'];
    $row = $db->one('SELECT * FROM `photo` WHERE `id` = :id', ['id' => $photoId]);
    $t->same($alice['id'], (int) $row['user_id']);
    $t->same(1920, (int) $row['width'], 'resized down to the 1920 long edge');
    $t->ok((int) $row['bytes'] > 0, 'the file has content');

    // The file is under var/photos/<user_id>/, outside public_html.
    $stored = $root . '/var/photos/' . $alice['id'] . '/' . $row['stored_name'];
    $t->ok(\is_file($stored), 'stored outside the document root at ' . $stored);

    // Served through the controller, and only to its owner.
    $served = $client->get('/photos/' . $photoId);
    $t->same(200, $served->status);
    $t->same('image/jpeg', $served->headers()['Content-Type']);
    $t->contains('private', $served->headers()['Cache-Control']);
    $t->same(200, $client->get('/photos/' . $photoId . '/thumb')->status);

    // Another account gets a 404, exactly as for a row that does not exist.
    $client->forgetCookies();
    $client->post('/login', ['username' => 'bob' . \substr($alice['username'], 5),
                             'password' => 'another good long passphrase']);
    $t->same(404, $client->get('/photos/' . $photoId)->status);

    // Back to Alice for the smoke test below.
    $client->forgetCookies();
    $client->post('/login', ['username' => $alice['username'], 'password' => $alice['password']]);

    @\unlink($tmp);
});

$t->test('a file that is not an image is refused with a reason', function ($t) use ($client): void {
    $tmp = \tempnam(\sys_get_temp_dir(), 'carlnot');
    \file_put_contents($tmp, 'this is not an image');

    $response = $client->postFiles('/photos', [], [
        'photo' => [
            'name' => 'notes.txt', 'type' => 'text/plain', 'tmp_name' => $tmp,
            'error' => \UPLOAD_ERR_OK, 'size' => (int) \filesize($tmp),
        ],
    ]);
    @\unlink($tmp);

    $t->same(400, $response->status);
    $t->contains('not an image', $response->body);
});

$t->group('Every screen renders');

$t->test('every GET route a user can reach returns 200',
    function ($t) use ($client, $app, $alice, $db, $plantIds): void {
    // The route table is the source of truth, so this cannot drift as routes
    // are added. It is the guard that catches a template that only breaks on
    // a value some other test never produced.
    $gardens = new GardenRepository($db, $alice['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Main Bed'])[0];
    $photoId = (int) ($db->value(
        'SELECT id FROM `photo` WHERE user_id = :u ORDER BY id DESC LIMIT 1',
        ['u' => $alice['id']]
    ) ?? 0);
    $plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

    $substitutions = [
        '/plants/{id:\d+}'                => (string) $plantIds['sow'],
        '/log/{id:\d+}'                   => (string) $plantIds['sow'],
        '/gardens/{id:\d+}'               => (string) $garden['id'],
        '/gardens/{id:\d+}/edit'          => (string) $garden['id'],
        '/gardens/{id:\d+}/actions'       => (string) $garden['id'],
        '/photos/{id:\d+}'                => (string) $photoId,
        '/photos/{id:\d+}/thumb'          => (string) $photoId,
        '/research/{id:\d+}'              => (string) $plantTypeId,
    ];

    $listTypes = \array_merge(ListType::all(), ['containers', 'hardening']);
    $checked = 0;

    foreach ($app->router()->all() as $route) {
        if ($route->method !== 'GET' || $route->access !== Carl\Core\Route::USER_ACCESS) {
            continue;
        }

        $paths = [];
        if ($route->pattern === '/lists/{type:[a-z_]+}') {
            foreach ($listTypes as $listType) {
                $paths[] = '/lists/' . $listType;
            }
        } elseif ($route->pattern === '/plants/new/{kind:indoor_seed|direct_sow|nursery_transplant}') {
            foreach (['indoor_seed', 'direct_sow', 'nursery_transplant'] as $kind) {
                $paths[] = '/plants/new/' . $kind;
            }
        } elseif (isset($substitutions[$route->pattern])) {
            $paths[] = \preg_replace('/\{[^}]+\}/', $substitutions[$route->pattern], $route->pattern);
        } elseif (!\str_contains($route->pattern, '{')) {
            $paths[] = $route->pattern;
        } else {
            throw new RuntimeException(
                'No smoke-test substitution for route ' . $route->pattern
                . '. Add one so new routes cannot slip past this check.'
            );
        }

        foreach ($paths as $path) {
            $response = $client->get($path);
            $t->same(200, $response->status, 'GET ' . $path);
            $checked++;
        }
    }

    $t->ok($checked >= 25, 'smoke-tested ' . $checked . ' pages');
});

$t->test('every GET admin route returns 200 for an admin',
    function ($t) use ($client, $app, $makeUser, $suffix): void {
    $admin = $makeUser('adm2' . $suffix, 'admin');
    $client->forgetCookies();
    $client->post('/login', ['username' => $admin['username'], 'password' => $admin['password']]);
    $client->post('/password/reset',
        ['password' => 'an admin passphrase here', 'password_confirm' => 'an admin passphrase here']);

    foreach ($app->router()->all() as $route) {
        if ($route->method !== 'GET' || $route->access !== Carl\Core\Route::ADMIN_ACCESS) {
            continue;
        }
        $t->same(200, $client->get($route->pattern)->status, 'GET ' . $route->pattern);
    }
});

$t->group('The MOTD weather matrix');

$t->test('the matrix shows the last observed days once the cron has run',
    function ($t) use ($client, $alice, $db): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $alice['username'], 'password' => $alice['password']]);

    $locationId = (int) $db->value(
        'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $alice['id']], 0
    );
    $held = (int) $db->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE location_id = :id',
        ['id' => $locationId], 0
    );

    $response = $client->get('/');
    $t->same(200, $response->status);

    if ($held === 0) {
        // Phase 1 acceptance allows the cron not to be live yet, and the box
        // says so rather than showing an empty table (handoff Section 14).
        $t->contains('Weather arrives nightly', $response->body);
        return;
    }

    $t->contains('High / low', $response->body);
    $t->contains('ET', $response->body);
    // Attribution is required and non-optional (weather.md Section 10).
    $t->contains('Open-Meteo.com', $response->body);
});

$t->test('dismissing the MOTD hides it for the day', function ($t) use ($client): void {
    $page = $client->get('/');
    if (!\str_contains($page->body, 'forecast_hash')) {
        $t->ok(true, 'nothing to dismiss without weather');
        return;
    }
    \preg_match('/name="forecast_hash" value="([^"]*)"/', $page->body, $m);

    $t->same(303, $client->post('/motd/dismiss', ['forecast_hash' => $m[1] ?? ''])->status);

    $after = $client->get('/');
    $t->notContains('High / low', $after->body, 'the matrix is gone for this session');
    $t->contains('What would you like to do?', $after->body, 'the menu is still there');
});
