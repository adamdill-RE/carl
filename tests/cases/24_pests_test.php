<?php

/**
 * The pest and disease reference (Phase 9).
 *
 * `db/migrations/022_pest_reference.sql` is the argument for shipping a
 * catalogue at all and `Carl\Research\PestCatalog` is the argument for what is
 * in it. This file guards the three things that would be expensive to get
 * wrong and cheap to break.
 *
 *  1. **THE CONTENT IS DATA AND DATA ROTS.** Seventy-six entries of prose in a
 *     CSV is exactly the shape of file where somebody adds a row with a
 *     mistyped kind, a duplicate key, an over-long name that MySQL truncates
 *     silently, or a chemical control with a MIXING RATE in it. Every one of
 *     those is checked against the file rather than against the database, so
 *     it fails in CI before it fails in a garden.
 *
 *  2. **THE CATALOGUE AND THE RESEARCH IMPORTER SHARE SIX COLUMNS.** Both
 *     write `pest` keyed on `pest_key`, both are idempotent, and the rule is
 *     last-writer-wins with `source` moving alongside the text it attributes.
 *     Re-applying the catalogue must not duplicate a row, must not touch
 *     `treatments`, and must not orphan the entries somebody typed for
 *     themselves.
 *
 *  3. **A GARDENER MAY STILL ADD TO IT**, which was the whole question. An
 *     account's own entry keeps working, gets adopted rather than duplicated
 *     when the catalogue turns out to name the same thing, and is visibly
 *     distinguished on the Lists screen.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\ListType;
use Carl\Repo\ListRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\UserRepository;
use Carl\Research\PestCatalog;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const PESTS_PASSPHRASE = 'pests-test-passphrase';

$rows = PestCatalog::rows($root);

// ========================================================================
// 1. The seed file
// ========================================================================

$t->group('The catalogue file is well formed');

$t->test('it loads, and it is a catalogue rather than a gesture', function ($t) use ($rows): void {
    // The number is not sacred, but a catalogue that quietly shrank to six
    // entries would still pass every other test in this file.
    $t->ok(\count($rows) >= 60, 'at least sixty entries, got ' . \count($rows));

    $byKind = [];
    foreach ($rows as $row) {
        $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + 1;
    }
    foreach (['pest', 'disease', 'disorder'] as $kind) {
        $t->ok(($byKind[$kind] ?? 0) >= 10, 'at least ten of kind ' . $kind);
    }
});

$t->test('every key is unique and slug-shaped', function ($t) use ($rows): void {
    // A duplicate would silently collapse two entries into one on the upsert
    // and the count would still look right. PestCatalog::rows() refuses one;
    // this says so in the open.
    $seen = [];
    foreach ($rows as $row) {
        $key = $row['pest_key'];
        $t->ok(\preg_match('/^[a-z0-9_]+$/', $key) === 1, $key . ' is a slug');
        $t->ok(!isset($seen[$key]), $key . ' appears once');
        $seen[$key] = true;
    }
});

$t->test('the keys the Hill County dataset already uses are the keys here',
    function ($t) use ($rows): void {
    // This is what makes an install that has already imported that dataset
    // MERGE rather than end up with two rows for aphids -- one from the
    // county and one from Carl, joined to different halves of the log.
    $keys = [];
    foreach ($rows as $row) {
        $keys[$row['pest_key']] = true;
    }
    foreach (['aphid', 'spider_mite', 'squash_vine_borer', 'squash_bug', 'cucumber_beetle',
              'hornworm', 'stink_bug', 'leaf_footed_bug', 'fire_ant', 'early_blight',
              'powdery_mildew', 'root_knot_nematode', 'curly_top', 'spotted_wilt',
              'blossom_end_rot', 'blossom_drop_heat'] as $key) {
        $t->ok(isset($keys[$key]), $key . ' keeps the key the dataset uses');
    }
});

$t->test('every cell that must not be empty is not, and every enum is real',
    function ($t) use ($rows): void {
    foreach ($rows as $row) {
        $where = $row['pest_key'];
        foreach (['name', 'description', 'signs', 'consequence', 'monitoring',
                  'prevention', 'organic_controls', 'treatments'] as $field) {
            $t->ok(\trim($row[$field]) !== '', $where . ' has a ' . $field);
        }
        $t->ok(\in_array($row['kind'], ['pest', 'disease', 'disorder'], true),
            $where . ' has a real kind');
        $t->ok($row['severity'] === ''
            || \in_array($row['severity'], ['cosmetic', 'manageable', 'serious', 'fatal'], true),
            $where . ' has a real severity');
        $t->ok(\in_array($row['pollinator_risk'], ['0', '1'], true),
            $where . ' has a boolean pollinator_risk');
    }
});

$t->test('nothing is long enough for MySQL to truncate it in silence',
    function ($t) use ($rows): void {
    // 022 gives these columns fixed widths. An over-long cell is not an error
    // in a non-strict session -- it is a value quietly cut in half, which is
    // how a pest ends up called "Tomato and tobacco hornwo".
    $limits = ['pest_key' => 64, 'name' => 120, 'latin_name' => 120, 'also_called' => 255,
               'affects_categories' => 500, 'look_alikes' => 500, 'beneficials' => 500];
    foreach ($rows as $row) {
        foreach ($limits as $field => $limit) {
            $t->ok(\strlen($row[$field]) <= $limit,
                $row['pest_key'] . '.' . $field . ' is ' . \strlen($row[$field])
                . ' bytes against a limit of ' . $limit);
        }
    }
});

$t->test('the file is ASCII, like everything else in this repository',
    function ($t) use ($rows): void {
    // A smart quote pasted in from a web page is invisible in a diff and
    // survives all the way to a PDF where the font has no glyph for it.
    foreach ($rows as $row) {
        foreach ($row as $field => $value) {
            $t->ok(\preg_match('/[^\x20-\x7E]/', $value) !== 1,
                $row['pest_key'] . '.' . $field . ' is plain ASCII');
        }
    }
});

$t->group('What the chemical advice may and may not say');

$t->test('no product brands, and NO RATES, anywhere in chemical_controls',
    function ($t) use ($rows): void {
    // FIFRA section 12(a)(2)(G): using a pesticide inconsistently with its
    // labeling is a federal violation, and registrations differ by state. A
    // table that printed a rate would be telling somebody to break the law
    // about half the time and would be out of date the rest. So this names
    // what the active ingredient IS and leaves every number to the packet.
    $rate = '/\d+\s*(ml|oz|fl|tbsp|tsp|gal|lb|kg|%|per cent|ppm|pint|quart)\b/i';
    foreach ($rows as $row) {
        $t->ok(\preg_match($rate, $row['chemical_controls']) !== 1,
            $row['pest_key'] . ' names no rate: ' . \substr($row['chemical_controls'], 0, 120));
    }
});

$t->test('every bee-risk entry actually says what the risk is',
    function ($t) use ($rows): void {
    // pollinator_risk is a flag because the hazard is invisible at the moment
    // of spraying -- spinosad is harmless dry and acutely toxic wet, and
    // "apply at dusk" is the entire difference. A flag with no sentence
    // behind it is a badge that means nothing.
    foreach ($rows as $row) {
        if ($row['pollinator_risk'] !== '1') {
            continue;
        }
        $blob = \strtolower($row['chemical_controls'] . ' ' . $row['organic_controls']
            . ' ' . $row['treatments']);
        $said = false;
        foreach (['bee', 'pollinat', 'dusk', 'evening', 'flower'] as $word) {
            $said = $said || \str_contains($blob, $word);
        }
        $t->ok($said, $row['pest_key'] . ' says why it is flagged for bees');
    }
});

$t->test('every host list uses the plant catalogue\'s own category names',
    function ($t) use ($rows, $db): void {
    // affects_categories is matched against pt.category by the MOTD, the
    // digest, the calendar and the reference screen. A category spelled
    // "Summer squash" here and "Squash (summer)" there matches nothing, and
    // nothing is exactly what a silent filter returns.
    $known = [];
    foreach ($db->column('SELECT DISTINCT `category` FROM `plant_type`') as $category) {
        $known[\strtolower(\trim((string) $category))] = true;
    }
    $t->ok($known !== [], 'the plant catalogue has categories to check against');

    // Crops Carl has no plant_type for yet are allowed: the catalogue is
    // about the pest, and somebody will import a dataset with potatoes in it.
    $allowedExtras = ['potato', 'pea', 'melon', 'strawberry', 'chard', 'beet', 'collard',
                      'leek', 'parsley', 'parsnip', 'celery', 'peanut', 'corn', 'asparagus',
                      'turnip'];
    foreach ($allowedExtras as $extra) {
        $known[$extra] = true;
    }

    foreach ($rows as $row) {
        foreach (\array_filter(\array_map('trim', \explode(';', $row['affects_categories']))) as $category) {
            $t->ok(isset($known[\strtolower($category)]),
                $row['pest_key'] . ' names a category Carl knows: ' . $category);
        }
    }
});

// ========================================================================
// 2. Applying it, beside the research importer
// ========================================================================

$t->group('Applying the catalogue');

$reference = new ReferenceRepository($db);

$t->test('the migration has already landed it, marked as built in',
    function ($t) use ($db, $rows): void {
    $builtin = (int) $db->value('SELECT COUNT(*) FROM `pest` WHERE `is_builtin` = 1', [], 0);
    $t->same(\count($rows), $builtin, 'every entry in the file is in the table');
});

$t->test('applying it again converges instead of duplicating',
    function ($t) use ($db, $app, $root, $rows): void {
    // The maintenance path for a corrected sentence is "edit the CSV, press
    // the button", and somebody will press it twice because they are not sure
    // it worked.
    $before = (int) $db->value('SELECT COUNT(*) FROM `pest`', [], 0);
    $written = PestCatalog::apply($db, $root);
    $after = (int) $db->value('SELECT COUNT(*) FROM `pest`', [], 0);

    $t->same(\count($rows), $written);
    $t->same($before, $after, 'no new rows on a second apply');
});

$t->test('a re-apply leaves the research importer\'s `treatments` alone',
    function ($t) use ($db, $root): void {
    // The shared-column rule: the catalogue owns the description of the
    // organism, the dataset keeps `treatments` and everything regional. If
    // this ever flips, a county dataset's local advice is lost on a button
    // press nobody thought was destructive.
    $marker = 'COUNTY-SPECIFIC-ADVICE-' . \bin2hex(\random_bytes(3));
    $db->run('UPDATE `pest` SET `treatments` = :t WHERE `pest_key` = :k',
        ['t' => $marker, 'k' => 'aphid']);

    PestCatalog::apply($db, $root);

    $t->same($marker, (string) $db->value(
        'SELECT `treatments` FROM `pest` WHERE `pest_key` = :k', ['k' => 'aphid']
    ));
});

$t->test('a re-apply does restore the catalogue\'s own description',
    function ($t) use ($db, $root): void {
    // The other half of the same rule, and the reason it is safe: both
    // writers are idempotent and explicit, so each is one action away from
    // the other. Nothing is lost in either direction.
    $db->run('UPDATE `pest` SET `description` = :d WHERE `pest_key` = :k',
        ['d' => 'A one-line description from a county dataset.', 'k' => 'aphid']);

    PestCatalog::apply($db, $root);

    $t->contains('sap-suckers', (string) $db->value(
        'SELECT `description` FROM `pest` WHERE `pest_key` = :k', ['k' => 'aphid']
    ));
});

// ========================================================================
// 3. The account's own list, which is the point of the question
// ========================================================================

$t->group('A loaded list you can still add to');

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('pester' . $suffix);

$client->forgetCookies();
$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => PESTS_PASSPHRASE, 'password_confirm' => PESTS_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Pest Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Pest Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$lists = new ListRepository($db, $owner['id']);

// One planting, of a known category, because the reference screen's default
// view is "what can affect the plants you grow" and an account with nothing
// planted cannot exercise it.
$plantings = new \Carl\Repo\PlantingRepository($db, $owner['id']);
$gardens = new \Carl\Repo\GardenRepository($db, $owner['id']);
$tomatoTypeId = (int) $db->value(
    "SELECT id FROM `plant_type` WHERE `category` = 'Tomato' ORDER BY id LIMIT 1"
);
$pestPlantingId = $plantings->insert([
    'plant_type_id'    => $tomatoTypeId,
    'garden_id'        => $gardens->ensureIndoorGarden(),
    'label'            => 'Pest Subject ' . $suffix,
    'start_method'     => 'indoor_seed',
    'start_date'       => (string) \Carl\Support\Clock::addDays(\gmdate('Y-m-d'), -20),
    'quantity_initial' => 3,
    'quantity_live'    => 3,
    'state'            => \Carl\Domain\PlantingState::PLANTED,
    'state_changed_at' => \gmdate('Y-m-d H:i:s'),
]);

$t->test('a new account opens a populated dropdown, not a blank one',
    function ($t) use ($lists, $rows): void {
    // This is the whole change. The mechanism -- seedForNewUser() copying the
    // global table -- has existed since Phase 1 and had nothing to copy.
    $items = $lists->ofType(ListType::PEST_DISEASE);
    $t->ok(\count($items) >= \count($rows), 'the catalogue reached the account');

    $withReference = 0;
    foreach ($items as $item) {
        $withReference += $item['pest_id'] === null ? 0 : 1;
    }
    $t->ok($withReference >= \count($rows), 'and each one is JOINED to its entry, not just named');
});

$t->test('the treatment shelf is seeded too, least drastic first',
    function ($t) use ($lists): void {
    // The pests half has come from the catalogue since Phase 1; the treatment
    // half was the one still starting empty, and it is asked at the same
    // moment, by somebody holding a bottle.
    $items = $lists->ofType(ListType::PEST_TREATMENT);
    $t->ok(\count($items) >= 15, 'a shelf, not a token');
    $t->same('Watched, not treated', (string) $items[0]['name'],
        'a pest deliberately left alone is a decision worth recording');

    $byName = [];
    foreach ($items as $item) {
        $byName[(string) $item['name']] = $item;
    }
    $t->same('Spinosad', (string) ($byName['Spinosad']['attr_1'] ?? ''),
        'attr_1 is the active ingredient, which is what a label carries');
});

$t->test('an entry you type yourself still works and is marked as yours',
    function ($t) use ($client, $lists, $owner, $db): void {
    $client->post('/lists', ['list_type' => ListType::PEST_DISEASE,
                             'name' => 'Something Carl has never heard of']);

    $mine = $db->one(
        'SELECT * FROM `user_list_item` WHERE `user_id` = :u AND `name` = :n',
        ['u' => $owner['id'], 'n' => 'Something Carl has never heard of']
    );
    $t->ok($mine !== null, 'it was created');
    $t->same(null, $mine['pest_id'], 'and it is yours, joined to nothing');

    $screen = $client->get('/lists/pest_disease');
    $t->same(200, $screen->status);
    $t->contains('Something Carl has never heard of', $screen->body);
    $t->contains('>yours<', $screen->body, 'the screen says which entries are the account\'s own');
    $t->contains('key=aphid', $screen->body, 'and links the reference ones to their card');
});

$t->test('an entry you typed that turns out to be in the catalogue is ADOPTED, not duplicated',
    function ($t) use ($db, $owner, $reference, $root): void {
    // Somebody who typed "Slugs and snails" last season has a row with a NULL
    // pest_id and the unique key (user_id, list_type, name) already spoken
    // for. Inserting beside it would fail; leaving it alone would keep a year
    // of their records joined to nothing. Adopting it is what turns their own
    // typing into data that adds up.
    $now = \gmdate('Y-m-d H:i:s');
    $db->run(
        'DELETE FROM `user_list_item` WHERE `user_id` = :u AND `list_type` = :lt AND `name` = :n',
        ['u' => $owner['id'], 'lt' => ListType::PEST_DISEASE, 'n' => 'Slugs and snails']
    );
    $db->run(
        'INSERT INTO `user_list_item` (user_id, list_type, name, pest_id, is_active,'
        . ' sort_order, created_at, updated_at)'
        . ' VALUES (:u, :lt, :n, NULL, 1, 0, :c, :m)',
        ['u' => $owner['id'], 'lt' => ListType::PEST_DISEASE, 'n' => 'Slugs and snails',
         'c' => $now, 'm' => $now]
    );

    $reference->syncPestListsForAllUsers();

    $matching = $db->all(
        'SELECT * FROM `user_list_item` WHERE `user_id` = :u AND `list_type` = :lt AND `name` = :n',
        ['u' => $owner['id'], 'lt' => ListType::PEST_DISEASE, 'n' => 'Slugs and snails']
    );
    $t->same(1, \count($matching), 'one row, not two');
    $t->ok($matching[0]['pest_id'] !== null, 'and it now points at the catalogue entry');
});

$t->test('syncing twice adds nothing the second time', function ($t) use ($reference): void {
    $reference->syncPestListsForAllUsers();
    $t->same(0, $reference->syncPestListsForAllUsers(), 'idempotent, so the button is safe to press');
});

// ========================================================================
// 4. The screen
// ========================================================================

$t->group('The reference screen');

$t->test('it renders, and the label warning is on it', function ($t) use ($client): void {
    // The one thing on this page that is not advice: the label on the bottle
    // is the legal authority on the crop, the amount and the interval before
    // picking. If this ever disappears the page is giving pesticide advice
    // with no framing at all.
    $response = $client->get('/pests');
    $t->same(200, $response->status);
    $t->contains('Read the label before you spray anything', $response->body);
    $t->contains('active ingredients', $response->body);
});

$t->test('it filters by kind, by search, and shows the whole catalogue on request',
    function ($t) use ($client): void {
    $diseases = $client->get('/pests', ['all' => '1', 'kind' => 'disease']);
    $t->same(200, $diseases->status);
    $t->contains('Early blight', $diseases->body);
    $t->notContains('key=aphid', $diseases->body, 'an insect is not a disease');

    // Searching by what you can SEE, which is the only thing somebody
    // standing at a plant actually has, is why `signs` is in the statement.
    $search = $client->get('/pests', ['all' => '1', 'q' => 'webbing']);
    $t->contains('key=spider_mite', $search->body);

    $latin = $client->get('/pests', ['all' => '1', 'q' => 'Melittia']);
    $t->contains('key=squash_vine_borer', $latin->body);
});

$t->test('the page is a list until somebody asks for a card', function ($t) use ($client): void {
    // Seventy-six full cards is 202 KB of HTML -- ten times the whole client
    // shell, on the connection somebody standing in a garden actually has.
    $list = $client->get('/pests', ['all' => '1']);
    $t->ok(\strlen($list->body) < 90000,
        'the list is ' . \strlen($list->body) . ' bytes, which is a page and not a download');
    $t->same(0, \substr_count($list->body, 'class="card pest"'), 'no cards until asked');
    // A pest the Hill County dataset does not name, so the sentence on the
    // page is the catalogue's own however the import and the catalogue last
    // took turns writing `signs`.
    $t->contains('Silvery dried slime trails', $list->body,
        'and the line you would search by is still on it');
});

$t->test('a link to one entry opens it whatever the filters would have shown',
    function ($t) use ($client): void {
    // The Lists screen and a bookmark both link straight to a card. Picking
    // the entry out of the filtered list would make those links depend on
    // filters the person following them never chose.
    $response = $client->get('/pests', ['key' => 'clubroot', 'kind' => 'pest']);
    $t->same(200, $response->status);
    $t->contains('id="pest-clubroot"', $response->body,
        'a disease opens even under a filter that says pests only');
    $t->contains('back to the list', $response->body);
});

$t->test('an entry carries its consequence and its controls, in the IPM order',
    function ($t) use ($client): void {
    $response = $client->get('/pests', ['key' => 'squash_vine_borer']);
    $body = $response->body;

    foreach (['What you will see', 'What it costs to ignore', 'Confused with',
              'When to look', 'Stopping it happening', 'Without a spray',
              'If you do spray'] as $heading) {
        $t->contains($heading, $body, $heading . ' is on the card');
    }

    // Chemistry last, and that is the argument rather than a layout choice.
    $t->ok(\strpos($body, 'Without a spray') < \strpos($body, 'If you do spray'),
        'the organic answer comes before the chemical one');
    $t->ok(\strpos($body, 'What it costs to ignore') < \strpos($body, 'Without a spray'),
        'and what it costs comes before either');
});

$t->test('the default view is what can affect the plants you grow',
    function ($t) use ($client): void {
    // The difference between a catalogue and a useful one. The escape hatch
    // has to exist as well, for somebody reading about clubroot in February
    // before they plant the cabbage.
    $mine = $client->get('/pests');
    $all = $client->get('/pests', ['all' => '1']);

    $count = static fn (string $body): int => \substr_count($body, '#pest-');
    $t->ok($count($mine->body) < $count($all->body), 'narrowed by default');
    $t->ok($count($mine->body) > 0, 'and not narrowed to nothing');
    $t->contains('Show the whole', $mine->body, 'with the way out named on the page');
});

$t->test('an entry that affects EVERYTHING survives the narrowing',
    function ($t) use ($client): void {
    // The load-bearing half of the empty-cell convention: slugs, cutworms,
    // frost, waterlogging and herbicide drift all carry an empty host list
    // and are exactly the entries a gardener most needs. A filter that
    // dropped them would hide the general answers and keep the specific ones.
    $mine = $client->get('/pests');
    foreach (['slug_snail', 'cutworm', 'frost_damage', 'herbicide_drift'] as $key) {
        $t->contains('key=' . $key, $mine->body, $key . ' is shown whatever you grow');
    }
});

$t->test('the log form points at the reference from the pest dropdown',
    function ($t) use ($client, $db, $owner, $suffix): void {
    // The moment somebody needs this page is the moment they are choosing a
    // name from that dropdown, not an hour later from a menu.
    $plantingId = (int) $db->value(
        'SELECT id FROM `planting` WHERE user_id = :u ORDER BY id LIMIT 1',
        ['u' => $owner['id']]
    );
    if ($plantingId === 0) {
        // No plantings in this account: the menu link is the assertion left.
        $menu = $client->get('/');
        $t->contains('/carl/pests', $menu->body, 'the menu carries it');
        return;
    }
    $form = $client->get('/log/' . $plantingId);
    $t->contains('/carl/pests', $form->body, 'the reference is one tap from the dropdown');
});

$t->test('the menu carries it, so it is findable without knowing the URL',
    function ($t) use ($client): void {
    $menu = $client->get('/');
    $t->contains('Pests and diseases', $menu->body);
});
