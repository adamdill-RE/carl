<?php

/**
 * Splitting a planting (docs/PLANTING-SPLIT-SPEC.md).
 *
 * The spec calls this change "smaller than it looks, and riskier than it is
 * big", and says exactly why: `PlantingState::derive()` is the one piece of
 * logic every other feature's correctness rests on, and the change to it is
 * the kind that passes every existing test -- nothing before this split
 * anything -- while being wrong for a case no test covered. So this file is
 * as large as the code, and it is pointed at the five things that can be
 * quietly wrong:
 *
 *  1. **Nothing changes for anybody who never splits.** The whole design is
 *     de-risked by that property (Section 4.2) and it is asserted rather
 *     than trusted.
 *  2. **A dispersal is not attrition.** Six plants leaving must never read
 *     as six plants dying -- not in the live count, not in the survival
 *     rate, and not in the word the page uses for a planting that ended
 *     (Sections 4.4 and 4.5).
 *  3. **The arithmetic degrades rather than explodes.** Carl backdates
 *     everything, so a death recorded after a split and dated before it will
 *     happen. The clamp is deliberate; an unstated clamp is a bug waiting to
 *     be "fixed" (Section 4.7).
 *  4. **The lineage is a link, not a merge.** Merging timelines would cost a
 *     statement per generation and break the assertions in
 *     11_reports_test.php (Section 4.6).
 *  5. **A split happens when, and only when, a subset moves somewhere
 *     else.** Every other partial-quantity action stays one event
 *     (Section 4.1).
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
use Carl\Reports\Series;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const SPLIT_PASSPHRASE = 'split-test-passphrase';

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('splitter' . $suffix);

$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => SPLIT_PASSPHRASE, 'password_confirm' => SPLIT_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Split Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Split Bed' . $suffix, 'row_count' => '3', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$events = new EventRepository($db, $owner['id'], $plantings);

$gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Split Bed' . $suffix])[0]['id'];
$rows = $gardens->rows($gardenId);
$indoorId = $gardens->ensureIndoorGarden();
// THE USER'S LOCAL TODAY, NEVER THE SERVER'S -- handoff Section 6, and the
// suite has to obey it as much as the application does. Every event Carl
// writes is dated in the account's own timezone; this account is in
// America/Chicago, so between UTC midnight and local midnight gmdate() and
// the right answer are DIFFERENT DAYS. Asserting the UTC one gives a suite
// that is green all afternoon and red for six hours every night, which is
// worse than a suite that is simply wrong.
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = $app->clock()->todayFor(
    (string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']])
);
$sownOn = (string) Clock::addDays($today, -60);

/** A tray of $count, indoors, started sixty days ago. */
$tray = static function (int $count, string $label) use ($plantings, $plantTypeId, $indoorId, $sownOn): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => $sownOn,
        'quantity_initial' => $count,
        'quantity_live'    => $count,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

// -- 1. The de-risking property ------------------------------------------

$t->group('Nothing changes for an account that never splits');

$t->test('every planting in the database is its own root, and nothing says 0',
    function ($t) use ($db): void {
    // The placeholder 019 puts in the rows it adds the column to is replaced
    // by 020, and by PlantingRepository::insert() for every row after that.
    // A zero here means a write path that does not go through the one writer.
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `planting` WHERE `root_planting_id` = 0', [], 0
    ), 'a planting with no root is invisible to a whole-sowing query');

    // Every root points at a real planting belonging to the same account.
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `planting` p'
        . ' LEFT JOIN `planting` r ON r.id = p.root_planting_id AND r.user_id = p.user_id'
        . ' WHERE r.id IS NULL', [], 0
    ), 'every root_planting_id names a planting of the same account');
});

