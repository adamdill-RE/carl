<?php

/**
 * How big is it? (Phase 12, migration 024.) And the way back to the menu.
 *
 * Two changes, one file, because both are small and both fail the same way if
 * they fail at all -- quietly, on a screen nobody is looking at.
 *
 * The size is the larger of the two and it has four ways of being silently
 * wrong, which is what this file is pointed at:
 *
 *  1. **The unit is thrown away on the way in.** The column is millimetres
 *     (weather.md Section 6.3: store SI, convert once at display) and the
 *     form offers four units. A conversion that is skipped, doubled or read
 *     off the wrong option stores a number that is plausible, wrong, and
 *     indistinguishable afterwards from a plant that really is that size.
 *  2. **The size only rides on `measured`.** It is deliberately universal --
 *     read for every action, the way the narrative is -- because the sentence
 *     is "watered it, it's fourteen inches now" and a second trip through the
 *     form is how a field stops being filled in.
 *  3. **A batch writes one measurement to twenty plants.** The same number
 *     against twenty plants is nineteen measurements nobody took. The form
 *     hides the boxes and the handler refuses the action, and BOTH are
 *     asserted, because either one alone leaves a hole.
 *  4. **An empty `measured` is recorded anyway.** A row that says somebody
 *     went and looked at a plant is not what they meant to save, and cannot
 *     be told apart later from a measurement that failed to store.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Support\Units;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const SIZE_PASSPHRASE = 'size-test-passphrase';

$owner = (new UserRepository($db))->createWithTemporaryPassword(
    'measurer' . $suffix,
    'measurer' . $suffix . '@example.test',
    'Measurer',
    new Password($app->config()->int('auth.bcrypt_cost', 11)),
    'user'
);

$client = new Client($root);
// $_SESSION is a process global that survives from the previous case file, so
// a brand new Client is still signed in as somebody else and /login bounces
// it straight back to the menu. Every case file since 21 opens this way.
$client->forgetCookies();
$client->post('/login', ['username' => 'measurer' . $suffix, 'password' => $owner['temporary_password']]);
$client->post('/password/reset',
    ['password' => SIZE_PASSPHRASE, 'password_confirm' => SIZE_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Size Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Size Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, (int) $owner['id']);
$plantings = new PlantingRepository($db, (int) $owner['id']);
$events = new EventRepository($db, (int) $owner['id'], $plantings);

$gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Size Bed' . $suffix])[0]['id'];
$gardenRows = $gardens->rows($gardenId);
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');

// The user's local today, never the server's (handoff Section 6, and
// tests/check_test_clocks.php). This account onboards with 76692, which is
// America/Chicago, and for six hours every night that is not the UTC day.
$today = $app->clock()->todayFor(
    (string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']])
);
$sownOn = (string) Clock::addDays($today, -40);

/** A planting already in the ground, so the whole action list is offered. */
$bed = static function (int $count, string $label)
    use ($plantings, $plantTypeId, $gardenId, $gardenRows, $sownOn): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $gardenId,
        'garden_row_id'    => (int) $gardenRows[0]['id'],
        'label'            => $label,
        'start_method'     => 'direct_sow',
        'start_date'       => $sownOn,
        'in_ground_date'   => $sownOn,
        'quantity_initial' => $count,
        'quantity_live'    => $count,
        'state'            => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

/** The most recent event of a planting, whatever it is. */
$latest = static function (int $plantingId) use ($db): array {
    return (array) $db->one(
        'SELECT * FROM `plant_event` WHERE `planting_id` = :p'
        . ' ORDER BY `id` DESC LIMIT 1',
        ['p' => $plantingId]
    );
};

// ========================================================================
// 1. The unit survives the trip
// ========================================================================

$t->group('A size is stored in millimetres, whatever the gardener typed');

