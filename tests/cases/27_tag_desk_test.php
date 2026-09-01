<?php

/**
 * QR plant tags, the desk half (docs/QR-TAGS-SPEC.md Section 5.2, finished
 * in Phase 13).
 *
 * Section 5.2 says binding "works from either end" and names the second end
 * precisely: "at the foot of Start a New Plant, and on any plant's page:
 * assign a tag". Phase 8 built the first end -- the scan -- completely, and
 * built the second as a link to the pool screen. So a person at a desk in
 * February, with the season planned in Carl and a sheet of labels in front of
 * them, could not put a stake on a plant without scanning one; and once it
 * was on, could not take it off again without walking to it.
 *
 * Three things are asserted here, and the third is the one that would
 * otherwise fail silently:
 *
 *  1. **The plant end works, both ways.** A free code goes on from the plant
 *     page and from the new-plant form; a stake comes off from the plant
 *     page; a swap is one step and leaves one live binding.
 *  2. **A deliberate choice is never quietly dropped.** A code that is not
 *     free is a form error with the reason and no plant is written -- not a
 *     plant recorded with the tag missing and a flash that does not say so.
 *  3. **The pool count stays honest.** Retiring one code, and un-retiring a
 *     sheet that has one retired code on it, leave that code retired.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
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
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = \gmdate('Y-m-d');  // utc-ok: backdates the sow closure only, never compared to an app-computed day

$sow = static function (string $label, int $daysAgo = 10) use ($plantings, $plantTypeId, $indoorId, $today): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => (string) Clock::addDays($today, -$daysAgo),
        'quantity_initial' => 6,
        'quantity_live'    => 6,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

$tagIdOf = static fn (string $code): int
    => (int) $db->value('SELECT id FROM `qr_tag` WHERE code = :code', ['code' => $code]);

// ========================================================================
// 0. Before any sheet exists
// ========================================================================

$t->group('An account with no tags is not asked about them');

$t->test('the new-plant form has no tag picker until there is a free code',
    function ($t) use ($client): void {
    $body = $client->get('/plants/new/indoor_seed')->body;
    $t->notContains('name="tag"', $body, 'nothing to pick from, so no field');
});

$t->test('a plant page with no free codes says to print a sheet, and offers no picker',
    function ($t) use ($client, $sow): void {
    $plant = $sow('Untagged Nothing Printed');
    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('No tag on this plant', $body);
    $t->contains('print a sheet', $body);
    $t->notContains('name="code"', $body);
});

// One sheet of the three-across stock: 24 codes, so positions are a real
// test (row 2 begins at the fourth label, not the third).
$minted = $tags->mint(1, LabelStock::AVERY_60517);
$codes = $minted['codes'];
$batchId = $minted['batch_id'];

// ========================================================================
// 1. The free list, in the sheet's own order
// ========================================================================

$t->group('Free codes are listed by sheet, in the order they sit on it');

$t->test('LabelStock::place() turns a minting ordinal into a row and a column', function ($t): void {
    $t->same(['sheet' => 1, 'row' => 1, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 0));
    $t->same(['sheet' => 1, 'row' => 1, 'column' => 3], LabelStock::place(LabelStock::AVERY_60517, 2));
    $t->same(['sheet' => 1, 'row' => 2, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 3));
    $t->same(['sheet' => 1, 'row' => 8, 'column' => 3], LabelStock::place(LabelStock::AVERY_60517, 23));
    $t->same(['sheet' => 2, 'row' => 1, 'column' => 1], LabelStock::place(LabelStock::AVERY_60517, 24));
    // Two across on the self-laminating stock.
    $t->same(['sheet' => 1, 'row' => 2, 'column' => 1], LabelStock::place(LabelStock::AVERY_00757, 2));
});

$t->test('freeBySheet() is one statement and keeps sheet positions after a code is taken',
    function ($t) use ($tags, $db, $codes, $batchId, $sow, $tagIdOf): void {
    $before = $db->statementCount();
    $sheets = $tags->freeBySheet();
    $t->same(1, $db->statementCount() - $before, 'one statement for every sheet');

    $t->same(1, \count($sheets));
    $t->same($batchId, $sheets[0]['batch_id']);
    $t->same(24, \count($sheets[0]['tags']));
    $t->same($codes[0], $sheets[0]['tags'][0]['code']);
    $t->same(1, $sheets[0]['tags'][0]['row']);
    $t->same(1, $sheets[0]['tags'][0]['column']);
    $t->same('string', \gettype($sheets[0]['tags'][0]['code']), 'codes stay strings (PHASE-9 Section 4.11)');

    // Take the first label off the sheet. The second is STILL row 1,
    // column 2: its place on the sheet is its rank among every code minted
    // there, bound or not, which is why the query reads the bound ones too.
    $plant = $sow('Position Keeper');
    $tags->bindTo($tagIdOf($codes[0]), $plant);

    $after = $tags->freeBySheet();
    $t->same(23, \count($after[0]['tags']));
    $t->same($codes[1], $after[0]['tags'][0]['code']);
    $t->same(1, $after[0]['tags'][0]['row']);
    $t->same(2, $after[0]['tags'][0]['column'], 'the gap on the sheet is where the peeled label was');
    $t->same(23, TagRepository::countFree($after));
});

// ========================================================================
// 2. From the plant's page
// ========================================================================

$t->group('The plant page puts a tag on, swaps it, and takes it off');

$t->test('an untagged plant page offers the free codes with their place on the sheet',
    function ($t) use ($client, $sow, $codes): void {
    $plant = $sow('Wants A Tag');
    $body = $client->get('/plants/' . $plant)->body;

    $t->contains('No tag on this plant', $body);
    $t->contains('name="code"', $body, 'the picker');
    $t->contains('<optgroup label="Sheet ', $body, 'grouped by sheet');
    $t->contains($codes[5], $body);
    $t->contains('row 2, column 3', $body, 'the sixth label is row 2, column 3 on a three-across sheet');
    $t->notContains($codes[0], $body, 'a code already on a plant is not offered');
});

$t->test('posting a free code binds it and comes back to the tag panel',
    function ($t) use ($client, $sow, $tags, $codes): void {
    $plant = $sow('Gets A Tag');
    $response = $client->post('/plants/' . $plant . '/tag', ['code' => \strtolower($codes[1])]);

    $t->same(303, $response->status);
    $t->same('/carl/plants/' . $plant . '#tag', (string) $response->headers()['Location'],
        'back to the panel, not the top of the report');
    $t->same($codes[1], (string) $tags->forPlanting($plant)['code']);

    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('Tag <span class="mono">' . $codes[1], $body);
    $t->contains('Take this tag off', $body);
    $t->contains('Swap for a different tag', $body);
});

$t->test('a code that is on another plant is refused and names it',
    function ($t) use ($client, $sow, $tags, $codes): void {
    $plant = $sow('Wants Someone Elses');
    $client->post('/plants/' . $plant . '/tag', ['code' => $codes[1]]);

    $t->same(null, $tags->forPlanting($plant), 'nothing bound');
    $body = $client->get('/plants/' . $plant)->body;
    $t->contains('is on Gets A Tag', $body, 'the flash says where it is');
    $t->contains('Take it off that plant first', $body);
});

$t->test('a code that is not one of yours reads the same as one that does not exist',
    function ($t) use ($client, $sow, $tags, $otherTags): void {
    $plant = $sow('Wants A Strangers');
    $stranger = $otherTags->mint(1, LabelStock::AVERY_00757)['codes'][0];

    $client->post('/plants/' . $plant . '/tag', ['code' => $stranger]);
    $strangerBody = $client->get('/plants/' . $plant)->body;
    $client->post('/plants/' . $plant . '/tag', ['code' => 'ZZZZZZ']);
    $unknownBody = $client->get('/plants/' . $plant)->body;

    $t->same(null, $tags->forPlanting($plant));
    $t->contains('No tag of yours has the code ' . $stranger, $strangerBody);
    $t->contains('No tag of yours has the code ZZZZZZ', $unknownBody);
});

$t->test('a swap puts the new code on and the old one back, in one step',
    function ($t) use ($client, $sow, $tags, $db, $codes, $tagIdOf): void {
    $plant = $sow('Stake Broke');
    $tags->bindTo($tagIdOf($codes[2]), $plant);

    $response = $client->post('/plants/' . $plant . '/tag', ['code' => $codes[3]]);
    $t->same(303, $response->status);

    $t->same($codes[3], (string) $tags->forPlanting($plant)['code'], 'the new one is on');
    $t->same(null, $tags->scan($codes[2])['planting_id'], 'the old one is free');

    // ONE live binding for the plant, and the old one CLOSED, not deleted:
    // "this tag was on that plant" is a fact about a real object
    // (PHASE-9-HANDOFF Section 4.3).
    $live = (int) $db->value(
        'SELECT COUNT(*) FROM `qr_tag_binding` WHERE planting_id = :p AND unbound_at IS NULL',
        ['p' => $plant]
    );
    $closed = (int) $db->value(
        'SELECT COUNT(*) FROM `qr_tag_binding` WHERE planting_id = :p AND unbound_at IS NOT NULL',
        ['p' => $plant]
    );
    $t->same(1, $live);
    $t->same(1, $closed);

    $body = $client->get('/plants/' . $plant)->body;
    $t->contains($codes[2] . ' is back in the pool', $body);
});

$t->test('taking the tag off from the plant page frees it, and can retire it',
    function ($t) use ($client, $sow, $tags, $codes, $tagIdOf): void {
    $plant = $sow('Pulled The Stake');
    $tags->bindTo($tagIdOf($codes[4]), $plant);
    $freeBefore = $tags->pool()['free'];

    $response = $client->post('/plants/' . $plant . '/tag/release', []);
    $t->same(303, $response->status);
    $t->same(null, $tags->forPlanting($plant));
    $t->same($freeBefore + 1, $tags->pool()['free'], 'back in the pool');

    // The same, ticking "the stake is lost or ruined".
    $tags->bindTo($tagIdOf($codes[4]), $plant);
    $client->post('/plants/' . $plant . '/tag/release', ['retire' => '1']);
    $t->same(null, $tags->forPlanting($plant));
    $t->ok($tags->scan($codes[4])['tag_retired_at'] !== null, 'retired as well');
    $t->same($freeBefore, $tags->pool()['free'], 'and NOT counted as free');
    $t->same(1, $tags->pool()['retired']);
});

$t->test('a retired code cannot be put on a plant until it is put back',
    function ($t) use ($client, $sow, $tags, $codes): void {
    $plant = $sow('Wants A Retired One');
    $client->post('/plants/' . $plant . '/tag', ['code' => $codes[4]]);
    $t->same(null, $tags->forPlanting($plant));
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
    $t->same(404, $client->post('/plants/' . $theirs . '/tag', ['code' => $codes[5]])->status);
    $t->same(404, $client->post('/plants/' . $theirs . '/tag/release', [])->status);
});

// ========================================================================
// 3. From Start a New Plant
// ========================================================================

$t->group('Start a New Plant picks a tag at the foot of the form');

$t->test('the form offers the free codes, and preselects one carried in from a scan',
    function ($t) use ($client, $codes): void {
    $body = $client->get('/plants/new/indoor_seed')->body;
    $t->contains('name="tag"', $body);
    $t->contains('>No tag</option>', $body, 'none is the default');
    $t->contains($codes[6], $body);
    $t->notContains('name="tag" value=', $body, 'no hidden carry any more: the picker is the field');

    // "Start a new plant with this tag" from the bind screen.
    $carried = $client->get('/plants/new/indoor_seed', ['tag' => \strtolower($codes[6])])->body;
    $t->contains('value="' . $codes[6] . '" selected', $carried, 'preselected');
    $t->contains('goes on this plant when you save it', $carried);
});

$t->test('saving with a code binds it', function ($t) use ($client, $plantings, $tags, $plantTypeId, $codes): void {
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Sown With A Tag',
        'tag' => $codes[7],
    ]);
    $t->same(303, $response->status);

    $plant = (int) $plantings->where('`label` = :l', ['l' => 'Sown With A Tag'], '`id` DESC', 1)[0]['id'];
    $t->same($codes[7], (string) $tags->forPlanting($plant)['code']);
    $t->contains('tag ' . $codes[7] . ' is on it', $client->get('/plants/' . $plant)->body);
});

$t->test('saving with a code that is not free is a form error, and no plant is written',
    function ($t) use ($client, $plantings, $plantTypeId, $codes): void {
    // The rule this whole file is for. Phase 8 bound best-effort after the
    // insert and said "Plant recorded" either way, which was right when the
    // only way a code arrived was a scan Carl had just called free. A
    // deliberate pick that is quietly dropped is a tag that is on the stake
    // and not in Carl, found in July.
    $before = $plantings->count();

    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Should Not Exist',
        'tag' => $codes[7],
    ]);
    $t->same(200, $response->status, 'the form comes back');
    $t->contains('is already on Sown With A Tag', $response->body);
    $t->contains('value="' . $codes[7] . '" selected', $response->body, 'with the choice still in it');
    $t->same($before, $plantings->count(), 'and nothing was written');

    $retired = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Should Not Exist Either',
        'tag' => $codes[4],
    ]);
    $t->contains('is retired', $retired->body);
    $t->same($before, $plantings->count());
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

$t->test('no tag is still fine', function ($t) use ($client, $plantings, $tags, $plantTypeId): void {
    $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '12', 'label' => 'Sown Bare', 'tag' => '',
    ]);
    $plant = (int) $plantings->where('`label` = :l', ['l' => 'Sown Bare'], '`id` DESC', 1)[0]['id'];
    $t->same(null, $tags->forPlanting($plant));
});

// ========================================================================
// 4. The directory on the Plant tags screen
// ========================================================================

$t->group('The Plant tags screen says which stake is on which plant');

$t->test('tags on plants are listed with the plant, and a stake comes off from the list',
    function ($t) use ($client, $tags, $codes): void {
    $body = $client->get('/tags')->body;
    $t->contains('Tags on plants', $body);
    $t->contains($codes[7], $body);
    $t->contains('Sown With A Tag', $body);
    $t->contains('Free codes, by sheet', $body);
    $t->contains('row 3, column 1', $body, 'the seventh free label -- the sixth was peeled');

    // "Take off" from the directory comes back to the directory.
    $response = $client->post('/t/' . $codes[7] . '/release', ['return' => 'tags']);
    $t->same(303, $response->status);
    $t->same('/carl/tags', (string) $response->headers()['Location']);
    $t->same(null, $tags->scan($codes[7])['planting_id']);

    // Without the hint it goes where it always did: the freed tag's screen.
    $t->contains('/t/' . $codes[1],
        (string) $client->post('/t/' . $codes[1] . '/release', [])->headers()['Location']);
});

// ========================================================================
// 5. Retiring one code, and what un-retiring a sheet does to it
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

    // And the sheet's page says so, with the one way back.
    $tags->retireTag($tagIdOf($codes[10]), false);
    $t->same(null, $tags->scan($codes[10])['tag_retired_at']);
});

// ========================================================================
// 6. The bind screen's replacement path
// ========================================================================

$t->group('Replacing a lost tag from the scan end picks the plant by name');

$t->test('the bind screen lists tagged plants with their code, not a box for an id',
    function ($t) use ($client, $tags, $sow, $codes, $tagIdOf): void {
    $plant = $sow('Lost Its Stake');
    $tags->bindTo($tagIdOf($codes[12]), $plant);

    $body = $client->get('/t/' . $codes[13])->body;
    $t->contains("isn't assigned yet", $body);
    $t->contains('<select name="planting_id"', $body);
    $t->contains('Lost Its Stake &middot; now ' . $codes[12], $body);
    $t->notContains('type="number" name="planting_id"', $body, 'nobody knows a plant\'s id');

    // Without the tick it is refused, as it always was (Section 6.4 item 3).
    $client->post('/t/' . $codes[13] . '/bind', ['planting_id' => (string) $plant]);
    $t->same($codes[12], (string) $tags->forPlanting($plant)['code']);

    $client->post('/t/' . $codes[13] . '/bind', ['planting_id' => (string) $plant, 'replace' => '1']);
    $t->same($codes[13], (string) $tags->forPlanting($plant)['code']);
    $t->same(null, $tags->scan($codes[12])['planting_id'], 'the lost one is back in the pool');
});