$t->test('a planting nobody split carries nulls and its own id',
    function ($t) use ($tray, $plantings): void {
    $id = $tray(12, 'Untouched');
    $row = $plantings->find($id);

    $t->same(null, $row['split_from_id']);
    $t->same($id, (int) $row['root_planting_id'], 'its own root');
    $t->same(null, $row['ended_reason']);
    $t->same(0, (int) $row['quantity_lost']);
    $t->same(12, (int) $row['quantity_live']);
});

$t->test('transplanting a whole planting still moves the row it was logged against',
    function ($t) use ($client, $plantings, $tray, $rows, $gardenId, $today, $db): void {
    $id = $tray(4, 'Whole move');
    $before = (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0);

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $rows[0]['id'],
    ]);
    $t->same(303, $response->status);

    $row = $plantings->find($id);
    $t->same((int) $rows[0]['id'], (int) $row['garden_row_id'], 'the planting itself moved');
    $t->same(4, (int) $row['quantity_live'], 'and all four went');
    $t->same(null, $row['split_from_id']);
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0),
        'no child was made for a move of the whole thing');
});

// -- 2. The split itself --------------------------------------------------

$t->group('Moving a subset makes a planting');

$parentId = $tray(100, 'The hundred');
$childId = 0;

$t->test('six of a hundred transplanted leaves ninety-four and makes a six',
    function ($t) use ($client, $plantings, $parentId, $rows, $gardenId, $today, $db, &$childId): void {
    $response = $client->post('/log/' . $parentId, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '6',
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $rows[1]['id'],
    ]);
    $t->same(303, $response->status);

    $childId = (int) $db->value(
        'SELECT id FROM `planting` WHERE `split_from_id` = :p ORDER BY id DESC LIMIT 1',
        ['p' => $parentId]
    );
    $t->ok($childId > 0, 'a child planting exists');

    $parent = $plantings->find($parentId);
    $child = $plantings->find($childId);

    $t->same(94, (int) $parent['quantity_live'], 'the parent kept the rest');
    $t->same(100, (int) $parent['quantity_initial'], 'and still says what it started as');
    $t->same(6, (int) $child['quantity_initial']);
    $t->same(6, (int) $child['quantity_live']);
    $t->same((int) $rows[1]['id'], (int) $child['garden_row_id'], 'the six are in the bed');
    $t->same($gardenId, (int) $child['garden_id'], 'and in the garden that bed belongs to');
});

$t->test('the parent is still where it was, and is not ended',
    function ($t) use ($plantings, $parentId, $indoorId): void {
    $parent = $plantings->find($parentId);
    $t->same($indoorId, (int) $parent['garden_id'], 'the tray did not follow the six');
    $t->same(null, $parent['garden_row_id']);
    $t->ok((string) $parent['state'] !== PlantingState::ENDED, 'ninety-four plants is not ended');
    $t->same(null, $parent['ended_at']);
    $t->same(null, $parent['ended_reason']);
});

$t->test('the child descends from the parent and carries the sowing it came from',
    function ($t) use ($plantings, $parentId, &$childId, $sownOn): void {
    $parent = $plantings->find($parentId);
    $child = $plantings->find($childId);

    $t->same($parentId, (int) $child['split_from_id']);
    $t->same((int) $parent['root_planting_id'], (int) $child['root_planting_id']);
    $t->same($sownOn, (string) $child['start_date'],
        'the six were sown when the tray was, not when they moved');
    $t->same('indoor_seed', (string) $child['start_method']);
    $t->same((int) $parent['plant_type_id'], (int) $child['plant_type_id']);
});

$t->test('the parent records that they left, and where to; the child records the move',
    function ($t) use ($events, $parentId, &$childId, $today): void {
    $splitOut = null;
    foreach ($events->timeline($parentId) as $event) {
        if ((string) $event['event_type'] === EventType::SPLIT_OUT) {
            $splitOut = $event;
        }
    }
    $t->ok($splitOut !== null, 'the parent has a split_out event');
    $t->same(-6, (int) $splitOut['quantity_delta'], 'a negative delta, which is not attrition');
    $t->same($childId, (int) $splitOut['split_planting_id'], 'pointing at the child');
    $t->same($today, (string) $splitOut['event_date']);

    $childTypes = \array_map(
        static fn (array $e): string => (string) $e['event_type'],
        $events->timeline($childId)
    );
    $t->ok(\in_array(EventType::TRANSPLANTED, $childTypes, true),
        'the child was transplanted, because that is what happened to it');
    $t->ok(!\in_array(EventType::SPLIT_OUT, $childTypes, true),
        'the split is recorded on the parent, never on the child');
});

