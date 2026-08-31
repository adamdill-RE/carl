<?php

/**
 * End Growing Season, crop rotation, and the Reports menu
 * (Phase 5 handoff Sections 3.2, 3.3 and 3.4).
 *
 * Three things here can only be caught by a test:
 *
 *  1. **The confirmation.** End Growing Season is the one destructive action
 *     in Carl. A refactor that drops the typed confirmation leaves a screen
 *     that looks identical and ends twenty plantings on a mis-tap, and no
 *     other test in the suite would notice.
 *  2. **The batch is re-read, not posted.** The list on the screen can be a
 *     week old. What gets ended has to be what is living NOW.
 *  3. **The rotation warning is one statement for every row.** Same shape as
 *     the occupancy hint it sits beside, and the same failure: a lookup per
 *     row is invisible until a garden has forty of them (hosting Section 9).
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const SEASON_PASSPHRASE = 'season-test-passphrase';

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('seasoner' . $suffix);
$stranger = $makeUser('interloper' . $suffix);

$onboard = static function (array $user, string $gardenName) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => $user['password']]);
    $client->post('/password/reset', [
        'password' => SEASON_PASSPHRASE, 'password_confirm' => SEASON_PASSPHRASE,
    ]);
    $client->post('/onboarding/profile', ['name' => 'Season Tester', 'zip' => '76692']);
    $client->post('/onboarding/garden',
        ['name' => $gardenName, 'row_count' => '3', 'soil_type' => 'loam']);
    $client->post('/onboarding/finish', []);
};

$login = static function (array $user) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => SEASON_PASSPHRASE]);
};

$onboard($owner, 'Season Bed' . $suffix);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Season Bed' . $suffix])[0]['id'];
$rows = $gardens->rows($gardenId);
$today = \gmdate('Y-m-d');

/** Plant types with two different families, so rotation has something to say. */
$families = $db->all(
    "SELECT id, category, type, plant_family FROM `plant_type`"
    . " WHERE plant_family IS NOT NULL AND plant_family <> ''"
    . ' ORDER BY plant_family, id'
);
$byFamily = [];
foreach ($families as $type) {
    $byFamily[(string) $type['plant_family']][] = $type;
}

$t->group('The Reports menu (Phase 5 handoff Section 3.2)');

$t->test('it links every report, download and the recommendations screen',
    function ($t) use ($client, $owner, $login, $suffix): void {
    $login($owner);
    $response = $client->get('/reports');
    $t->same(200, $response->status);
    $html = $response->body;

    foreach (['/carl/advice', '/carl/plants', '/carl/gardens',
              '/carl/export/plants.csv', '/carl/export/events.csv',
              '/carl/export/weather.csv', '/carl/export/claude.json'] as $link) {
        $t->contains('href="' . $link . '"', $html, 'links ' . $link);
    }
    $t->contains('Season Bed' . $suffix, $html, 'and names the gardens by hand');
});

$t->test('it reaches the menu, so it is findable without knowing the URL',
    function ($t) use ($client, $owner, $login): void {
    $login($owner);
    $t->contains('href="/carl/reports"', $client->get('/')->body);
});

$t->group('Crop rotation warnings (Phase 5 handoff Section 3.4)');