$t->test('the four units the form offers all convert, and none is guessed at',
    function ($t): void {
    // Exact, not approximate: an inch is 25.4 mm by definition, so a
    // tolerance here would hide a wrong constant rather than allow for one.
    $t->same(304.8, Units::toMillimetres(12, 'in'), 'twelve inches');
    $t->same(304.8, Units::toMillimetres(1, 'ft'), 'a foot is the same length');
    $t->same(304.8, Units::toMillimetres(30.48, 'cm'), 'and so is 30.48 cm');
    $t->same(1000.0, Units::toMillimetres(1, 'm'), 'a metre');

    $t->same(null, Units::toMillimetres(null, 'in'), 'nothing typed is nothing stored');
    $t->same(null, Units::toMillimetres('', 'in'), 'and so is an empty box');

    // The one that matters: an unknown unit stores NOTHING rather than
    // falling back to inches, which would be a number 25 times too small
    // arriving with no sign that anything went wrong.
    $t->same(null, Units::toMillimetres(12, 'mm'), 'a unit the form never offered');
    $t->same(null, Units::toMillimetres(12, ''), 'and no unit at all');

    // The dropdown and the converter read the same list, so a unit can never
    // be offered that cannot be converted.
    foreach (Units::SIZE_UNITS as $unit) {
        $t->ok(Units::toMillimetres(1, $unit) !== null, $unit . ' is convertible');
    }
});

$t->test('a size comes back out in the account\'s own unit, one unit all the way up',
    function ($t): void {
    $us = new Units('us');
    $si = new Units('si');

    $t->same('12.0 in', $us->size(304.8));
    $t->same('30.5 cm', $si->size(304.8));
    $t->same('in', $us->sizeUnit());
    $t->same('cm', $si->sizeUnit());

    // A six-foot tomato stays in inches. weight() switches oz to lb at
    // sixteen; a size must not, because it is plotted and a chart axis
    // cannot change units halfway up (Units::size docblock).
    $t->same('72.0 in', $us->size(1828.8), 'six feet, still in inches');
    $t->same('182.9 cm', $si->size(1828.8));

    $t->same('--', $us->size(null), 'nothing measured prints a dash');
    $t->same(12.0, $us->sizeValue(304.8, 1), 'and the chart gets a number');
});

$t->test('the form posts a unit and the column ends up in millimetres',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(3, 'Measured in inches');

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '14', 'size_unit' => 'in',
    ]);
    $t->same(303, $response->status, 'a recorded event redirects');

    $row = $latest($id);
    $t->same(EventType::MEASURED, $row['event_type']);
    $t->same('355.60', $row['height_mm'], 'fourteen inches, in millimetres');
    $t->same(null, $row['diameter_mm'], 'and nothing where nothing was typed');
});

$t->test('feet and metres are undone on the way in, not stored as typed',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(1, 'A sunflower');

    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '6', 'size_diameter' => '2', 'size_unit' => 'ft',
    ]);

    $row = $latest($id);
    $t->same('1828.80', $row['height_mm'], 'six feet');
    $t->same('609.60', $row['diameter_mm'], 'two feet across');
});

$t->test('a metre typed into a box set to metres is not an absurd plant, and 200 m is',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(1, 'Bounds');

    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '2', 'size_unit' => 'm',
    ]);
    $t->same('2000.00', $latest($id)['height_mm'], 'a two-metre plant is ordinary');

    // Over the ceiling the value is dropped, and `measured` with nothing left
    // is refused rather than written empty -- which is the two rules meeting.
    $refused = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '200', 'size_unit' => 'm',
    ]);
    $t->same(400, $refused->status, 'a 200 m plant is a unit left on the wrong option');
    $t->same('2000.00', $latest($id)['height_mm'], 'and nothing was written');

    $negative = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '-5', 'size_unit' => 'in',
    ]);
    $t->same(400, $negative->status, 'nor is a plant minus five inches tall');
});

// ========================================================================
// 2. A size rides on any action, not only on `measured`
// ========================================================================

$t->group('A size belongs to the visit, not to one action');