$t->test('the child was born in the ground and the parent was not',
    function ($t) use ($plantings, $parentId, &$childId, $today): void {
    $t->same($today, (string) $plantings->find($childId)['in_ground_date']);
    $t->same(null, $plantings->find($parentId)['in_ground_date'],
        'the tray is still a tray');
});

$t->test('the childs derived dates are its own, not copied from the tray',
    function ($t) use ($plantings, &$childId): void {
    // germinated_at and hardening_started_at are functions of a planting's
    // OWN log. Copying them would be merging the timelines in a column, which
    // is the thing Section 4.6 refuses to do in the open.
    $child = $plantings->find($childId);
    $t->same(null, $child['germinated_at']);
    $t->same(null, $child['hardening_started_at']);
});

// -- 3. A split of a split ------------------------------------------------

$t->group('A split of a split');

$t->test('the grandchild points at its parent and at the original sowing',
    function ($t) use ($plantings, $events, $parentId, &$childId, $rows, $gardenId, $today): void {
    $moved = $plantings->split(
        $events, $childId, 2, EventType::MOVED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[2]['id'], 'container_id' => null]
    );
    $grandchild = $plantings->find($moved['child_id']);

    $t->same($childId, (int) $grandchild['split_from_id'], 'its parent is the six');
    $t->same($parentId, (int) $grandchild['root_planting_id'],
        'but its root is the hundred: the chain is flattened as it is built');
    $t->same(4, (int) $plantings->find($childId)['quantity_live'], 'the six are now four');
});

$t->test('the whole sowing is one statement, however deep the chain goes',
    function ($t) use ($db, $plantings, $parentId): void {
    $before = $db->statementCount();
    $family = $plantings->wholeSowing($parentId);
    $t->same(1, $db->statementCount() - $before, 'one statement for three generations');

    $ids = \array_map(static fn (array $p): int => (int) $p['id'], $family);
    $t->same(3, \count($ids), 'the tray, the six and the two');
    $t->ok(\in_array($parentId, $ids, true), 'the sowing is in its own sowing');
});

// -- 4. Ending by dispersal ----------------------------------------------

$t->group('A tray every plant left is not a tray that died');

$t->test('splitting the last plant out ends the planting as dispersed',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today): void {
    $id = $tray(8, 'All of them leave');
    $plantings->split($events, $id, 8, EventType::TRANSPLANTED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[0]['id'], 'container_id' => null]);

    $row = $plantings->find($id);
    $t->same(0, (int) $row['quantity_live']);
    $t->same(PlantingState::ENDED, (string) $row['state']);
    // The pair. Before the split existed, derive() set state=ended and left
    // ended_at null for exactly this case: an inconsistent row, and a UI that
    // called a fully transplanted tray "ended", which reads as dead.
    $t->same($today, (string) $row['ended_at'], 'ended_at is set, not null');
    $t->same(PlantingState::ENDED_BY_DISPERSAL, (string) $row['ended_reason']);
    $t->same(0, (int) $row['quantity_lost'], 'nothing was lost: they all moved');
});

$t->test('the two endings print different sentences', function ($t): void {
    $t->same('Fully moved out', PlantingState::endedLabel(PlantingState::ENDED_BY_DISPERSAL));
    $t->same('Ended', PlantingState::endedLabel(PlantingState::ENDED_BY_ATTRITION));
    // Every planting that ended before migration 019 has a null reason, and
    // nothing could disperse then, so a null reads as it always has.
    $t->same('Ended', PlantingState::endedLabel(null));
});