$t->test('what a row grew is one statement for every row this user has',
    function ($t) use ($db, $owner, $plantings, $gardenId, $rows, $byFamily, $today): void {
    if (\count($byFamily) < 2) {
        $t->ok(true, 'the research set has fewer than two plant families; nothing to compare');
        return;
    }

    $familyNames = \array_keys($byFamily);
    $solanaceae = $byFamily[$familyNames[0]][0];
    $other = $byFamily[$familyNames[1]][0];

    // Last year in row 1, and this year in row 2 -- so a per-row lookup and
    // a single grouped statement give visibly different answers if the
    // grouping is wrong.
    $plantings->insert([
        'plant_type_id' => (int) $solanaceae['id'], 'garden_id' => $gardenId,
        'garden_row_id' => (int) $rows[0]['id'], 'start_method' => 'direct_sow',
        'start_date' => (string) Clock::addDays($today, -300),
        'quantity_initial' => 4, 'quantity_live' => 4, 'state' => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $plantings->insert([
        'plant_type_id' => (int) $other['id'], 'garden_id' => $gardenId,
        'garden_row_id' => (int) $rows[1]['id'], 'start_method' => 'direct_sow',
        'start_date' => (string) Clock::addDays($today, -30),
        'quantity_initial' => 4, 'quantity_live' => 4, 'state' => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    $before = $db->statementCount();
    $history = $plantings->familyHistoryByRow((string) Clock::addDays($today, -1095));
    $t->same(1, $db->statementCount() - $before, 'one statement, every row');

    $t->same([(string) $solanaceae['plant_family']],
        \array_column($history[(int) $rows[0]['id']] ?? [], 'family'));
    $t->same([(string) $other['plant_family']],
        \array_column($history[(int) $rows[1]['id']] ?? [], 'family'));
    $t->same([], $history[(int) $rows[2]['id']] ?? [], 'a row that grew nothing says nothing');
});

$t->test('the window is what stops a bed being warned about for ever',
    function ($t) use ($plantings, $rows, $today): void {
    // The planting above is 300 days old. A one-year window still sees it; a
    // 100-day one does not, and a rotation warning that never expires is a
    // warning people learn to ignore.
    $recent = $plantings->familyHistoryByRow((string) Clock::addDays($today, -100));
    $t->same([], $recent[(int) $rows[0]['id']] ?? []);

    $wide = $plantings->familyHistoryByRow((string) Clock::addDays($today, -1095));
    $t->ok(($wide[(int) $rows[0]['id']] ?? []) !== []);
});

$t->test('the form carries the history in the row option and the family on the type',
    function ($t) use ($client, $owner, $login, $rows, $byFamily): void {
    if (\count($byFamily) < 2) {
        $t->ok(true, 'skipped: fewer than two families in the research set');
        return;
    }
    $login($owner);
    $html = $client->get('/plants/new/direct_sow')->body;

    // With JavaScript off this is the whole feature: the fact rides in the
    // option's own text, which is where the occupancy hint already lives.
    $family = (string) \array_keys($byFamily)[0];
    $t->contains('grew ' . $family, $html, 'the row option says what it grew');
    $t->contains('data-families="', $html, 'and carries it for the script too');
    $t->contains('data-family="' . $family . '"', $html, 'the plant type carries its own family');
    $t->contains('id="rotation-warning"', $html, 'with somewhere for the warning to go');
});

$t->test('the warning does not block the planting it warns about',
    function ($t) use ($client, $db, $owner, $login, $gardenId, $rows, $byFamily): void {
    if (\count($byFamily) < 2) {
        $t->ok(true, 'skipped: fewer than two families in the research set');
        return;
    }
    $login($owner);
    // The same family, straight back into the same row. Handoff Section 4.3
    // is explicit that the occupancy hint beside this is a nudge and never a
    // block, and this is the same kind of hint.
    $sameFamily = $byFamily[\array_keys($byFamily)[0]][0];
    $response = $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $sameFamily['id'],
        'quantity_initial' => '3', 'garden_id' => (string) $gardenId,
        'garden_row_id' => (string) $rows[0]['id'],
    ]);
    $t->same(303, $response->status, 'recorded, warning and all');
});

$t->group('End Growing Season (Phase 5 handoff Section 3.3)');

$t->test('the confirmation screen names every planting it will end',
    function ($t) use ($client, $owner, $login, $gardenId, $plantings): void {
    $login($owner);
    $response = $client->get('/gardens/' . $gardenId . '/end-season');
    $t->same(200, $response->status);
    $html = $response->body;

    $living = $plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true]);
    $t->ok(\count($living) >= 2, 'there is more than one to name');

    // A count alone reads the same whether or not the fourteenth planting is
    // the one that matters to the person reading it.
    foreach ($living as $planting) {
        $t->contains((string) $planting['type'], $html);
    }
    $t->contains('no undo', $html, 'and says what it is');
});

