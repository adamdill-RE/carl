<?php

/**
 * CSV export (handoff Section 13.3).
 *
 * The guard that matters here is formula injection: a cell beginning `=`,
 * `+`, `-` or `@` executes on the machine of the person the export is for
 * (hosting Section 8.5). The second is scope -- an export is the easiest
 * place in an application to hand one user another user's rows.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Support\Csv;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('The formula-injection guard (hosting 8.5)');

$t->test('a cell that would run as a formula is neutralised', function ($t): void {
    foreach (['=cmd|\' /C calc\'!A0', '@SUM(1:1)', "=1+1", "\tsomething", "\rrow"] as $attack) {
        $guarded = Csv::neutralise($attack);
        $t->same("'" . $attack, $guarded, 'unguarded: ' . \addcslashes($attack, "\t\r"));
    }
});

$t->test('the dangerous leaders that begin a formula, not a number, are caught', function ($t): void {
    // The real payload shape: it starts with a minus, so it looks numeric
    // for exactly one character.
    $t->same("'-2+3+cmd|' /C calc'!A0", Csv::neutralise("-2+3+cmd|' /C calc'!A0"));
    $t->same("'+cmd", Csv::neutralise('+cmd'));
});

$t->test('a plain number keeps its sign rather than being corrupted', function ($t): void {
    // quantity_delta is negative on every cull. Prefixing it would break the
    // column an export exists to let someone analyse.
    foreach (['-3', '+3.5', '-0.25', '12', '1.5e3', '-1.5E-3', '.5', '-.5'] as $number) {
        $t->same($number, Csv::neutralise($number), $number . ' is a number, not a formula');
    }
});

$t->test('a leading space does not smuggle a formula past the check', function ($t): void {
    $t->same("' =cmd", Csv::neutralise(' =cmd'));
});

$t->test('fields are quoted only when the format needs it', function ($t): void {
    $t->same('plain', Csv::field('plain'));
    $t->same('"has,comma"', Csv::field('has,comma'));
    $t->same('"say ""hi"""', Csv::field('say "hi"'));
    $t->same("\"line\r\nbreak\"", Csv::field("line\r\nbreak"));
    $t->same('" padded "', Csv::field(' padded '));
    // A neutralised cell is always quoted, so the apostrophe reads as part
    // of the value rather than as an accident of the format.
    $t->same('"\'=cmd"', Csv::field('=cmd'));
    $t->same('', Csv::field(null));
    $t->same('-3', Csv::field(-3));
});

$t->test('a line is CRLF-terminated, as RFC 4180 asks', function ($t): void {
    $t->same("a,b\r\n", Csv::line(['a', 'b']));
});

$t->group('Exporting real data');

$makeUser = static function (string $username) use ($db, $app): array {
    $repo = new UserRepository($db);
    $created = $repo->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username, 'password' => $created['temporary_password']];
};

$owner = $makeUser('exporter' . $suffix);
$stranger = $makeUser('stranger' . $suffix);

$client = new Client($root);
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

/** Sign in, reset the forced password, and finish onboarding at ZIP 76692. */
$onboard = static function (array $user) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => $user['password']]);
    $client->post('/password/reset', [
        'current_password' => $user['password'],
        'password' => 'export-test-passphrase', 'password_confirm' => 'export-test-passphrase',
    ]);
    $client->post('/onboarding/profile', ['name' => 'Export Tester', 'zip' => '76692']);
    $client->post('/onboarding/garden', ['name' => 'Export Bed', 'row_count' => '2', 'soil_type' => 'loam']);
    $client->post('/onboarding/finish', []);
};

$onboard($owner);

$t->test('a plant whose nickname is a formula is recorded as typed', function ($t) use ($client, $db, $owner, $plantTypeId): void {
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '6', 'label' => '=cmd|\' /C calc\'!A0',
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $owner['id']);
    $rows = $plantings->where('', [], '`id` DESC', 1);
    $t->same('=cmd|\' /C calc\'!A0', $rows[0]['label'],
        'the database keeps what the user typed; the export is what neutralises it');
});