$t->test('culling the last plant still ends it as attrition',
    function ($t) use ($plantings, $events, $tray, $today): void {
    $id = $tray(3, 'All of them die');
    $events->record($id, EventType::CULLED, $today, ['quantity_delta' => -3, 'count_qty' => 3]);

    $row = $plantings->find($id);
    $t->same(PlantingState::ENDED, (string) $row['state']);
    $t->same($today, (string) $row['ended_at']);
    $t->same(PlantingState::ENDED_BY_ATTRITION, (string) $row['ended_reason']);
    $t->same(3, (int) $row['quantity_lost']);
});

// -- 5. Dispersal is not attrition ---------------------------------------

$t->group('Six plants leaving is not six plants dying');

$t->test('survival counts what died, and a split does not move it',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today): void {
    $id = $tray(100, 'Survival');
    $t->same(100, PlantingState::survivalPercent(100, 0));

    $plantings->split($events, $id, 40, EventType::TRANSPLANTED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[1]['id'], 'container_id' => null]);

    $row = $plantings->find($id);
    $t->same(60, (int) $row['quantity_live'], 'sixty left on the tray');
    $t->same(0, (int) $row['quantity_lost'], 'and none of the forty is a loss');
    $t->same(100, PlantingState::survivalPercent(
        (int) $row['quantity_initial'], (int) $row['quantity_lost']
    ), 'a hundred per cent survival, because nothing died');

    // The old expression, for contrast: it would have said 60%.
    $t->ok((int) $row['quantity_live'] / (int) $row['quantity_initial'] * 100 < 100,
        'the live-over-initial expression this replaces would read 60%');
});

$t->test('attrition after a split is counted, and only the attrition',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today): void {
    $id = $tray(100, 'Survival two');
    $plantings->split($events, $id, 30, EventType::TRANSPLANTED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[2]['id'], 'container_id' => null]);
    $events->record($id, EventType::DIED, $today, ['quantity_delta' => -10, 'count_qty' => 10]);

    $row = $plantings->find($id);
    $t->same(60, (int) $row['quantity_live']);
    $t->same(10, (int) $row['quantity_lost']);
    $t->same(90, PlantingState::survivalPercent(100, 10), 'ninety of the hundred are alive somewhere');
});

$t->test('the survival helper refuses to divide by nothing', function ($t): void {
    $t->same(null, PlantingState::survivalPercent(0, 0));
    $t->same(0, PlantingState::survivalPercent(10, 10));
    $t->same(0, PlantingState::survivalPercent(10, 99), 'clamped, never negative');
});

// -- 6. Backdating a contradiction ---------------------------------------

$t->group('Backdating a death to before the split');

$contradiction = 0;

$t->test('the parent degrades to zero rather than to a negative or an exception',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today, &$contradiction): void {
    // Split every plant out on the 14th, then record on the 20th that twenty
    // died on the 12th. The parent now had 80, and 100 of them left.
    $id = $tray(100, 'Contradiction');
    $contradiction = $id;
    $splitOn = (string) Clock::addDays($today, -6);
    $diedOn = (string) Clock::addDays($today, -8);

    $moved = $plantings->split($events, $id, 100, EventType::TRANSPLANTED, $splitOn,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[0]['id'], 'container_id' => null]);
    $events->record($id, EventType::DIED, $diedOn, ['quantity_delta' => -20, 'count_qty' => 20]);
    $contradiction = $id;

    $row = $plantings->find($id);
    // The clamp is deliberate and is stated in the derive() docblock. If it
    // is ever "fixed" into an exception, a gardener correcting their own
    // records gets a 500.
    $t->same(0, (int) $row['quantity_live'], 'clamped to zero, not -20');
    $t->same(PlantingState::ENDED, (string) $row['state']);

    // The child is never retroactively resized: those hundred physically
    // moved, and no later bookkeeping un-moves them.
    $t->same(100, (int) $plantings->find($moved['child_id'])['quantity_initial']);
    $t->same(100, (int) $plantings->find($moved['child_id'])['quantity_live']);
});

