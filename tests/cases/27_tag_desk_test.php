<?php

/**
 * QR plant tags over a season: the desk half of Section 5.2, and a planting
 * that carries a stake per cell (docs/QR-TAGS-SPEC.md Section 14).
 *
 * Section 5.2 says binding "works from either end" and names the second end:
 * "at the foot of Start a New Plant, and on any plant's page: assign a tag".
 * Phase 8 built the scan end and built the desk end as a link to the pool.
 * It also let a planting carry ONE tag -- and a planting is a tray of
 * twenty-four cells, each with a stake in it, whose plants will go to three
 * different beds in May.
 *
 * What is asserted, in the order a season happens:
 *
 *  1. **Sowing.** Start a New Plant takes as many stakes as the tray has
 *     cells, checked before the plant is written; the free list is in code
 *     order with a sheet's labels told apart from loose stakes.
 *  2. **At the desk and at the tray.** The plant page adds and removes
 *     stakes; a scanned free tag lists the tray that is part-way through
 *     its stakes; a tagging session puts scan after scan on the same tray
 *     until it is full, then moves on.
 *  3. **Transplanting.** Six of twenty-four go to a bed: the stakes ticked
 *     on the log form go with them, so scanning one in the bed opens the
 *     planting that is actually there.
 *  4. **Losing one, and the end of the season.** Retiring one code without
 *     its sheet; un-retiring a sheet that has one retired code on it.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\LabelStock;
use Carl\Domain\PlantingState;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\TagRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const TAG_DESK_PASSPHRASE = 'tag-desk-passphrase';

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('desk' . $suffix);
$other = $makeUser('deskother' . $suffix);

// $_SESSION outlives a Client across the suite and AuthController silently
// declines to log in when somebody already is (PHASE-8-HANDOFF Section 7).
$client->forgetCookies();
$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => TAG_DESK_PASSPHRASE, 'password_confirm' => TAG_DESK_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Desk Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Desk Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$tags = new TagRepository($db, $owner['id']);
$otherTags = new TagRepository($db, $other['id']);
$otherPlantings = new PlantingRepository($db, $other['id']);

$indoorId = $gardens->ensureIndoorGarden();
$bedId = (int) $gardens->where('`name` = :n', ['n' => 'Desk Bed' . $suffix])[0]['id'];
$bedRows = $gardens->rows($bedId);
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = \gmdate('Y-m-d');  // utc-ok: backdates the sow closure only, never compared to an app-computed day

$sow = static function (string $label, int $quantity = 6, int $daysAgo = 10) use ($plantings, $plantTypeId, $indoorId, $today): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => (string) Clock::addDays($today, -$daysAgo),
        'quantity_initial' => $quantity,
        'quantity_live'    => $quantity,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

$tagIdOf = static fn (string $code): int
    => (int) $db->value('SELECT id FROM `qr_tag` WHERE code = :code', ['code' => $code]);
// The tray's id, kept rather than looked up by label: a split CHILD inherits
// its parent's label (PlantingRepository::split()), so "the newest planting
// called Tray Of Twelve" is the wrong one from Section 5 on.
$ids = ['tray' => 0];
$codesOn = static fn (int $plant): array
    => \array_map(static fn (array $r): string => (string) $r['code'], $tags->tagsOn($plant));

// ========================================================================
// 0. Before any sheet exists
// ========================================================================

$t->group('An account with no tags is not asked about them');

$t->test('the new-plant form has no stake grid until there is a free code',
    function ($t) use ($client): void {
    $body = $client->get('/plants/new/indoor_seed')->body;
    $t->notContains('name="tags[]"', $body, 'nothing to pick from, so no field');
});

$t->test('a plant page with no free codes says to print a sheet, and offers no grid',
    function ($t) use ($client, $sow): void {
    $plant = $sow('Untagged Nothing Printed');
    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('No stake on this plant', $body);
    $t->contains('print a sheet', $body);
    $t->notContains('name="tags[]"', $body);
});

// One sheet of the three-across stock: 24 codes, so positions are a real
// test (row 2 begins at the fourth label, not the third).
$minted = $tags->mint(1, LabelStock::AVERY_60517);
$codes = $minted['codes'];
$batchId = $minted['batch_id'];
$sorted = $codes;
\sort($sorted, \SORT_STRING);

// ========================================================================
// 1. The free list: by code, and a sheet's labels apart from loose stakes
// ========================================================================

$t->group('Free codes are listed by code, with the sheet told apart from the box');

$t->test('LabelStock::place() turns a minting ordinal into a row and a column', function ($t): void {
    $t->same(['sheet' => 1, 'row' => 1, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 0));
    $t->same(['sheet' => 1, 'row' => 1, 'column' => 3], LabelStock::place(LabelStock::AVERY_60517, 2));
    $t->same(['sheet' => 1, 'row' => 2, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 3));
    $t->same(['sheet' => 1, 'row' => 8, 'column' => 3], LabelStock::place(LabelStock::AVERY_60517, 23));
    $t->same(['sheet' => 2, 'row' => 1, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 24));
    // Two across on the self-laminating stock.
    // The self-laminating sheet is ONE column of ten (Phase 16): the third
    // code is the third row, and there is no column to name.
    $t->same(['sheet' => 1, 'row' => 3, 'column' => 1], LabelStock::place(LabelStock::AVERY_00757, 2));
    $t->same(['sheet' => 2, 'row' => 1, 'column' => 1], LabelStock::place(LabelStock::AVERY_00757, 10));
    $t->same('row 3', LabelStock::placeText(LabelStock::AVERY_00757, 2));
    $t->same('page 2, row 1', LabelStock::placeText(LabelStock::AVERY_00757, 10));
    $t->same('row 2, column 1', LabelStock::placeText(LabelStock::AVERY_60517, 3));
});

$t->test('free() is one statement, in code order, and a used stake moves to the loose list',
    function ($t) use ($tags, $db, $codes, $sorted, $sow, $tagIdOf): void {
    $before = $db->statementCount();
    $free = $tags->free();
    $t->same(1, $db->statementCount() - $before, 'one statement for the whole pool');

    $t->same(24, \count($free['sheet']));
    $t->same([], $free['loose'], 'nothing has been on a plant yet');
    $t->same($sorted, \array_column($free['sheet'], 'code'), 'ascending by code, not by position');
    $t->same('string', \gettype($free['sheet'][0]['code']), 'codes stay strings (PHASE-9 Section 4.11)');

    // The first label minted is row 1, column 1 whatever its code sorts to.
    foreach ($free['sheet'] as $tag) {
        if ($tag['code'] === $codes[0]) {
            $t->same(1, $tag['row']);
            $t->same(1, $tag['column']);
            $t->same(0, $tag['ordinal']);
        }
        if ($tag['code'] === $codes[5]) {
            $t->same(2, $tag['row'], 'the sixth label is row 2, column 3 on a three-across sheet');
            $t->same(3, $tag['column']);
        }
    }

    // A stake that has been on a plant and come off is LOOSE: its place on
    // the sheet means nothing any more, and it is listed by code alone.
    $plant = $sow('Season One');
    $tags->bindTo($tagIdOf($codes[0]), $plant);
    $tags->unbind($tagIdOf($codes[0]));

    $after = $tags->free();
    $t->same(23, \count($after['sheet']));
    $t->same([$codes[0]], \array_column($after['loose'], 'code'));
    $t->same(24, TagRepository::countFree($after));

    // And a stake still ON a plant is in neither list.
    $tags->bindTo($tagIdOf($codes[1]), $plant);
    $t->same(23, TagRepository::countFree($tags->free()));
    $tags->unbind($tagIdOf($codes[1]));
});

// ========================================================================
// 2. Sowing: Start a New Plant takes a tray's worth
// ========================================================================

$t->group('Start a New Plant takes as many stakes as the tray has cells');

$t->test('the form offers the free codes as a grid, and pre-ticks one carried in from a scan',
    function ($t) use ($client, $codes): void {
    $body = $client->get('/plants/new/indoor_seed')->body;
    $t->contains('name="tags[]"', $body);
    $t->contains('Still on a sheet', $body);
    $t->contains('Loose stakes', $body, 'the two stakes that came off in group 1');
    $t->contains('value="' . $codes[6] . '"', $body);
    $t->notContains('<select id="tag"', $body, 'a grid, not a dropdown: a tray takes twenty-four at once');

    // "Start a new plant with this tag" from the bind screen.
    $carried = $client->get('/plants/new/indoor_seed', ['tag' => \strtolower($codes[6])])->body;
    $t->contains('value="' . $codes[6] . '"' . "\n               checked", $carried, 'pre-ticked');
    $t->contains('goes on this plant when you save it', $carried);
});

$t->test('saving with twelve codes puts twelve stakes on the tray',
    function ($t) use ($client, $plantings, $tags, $plantTypeId, $codes, $codesOn, &$ids): void {
    $twelve = \array_slice($codes, 2, 12);
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Tray Of Twelve',
        'tags' => \array_map('strtolower', $twelve),
    ]);
    $t->same(303, $response->status);

    $plant = (int) $plantings->where('`label` = :l', ['l' => 'Tray Of Twelve'], '`id` DESC', 1)[0]['id'];
    $ids['tray'] = $plant;
    $on = $codesOn($plant);
    \sort($on, \SORT_STRING);
    $expected = $twelve;
    \sort($expected, \SORT_STRING);
    $t->same($expected, $on, 'all twelve, no more');
    $t->contains('12 stakes on it', $client->get('/plants/' . $plant)->body);

    // The list screen says how many rather than printing twelve codes.
    $t->contains('12 stakes', $client->get('/plants')->body);
});

$t->test('one code that is not free is a form error, and no plant is written',
    function ($t) use ($client, $plantings, $plantTypeId, $codes): void {
    // The rule this file is for. Phase 8 bound best-effort after the insert
    // and said "Plant recorded" either way, which was right when the only
    // way a code arrived was a scan Carl had just called free. A deliberate
    // tick that is quietly dropped is a stake in a cell that Carl does not
    // know about, found in July.
    $before = $plantings->count();

    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Should Not Exist',
        'tags' => [$codes[14], $codes[2]],   // 2 is on the tray of twelve
    ]);
    $t->same(200, $response->status, 'the form comes back');
    $t->contains('is already on Tray Of Twelve', $response->body);
    $t->contains('value="' . $codes[14] . '"' . "\n               checked", $response->body,
        'with the good tick still in it');
    $t->same($before, $plantings->count(), 'and nothing was written');
});

$t->test('every other validation error on the form is shown too',
    function ($t) use ($client, $plantings): void {
    // Found while writing the case above. create() rendered the form with
    // formData() + ['errors' => $errors], and formData() carries an empty
    // 'errors' of its own; PHP's array union keeps the LEFT value, so from
    // Phase 1 every server-side error on this form was dropped and the form
    // came back looking untouched. The browser's `required` hid it -- until
    // the tag was the first check a browser cannot make.
    $before = $plantings->count();
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => '',
        'quantity_initial' => '12', 'label' => 'No Type',
    ]);
    $t->same(200, $response->status);
    $t->contains('Choose a plant category and type.', $response->body, 'the error is on the page');
    $t->same($before, $plantings->count());
});

$t->test('no stakes is still fine', function ($t) use ($client, $plantings, $tags, $plantTypeId): void {
    $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Sown Bare',
    ]);
    $plant = (int) $plantings->where('`label` = :l', ['l' => 'Sown Bare'], '`id` DESC', 1)[0]['id'];
    $t->same([], $tags->tagsOn($plant));
});

// ========================================================================
// 3. The plant page: on, more, off, lost
// ========================================================================

$t->group('The plant page puts stakes on, adds more, and takes them off');

$t->test('an unstaked plant page offers the grid; ticking three puts three on',
    function ($t) use ($client, $sow, $tags, $codes, $codesOn): void {
    $plant = $sow('Wants Stakes', 6);
    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('No stake on this plant', $body);
    $t->contains('Put stakes on it', $body);
    $t->contains('name="tags[]"', $body);
    $t->notContains('value="' . $codes[2] . '"', $body, 'a code already on a plant is not offered');

    $three = [$codes[14], $codes[15], $codes[16]];
    $response = $client->post('/plants/' . $plant . '/tag', ['tags' => $three]);
    $t->same(303, $response->status);
    $t->same('/carl/plants/' . $plant . '#tag', (string) $response->headers()['Location'],
        'back to the panel, not the top of the report');
    $t->same($three, $codesOn($plant), 'in the order they went on');

    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('3 stakes on this plant', $body);
    $t->contains('room for more', $body, 'six living, three stakes');
    $t->contains('Add more stakes', $body);
    $t->contains('Take all 3 off', $body);
});

$t->test('a batch with one bad code puts nothing on, and names the problem',
    function ($t) use ($client, $sow, $tags, $codes, $otherTags): void {
    $plant = $sow('All Or Nothing', 6);
    $stranger = $otherTags->mint(1, LabelStock::AVERY_00757)['codes'][0];

    $client->post('/plants/' . $plant . '/tag', ['tags' => [$codes[17], $codes[14], $stranger]]);
    $t->same([], $tags->tagsOn($plant), 'nothing bound');

    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('Nothing was put on', $body);
    $t->contains($codes[14] . ' is on Wants Stakes', $body, 'the one on another plant is named');
    $t->contains('no tag of yours has the code ' . $stranger, $body,
        'a stranger\'s reads the same as one that does not exist');
});

$t->test('one stake comes off by id, and "lost" retires it; the rest stay',
    function ($t) use ($client, $plantings, $tags, $codes, $codesOn, $tagIdOf, $ids): void {
    $plant = (int) $plantings->where('`label` = :l', ['l' => 'Wants Stakes'], '`id` DESC', 1)[0]['id'];
    $freeBefore = $tags->pool()['free'];

    $client->post('/plants/' . $plant . '/tag/release', ['tag_id' => (string) $tagIdOf($codes[15])]);
    $t->same([$codes[14], $codes[16]], $codesOn($plant), 'only the one asked for');
    $t->same($freeBefore + 1, $tags->pool()['free'], 'back in the pool');

    // Lost: off AND retired, so a stake in the bin stops counting as free.
    $client->post('/plants/' . $plant . '/tag/release',
        ['tag_id' => (string) $tagIdOf($codes[16]), 'retire' => '1']);
    $t->same([$codes[14]], $codesOn($plant));
    $t->ok($tags->scan($codes[16])['tag_retired_at'] !== null, 'retired as well');
    $t->same($freeBefore + 1, $tags->pool()['free'], 'NOT counted as free');

    // An id that is not on this plant does nothing to any plant.
    $tray = $ids['tray'];
    $client->post('/plants/' . $plant . '/tag/release', ['tag_id' => (string) $tagIdOf($codes[2])]);
    $t->same(12, \count($tags->tagsOn($tray)), 'the tray is untouched');
    $t->same([$codes[14]], $codesOn($plant));

    // And no id at all is all of them.
    $tags->bindTo($tagIdOf($codes[15]), $plant);
    $client->post('/plants/' . $plant . '/tag/release', []);
    $t->same([], $codesOn($plant));
});

$t->test('a retired code cannot be put on a plant until it is put back',
    function ($t) use ($client, $sow, $tags, $codes): void {
    $plant = $sow('Wants A Retired One');
    $client->post('/plants/' . $plant . '/tag', ['tags' => [$codes[16]]]);
    $t->same([], $tags->tagsOn($plant));
    $t->contains('is retired', $client->get('/plants/' . $plant)->body);
});

$t->test('another account\'s plant is a 404 from this end too',
    function ($t) use ($client, $otherPlantings, $plantTypeId, $today, $codes): void {
    $theirs = $otherPlantings->insert([
        'plant_type_id'    => $plantTypeId,
        'label'            => 'Not Yours',
        'start_method'     => 'indoor_seed',
        'start_date'       => $today,
        'quantity_initial' => 1,
        'quantity_live'    => 1,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $t->same(404, $client->post('/plants/' . $theirs . '/tag', ['tags' => [$codes[17]]])->status);
    $t->same(404, $client->post('/plants/' . $theirs . '/tag/release', [])->status);
});

// ========================================================================
// 4. At the tray: a scanned free tag, and the session
// ========================================================================

$t->group('A scanned free tag offers the tray that is part-way through its stakes');

$t->test('the bind screen lists plants with no stake first, then part-staked ones, then the full under the fold',
    function ($t) use ($client, $sow, $tags, $codes, $tagIdOf): void {
    // Section 6.4 said "untagged plants"; Section 14.7 makes it "plants
    // that still want stakes". The tray with three of six is exactly the
    // plant you are standing at with the fourth stake in your hand.
    $none = $sow('Bare Tray', 6, 1);
    $part = $sow('Part Tray', 6, 1);
    $full = $sow('Full Pot', 1, 1);
    $tags->bindTo($tagIdOf($codes[17]), $part);
    $tags->bindTo($tagIdOf($codes[18]), $full);

    $body = $client->get('/t/' . $codes[19])->body;
    $t->contains("isn't assigned yet", $body);
    $t->contains('Bare Tray', $body);
    $t->contains('no stake yet', $body);
    $t->contains('Part Tray', $body);
    $t->contains('1 of 6 stakes', $body);
    $t->contains('Plants with a stake for every plant', $body);
    $t->contains('Full Pot', $body, 'still offered, under the fold: the count is a guide');
    $t->notContains('Replace a tag that was lost or ruined', $body, 'no replace path: a plant just takes another');
    $t->ok(\strpos($body, 'Bare Tray') < \strpos($body, 'Part Tray'), 'none first');
    $t->ok(\strpos($body, 'Part Tray') < \strpos($body, 'Full Pot'), 'then part, then full');

    // A tap on the part-staked tray simply adds.
    $client->post('/t/' . $codes[19] . '/bind', ['planting_id' => (string) $part]);
    $t->same(2, \count($tags->tagsOn($part)));
});

$t->test('a tagging session puts scan after scan on the same tray until it is full, then moves on',
    function ($t) use ($client, $sow, $tags, $codes, $codesOn, $tagIdOf): void {
    // Section 6.5: "the scan is the confirm. Twelve scans, zero taps." Phase
    // 8 rendered the bind screen on every scan, so the strip named the
    // next plant and then asked for a tap anyway.
    // Both today, the tray sown second so it is the newest bare plant and
    // Session Next is the one after it.
    $next = $sow('Session Next', 1, 0);
    $tray = $sow('Session Tray', 3, 0);
    $client->post('/tags/session', ['action' => 'start']);

    // The first scan goes on the newest plant with no stake, and lands on
    // the field screen with the undo, not on the bind screen.
    $response = $client->get('/t/' . $codes[20]);
    $t->same(303, $response->status, 'bound on the scan, no tap');
    $t->contains('/t/' . $codes[20] . '?bound=', (string) $response->headers()['Location']);
    $t->same([$codes[20]], $codesOn($tray));

    $strip = $client->get('/tags')->body;
    $t->contains('Filling Session Tray', $strip);
    $t->contains('1 of 3 stakes', $strip);
    $t->contains('Next plant', $strip);

    // The next two scans stay on the tray.
    $client->get('/t/' . $codes[21]);
    $client->get('/t/' . $codes[22]);
    $t->same([$codes[20], $codes[21], $codes[22]], $codesOn($tray), 'three scans, one tray');

    // Full. The fourth goes on the next plant with no stake.
    $client->get('/t/' . $codes[23]);
    $t->same(3, \count($tags->tagsOn($tray)), 'the tray is full');
    $t->same([$codes[23]], $codesOn($next));

    // A bound tag scanned mid-session is the field screen and consumes nothing.
    $t->same(200, $client->get('/t/' . $codes[20])->status);

    $client->post('/tags/session', ['action' => 'stop']);
    $t->notContains('Stop tagging', $client->get('/')->body);
});

$t->test('"Next plant" leaves a tray before it is full', function ($t) use ($client, $sow, $tags, $codes, $codesOn, $tagIdOf): void {
    // A row of a hundred carrots gets one stake and a tap, not a hundred
    // scans. Free two codes for it.
    $tags->unbind($tagIdOf($codes[21]));
    $tags->unbind($tagIdOf($codes[22]));
    // Both today, beans first, so carrots are the newest bare plant and
    // beans the one after them.
    $beans = $sow('Bean Row', 30, 0);
    $carrots = $sow('Carrot Row', 100, 0);
    $client->post('/tags/session', ['action' => 'start']);

    $client->get('/t/' . $codes[21]);
    $t->same([$codes[21]], $codesOn($carrots), 'the newest bare plant');
    $t->contains('1 of 100 stakes', $client->get('/tags')->body);

    $client->post('/tags/session', ['action' => 'next']);
    $client->get('/t/' . $codes[22]);
    $t->same([$codes[22]], $codesOn($beans), 'moved on');
    $t->same(1, \count($tags->tagsOn($carrots)));

    $client->post('/tags/session', ['action' => 'stop']);
});

// ========================================================================
// 5. Transplanting: the stakes go with the plants
// ========================================================================

$t->group('Six of twenty-four go to a bed, and their stakes go with them');

$t->test('the log form lists the stakes, pre-ticks the one it was reached through, and moves the ticked ones with a split',
    function ($t) use ($client, $tags, $db, $codes, $codesOn, $tagIdOf, $bedId, $bedRows, $ids): void {
    $tray = $ids['tray'];
    $on = $codesOn($tray);
    $t->same(12, \count($on));

    // From the field screen of one stake -- scanned standing in the bed.
    $field = $client->get('/t/' . $on[0])->body;
    $t->contains('log/' . $tray . '?tag=' . $on[0], $field, 'the field screen carries its own code');

    $form = $client->get('/log/' . $tray, ['tag' => $on[0]])->body;
    $t->contains('Which stakes went with them?', $form);
    $t->contains('name="move_tags[]"', $form);
    $t->contains('value="' . $tagIdOf($on[0]) . '"' . "\n                 checked", $form, 'the scanned stake is ticked');
    $t->notContains('value="' . $tagIdOf($on[1]) . '"' . "\n                 checked", $form, 'the others are not');

    // Four of the twelve go to the bed, with four stakes.
    $moving = [$on[0], $on[3], $on[5], $on[7]];
    $response = $client->post('/log/' . $tray, [
        'event_type'    => EventType::TRANSPLANTED,
        'move_quantity' => '4',
        'garden_id'     => (string) $bedId,
        'garden_row_id' => (string) $bedRows[0]['id'],
        'move_tags'     => \array_map($tagIdOf, $moving),
    ]);
    $t->same(303, $response->status);
    $location = (string) $response->headers()['Location'];
    $childId = (int) \substr($location, \strrpos($location, '/') + 1);
    $t->ok($childId > 0 && $childId !== $tray, 'a split: the four are their own planting');

    $childCodes = $codesOn($childId);
    \sort($childCodes, \SORT_STRING);
    $expected = $moving;
    \sort($expected, \SORT_STRING);
    $t->same($expected, $childCodes, 'the four stakes are on the four plants in the bed');
    $t->same(8, \count($tags->tagsOn($tray)), 'and eight stay in the tray');

    // And the flash said so (read first: the next GET would consume it).
    $t->contains('4 stakes went with them', $client->get('/plants/' . $childId)->body);

    // Scanning a moved stake opens the planting that is actually there.
    $t->contains('Desk Bed', $client->get('/t/' . $on[3])->body);

    // Each stake's history says "the tray, then the bed".
    $history = $tags->scan($on[3]);
    $t->same($childId, (int) $history['planting_id']);
    $closed = (int) $db->value(
        'SELECT COUNT(*) FROM `qr_tag_binding` WHERE tag_id = :t AND planting_id = :p AND unbound_at IS NOT NULL',
        ['t' => $tagIdOf($on[3]), 'p' => $tray]
    );
    $t->same(1, $closed, 'the tray binding is closed, not deleted');
});

$t->test('a stake that is not on the planting cannot be moved by a forged form',
    function ($t) use ($client, $plantings, $tags, $codes, $tagIdOf, $bedId, $bedRows, $ids): void {
    $tray = $ids['tray'];
    $elsewhere = (int) $plantings->where('`label` = :l', ['l' => 'Part Tray'], '`id` DESC', 1)[0]['id'];
    $foreign = (string) $tags->tagsOn($elsewhere)[0]['code'];

    $response = $client->post('/log/' . $tray, [
        'event_type'    => EventType::TRANSPLANTED,
        'move_quantity' => '2',
        'garden_id'     => (string) $bedId,
        'garden_row_id' => (string) $bedRows[1]['id'],
        'move_tags'     => [(string) $tagIdOf($foreign)],
    ]);
    $t->same(303, $response->status);
    $t->same($elsewhere, (int) $tags->scan($foreign)['planting_id'], 'still where it was');
});

$t->test('moving all of them moves nothing: the stakes stay on the planting that moved',
    function ($t) use ($client, $sow, $tags, $codes, $codesOn, $tagIdOf, $bedId, $bedRows): void {
    $tags->retireTag($tagIdOf($codes[16]), false);
    $pot = $sow('Whole Pot', 2, 5);
    $tags->bindTo($tagIdOf($codes[16]), $pot);

    $client->post('/log/' . $pot, [
        'event_type'    => EventType::TRANSPLANTED,
        'garden_id'     => (string) $bedId,
        'garden_row_id' => (string) $bedRows[1]['id'],
        'move_tags'     => [(string) $tagIdOf($codes[16])],
    ]);
    $t->same([$codes[16]], $codesOn($pot), 'no split, so nothing to move to');
});

// ========================================================================
// 6. The directory on the Plant tags screen
// ========================================================================

$t->group('The Plant tags screen says which stakes are on which plant');

$t->test('stakes are grouped by plant, and all of a plant\'s stakes come off from the list',
    function ($t) use ($client, $sow, $tags, $codes, $tagIdOf, $ids): void {
    $tray = $ids['tray'];
    $body = $client->get('/tags')->body;
    $t->contains('Stakes on plants', $body);
    $t->contains('Tray Of Twelve', $body);
    $t->contains('8 stakes', $body);
    $t->contains('Free codes (', $body);
    $t->contains('Loose stakes, used before', $body);

    $response = $client->post('/plants/' . $tray . '/tag/release', ['return' => 'tags']);
    $t->same(303, $response->status);
    $t->same([], $tags->tagsOn($tray), 'all eight off');

    // Release from the tag's end with the hint comes back to the directory,
    // not to the freed tag's bind screen.
    $pot = $sow('Directory Pot', 1);
    $tags->bindTo($tagIdOf($codes[14]), $pot);
    $response = $client->post('/t/' . $codes[14] . '/release', ['return' => 'tags']);
    $t->same(303, $response->status);
    $t->same('/carl/tags', (string) $response->headers()['Location']);
    $t->same([], $tags->tagsOn($pot));
});

// ========================================================================
// 7. Retiring one code, and what un-retiring a sheet does to it
// ========================================================================

$t->group('One code can be retired without its sheet');

$t->test('a free code retires and comes back; a bound one is refused',
    function ($t) use ($client, $tags, $sow, $codes, $tagIdOf): void {
    $freeBefore = $tags->pool()['free'];

    $t->same(303, $client->post('/t/' . $codes[8] . '/retire', [])->status);
    $t->ok($tags->scan($codes[8])['tag_retired_at'] !== null);
    $t->same($freeBefore - 1, $tags->pool()['free']);

    $client->post('/t/' . $codes[8] . '/retire', []);
    $t->same(null, $tags->scan($codes[8])['tag_retired_at'], 'toggled back');
    $t->same($freeBefore, $tags->pool()['free']);

    $plant = $sow('Holding A Stake');
    $tags->bindTo($tagIdOf($codes[9]), $plant);
    $client->post('/t/' . $codes[9] . '/retire', []);
    $t->same(null, $tags->scan($codes[9])['tag_retired_at'], 'refused while on a plant');
    $t->contains('Take it off that plant before retiring it', $client->get('/tags')->body);

    // The repository refuses too, so a forged form cannot do it either.
    $t->same(false, $tags->retireTag($tagIdOf($codes[9]), true));
});

$t->test('un-retiring a sheet leaves a code retired on its own where it was',
    function ($t) use ($tags, $codes, $batchId, $tagIdOf): void {
    // The stake for codes[10] snapped in May; the whole sheet was mislaid
    // in June and retired; it turned up in a drawer in September. Putting
    // the sheet back must not put the snapped stake back.
    $tags->retireTag($tagIdOf($codes[10]), true);
    $ownStamp = (string) $tags->scan($codes[10])['tag_retired_at'];

    // A second, so the sheet's stamp cannot equal the code's own.
    \sleep(1);
    $tags->retireBatch($batchId, true);
    $t->ok($tags->scan($codes[11])['tag_retired_at'] !== null, 'the sheet is retired');
    $t->same($ownStamp, (string) $tags->scan($codes[10])['tag_retired_at'],
        'a code already retired keeps its own earlier stamp');

    $tags->retireBatch($batchId, false);
    $t->same(null, $tags->scan($codes[11])['tag_retired_at'], 'the sheet is back');
    $t->ok($tags->scan($codes[10])['tag_retired_at'] !== null, 'the snapped one is still gone');
    $t->same(null, $tags->findBatch($batchId)['retired_at']);

    $tags->retireTag($tagIdOf($codes[10]), false);
    $t->same(null, $tags->scan($codes[10])['tag_retired_at']);
});