$t->test('without the typed words, nothing happens',
    function ($t) use ($client, $owner, $login, $gardenId, $plantings): void {
    $login($owner);
    $before = \count($plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true]));

    // A checkbox next to a submit button is one mis-tap on a phone.
    foreach (['', 'yes', 'END', 'end seasons'] as $attempt) {
        $response = $client->post('/gardens/' . $gardenId . '/end-season', ['confirm' => $attempt]);
        $t->same(200, $response->status, 'refused: ' . ($attempt === '' ? '(empty)' : $attempt));
        $t->contains('Nothing has been changed', $response->body);
    }

    $t->same($before,
        \count($plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true])),
        'and every planting is still living');
});

$t->test('another account cannot end a garden that is not theirs',
    function ($t) use ($client, $stranger, $onboard, $gardenId, $plantings): void {
    $onboard($stranger, 'Interloper Bed');
    $response = $client->post('/gardens/' . $gardenId . '/end-season',
        ['confirm' => 'end season']);
    $t->same(404, $response->status, 'not 403 -- the garden does not exist for them');
    $t->ok($plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true]) !== []);
});

$t->test('it ends every living planting, on the date given, with each own remainder',
    function ($t) use ($client, $db, $owner, $login, $gardenId, $plantings, $today): void {
    $login($owner);

    $living = $plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true]);
    $expected = \count($living);
    $quantities = [];
    foreach ($living as $planting) {
        $quantities[(int) $planting['id']] = (int) $planting['quantity_live'];
    }
    $t->ok($expected >= 2);

    $frostDate = (string) Clock::addDays($today, -5);
    $response = $client->post('/gardens/' . $gardenId . '/end-season', [
        'confirm'    => 'End Season',   // case and spacing are forgiven
        'event_date' => $frostDate,
        'narrative'  => 'First hard freeze.',
    ]);
    $t->same(303, $response->status);

    $t->same([], $plantings->listWithDetail(['garden_id' => $gardenId, 'living' => true]),
        'nothing living is left');

    foreach ($quantities as $plantingId => $wasLive) {
        $row = $plantings->find($plantingId);
        $t->same(PlantingState::ENDED, (string) $row['state']);
        $t->same(0, (int) $row['quantity_live']);
        $t->same($frostDate, (string) $row['ended_at'], 'ended on the date given, not today');

        // quantity_all means "each planting's own remainder", not one number
        // across all of them (handoff Section 4.4).
        $delta = (int) $db->value(
            'SELECT `quantity_delta` FROM `plant_event`'
            . ' WHERE `planting_id` = :p AND `event_type` = :e ORDER BY `id` DESC LIMIT 1',
            ['p' => $plantingId, 'e' => EventType::CULLED], 0
        );
        $t->same(-$wasLive, $delta);
    }

    $t->same($expected, (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `user_id` = :u AND `event_type` = :e'
        . ' AND `event_date` = :d AND `narrative` = :n',
        ['u' => $owner['id'], 'e' => EventType::CULLED, 'd' => $frostDate,
         'n' => 'First hard freeze.'], 0
    ), 'one event each, all carrying the note');
});

$t->test('the log is still append-only: every timeline survives',
    function ($t) use ($client, $owner, $login, $plantings, $gardenId): void {
    $login($owner);
    $ended = $plantings->listWithDetail(['garden_id' => $gardenId]);
    $t->ok($ended !== [], 'the plantings are still there, ended rather than deleted');

    $response = $client->get('/plants/' . (int) $ended[0]['id']);
    $t->same(200, $response->status);
    $t->contains('Culled', $response->body, 'and the ending is on the timeline as an event');
});

$t->test('a garden with nothing living says so instead of offering a button',
    function ($t) use ($client, $owner, $login, $gardenId): void {
    $login($owner);
    $response = $client->get('/gardens/' . $gardenId . '/end-season');
    $t->same(200, $response->status);
    $t->contains('no season to end', $response->body);
    $t->notContains('End the season for', $response->body);
});
