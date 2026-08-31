<?php

/**
 * The companion planting reference (handoff Section 14 v2, Phase 6 handoff
 * Section 3.3).
 *
 * The import side is tested in 02_research_test.php, which is where the
 * dataset is loaded. This file is about the two things the reference itself
 * has to get right:
 *
 *  1. **The pair is stored once and read both ways.** Somebody looking up
 *     "Onion" must find the pairing that the dataset happened to file under
 *     "Bean". A lookup that reads only one column finds half the table and
 *     looks like it worked.
 *
 *  2. **The confidence survives to the page.** This is the subject where the
 *     gap between what is repeated and what has been tested is widest, and a
 *     screen that drops the marker is teaching folklore in Carl's voice.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);
$reference = new ReferenceRepository($db);

$t->group('Reading a pair from either end');

$t->test('the pairing filed under Bean is found by looking up Onion',
    function ($t) use ($reference): void {
    // The dataset states this one as (Bean, Onion). Stored lexically that is
    // category='Bean', other_category='Onion' -- so a lookup for Onion has to
    // read the SECOND column and flip the row, or it finds nothing.
    $forBean = $reference->companionsFor('Bean');
    $forOnion = $reference->companionsFor('Onion');

    $beanSaysOnion = \array_filter($forBean,
        static fn (array $c): bool => $c['other'] === 'Onion');
    $onionSaysBean = \array_filter($forOnion,
        static fn (array $c): bool => $c['other'] === 'Bean');

    $t->ok($beanSaysOnion !== [], 'Bean knows about Onion');
    $t->ok($onionSaysBean !== [], 'and Onion knows about Bean');

    $one = \array_values($beanSaysOnion)[0];
    $two = \array_values($onionSaysBean)[0];
    $t->same($one['relationship'], $two['relationship'], 'the same fact both ways');
    $t->same($one['reason'], $two['reason']);
    $t->same('bad', $one['relationship']);
});

$t->test('every row names the other plant, never the one asked about',
    function ($t) use ($reference): void {
    foreach (['Tomato', 'Bean', 'Squash (summer)', 'Onion', 'Lettuce'] as $category) {
        foreach ($reference->companionsFor($category) as $companion) {
            $t->ok(\strcasecmp($companion['other'], $category) !== 0,
                $category . ' was listed as its own companion');
        }
    }
});

$t->test('a category with no pairings gets an empty list, not everything',
    function ($t) use ($reference): void {
    $t->same([], $reference->companionsFor('Nothing At All'));
    $t->same([], $reference->companionsFor(''), 'and an empty string asks nothing');
    $t->same([], $reference->companionsFor('   '));
});

$t->test('the lookup is one statement whatever the pairing count',
    function ($t) use ($db, $reference): void {
    $before = $db->statementCount();
    $reference->companionsFor('Tomato');
    $t->same(1, $db->statementCount() - $before);
});

$t->group('What the reference says, and how sure it is');

$t->test('the best-evidenced pairing and the best-known one are marked differently',
    function ($t) use ($reference): void {
    $tomato = [];
    foreach ($reference->companionsFor('Tomato') as $companion) {
        $tomato[$companion['other']] = $companion;
    }

    $t->ok(isset($tomato['Marigold']), 'got: ' . \implode(', ', \array_keys($tomato)));
    $t->same('verified', $tomato['Marigold']['confidence'],
        'a named mechanism from an extension source');
    $t->contains('thiophenes', (string) $tomato['Marigold']['reason']);

    $t->ok(isset($tomato['Basil']));
    $t->same('generic', $tomato['Basil']['confidence'],
        'the most repeated pairing there is, and one of the least tested');

    // The distinction is the whole point of the table. If these ever come
    // back equal, the honesty has been flattened out of the dataset.
    $t->ok($tomato['Marigold']['confidence'] !== $tomato['Basil']['confidence']);
});

$t->test('every pairing carries a reason and a citation', function ($t) use ($db): void {
    $bare = $db->all(
        'SELECT `category`, `other_category` FROM `plant_companion`'
        . " WHERE `reason` IS NULL OR `reason` = '' OR `source` IS NULL OR `source` = ''"
    );
    $named = \array_map(
        static fn (array $r): string => $r['category'] . '/' . $r['other_category'], $bare);
    $t->same([], $named, 'a pairing that cannot say why is an assertion: ' . \implode(', ', $named));
});

$t->group('The screen');

$repo = new UserRepository($db);
$username = 'comp' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Companion Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'companion-test-passphrase', 'password_confirm' => 'companion-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Companion Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Companion Bed ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$t->test('the page lists pairings under both crops', function ($t) use ($client): void {
    $response = $client->get('/companions');
    $t->same(200, $response->status);
    $t->contains('Companion planting', $response->body);
    $t->contains('Grows well with', $response->body);
    $t->contains('Keep apart from', $response->body);
});

$t->test('the confidence marker reaches the page, and so does the caveat',
    function ($t) use ($client): void {
    $response = $client->get('/companions');
    $t->contains('confidence-verified', $response->body);
    $t->contains('confidence-generic', $response->body);
    // The page must say what "generic" means here, or the marker is decoration.
    $t->contains('traditional and widely printed', $response->body);
});

$t->test('it says plainly that nothing acts on this', function ($t) use ($client): void {
    // Companion advice must not quietly acquire the authority of the crop
    // rotation warning, which has real evidence behind it.
    $response = $client->get('/companions');
    $t->contains('nothing with them beyond showing them', $response->body);
});

$t->test('the reference is two statements, not one per crop',
    function ($t) use ($client, $db): void {
    $before = $db->statementCount();
    $client->get('/companions');
    $cost = $db->statementCount() - $before;
    $t->ok($cost <= 6, 'the page cost ' . $cost . ' statements');
});

$t->test('the research card shows the neighbours for the plant being read',
    function ($t) use ($client, $db): void {
    $tomato = $db->one(
        "SELECT id FROM `plant_type` WHERE `category` = 'Tomato' ORDER BY id LIMIT 1"
    );
    $response = $client->get('/research/' . (int) $tomato['id']);
    $t->same(200, $response->status);
    $t->contains('Neighbours', $response->body);
    $t->contains('Marigold', $response->body);
});

$t->test('a user with no researched region still gets the companions',
    function ($t) use ($db, $app, $root, $suffix): void {
    // They are global by design: whether basil suits a tomato is a fact about
    // the plants, so an unresearched county is no reason to withhold it.
    $repo = new UserRepository($db);
    $name = 'compnr' . $suffix;
    $made = $repo->createWithTemporaryPassword(
        $name, $name . '@example.test', 'No Region',
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    $db->run(
        "UPDATE `user` SET `region_id` = NULL, `must_reset_password` = 0,"
        . " `onboarding_step` = 'done' WHERE `id` = :id",
        ['id' => (int) $made['id']]
    );

    $reference = new ReferenceRepository($db);
    $tomato = $db->one("SELECT id FROM `plant_type` WHERE `category` = 'Tomato' ORDER BY id LIMIT 1");
    $card = $reference->researchCard((int) $tomato['id'], null);

    $t->same([], $card['regions'], 'no region, no planting windows');
    $t->ok($card['companions'] !== [], 'but the companions are still there');
});