$t->test('watering a plant can say how big it was while you were there',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(2, 'Watered and measured');

    $client->post('/log/' . $id, [
        'event_type' => EventType::WATERED, 'event_date' => $today,
        'duration_min' => '15',
        'size_height' => '20', 'size_diameter' => '9', 'size_unit' => 'in',
    ]);

    $row = $latest($id);
    $t->same(EventType::WATERED, $row['event_type'], 'still one event, still a watering');
    $t->same(15, (int) $row['duration_min'], 'and it still carries the watering fields');
    $t->same('508.00', $row['height_mm']);
    $t->same('228.60', $row['diameter_mm']);
});

$t->test('a harvest can too, and the two sets of numbers do not collide',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(2, 'Picked and measured');

    $client->post('/log/' . $id, [
        'event_type' => EventType::YIELDED, 'event_date' => $today,
        'yield_weight' => '8', 'yield_weight_unit' => 'oz',
        'size_height' => '150', 'size_unit' => 'cm',
    ]);

    $row = $latest($id);
    $t->same(EventType::YIELDED, $row['event_type']);
    $t->ok(\abs((float) $row['weight_g'] - 226.796) < 0.01, 'eight ounces is still eight ounces');
    $t->same('1500.00', $row['height_mm'], 'and 150 cm is 1500 mm');
});

$t->test('every other form on the site posts no unit, and stores no size',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(2, 'No size posted');

    $client->post('/log/' . $id, [
        'event_type' => EventType::WATERED, 'event_date' => $today,
        'duration_min' => '10',
    ]);

    $row = $latest($id);
    $t->same(null, $row['height_mm'], 'no unit posted, no size read');
    $t->same(null, $row['diameter_mm']);
});

$t->test('a size posted with no unit is dropped rather than read as inches',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(1, 'Unit missing');

    $client->post('/log/' . $id, [
        'event_type' => EventType::WATERED, 'event_date' => $today,
        'size_height' => '14',
    ]);

    $t->same(null, $latest($id)['height_mm'],
        'reading it as inches would store a plausible wrong number');
});

// ========================================================================
// 3. Height OR diameter OR both
// ========================================================================

$t->group('Height or diameter or both, and never neither');

$t->test('a diameter with no height is a whole measurement',
    function ($t) use ($client, $bed, $today, $latest): void {
    $id = $bed(1, 'A squash');

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_diameter' => '30', 'size_unit' => 'in',
    ]);
    $t->same(303, $response->status, 'a squash is a spread, not a height');

    $row = $latest($id);
    $t->same(null, $row['height_mm']);
    $t->same('762.00', $row['diameter_mm']);
});

$t->test('"Measured" with neither is refused, and refused with a reason',
    function ($t) use ($client, $bed, $today, $db): void {
    $id = $bed(1, 'Nothing measured');
    $before = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `planting_id` = :p', ['p' => $id], 0);

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_unit' => 'in',
    ]);

    $t->same(400, $response->status);
    $t->contains('height, a diameter, or both', $response->body,
        'and it says which of the two boxes to fill in');
    $t->same($before, (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `planting_id` = :p', ['p' => $id], 0),
        'an event that says somebody went and looked is not the event they meant');
});

$t->test('a measurement moves nothing: not the state, not the count, not the dates',
    function ($t) use ($client, $bed, $today, $plantings): void {
    $id = $bed(5, 'State unchanged');
    $before = $plantings->find($id);

    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '18', 'size_unit' => 'in',
    ]);

    $after = $plantings->find($id);
    $t->same($before['state'], $after['state'], 'measuring a plant does not grow it');
    $t->same((int) $before['quantity_live'], (int) $after['quantity_live']);
    $t->same($before['ended_at'], $after['ended_at']);
    $t->same($before['in_ground_date'], $after['in_ground_date']);
});

// ========================================================================
// 4. One plant at a time
// ========================================================================

$t->group('A measurement belongs to the plant it was taken from');