$t->test('the event that crossed zero is what says how it ended',
    function ($t) use ($plantings, &$contradiction, $today): void {
    // A child inherits its parent's label, so this reads the id and not the
    // label -- and that is worth knowing: after a split there are two
    // plantings called the same thing, told apart by the "moved out of
    // another" badge.
    $row = $plantings->find($contradiction);

    // Ordered by date, the death is first and takes the tray to 80; the split
    // then takes it past zero. The split is what crossed zero, so the tray
    // ended by dispersal even though twenty of it died.
    $t->same((string) Clock::addDays($today, -6), (string) $row['ended_at']);
    $t->same(PlantingState::ENDED_BY_DISPERSAL, (string) $row['ended_reason']);
    $t->same(20, (int) $row['quantity_lost'], 'twenty died, and the clamp did not eat them');
    $t->same(80, PlantingState::survivalPercent(100, (int) $row['quantity_lost']),
        'eighty of the hundred are alive in the bed');
});

$t->test('lost is clamped to what was started, however contradictory the log',
    function ($t) use ($plantings, $events, $tray, $today): void {
    $id = $tray(5, 'Over-culled');
    $events->record($id, EventType::CULLED, $today, ['quantity_delta' => -5, 'count_qty' => 5]);
    $events->record($id, EventType::DIED, (string) Clock::addDays($today, -1),
        ['quantity_delta' => -4, 'count_qty' => 4]);

    $row = $plantings->find($id);
    $t->same(0, (int) $row['quantity_live']);
    $t->same(5, (int) $row['quantity_lost'], 'nine cannot die out of five');
    $t->same(0, PlantingState::survivalPercent(5, (int) $row['quantity_lost']));
});

// -- 7. The child lives its own life -------------------------------------

$t->group('After the split the two are separate plantings');

$t->test('the child can die without touching the parent',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today): void {
    $id = $tray(20, 'Independent');
    $moved = $plantings->split($events, $id, 5, EventType::TRANSPLANTED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[1]['id'], 'container_id' => null]);

    $events->record($moved['child_id'], EventType::DIED, $today,
        ['quantity_delta' => -5, 'count_qty' => 5]);

    $child = $plantings->find($moved['child_id']);
    $parent = $plantings->find($id);

    $t->same(0, (int) $child['quantity_live']);
    $t->same(PlantingState::ENDED_BY_ATTRITION, (string) $child['ended_reason']);
    $t->same(5, (int) $child['quantity_lost']);

    $t->same(15, (int) $parent['quantity_live'], 'the parent did not notice');
    $t->same(0, (int) $parent['quantity_lost'], 'and did not inherit the loss');
    $t->ok((string) $parent['state'] !== PlantingState::ENDED);
});

$t->test('deleting a child leaves the parents record of the move intact',
    function ($t) use ($plantings, $events, $tray, $rows, $gardenId, $today, $db): void {
    $id = $tray(10, 'Deleted child');
    $moved = $plantings->split($events, $id, 3, EventType::TRANSPLANTED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[2]['id'], 'container_id' => null]);

    $plantings->delete($moved['child_id']);

    // ON DELETE SET NULL, not CASCADE: the three plants did leave, and the
    // parent's count must not silently gain them back.
    $row = $db->one(
        'SELECT * FROM `plant_event` WHERE `planting_id` = :p AND `event_type` = :e',
        ['p' => $id, 'e' => EventType::SPLIT_OUT]
    );
    $t->ok($row !== null, 'the split_out row survived the delete');
    $t->same(null, $row['split_planting_id'], 'only the link went');
    $t->same(-3, (int) $row['quantity_delta']);

    $plantings->recomputeState($id);
    $t->same(7, (int) $plantings->find($id)['quantity_live'],
        'and the parent still has seven, not ten');
});

