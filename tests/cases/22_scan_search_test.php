<?php

/**
 * A tag code typed into the plant list's search box (Phase 9).
 *
 * `docs/QR-TAGS-SPEC.md` Section 7 rules out an in-app scanner and is right
 * to: a phone camera reads a QR symbol better than anything that would fit in
 * the 150 KB budget, and it navigates to `/t/{code}` on its own. What a camera
 * CANNOT do is put six characters into a box on a page you are already
 * looking at, which is the case this covers -- the code read off the stake in
 * your hand while you are at a desk.
 *
 * TWO THINGS HAVE TO HOLD AND THEY PULL AGAINST EACH OTHER:
 *
 *  1. **A real code lands on the right screen**, which is the screen you were
 *     already on. From View Plants that is the report page; from Log Plant
 *     Activity it is the log form. Not the field screen: `/t/{code}` is one
 *     hand, mud and today's date, and somebody typing into this box came here
 *     to backdate a yield.
 *
 *  2. **Anything that is NOT a code of yours still behaves like a search.**
 *     Real words collide with the alphabet -- "pepper" and "garden" are both
 *     six legal characters -- so a hijacked search would be a bug you would
 *     only find in July. And a code that does not exist has to be
 *     indistinguishable from one that belongs to somebody else (spec Section
 *     6.2), which silence achieves and a message does not.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\PlantingState;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\TagRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const SCAN_SEARCH_PASSPHRASE = 'scan-search-passphrase';

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('scanner' . $suffix);
$other = $makeUser('scanother' . $suffix);

// $_SESSION outlives a Client across the suite and AuthController silently
// declines to log in when somebody already is (PHASE-8-HANDOFF Section 7).
$client->forgetCookies();
$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => SCAN_SEARCH_PASSPHRASE, 'password_confirm' => SCAN_SEARCH_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Scan Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Scan Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$events = new EventRepository($db, $owner['id'], $plantings);
$tags = new TagRepository($db, $owner['id']);
$otherTags = new TagRepository($db, $other['id']);

$indoorId = $gardens->ensureIndoorGarden();
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = \gmdate('Y-m-d');  // utc-ok: backdates the sow closure only, never compared to an app-computed day

$sow = static function (string $label) use ($plantings, $plantTypeId, $indoorId, $today): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => (string) Clock::addDays($today, -20),
        'quantity_initial' => 6,
        'quantity_live'    => 6,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

$plantingId = $sow('Scanned Plant ' . $suffix);
$boundCode = $tags->mint(1, \Carl\Domain\LabelStock::AVERY_60517)['codes'][0];
$freeCode = $tags->mint(1, \Carl\Domain\LabelStock::AVERY_60517)['codes'][0];
$tags->bindTo(
    (int) $db->value('SELECT id FROM `qr_tag` WHERE code = :code', ['code' => $boundCode]),
    $plantingId
);

// Somebody else's real, bound code. It must be indistinguishable from a code
// that was never minted at all.
$strangerCode = $otherTags->mint(1, \Carl\Domain\LabelStock::AVERY_60517)['codes'][0];

$t->group('A whole tag code jumps to the plant, on the screen you were on');

$t->test('from View Plants it opens the report page', function ($t) use ($client, $boundCode, $plantingId): void {
    $response = $client->get('/plants', ['q' => $boundCode]);
    $t->same(303, $response->status, 'a code is a jump, not a filtered list');
    $t->same('/carl/plants/' . $plantingId, $response->headers()['Location'] ?? '');
});

$t->test('from Log Plant Activity it opens the LOG form, not the field screen',
    function ($t) use ($client, $boundCode, $plantingId): void {
    // The whole reason this exists rather than a link to /tags/find. /t/{code}
    // is the field screen -- six large buttons and today's date -- which is
    // the right page in a garden and the wrong one at a desk.
    $response = $client->get('/log', ['q' => $boundCode]);
    $t->same(303, $response->status);
    $t->same('/carl/log/' . $plantingId, $response->headers()['Location'] ?? '');
    $t->notContains('/t/', $response->headers()['Location'] ?? '',
        'never the field screen from a list');
});

$t->test('case, spaces and hyphens do not matter', function ($t) use ($client, $boundCode, $plantingId): void {
    // TagRepository::normalise() is forgiving about exactly these, because
    // the fallback path is a person reading six characters off a faded tag.
    foreach ([
        \strtolower($boundCode),
        \substr($boundCode, 0, 3) . '-' . \substr($boundCode, 3),
        ' ' . \substr($boundCode, 0, 2) . ' ' . \substr($boundCode, 2) . ' ',
    ] as $typed) {
        $response = $client->get('/plants', ['q' => $typed]);
        $t->same('/carl/plants/' . $plantingId, $response->headers()['Location'] ?? '',
            'typed as "' . $typed . '"');
    }
});

$t->test('a code with no plant on it opens the bind screen', function ($t) use ($client, $freeCode): void {
    // Typing a free tag's code is how somebody says "put this one on
    // something", and /t/{code} on an unbound tag is exactly that screen.
    $response = $client->get('/log', ['q' => $freeCode]);
    $t->same(303, $response->status);
    $t->same('/carl/t/' . $freeCode, $response->headers()['Location'] ?? '');
});

$t->group('Anything that is not one of your codes is still a search');

$t->test('a real word of six legal characters does not hijack the search',
    function ($t) use ($client): void {
    // PEPPER and GARDEN are both six characters of the Crockford alphabet.
    // The gate is not "does it look like a code" -- it is "is it one of
    // YOURS" -- and this is the case that makes the difference matter.
    foreach (['pepper', 'GARDEN'] as $word) {
        $t->same(true, TagRepository::isWellFormed(TagRepository::normalise($word)),
            $word . ' really is a well-formed code');
        $response = $client->get('/plants', ['q' => $word]);
        $t->same(200, $response->status, $word . ' stays a search');
    }
});

$t->test('a stranger\'s real code and a code that never existed are indistinguishable',
    function ($t) use ($client, $strangerCode): void {
    // Spec Section 6.2. A tag on a stake in a front garden is photographable
    // from the pavement; anything that told the two apart would let somebody
    // enumerate which codes are real.
    $mine = $client->get('/plants', ['q' => $strangerCode]);
    $nobody = $client->get('/plants', ['q' => 'ZZZZZZ']);

    $t->same(200, $mine->status, 'a stranger\'s code is a search, not a 404');
    $t->same(200, $nobody->status);

    // The pages differ only where the box echoes what was typed. Comparing
    // them with that one value blanked out is the assertion: anything else
    // that differed would be Carl telling a stranger which codes are real.
    $t->same(
        \str_replace('ZZZZZZ', 'CODE', $nobody->body),
        \str_replace($strangerCode, 'CODE', $mine->body),
        'the two answers are the same page'
    );
    $t->contains('Nothing matches', $mine->body, 'and it is the ordinary empty search');
});

$t->test('fewer than six characters narrows the list instead of jumping',
    function ($t) use ($client, $boundCode, $suffix): void {
    $partial = \substr($boundCode, 0, 4);
    $response = $client->get('/plants', ['q' => $partial]);
    $t->same(200, $response->status, 'a partial code is a filter, not a jump');
    $t->contains('Scanned Plant ' . $suffix, $response->body,
        'four characters off a faded tag still find the plant');
});

$t->test('the lookup costs one statement, and only when it could be a code',
    function ($t) use ($db, $tags): void {
    // An ordinary search must pay nothing for this feature. The gate is
    // isWellFormed(normalise()), which touches no database at all.
    $t->same(false, TagRepository::isWellFormed(TagRepository::normalise('carrot')),
        'carrot is five legal characters, so the lookup never runs');
    $t->same(false, TagRepository::isWellFormed(TagRepository::normalise('tomato')),
        'tomato loses its O and is four');

    $before = $db->statementCount();
    $tags->scan('PEPPER');
    $t->same(1, $db->statementCount() - $before, 'one statement when it could be a code');
});

$t->group('The code travels with the plant on the screens that list it');

$t->test('the plant list shows the code on the row it belongs to',
    function ($t) use ($client, $boundCode, $suffix): void {
    $response = $client->get('/plants', ['q' => 'Scanned Plant ' . $suffix]);
    $t->contains($boundCode, $response->body, 'the stake and the row carry the same identifier');
});

$t->test('the log form names the stake, and it costs no extra statement',
    function ($t) use ($client, $plantings, $plantingId, $boundCode): void {
    // The code rides in on the row the form was built from -- LIST_SELECT
    // carries it -- so naming the stake is free.
    $row = $plantings->findWithDetail($plantingId);
    $t->same($boundCode, $row['tag_codes'] ?? null, 'findWithDetail carries the live code');
    $t->same(1, (int) ($row['tag_count'] ?? 0), 'and how many stakes there are');

    $response = $client->get('/log/' . $plantingId);
    $t->contains($boundCode, $response->body);
});

$t->test('a rebound tag does not list the plant twice',
    function ($t) use ($db, $tags, $plantings, $plantingId, $boundCode, $suffix): void {
    // The codes are subqueries over the LIVE bindings, not a join. A join
    // would fan the row out once per stake -- and a tray now carries a stake
    // per cell (QR-TAGS-SPEC Section 14.7) -- so a planting with three would
    // appear three times in View Plants.
    $extra = $tags->mint(1, \Carl\Domain\LabelStock::AVERY_60517)['codes'][0];
    $extraId = (int) $db->value('SELECT id FROM `qr_tag` WHERE code = :code', ['code' => $extra]);

    $tags->bindTo($extraId, $plantingId);   // a second stake on the same plant
    $rows = $plantings->listWithDetail(['search' => 'Scanned Plant ' . $suffix]);

    $t->same(1, \count($rows), 'one row for one planting, however many stakes it wears');
    $t->same(2, (int) $rows[0]['tag_count'], 'both stakes counted');
    $t->contains($extra, (string) $rows[0]['tag_codes'], 'and both codes on the row');
    $t->contains($boundCode, (string) $rows[0]['tag_codes']);

    // And a closed binding is not a stake: take the extra off again.
    $tags->unbind($extraId);
    $rows = $plantings->listWithDetail(['search' => 'Scanned Plant ' . $suffix]);
    $t->same(1, \count($rows));
    $t->same($boundCode, (string) $rows[0]['tag_codes'], 'only the live one');
});