$t->test('the batch form does not offer it, and does not show the boxes',
    function ($t) use ($client, $bed): void {
    $a = $bed(3, 'Batch A');
    $b = $bed(3, 'Batch B');

    $response = $client->post('/log/batch', [
        'planting_ids' => [(string) $a, (string) $b],
    ]);
    $t->same(200, $response->status, 'step one of a batch is the form');

    $t->contains('Log the same action for 2 plants', $response->body);
    $t->notContains('value="' . EventType::MEASURED . '"', $response->body,
        'Measured is not in the batch dropdown');
    $t->notContains('name="size_height"', $response->body,
        'and neither are the boxes: one number against two plants is one measurement too few');
    $t->contains('value="' . EventType::WATERED . '"', $response->body,
        'everything genuinely shared is still offered');
});

$t->test('and posting it to the batch endpoint anyway is refused',
    function ($t) use ($client, $bed, $today, $db): void {
    $a = $bed(3, 'Batch C');
    $b = $bed(3, 'Batch D');
    $before = (int) $db->value('SELECT COUNT(*) FROM `plant_event`'
        . ' WHERE `event_type` = :e', ['e' => EventType::MEASURED], 0);

    $response = $client->post('/log/batch', [
        'planting_ids' => [(string) $a, (string) $b],
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '14', 'size_unit' => 'in',
    ]);

    $t->same(400, $response->status, 'the handler refuses what the form withheld');
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `plant_event`'
        . ' WHERE `event_type` = :e', ['e' => EventType::MEASURED], 0),
        'and nothing was written to either plant');
});

$t->test('the single-plant form does offer it, and does show the boxes',
    function ($t) use ($client, $bed): void {
    $id = $bed(3, 'Single');

    $response = $client->get('/log/' . $id);
    $t->same(200, $response->status);
    $t->contains('value="' . EventType::MEASURED . '"', $response->body);
    $t->contains('name="size_height"', $response->body);
    $t->contains('name="size_diameter"', $response->body);
    $t->contains('name="size_unit"', $response->body);
});

$t->test('an ended planting is not asked how big it is',
    function ($t) use ($client, $bed, $today, $plantings): void {
    $id = $bed(2, 'Ended');
    $client->post('/log/' . $id, [
        'event_type' => EventType::DIED, 'event_date' => $today,
    ]);
    $t->same(PlantingState::ENDED, (string) $plantings->find($id)['state']);

    $response = $client->get('/log/' . $id);
    $t->notContains('value="' . EventType::MEASURED . '"', $response->body,
        'a plant that is gone has no size now');

    $refused = $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '14', 'size_unit' => 'in',
    ]);
    $t->same(400, $refused->status);
});

$t->test('the one-tap field screen leaves it out too: a measurement is a number',
    function ($t): void {
    // The tag screen narrows PlantingState::actionsFor() to the actions a
    // single tap can honestly record (TagController::fieldActions). A
    // "Measured" button there would write a row that says somebody looked.
    $method = new \ReflectionMethod(\Carl\Controller\TagController::class, 'fieldActions');
    $method->setAccessible(true);

    foreach ([PlantingState::PLANTED, PlantingState::SEED_STARTED, PlantingState::YIELDING] as $state) {
        $offered = $method->invoke(null, $state);
        $t->ok(!\in_array(EventType::MEASURED, $offered, true),
            'no one-tap Measured in the ' . $state . ' state');
        $t->ok(\in_array(EventType::WATERED, $offered, true),
            'but the one-tap actions are still there in ' . $state);
    }
});

// ========================================================================
// 5. Where a size shows up afterwards
// ========================================================================

$t->group('A measurement that is recorded and never shown is not recorded');

$t->test('the timeline says how tall and how wide, in the account\'s unit',
    function ($t) use ($client, $bed, $today): void {
    $id = $bed(1, 'On the timeline');
    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '14', 'size_diameter' => '8', 'size_unit' => 'in',
    ]);

    $response = $client->get('/plants/' . $id);
    $t->same(200, $response->status);
    $t->contains('14.0 in tall', $response->body);
    $t->contains('8.0 in across', $response->body);
});