$t->test('plants.csv carries the header, the row, and the neutralised nickname',
    function ($t) use ($client): void {
    $response = $client->get('/export/plants.csv');
    $t->same(200, $response->status);
    $t->same('text/csv; charset=utf-8', $response->headers()['Content-Type']);
    $t->contains('attachment; filename="carl-plants-', $response->headers()['Content-Disposition']);
    $t->ok($response->isStreamed(), 'the body is produced in chunks, not built in memory');

    $body = $response->collect();
    $t->contains(Csv::BOM . 'planting_id,category,type', $body, 'BOM first, then the header');
    $t->contains('"\'=cmd|\' /C calc\'!A0"', $body, 'the nickname is neutralised on the way out');
    $t->notContains(",=cmd", $body, 'and never reaches a cell unguarded');
});

$t->test('events.csv carries plant events and garden events, told apart',
    function ($t) use ($client, $db, $owner): void {
    $gardens = new GardenRepository($db, $owner['id']);
    $garden = $gardens->where('`name` = :n', ['n' => 'Export Bed'])[0];

    // A garden-level mulch fans out to nothing (handoff 4.7), so it exists
    // only in garden_event -- exactly the row a plant-events-only export
    // would lose.
    $client->post('/gardens/' . $garden['id'] . '/actions', [
        'event_type' => 'mulched', 'mulch_new' => 'Straw',
        'narrative' => 'Two bales, north end',
    ]);

    $response = $client->get('/export/events.csv');
    $t->same(200, $response->status);
    $body = $response->collect();

    $t->contains('scope,event_id,event_date', $body);
    $t->contains('plant,', $body, 'plant events are in there');
    $t->contains('garden,', $body, 'and so are garden events');
    $t->contains('Two bales, north end', $body);
});

$t->test('weather.csv is the stored SI series for the user own location',
    function ($t) use ($client, $db, $owner): void {
    $locationId = (int) ($db->value(
        'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $owner['id']]
    ) ?? 0);
    $t->ok($locationId > 0, 'onboarding attached a weather location');

    $db->run(
        'INSERT INTO `weather_daily` (location_id, obs_date, temp_max_c, temp_min_c,'
        . ' precip_mm, precip_hours, et0_mm, source_model, is_provisional, fetched_at)'
        . " VALUES (:loc, :date, 31.5, 19.0, 0.0, 0.0, 6.25, 'best_match', 0, UTC_TIMESTAMP())"
        . ' ON DUPLICATE KEY UPDATE `et0_mm` = VALUES(`et0_mm`)',
        ['loc' => $locationId, 'date' => (string) Clock::addDays(\gmdate('Y-m-d'), -400)]
    );

    $response = $client->get('/export/weather.csv');
    $t->same(200, $response->status);
    $body = $response->collect();
    $t->contains('obs_date,temp_max_c,temp_min_c', $body);
    $t->contains('31.50', $body, 'stored SI, not converted for display');
    $t->contains('6.25', $body);
});

$t->test('the chunked walk returns every row, not just the first chunk',
    function ($t) use ($db, $owner): void {
    // The keyset loop is the part that can silently truncate. Walk it with a
    // chunk size of one and assert it reaches the end.
    $plantings = new PlantingRepository($db, $owner['id']);
    $total = $plantings->count();
    $t->ok($total > 0, 'there is something to walk');

    $seen = [];
    $afterId = 0;
    do {
        $rows = $plantings->exportChunk($afterId, 1);
        foreach ($rows as $row) {
            $afterId = (int) $row['id'];
            $seen[$afterId] = true;
        }
    } while (\count($rows) === 1);

    $t->same($total, \count($seen), 'every planting came back exactly once');
});

$t->group('/export/claude.json (handoff Section 13.3)');

$t->test('the document is valid JSON, streamed, and carries every section',
    function ($t) use ($client, $db, $owner): void {
    $response = $client->get('/export/claude.json');
    $t->same(200, $response->status);
    $t->same('application/json; charset=utf-8', $response->headers()['Content-Type']);
    $t->contains('attachment; filename="carl-for-claude-', $response->headers()['Content-Disposition']);
    $t->ok($response->isStreamed(), 'built in pieces, not assembled and encoded once');

    $body = $response->collect();
    $document = \json_decode($body, true);
    $t->ok(\is_array($document), 'the stream really is one valid JSON document');

    foreach (['carl', 'gardener', 'gardens', 'containers', 'lists', 'plantings',
              'plant_events', 'garden_events', 'weather', 'research', 'attribution'] as $section) {
        $t->ok(\array_key_exists($section, $document), 'section: ' . $section);
    }

    $t->same('carl-export', $document['carl']['document']);
    $t->ok($document['carl']['read_me'] !== [], 'and it says how to read itself');
    $t->ok(\count($document['plantings']) > 0, 'with the plantings in it');
});