// -- 8. When a split happens, and when it must not -----------------------

$t->group('A split happens only when a subset moves somewhere else');

$t->test('a partial move with nowhere to go is refused, with a reason',
    function ($t) use ($client, $tray, $today, $db): void {
    $id = $tray(30, 'Nowhere');
    $before = (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0);

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '6',
    ]);
    $t->same(400, $response->status);
    $t->contains('somewhere for them to go', $response->body);
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0),
        'and nothing was created');
});

$t->test('a partial move to where they already are is refused too',
    function ($t) use ($client, $tray, $today, $indoorId): void {
    $id = $tray(30, 'Same place');
    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '6', 'garden_id' => (string) $indoorId,
    ]);
    $t->same(400, $response->status);
});

$t->test('an up-pot with no destination records the soil and moves nothing',
    function ($t) use ($client, $plantings, $tray, $today, $indoorId, $db): void {
    $id = $tray(9, 'Up-potted in place');
    $before = (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0);

    $response = $client->post('/log/' . $id, [
        'event_type' => EventType::UP_POTTED, 'event_date' => $today,
        'soil_new' => 'Potting mix', 'container_type_new' => '4 inch',
    ]);
    $t->same(303, $response->status);

    $row = $plantings->find($id);
    $t->same($indoorId, (int) $row['garden_id'], 'a blank destination did not blank the placement');
    $t->same(9, (int) $row['quantity_live']);
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0));
});

$t->test('culling six of thirty is one event and never a split',
    function ($t) use ($client, $plantings, $tray, $today, $db, $rows, $gardenId): void {
    $id = $tray(30, 'Partial cull');
    $before = (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0);

    // Into the ground first: a seed tray cannot be culled, only died or
    // failed to germinate (PlantingState::actionsFor).
    $t->same(303, $client->post('/log/' . $id, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $rows[2]['id'],
    ])->status);
    $t->same(303, $client->post('/log/' . $id, [
        'event_type' => EventType::CULLED, 'event_date' => $today, 'quantity' => '6',
    ])->status);

    $row = $plantings->find($id);
    $t->same(24, (int) $row['quantity_live']);
    $t->same(6, (int) $row['quantity_lost'], 'a cull is a loss, and stays one');
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0),
        'the plants did not go anywhere, so nothing was split');
});

$t->test('moving more than there are moves all of them and does not split',
    function ($t) use ($client, $plantings, $tray, $rows, $gardenId, $today, $db): void {
    $id = $tray(4, 'Overreach');
    $before = (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0);

    $client->post('/log/' . $id, [
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '99',
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $rows[0]['id'],
    ]);

    $t->same((int) $rows[0]['id'], (int) $plantings->find($id)['garden_row_id']);
    $t->same(4, (int) $plantings->find($id)['quantity_live']);
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `planting`', [], 0));
});

// -- 9. The batch ---------------------------------------------------------

$t->group('A batch measures the quantity against each plants own count');

$t->test('six of a tray of twenty splits, and six of a pot of four moves whole',
    function ($t) use ($client, $plantings, $tray, $rows, $gardenId, $today, $db): void {
    $big = $tray(20, 'Batch big');
    $small = $tray(4, 'Batch small');

    $response = $client->post('/log/batch', [
        'planting_ids' => [(string) $big, (string) $small],
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '6',
        'garden_id' => (string) $gardenId, 'garden_row_id' => (string) $rows[1]['id'],
    ]);
    $t->same(303, $response->status);

    $t->same(14, (int) $plantings->find($big)['quantity_live'], 'the tray of twenty split');
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `planting` WHERE `split_from_id` = :p', ['p' => $big], 0
    ));

    $t->same(4, (int) $plantings->find($small)['quantity_live'], 'the pot of four moved whole');
    $t->same((int) $rows[1]['id'], (int) $plantings->find($small)['garden_row_id']);
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `planting` WHERE `split_from_id` = :p', ['p' => $small], 0
    ), 'and was not split');
});

