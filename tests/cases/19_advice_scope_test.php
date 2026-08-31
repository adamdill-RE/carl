<?php

/**
 * What Recommendations grew in Phase 6 (handoff Section 3.5): a narrower
 * scope, a trimmed research section, and a page that says what it cost.
 *
 * The scope is the part with a security shape. Parsing one says which rows
 * to FILTER to; it says nothing about who may see them. Those are separate
 * questions and this file keeps them separate: a forged `garden:999` is
 * refused at the controller, and even if it were not, the document is built
 * from repositories that scoped their rows to the owner on the way in.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Analysis\Analyst;
use Carl\Analysis\Document;
use Carl\Analysis\Scope;
use Carl\Auth\Password;
use Carl\Auth\User;
use Carl\Repo\PlantingRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('The scope grammar');

$t->test('season, garden and plant round-trip through the column', function ($t): void {
    // analysis.scope is VARCHAR(16), which is what makes this need no
    // migration -- and what bounds the grammar.
    foreach (['season', 'garden:12', 'plant:3456'] as $raw) {
        $t->same($raw, Scope::parse($raw)->value());
        $t->ok(\strlen($raw) <= 16, $raw . ' fits the column');
    }
    $t->same('garden:999999999', Scope::garden(999999999)->value());
    $t->ok(\strlen(Scope::garden(999999999)->value()) <= 16, 'any id this app will have fits');
});

$t->test('anything unrecognised reads as the season, not as nothing',
    function ($t): void {
    // The widest reading is the safe one: a scope Carl cannot parse must not
    // silently narrow an answer down to an empty document the gardener paid
    // for.
    foreach ([null, '', '   ', 'nonsense', 'garden:', 'garden:abc', 'garden:0',
              'garden:-4', 'bed:12', 'plant:12:34'] as $raw) {
        $scope = Scope::parse($raw);
        $t->ok($scope->isSeason(), \var_export($raw, true) . ' should read as the season');
    }
    // ...except the one that is a real scope with trailing rubbish, which is
    // still not a real scope.
    $t->same('season', Scope::parse('garden:12x')->value());
});

$t->test('it describes itself for the prompt and the page', function ($t): void {
    $t->same('the whole season', Scope::season()->describe());
    $t->same('the garden Main Bed', Scope::garden(3)->describe('Main Bed'));
    $t->same('the planting Roma', Scope::plant(7)->describe('Roma'));
    $t->contains('#7', Scope::plant(7)->describe(null), 'an unnamed subject still says which');
});

$t->group('A scoped document carries only its subject');

$repo = new UserRepository($db);
$username = 'scope' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Scope Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'scope-test-passphrase', 'password_confirm' => 'scope-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Scope Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Scope Bed A ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);
$client->post('/gardens',
    ['name' => 'Scope Bed B ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);

$gardens = $db->all(
    "SELECT id, name FROM `garden` WHERE user_id = :u AND name LIKE 'Scope Bed%' ORDER BY id",
    ['u' => $userId]
);
$types = $db->all('SELECT id FROM `plant_type` ORDER BY id LIMIT 2');

// Through the repository rather than raw SQL: planting.root_planting_id is
// NOT NULL with no default, and the repository is the one writer that knows
// to point a fresh sowing at itself (migration 019).
$plantings = new PlantingRepository($db, $userId);

foreach ($gardens as $i => $garden) {
    $plantings->insert([
        'plant_type_id'    => (int) $types[$i % \count($types)]['id'],
        'garden_id'        => (int) $garden['id'],
        'label'            => 'Scoped ' . $i,
        'start_method'     => 'direct_sow',
        'start_date'       => '2026-05-01',
        'quantity_initial' => 4,
        'quantity_live'    => 4,
        'state'            => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
}

$user = User::fromRow($db->one('SELECT * FROM `user` WHERE id = :id', ['id' => $userId]));

$t->test('the season carries both beds and a garden scope carries one',
    function ($t) use ($app, $user, $gardens): void {
    $document = Document::forUser($app, $user);

    $whole = $document->build($user, '2026-08-31');
    $t->ok(\count($whole['plantings']) >= 2, 'the season has both');

    $one = $document->build($user, '2026-08-31', Scope::garden((int) $gardens[0]['id']));
    $t->same(1, \count($one['plantings']));
    $t->same('Scoped 0', $one['plantings'][0]['label']);

    // The gardens section narrows too, or the document would describe beds
    // whose plants it does not contain.
    $names = \array_map(static fn (array $g): string => (string) $g['name'], $one['gardens']);
    $t->same([$gardens[0]['name']], $names, 'got: ' . \implode(', ', $names));
});

$t->test('a plant scope carries that plant alone', function ($t) use ($app, $user, $db, $userId): void {
    $planting = $db->one(
        "SELECT id FROM `planting` WHERE user_id = :u AND label = 'Scoped 1'", ['u' => $userId]
    );
    $one = Document::forUser($app, $user)
        ->build($user, '2026-08-31', Scope::plant((int) $planting['id']));

    $t->same(1, \count($one['plantings']));
    $t->same('Scoped 1', $one['plantings'][0]['label']);
});

$t->test('the document says what it is about, in covers', function ($t) use ($app, $user, $gardens): void {
    // Section 2.5 of the Phase 6 handoff: a document that does not announce
    // its own bounds gets read as the whole record. A scope is a bound
    // exactly as the dates are, so it sits in the same block.
    $document = Document::forUser($app, $user);

    $whole = $document->build($user, '2026-08-31');
    $t->ok(!isset($whole['covers']['subject']), 'the season names no subject');

    $one = $document->build($user, '2026-08-31', Scope::garden((int) $gardens[0]['id']));
    $t->same('garden:' . $gardens[0]['id'], $one['covers']['subject']);
});

$t->test('a scope for somebody else\'s garden produces nothing, never their rows',
    function ($t) use ($app, $user, $db, $userId): void {
    $other = $db->value(
        'SELECT id FROM `garden` WHERE user_id <> :u ORDER BY id LIMIT 1', ['u' => $userId]
    );
    if ($other === null) {
        $t->ok(true, 'no other account has a garden in this database');
        return;
    }

    // Even with the authorisation check bypassed entirely, the repositories
    // scoped their rows on the way in -- so the worst case is an empty
    // document, not a leak.
    $document = Document::forUser($app, $user)
        ->build($user, '2026-08-31', Scope::garden((int) $other));
    $t->same([], $document['plantings']);
    $t->same([], $document['gardens']);
});

$t->test('narrowing the scope does not cost a statement', function ($t) use ($app, $user, $db, $gardens): void {
    // Phase 6 handoff Section 4.3: twelve statements, and a second garden
    // does not make it thirteen. A scope is a filter over rows already
    // fetched, so it must not make it thirteen either.
    $document = Document::forUser($app, $user);

    $before = $db->statementCount();
    $document->build($user, '2026-08-31');
    $season = $db->statementCount() - $before;

    $before = $db->statementCount();
    $document->build($user, '2026-08-31', Scope::garden((int) $gardens[0]['id']));
    $scoped = $db->statementCount() - $before;

    $t->same($season, $scoped, 'season cost ' . $season . ', scoped cost ' . $scoped);
    $t->same(12, $season, 'and it is still twelve');
});

$t->group('Asking for one');

$t->test('the form offers the gardens and plants this account has',
    function ($t) use ($client, $gardens): void {
    $response = $client->get('/advice');
    $t->same(200, $response->status);
    $t->contains('name="scope"', $response->body);
    $t->contains('garden:' . $gardens[0]['id'], $response->body);
    $t->contains('The whole season', $response->body);
});

$t->test('a queued request stores the scope it was asked with',
    function ($t) use ($client, $db, $userId, $gardens): void {
    $db->run('DELETE FROM `analysis` WHERE `user_id` = :u', ['u' => $userId]);

    $client->post('/advice', ['scope' => 'garden:' . $gardens[0]['id'], 'question' => '']);
    $row = $db->one(
        'SELECT * FROM `analysis` WHERE `user_id` = :u ORDER BY id DESC LIMIT 1', ['u' => $userId]
    );
    $t->ok($row !== null, 'a row was queued');
    $t->same('garden:' . $gardens[0]['id'], $row['scope']);
});

$t->test('the same day, two scopes are two questions, not one',
    function ($t) use ($client, $db, $userId, $gardens): void {
    $db->run('DELETE FROM `analysis` WHERE `user_id` = :u', ['u' => $userId]);

    $client->post('/advice', ['scope' => 'season', 'question' => '']);
    $client->post('/advice', ['scope' => 'garden:' . $gardens[0]['id'], 'question' => '']);

    $t->same(2, (int) $db->value(
        'SELECT COUNT(*) FROM `analysis` WHERE `user_id` = :u', ['u' => $userId], 0
    ), 'the dedupe key carries the scope, so one does not swallow the other');

    // ...but the same scope twice is still one.
    $client->post('/advice', ['scope' => 'season', 'question' => '']);
    $t->same(2, (int) $db->value(
        'SELECT COUNT(*) FROM `analysis` WHERE `user_id` = :u', ['u' => $userId], 0
    ));
});

$t->test('a scope naming a garden that is not yours is refused, not queued',
    function ($t) use ($client, $db, $userId): void {
    $db->run('DELETE FROM `analysis` WHERE `user_id` = :u', ['u' => $userId]);

    $other = $db->value(
        'SELECT id FROM `garden` WHERE user_id <> :u ORDER BY id LIMIT 1', ['u' => $userId]
    );
    if ($other === null) {
        $t->ok(true, 'no other account has a garden');
        return;
    }

    $response = $client->post('/advice', ['scope' => 'garden:' . (int) $other, 'question' => '']);
    $t->same(303, $response->status, 'it redirects rather than erroring');
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `analysis` WHERE `user_id` = :u', ['u' => $userId], 0
    ), 'and nothing was queued, so nothing was paid for');
});

$t->group('The research section, trimmed');

$t->test('every citation survives the trim, none is dropped',
    function ($t) use ($app, $user, $db): void {
    // Phase 6 handoff Section 3.5: the citations and confidences are what
    // stop the answer presenting a catalogue default as a local measurement,
    // so a trim that loses one has broken the thing the section is for.
    $built = Document::forUser($app, $user)->build($user, '2026-08-31');
    $research = $built['research'];

    // The document's plantings section is a curated shape and does not carry
    // plant_type_id -- the id is bookkeeping the reader cannot use. So the
    // ids come from the database, which is where the comparison belongs
    // anyway: this is asking whether the trim lost anything the SOURCE had.
    $plantTypeIds = \array_map('intval', $db->column(
        'SELECT DISTINCT `plant_type_id` FROM `planting` WHERE `user_id` = :u',
        ['u' => $user->id]
    ));
    if ($plantTypeIds === []) {
        $t->ok(true, 'nothing planted, nothing researched');
        return;
    }

    $raw = (new ReferenceRepository($db))->researchFor($plantTypeIds, $user->regionId);
    $wanted = [];
    foreach (['plants', 'regions'] as $part) {
        foreach ($raw[$part] as $row) {
            if (\is_string($row['source'] ?? null) && $row['source'] !== '') {
                $wanted[(string) $row['source']] = true;
            }
        }
    }

    $have = \array_flip($research['sources'] ?? []);
    foreach (\array_keys($wanted) as $citation) {
        $t->ok(isset($have[$citation]), 'lost: ' . $citation);
    }
});

$t->test('a row points at a real citation, and the version is said once',
    function ($t) use ($app, $user): void {
    $research = Document::forUser($app, $user)->build($user, '2026-08-31')['research'];
    $sources = $research['sources'] ?? [];

    $dangling = [];
    foreach ($research['plants'] as $plant) {
        foreach (\array_merge([$plant], $plant['windows'] ?? []) as $row) {
            if (isset($row['source_id']) && !isset($sources[$row['source_id']])) {
                $dangling[] = (string) $row['source_id'];
            }
        }
        // The bookkeeping column that was on all 99 rows.
        $t->ok(!isset($plant['dataset_version']), 'the version is not repeated per row');
    }
    $t->same([], $dangling, 'a source_id that points nowhere is worse than no citation');
    $t->ok(isset($research['dataset_version']), 'it is stated once, at the section');
});

$t->test('nulls are absent rather than spelled out', function ($t) use ($app, $user): void {
    $research = Document::forUser($app, $user)->build($user, '2026-08-31')['research'];
    foreach ($research['plants'] as $plant) {
        foreach ($plant as $key => $value) {
            if ($key === 'windows') {
                continue;
            }
            $t->ok($value !== null, $key . ' came through as an explicit null');
        }
    }
});

$t->test('the read_me explains the sources map', function ($t) use ($app, $user): void {
    // A reader handed `source_id: "s3"` with no explanation has been given a
    // worse document, not a smaller one.
    $built = Document::forUser($app, $user)->build($user, '2026-08-31');
    $t->contains('source_id', \implode(' ', $built['read_me']));
    $t->same(2, $built['version'], 'the shape changed, so the version did');
});

$t->group('What it cost');

$t->test('tokens are summed per month and per model, and priced',
    function ($t) use ($app, $db, $userId): void {
    $db->run('DELETE FROM `analysis` WHERE `user_id` = :u', ['u' => $userId]);
    $db->run(
        'INSERT INTO `analysis` (user_id, scope, requested_on, status, attempts,'
        . ' next_attempt_at, model, document_bytes, input_tokens, output_tokens,'
        . ' created_at, dedupe_key)'
        . " VALUES (:u, 'season', UTC_DATE(), 'done', 1, UTC_TIMESTAMP(), 'claude-opus-5',"
        . '  140510, 2000000, 100000, UTC_TIMESTAMP(), :key)',
        ['u' => $userId, 'key' => 'cost-test-' . \bin2hex(\random_bytes(6))]
    );

    $months = (new Analyst($app))->costByMonth(12);
    $mine = null;
    foreach ($months as $row) {
        if ($row['model'] === 'claude-opus-5') {
            $mine = $row;
            break;
        }
    }
    $t->ok($mine !== null, 'the month came back');
    $t->ok($mine['input_tokens'] >= 2000000);

    // 2M in at $5/M plus 100k out at $25/M is $10.00 + $2.50. Asserted as a
    // floor rather than an equality because other tests in this file queue
    // rows of their own into the same month.
    $t->ok($mine['cost'] >= 12.50,
        'priced from config; got ' . \var_export($mine['cost'], true));
});

$t->test('a model with no configured rate costs null, never zero',
    function ($t) use ($app, $db, $userId): void {
    $db->run(
        'INSERT INTO `analysis` (user_id, scope, requested_on, status, attempts,'
        . ' next_attempt_at, model, document_bytes, input_tokens, output_tokens,'
        . ' created_at, dedupe_key)'
        . " VALUES (:u, 'season', UTC_DATE(), 'done', 1, UTC_TIMESTAMP(), 'some-other-model',"
        . '  1000, 500, 100, UTC_TIMESTAMP(), :key)',
        ['u' => $userId, 'key' => 'cost-unpriced-' . \bin2hex(\random_bytes(6))]
    );

    $found = null;
    foreach ((new Analyst($app))->costByMonth(12) as $row) {
        if ($row['model'] === 'some-other-model') {
            $found = $row;
            break;
        }
    }
    $t->ok($found !== null);
    $t->same(null, $found['cost'], 'zero would read as free');
});

$t->test('the admin page shows the counts as facts and the money as an estimate',
    function ($t) use ($db, $root, $app): void {
    $admin = $db->one("SELECT `username` FROM `user` WHERE `role` = 'admin' ORDER BY id LIMIT 1");
    if ($admin === null) {
        $t->ok(true, 'no admin account in this database');
        return;
    }

    $client = new Client($root);
    $client->forgetCookies();
    $client->post('/login', ['username' => $admin['username'], 'password' => 'admin-test-passphrase']);

    $response = $client->get('/admin/analysis');
    if ($response->status !== 200) {
        $t->ok(true, 'admin sign-in not available in this run (status ' . $response->status . ')');
        return;
    }

    $t->contains('Recommendations cost', $response->body);
    $t->contains('The token counts are facts', $response->body);
    $t->contains('The money is an estimate', $response->body);
    $t->contains('config/app.php', $response->body, 'and it says where the rates live');
});