$t->test('it is NOT formula-injection guarded, and that is the point',
    function ($t) use ($client): void {
    // Csv::field() prefixes a leading '=' so a spreadsheet does not run the
    // cell. JSON has no formulas, and running these values through the guard
    // would rewrite every negative number in the file -- quantity_delta is
    // negative on every cull. The absence is deliberate; this is the test
    // that stops someone "fixing" it.
    $document = \json_decode($client->get('/export/claude.json')->collect(), true);

    $labels = \array_column($document['plantings'], 'label');
    $t->ok(\in_array('=cmd|\' /C calc\'!A0', $labels, true),
        'the nickname comes back exactly as it was typed');
    foreach ($labels as $label) {
        $t->notContains("'=cmd", (string) $label, 'and is never apostrophe-prefixed');
    }
});

$t->test('no user_id rides along on any row', function ($t) use ($client): void {
    $body = $client->get('/export/claude.json')->collect();
    $t->notContains('"user_id"', $body, 'it is this user own id on every row: noise, not data');
});

$t->test('the weather carries its credit, generated from the rows in it',
    function ($t) use ($client): void {
    // weather.md Section 10: attribution is required and non-optional, and
    // generated from source_model rather than hard-coded.
    $document = \json_decode($client->get('/export/claude.json')->collect(), true);
    if ($document['weather']['days'] === []) {
        $t->same([], $document['attribution'], 'no rows, no credit');
        return;
    }
    $t->contains('Open-Meteo.com', $document['attribution'][0]);
});

$t->test('the document does not cost a statement per row',
    function ($t) use ($client, $db, $owner): void {
    // The three unbounded tables are walked in keyset chunks; everything else
    // is bounded by what a person builds by hand. Either way the count must
    // not track the number of rows.
    $before = $db->statementCount();
    $client->get('/export/claude.json')->collect();
    $spent = $db->statementCount() - $before;

    $rows = (int) $db->value(
        'SELECT (SELECT COUNT(*) FROM `planting` WHERE user_id = :u1)'
        . ' + (SELECT COUNT(*) FROM `plant_event` WHERE user_id = :u2)',
        ['u1' => $owner['id'], 'u2' => $owner['id']],
        0
    );
    $t->ok($rows > 0, 'there is something to walk: ' . $rows . ' rows');
    $t->ok($spent < 40, 'spent ' . $spent . ' statements on ' . $rows . ' rows');
});

$t->group('An export is one user data');

$t->test('a second account exports its own rows and none of the first',
    function ($t) use ($client, $onboard, $stranger, $owner, $db): void {
    $onboard($stranger);

    $response = $client->get('/export/plants.csv');
    $t->same(200, $response->status);
    $body = $response->collect();

    $t->notContains('=cmd', $body, "the other account's plant is not in here");
    $t->notContains("'=cmd", $body);

    $ownerRows = (int) $db->value(
        'SELECT COUNT(*) FROM `planting` WHERE user_id = :id', ['id' => $owner['id']], 0
    );
    $t->ok($ownerRows > 0, 'the first account does have plantings to leak');
    // Header only: this account has recorded nothing.
    $t->same(1, \substr_count(\trim($body), "\r\n") + 1, 'one line, the header');

    // The JSON export is the same scope, through the same repositories.
    $json = $client->get('/export/claude.json');
    $t->same(200, $json->status);
    $document = \json_decode($json->collect(), true);
    $t->same([], $document['plantings'], "and no plantings of the first account's");
    $t->same([], $document['plant_events']);
    $t->same([], $document['research']['plants'], 'nor research for plants it does not grow');
});

$t->test('a signed-out request is redirected to login, not served a file',
    function ($t) use ($client): void {
    $client->forgetCookies();
    $response = $client->get('/export/plants.csv');
    $t->same(303, $response->status);
    $t->contains('login', (string) $response->headers()['Location']);
});