$t->test('a batch that cannot split all of them writes none of them',
    function ($t) use ($client, $plantings, $tray, $today, $db): void {
    // No destination. The pot of four moves whole and is fine; the tray of
    // twenty is a partial move with nowhere to go and is refused. A batch
    // that half-applies is the failure batch() already goes out of its way to
    // avoid, so the refusal has to happen before the first write.
    $small = $tray(4, 'Batch atomic small');
    $big = $tray(20, 'Batch atomic big');
    $before = (int) $db->value('SELECT COUNT(*) FROM `plant_event`', [], 0);

    $response = $client->post('/log/batch', [
        'planting_ids' => [(string) $small, (string) $big],
        'event_type' => EventType::TRANSPLANTED, 'event_date' => $today,
        'move_quantity' => '6',
    ]);

    $t->same(400, $response->status);
    $t->same($before, (int) $db->value('SELECT COUNT(*) FROM `plant_event`', [], 0),
        'not one event was written');
    $t->same(4, (int) $plantings->find($small)['quantity_live']);
    $t->same(20, (int) $plantings->find($big)['quantity_live']);
});

// -- 10. The lineage is a link -------------------------------------------

$t->group('Lineage is a link, never a merged timeline');

$t->test('both ends of a split name the other, in one statement',
    function ($t) use ($db, $plantings, $parentId, &$childId): void {
    $before = $db->statementCount();
    $childSide = $plantings->lineage($childId, $parentId);
    $t->same(1, $db->statementCount() - $before, 'one statement for parent and children together');

    $t->ok($childSide['parent'] !== null, 'the child names the tray it came out of');
    $t->same($parentId, (int) $childSide['parent']['id']);
    $t->same(1, \count($childSide['children']), 'and names the two it sent on');

    $parentSide = $plantings->lineage($parentId, null);
    $t->same(null, $parentSide['parent'], 'the tray came out of nothing');
    $t->same(1, \count($parentSide['children']));
    $t->same($childId, (int) $parentSide['children'][0]['id']);
});

$t->test('the child page links to the parent and does not repeat its history',
    function ($t) use ($client, &$childId, $parentId, $events, $sownOn): void {
    // Something on the tray's timeline that could only have come from the
    // tray. If it turns up on the child's page, the timelines were merged.
    $events->record($parentId, EventType::NOTE, $sownOn,
        ['narrative' => 'Sown into the propagator on the bench.']);

    $response = $client->get('/plants/' . $childId);
    $t->same(200, $response->status);
    $body = $response->collect();

    $t->contains('Split from', $body);
    $t->contains('plants/' . $parentId, $body, 'and links to it');
    // Merging is what Section 4.6 refuses: it costs a statement per
    // generation, and it is also untrue -- that note is about the tray.
    $t->notContains('Sown into the propagator', $body);

    $t->contains('Sown into the propagator',
        $client->get('/plants/' . $parentId)->collect(),
        'the note is on the page it happened to');
});

$t->test('the parent page lists what left it', function ($t) use ($client, $parentId, &$childId): void {
    $body = $client->get('/plants/' . $parentId)->collect();
    $t->contains('moved out', $body);
    $t->contains('plants/' . $childId, $body);
});

$t->test('a planting with no lineage draws no panel',
    function ($t) use ($client, $tray): void {
    $id = $tray(2, 'Lonely');
    $body = $client->get('/plants/' . $id)->collect();
    $t->same(200, $client->get('/plants/' . $id)->status);
    $t->notContains('Where these came from', $body);
});