$t->test('the plant report leads with the latest of each, from whichever day it came',
    function ($t) use ($client, $bed, $today): void {
    $id = $bed(1, 'Latest of each');
    $earlier = (string) Clock::addDays($today, -14);

    // A height in June and a diameter in July: the report has to carry both,
    // each with its own date, because a single date column would be wrong
    // about one of them.
    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $earlier,
        'size_height' => '10', 'size_diameter' => '4', 'size_unit' => 'in',
    ]);
    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '22', 'size_unit' => 'in',
    ]);

    $response = $client->get('/plants/' . $id);

    // Scoped to "Where it stands", not to the page: the timeline below it
    // carries every measurement ever taken, which is the point of a timeline.
    // A negative assertion over the whole body would pass for the wrong
    // reason the day the timeline stopped printing sizes.
    $from = \strpos($response->body, 'Where it stands');
    $t->ok($from !== false, 'the summary table is on the page');
    $summary = \substr($response->body, (int) $from,
        (int) \strpos($response->body, '</section>', (int) $from) - (int) $from);

    $t->contains('<th>Size</th>', $summary);
    $t->contains('22.0 in tall', $summary, 'the newest height');
    $t->contains('4.0 in across', $summary, 'and the last diameter, from a fortnight before');
    $t->notContains('10.0 in tall', $summary, 'not the height it has outgrown');
    $t->contains('10.0 in tall', $response->body, 'which the timeline below still remembers');
});

$t->test('the events export carries both columns, named in the unit they are stored in',
    function ($t) use ($client, $bed, $today): void {
    $id = $bed(1, 'Exported');
    $client->post('/log/' . $id, [
        'event_type' => EventType::MEASURED, 'event_date' => $today,
        'size_height' => '1', 'size_unit' => 'm',
    ]);

    $response = $client->get('/export/events.csv');
    $t->same(200, $response->status);
    // collect(), not ->body: the export is streamed a chunk at a time so a
    // season of events never has to be held in 128 MB at once.
    $body = $response->collect();
    $t->contains('height_mm,diameter_mm', $body, 'an export gets the stored numbers');
    $t->contains('1000.00', $body, 'a metre, as a thousand millimetres');

    // The garden half of the same file has to line up under the same header,
    // or every column after it is shifted by two for half the rows.
    $lines = \array_values(\array_filter(\explode("\n", \trim($body))));
    $columns = \substr_count($lines[0], ',');
    foreach ($lines as $i => $line) {
        $t->same($columns, \substr_count($line, ','),
            'row ' . $i . ' has the same number of fields as the header');
    }
});

// ========================================================================
// 6. The way back to the menu
// ========================================================================

$t->group('The way out of a page');

$t->test('every signed-in screen carries a labelled link to the menu',
    function ($t) use ($client, $bed): void {
    $id = $bed(1, 'Nav');

    // Deep in a report, deep in a form, and on a list: the three places
    // somebody was scrolling to the bottom of to get anywhere.
    foreach (['/plants/' . $id, '/log/' . $id, '/plants', '/reports', '/calendar'] as $path) {
        $response = $client->get($path);
        $t->same(200, $response->status, $path . ' renders');
        $t->contains('class="nav-menu"', $response->body, $path . ' has the way back');
        $t->contains('>Menu</a>', $response->body, $path . ' says the word');
    }
});

$t->test('on the menu itself it is marked as where you are, not removed',
    function ($t) use ($client): void {
    $response = $client->get('/');
    $t->same(200, $response->status);
    // Marked rather than dropped: the bar is sticky and a control that comes
    // and goes moves everything beside it on every navigation.
    $t->contains('nav-menu is-current', $response->body);
    $t->contains('aria-current="page"', $response->body);
});

$t->test('a stranger is not offered a menu they cannot reach',
    function ($t) use ($client): void {
    // forgetCookies() and not a fresh Client: $_SESSION is a process global
    // that a second client would inherit, so a new object alone is still
    // signed in and /login redirects it back to the menu.
    $client->forgetCookies();

    $response = $client->get('/login');
    $t->same(200, $response->status);
    $t->notContains('class="nav-menu', $response->body,
        'signed out there is nothing behind it but the login screen');
});