$t->test('and answers "no lineage" from the row it already has, not a statement',
    function ($t) use ($db, $plantings, $tray): void {
    // The controller decides from the planting row in hand: split_from_id is
    // on it, and a planting that never sent anything out has
    // quantity_live + quantity_lost = quantity_initial, because dispersal is
    // the only other thing that takes plants off a row. So the plant page of
    // an account that has never split spends nothing on this.
    $id = $tray(2, 'Lonely two');
    $row = $plantings->find($id);

    $t->same(null, $row['split_from_id']);
    $t->same(
        (int) $row['quantity_initial'],
        (int) $row['quantity_live'] + (int) $row['quantity_lost'],
        'nothing has left this planting, and the row says so on its own'
    );

    // And when it HAS sent something out, the sum no longer balances, which
    // is exactly the signal the page reads.
    $moved = $plantings->find((int) $db->value(
        'SELECT split_from_id FROM `planting`'
        . ' WHERE user_id = :u AND split_from_id IS NOT NULL ORDER BY id LIMIT 1',
        ['u' => $plantings->userId()]
    ));
    $t->ok(
        (int) $moved['quantity_live'] + (int) $moved['quantity_lost'] < (int) $moved['quantity_initial'],
        'a planting that sent plants out does not balance, and gets the panel'
    );
});

$t->test('the plant list badges a planting that was moved out of another',
    function ($t) use ($client, &$childId): void {
    $body = $client->get('/plants')->collect();
    $t->contains('moved out of another', $body);
    $t->ok($childId > 0);
});

// -- 11. Nothing downstream paid for it ----------------------------------

$t->group('What must not have got slower');

$t->test('the weather series costs the same planting the same before and after a split',
    function ($t) use ($app, $db, $owner, $plantings, $events, $tray, $rows, $gardenId, $today): void {
    // 11_reports_test.php pins the absolute number. What matters here is that
    // the lineage did not creep into the report path: the same planting, on
    // the same dates, must cost the same once it has a child -- or the
    // assertions in that file quietly become "the same, unless somebody
    // split something".
    $series = new Series(
        $plantings,
        new EventRepository($db, $owner['id'], $plantings),
        new GardenRepository($db, $owner['id']),
        new Carl\Repo\WeatherRepository($db),
        $app->units()
    );
    $locationId = (int) $db->value(
        'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $owner['id']]
    );

    $id = $tray(40, 'Series cost');
    $events->record($id, EventType::TRANSPLANTED, (string) Clock::addDays($today, -30), [
        'garden_id' => $gardenId, 'garden_row_id' => (int) $rows[0]['id'],
    ]);
    $plantings->update($id, ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[0]['id']]);

    $before = $db->statementCount();
    $series->forPlanting($id, $locationId, $today);
    $unsplit = $db->statementCount() - $before;

    $plantings->split($events, $id, 10, EventType::MOVED, $today,
        ['garden_id' => $gardenId, 'garden_row_id' => (int) $rows[1]['id'], 'container_id' => null]);

    $before = $db->statementCount();
    $series->forPlanting($id, $locationId, $today);
    $split = $db->statementCount() - $before;

    $t->same(3, $unsplit, 'one for the planting, one for the weather, one for the events');
    $t->same($unsplit, $split,
        'after the split it cost ' . $split . ' statements; before, ' . $unsplit);
});

$t->test('the log screen offers the quantity on all three relocations',
    function ($t) use ($client, $parentId): void {
    $body = $client->get('/log/' . $parentId)->collect();
    $t->contains('move_quantity', $body);
    $t->contains('How many are moving', $body);
    $t->notContains('<script>', $body, 'CSP is script-src \'self\': no inline script');
    $t->notContains(' style="', $body, 'and style-src \'self\': no inline style');
});

$t->test('the export names the lineage on both sides',
    function ($t) use ($client, &$childId, $parentId): void {
    $csv = $client->get('/export/plants.csv')->collect();
    $t->contains('split_from_planting_id', $csv);
    $t->contains('root_planting_id', $csv);
    $t->contains('quantity_lost', $csv);

    $events = $client->get('/export/events.csv')->collect();
    $t->contains('moved_to_planting_id', $events);
    $t->contains(EventType::SPLIT_OUT, $events);
});
