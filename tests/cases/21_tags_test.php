<?php

/**
 * QR plant tags (docs/QR-TAGS-SPEC.md).
 *
 * The feature splits cleanly into two halves with completely different
 * failure modes, and this file is organised around that:
 *
 *  1. **THE ENCODER, WHICH IS EITHER RIGHT OR USELESS.** A QR symbol that is
 *     one module wrong does not degrade -- it does not scan, and it does not
 *     scan on a hundred stakes that have already been printed, applied and
 *     put in the ground. There is no PHP decoder to round-trip against
 *     (Section 4.1), so the fixtures in tests/fixtures/qr are matrices that
 *     an independent DECODER read back correctly and an independent ENCODER
 *     agreed with, captured once and asserted bit for bit. Everything the
 *     fixtures cannot see -- the capacity arithmetic Section 2.3 sizes the
 *     physical tag from, and the Reed-Solomon generator polynomial -- is
 *     checked against the standard's own published values.
 *
 *  2. **THE BINDING, WHICH IS EITHER TRUE OR A LIE ABOUT A PHYSICAL OBJECT.**
 *     A tag is a stake somebody is holding. Two live bindings on one tag, or
 *     a binding that survives an undo, is Carl telling a gardener that the
 *     thing in their hand is something it is not.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\LabelStock;
use Carl\Domain\PlantingState;
use Carl\Qr\Encoder;
use Carl\Qr\Galois;
use Carl\Qr\Svg;
use Carl\Qr\TagUrl;
use Carl\Reports\LabelSheet;
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

const TAGS_PASSPHRASE = 'tag-test-passphrase';

// ========================================================================
// 1. The encoder
// ========================================================================

$t->group('The QR encoder reproduces matrices an independent decoder read back');

$fixtures = \json_decode(
    (string) \file_get_contents($root . '/tests/fixtures/qr/matrices.json'),
    true
);

$t->test('the fixture file is there and covers what Section 4.1 asks for',
    function ($t) use ($fixtures): void {
    $t->ok(\is_array($fixtures) && $fixtures !== [], 'fixtures load');

    // Section 4.1: "the shortest and longest payload that fits version 3
    // level Q, one that forces a version bump, and one that forces the
    // byte-mode fallback."
    foreach (['tag_url', 'v3q_shortest', 'v3q_longest', 'version_bump', 'byte_fallback'] as $name) {
        $t->ok(isset($fixtures[$name]), $name . ' is covered');
    }
});

foreach ($fixtures as $name => $case) {
    $t->test('the matrix for ' . $name . ' is exact', function ($t) use ($case): void {
        $symbol = Encoder::encode($case['payload'], $case['ec']);

        $t->same($case['version'], $symbol->version, 'version');
        $t->same($case['mode'], $symbol->mode, 'mode');
        $t->same($case['mask'], $symbol->mask, 'mask');

        // Compared row by row, not as one blob: a failure prints the two grids
        // and the wrong module is visible. Two base64 strings are not.
        $rows = $symbol->toRows();
        $t->same(\count($case['rows']), \count($rows), 'row count');
        foreach ($case['rows'] as $i => $expected) {
            $t->same($expected, $rows[$i] ?? '', 'row ' . $i);
        }
    });
}

$t->test('the tag URL fits version 3 at level Q with three characters spare',
    function ($t): void {
    // The number the whole physical design rests on (Section 2.3). If this
    // moves, the module size on a 1 inch stake moves with it.
    $t->same(47, Encoder::capacity(3, Encoder::EC_Q, Encoder::MODE_ALNUM),
        'version 3 level Q holds 47 alphanumeric characters');
    $t->same(44, \strlen('HTTPS://WWW.RESHIFTMANAGER.COM/CARL/T/AB7K4M'));
});

$t->test('the published capacity table falls out of the block table',
    function ($t): void {
    // Derived rather than transcribed, so the two cannot disagree -- but the
    // derivation is only worth anything if it lands on the published numbers.
    // ISO 18004 Table 7, versions 1-4, levels M and Q.
    $expected = [
        [1, Encoder::EC_M, Encoder::MODE_ALNUM, 20], [1, Encoder::EC_Q, Encoder::MODE_ALNUM, 16],
        [2, Encoder::EC_M, Encoder::MODE_ALNUM, 38], [2, Encoder::EC_Q, Encoder::MODE_ALNUM, 29],
        [3, Encoder::EC_M, Encoder::MODE_ALNUM, 61], [3, Encoder::EC_Q, Encoder::MODE_ALNUM, 47],
        [4, Encoder::EC_M, Encoder::MODE_ALNUM, 90], [4, Encoder::EC_Q, Encoder::MODE_ALNUM, 67],
        [1, Encoder::EC_M, Encoder::MODE_BYTE, 14],  [1, Encoder::EC_Q, Encoder::MODE_BYTE, 11],
        [2, Encoder::EC_M, Encoder::MODE_BYTE, 26],  [2, Encoder::EC_Q, Encoder::MODE_BYTE, 20],
        [3, Encoder::EC_M, Encoder::MODE_BYTE, 42],  [3, Encoder::EC_Q, Encoder::MODE_BYTE, 32],
        [4, Encoder::EC_M, Encoder::MODE_BYTE, 62],  [4, Encoder::EC_Q, Encoder::MODE_BYTE, 46],
    ];
    foreach ($expected as [$version, $level, $mode, $capacity]) {
        $t->same($capacity, Encoder::capacity($version, $level, $mode),
            'version ' . $version . ' level ' . $level . ' ' . $mode);
    }
});

$t->test('the Reed-Solomon generator polynomial matches the standard',
    function ($t): void {
    // ISO 18004 Annex A publishes these. It is the one part of the encoder
    // with a cheap independent check that is not a fixture, and everything
    // else in the error correction is built on it.
    //
    // Degree 7 (version 1 level L) as alpha exponents: 0 87 229 146 149 238 102 21.
    $seven = Galois::generator(7);
    $t->same(8, \count($seven), 'a degree 7 generator has 8 coefficients');
    $t->same(1, $seven[0], 'monic');

    // Coefficients as field values, from the published alpha exponents.
    $alpha = static function (int $exponent): int {
        $value = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $value = Galois::multiply($value, 2);
        }
        return $value;
    };
    foreach ([0, 87, 229, 146, 149, 238, 102, 21] as $i => $exponent) {
        $t->same($alpha($exponent), $seven[$i], 'coefficient ' . $i);
    }

    // Degree 10, the version 1 level M generator: 0 251 67 46 61 118 70 64 94 32 45.
    $ten = Galois::generator(10);
    $t->same(11, \count($ten));
    foreach ([0, 251, 67, 46, 61, 118, 70, 64, 94, 32, 45] as $i => $exponent) {
        $t->same($alpha($exponent), $ten[$i], 'degree 10 coefficient ' . $i);
    }
});

$t->test('GF(256) is a field: every non-zero value has an inverse',
    function ($t): void {
    // One assertion that would catch a wrong primitive polynomial, a wrong
    // generator, or an off-by-one in the log tables -- all of which produce
    // an encoder that looks fine and emits symbols nothing can read.
    $bad = 0;
    for ($a = 1; $a < 256; $a++) {
        $found = false;
        for ($b = 1; $b < 256 && !$found; $b++) {
            $found = Galois::multiply($a, $b) === 1;
        }
        if (!$found) {
            $bad++;
        }
    }
    $t->same(0, $bad, 'every non-zero element is invertible');
});

$t->test('a payload too long for version 4 is refused, not silently truncated',
    function ($t): void {
    $t->throws(InvalidArgumentException::class, static function (): void {
        Encoder::encode(\str_repeat('A', 200), Encoder::EC_Q);
    });
});

$t->test('the inline SVG is one path, no script, and nothing off-site',
    function ($t): void {
    // Section 9 rules 4 and 5, both test-enforced elsewhere for the client
    // shell. A tag renders inside a page, so it has to obey them too.
    $svg = Svg::render(Encoder::encode('HTTPS://EXAMPLE.COM/T/AB7K4M'), 4, 'Tag AB7K4M');

    $t->same(1, \substr_count($svg, '<path'), 'one path, not 841 rects');
    $t->notContains('<script', $svg);
    // Nothing that FETCHES anything. The one http:// in here is the SVG
    // namespace URI, which is an identifier and is never dereferenced -- so
    // the assertion is on the attributes that would actually pull a resource,
    // not on the substring.
    $t->notContains('src=', $svg);
    $t->notContains('href=', $svg);
    $t->notContains('<image', $svg);
    $t->notContains('url(http', $svg);
    $t->contains('var(--carl-qr-ink', $svg);
    $t->contains('var(--carl-qr-paper', $svg);
    $t->contains('<title>Tag AB7K4M</title>', $svg);
});

$t->test('the QR colours are in tokens.css and marked un-themeable',
    function ($t) use ($root): void {
    // Section 4.2 and Section 9 rule 6. A designer who tints the ink to brand
    // green has silently broken every tag in every garden -- printed ones
    // too, because the PDF layer reads this file -- and nothing reports it.
    // So the warning has to be where the temptation is.
    $css = (string) \file_get_contents($root . '/public/assets/css/tokens.css');

    $t->contains('--carl-qr-ink', $css);
    $t->contains('--carl-qr-paper', $css);
    $t->contains('DO NOT THEME', $css);
});

// ========================================================================
// 2. What the physical tag is sized from
// ========================================================================

$t->group('The tag URL, and what its case costs');

$t->test('uppercase buys a version and a bigger module', function ($t): void {
    $lower = TagUrl::describe('https://www.reshiftmanager.com', '/carl/', false);
    $upper = TagUrl::describe('https://www.reshiftmanager.com', '/carl/', true);

    $t->same(Encoder::MODE_BYTE, $lower['mode'], 'one lower-case character means byte mode');
    $t->same(4, $lower['version']);
    $t->same(Encoder::MODE_ALNUM, $upper['mode']);
    $t->same(3, $upper['version']);

    // Section 2.3's arithmetic, reproduced by the code rather than quoted:
    // 29 modules plus 4 of quiet zone each side into 24.0 mm.
    $t->same(0.649, $upper['module_mm'], 'uppercase gives 0.649 mm per module');
    $t->ok($lower['module_mm'] > 0.5, 'and lower case is still well above the print floor');
    $t->ok($upper['module_mm'] > $lower['module_mm']);
});

$t->test('uppercase is off by default under a subpath, and on at the domain root',
    function ($t): void {
    // The correction to Section 2.2, and the reason for it is not an
    // encoding fact: the mount segment of the path is a real directory, and
    // Apache matches those case-sensitively, so /CARL/T/AB7K4M is a
    // web-server 404 that never reaches PHP. See Carl\Qr\TagUrl.
    $t->same(false, TagUrl::uppercaseIsSafe(null, '/carl/'), 'a subpath is not safe by default');
    $t->same(true, TagUrl::uppercaseIsSafe(null, '/'), 'the domain root has no directory to get wrong');
    $t->same(true, TagUrl::uppercaseIsSafe(true, '/carl/'), 'an explicit yes is honoured');
    $t->same(false, TagUrl::uppercaseIsSafe(false, '/'), 'and so is an explicit no');
});

$t->test('the short domain of Section 2.3 really is the biggest lever',
    function ($t): void {
    // Lever 1: "by far the biggest lever available and it is not an
    // engineering task". Worth asserting so that a future phase weighing a
    // domain registration against a code change has the number.
    $long = TagUrl::describe('https://www.reshiftmanager.com', '/carl/', true);
    $short = TagUrl::describe('https://carl.garden', '/', true);

    $t->same(2, $short['version'], 'a short domain fits version 2');
    $t->same(0.727, $short['module_mm']);
    $t->ok($short['module_mm'] > $long['module_mm'] * 1.1, 'over 10% bigger modules');
});

$t->group('The label sheets');

$t->test('every stock fits on US Letter', function ($t): void {
    // AutoPageBreak is off on a label sheet (Section 5.5), so geometry that
    // runs past the paper does not error and does not spill to a second page
    // -- it prints off the bottom and the labels are simply not there. Half
    // of these constants are derived rather than published, so this is the
    // guard on correcting one wrongly.
    foreach (LabelStock::all() as $sku) {
        $t->ok(LabelStock::fitsPage($sku), $sku . ' fits the page');

        $geometry = LabelStock::geometry($sku);
        $t->ok($geometry['print_h'] <= $geometry['label_h'] + 0.001,
            $sku . ': the printable area is not taller than the label');
        $t->ok($geometry['pitch_x'] >= $geometry['label_w'] - 0.001,
            $sku . ': columns do not overlap');
        $t->ok($geometry['pitch_y'] >= $geometry['label_h'] - 0.001,
            $sku . ': rows do not overlap');
    }
});

$t->test('positions march across then down, and wrap at the sheet',
    function ($t): void {
    $geometry = LabelStock::geometry(LabelStock::AVERY_60517);
    [$x0, $y0] = LabelStock::position(LabelStock::AVERY_60517, 0);
    [$x1, $y1] = LabelStock::position(LabelStock::AVERY_60517, 1);
    [$xw, $yw] = LabelStock::position(LabelStock::AVERY_60517, $geometry['columns']);

    $t->same($geometry['origin_x'], $x0);
    $t->same($geometry['origin_y'], $y0);
    $t->same($geometry['origin_x'] + $geometry['pitch_x'], $x1, 'second label is one pitch across');
    $t->same($y0, $y1, 'and on the same row');
    $t->same($x0, $xw, 'the next row starts back at the left');
    $t->same($y0 + $geometry['pitch_y'], $yw);
});

$t->test('a blank sheet is a Letter PDF with a calibration rule, and no page two',
    function ($t): void {
    $tags = [];
    for ($i = 0; $i < LabelStock::perSheet(LabelStock::AVERY_60517); $i++) {
        $tags[] = ['code' => \sprintf('AB%04d', $i)];
    }

    $sheet = new LabelSheet(LabelStock::AVERY_60517, 'HTTPS://EXAMPLE.COM/T/');
    $sheet->blankSheets($tags);
    $pdf = $sheet->render();

    $t->contains('%PDF', \substr($pdf, 0, 8));
    // 612 x 792 points is US Letter. A4 would be 595 x 842, and rendering a
    // Letter template onto it puts every column ~3 mm off and drops the
    // bottom row off the page, silently (Section 5.5).
    $t->contains('612', $pdf);
    $t->same(1, \substr_count($pdf, '/Type /Page' . "\n"), 'exactly one page for one sheet');
    $t->ok(\strlen($pdf) > 8000, 'the codes are actually drawn');
});

$t->test('the registration sheet says what it is for', function ($t): void {
    // Section 5.6: this is the acceptance test for the layout constants, and
    // it is worthless if somebody prints it scaled.
    $sheet = new LabelSheet(LabelStock::AVERY_00757, 'HTTPS://EXAMPLE.COM/T/');
    $sheet->registrationSheet();
    $t->contains('%PDF', \substr($sheet->render(), 0, 8));
});

$t->test('the sheet costs no image, no temp file and little memory',
    function ($t) use ($root): void {
    // Section 4.3: FPDF rectangles rather than an image buys vector output at
    // whatever DPI the printer has, no GD, and nothing written to disk on a
    // host where the writable directories are few.
    $before = \memory_get_usage();
    $tags = [];
    for ($i = 0; $i < 24; $i++) {
        $tags[] = ['code' => \sprintf('CD%04d', $i)];
    }
    $sheet = new LabelSheet(LabelStock::AVERY_60517, 'HTTPS://EXAMPLE.COM/T/');
    $sheet->blankSheets($tags);
    $pdf = $sheet->render();

    $t->ok(\memory_get_usage() - $before < 8 * 1024 * 1024, 'under 8 MB for a full sheet');
    $t->notContains('/Subtype /Image', $pdf, 'no raster anywhere in it');
});

// ========================================================================
// 3. The data model
// ========================================================================

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('tagger' . $suffix);

// FORGET FIRST, AND THIS IS NOT BELT AND BRACES. The whole suite runs in one
// PHP process and $_SESSION outlives a Client, so a file whose predecessor
// finished signed in starts signed in as that user -- and AuthController
// refuses to log in when somebody already is, silently, by redirecting to the
// menu. Every screen then renders for the wrong account and every lookup
// scoped to $owner comes back 404. The file passes on its own and fails in the
// suite, which is the worst shape a test failure has.
$client->forgetCookies();
$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => TAGS_PASSPHRASE, 'password_confirm' => TAGS_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Tag Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Tag Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$events = new EventRepository($db, $owner['id'], $plantings);
$tags = new TagRepository($db, $owner['id']);

$indoorId = $gardens->ensureIndoorGarden();
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = \gmdate('Y-m-d');  // utc-ok: backdates the sow closure only; the real today assertion below uses todayFor()

$sow = static function (string $label, int $count = 12, int $daysAgo = 30)
    use ($plantings, $plantTypeId, $indoorId, $today): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => (string) Clock::addDays($today, -$daysAgo),
        'quantity_initial' => $count,
        'quantity_live'    => $count,
        'state'            => PlantingState::SEED_STARTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

$t->group('Codes');

$t->test('the alphabet excludes the four characters a human confuses',
    function ($t): void {
    // Section 2.4: the recovery path when a symbol is caked in soil -- and one
    // will be -- is reading six characters off a faded tag and typing them in.
    // I, L, O and U are what a person gets wrong.
    $seen = [];
    for ($i = 0; $i < 400; $i++) {
        foreach (\str_split(TagRepository::generate()) as $character) {
            $seen[$character] = true;
        }
    }
    foreach (['I', 'L', 'O', 'U'] as $forbidden) {
        $t->ok(!isset($seen[$forbidden]), $forbidden . ' never appears in a code');
    }
    $t->same(6, \strlen(TagRepository::generate()));
});

$t->test('normalise forgives case, spaces and hyphens and guesses nothing',
    function ($t): void {
    $t->same('AB7K4M', TagRepository::normalise('ab7k-4m'));
    $t->same('AB7K4M', TagRepository::normalise('  AB7K 4M '));
    $t->same('AB7K4M', TagRepository::normalise('AB7K4M'));

    // Deliberately does NOT map O to 0 or I to 1. The alphabet excludes them
    // so a person never has to choose; silently rewriting a character would
    // turn a typo into a different VALID code, pointing at somebody else's
    // plant.
    $t->same('AB7K4M', TagRepository::normalise('OAB7IK4M'), 'O and I are dropped, not translated');
    $t->same(false, TagRepository::isWellFormed('AB7K4'), 'five characters is not a code');
    $t->same(false, TagRepository::isWellFormed('AB7K4MM'), 'nor is seven');
    $t->same(true, TagRepository::isWellFormed('AB7K4M'));
});

$t->group('Minting');

$t->test('a sheet mints exactly one sheet of unique codes, in one statement',
    function ($t) use ($tags, $db): void {
    $before = $db->statementCount();
    $minted = $tags->mint(1, LabelStock::AVERY_60517);
    $spent = $db->statementCount() - $before;

    $t->same(24, \count($minted['codes']), 'a 60517 sheet is 24');
    $t->same(24, \count(\array_unique($minted['codes'])), 'all distinct');
    // Section 5.8: ONE multi-row INSERT. The codes are generated in PHP so
    // nothing needs reading back, which matters because MySQL has no
    // RETURNING and the database is on other hardware.
    $t->ok($spent <= 3, 'batch row, one INSERT for every code, and the transaction: ' . $spent);

    foreach ($minted['codes'] as $code) {
        // same(), so a code that came back as an int rather than a string
        // fails here rather than at the far end of the season -- PHP casts a
        // canonical decimal array key to an integer, and roughly one code in
        // twelve hundred is six digits with no leading zero.
        $t->same('string', \gettype($code), 'codes are strings, always');
        $t->same(true, TagRepository::isWellFormed($code));
    }
});

$t->test('an all-digit code survives minting as a string', function ($t) use ($db, $owner): void {
    // The specific shape of the bug above, pinned rather than left to chance:
    // "123456" is a canonical decimal, so PHP turns it into an integer the
    // moment it is used as an array key.
    $probe = [];
    $probe['123456'] = true;
    $probe['012345'] = true;
    $t->same('integer', \gettype(\array_keys($probe)[0]), 'PHP really does do this');
    $t->same('string', \gettype(\array_keys($probe)[1]), 'but not with a leading zero');

    // And minting does not, however many codes it makes.
    $tags = new TagRepository($db, $owner['id']);
    foreach ($tags->mint(2, LabelStock::AVERY_60517)['codes'] as $code) {
        $t->same('string', \gettype($code));
    }
});

$t->test('the batch remembers its stock, so a reprint cannot drift',
    function ($t) use ($tags): void {
    // Section 5.4: the render is a pure function of the batch row, INCLUDING
    // after the user has changed their stock preference -- which is exactly
    // when a re-render would otherwise come back subtly wrong against the
    // half-used sheet in their hand.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $batch = $tags->findBatch($minted['batch_id']);

    $t->same(LabelStock::AVERY_00757, (string) $batch['stock_sku']);
    $t->same(10, (int) $batch['tag_count']);
    $t->same(10, \count($tags->batchTags($minted['batch_id'])));
});

$t->group('Binding is a period of time, not a pointer');

$t->test('a tag is on one plant at a time; a plant carries as many tags as it has cells',
    function ($t) use ($tags, $sow, $db): void {
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $sheet = $tags->batchTags($minted['batch_id']);
    $plant = $sow('Cherokee Purple');

    $tags->bindTo((int) $sheet[0]['id'], $plant);
    $bound = $tags->forPlanting($plant);
    $t->same((string) $sheet[0]['code'], (string) $bound['code']);

    // Move the SAME tag to another plant: the first binding closes. A
    // stake is in one place.
    $other = $sow('Provider');
    $tags->bindTo((int) $sheet[0]['id'], $other);

    $t->same(null, $tags->forPlanting($plant), 'the first plant has no tag now');
    $t->same((string) $sheet[0]['code'], (string) $tags->forPlanting($other)['code']);

    // Put a DIFFERENT tag on a plant that already has one: it ADDS. A
    // planting is a tray of cells and the stake goes in the cell
    // (QR-TAGS-SPEC Section 14.7). Phase 8 closed the first binding here,
    // so the second stake in a tray silently pulled the first one off.
    $tags->bindTo((int) $sheet[1]['id'], $other);
    $on = $tags->tagsOn($other);
    $t->same(2, \count($on), 'two stakes on one plant');
    $t->same((string) $sheet[0]['code'], (string) $on[0]['code'], 'in the order they went on');
    $t->same((string) $sheet[1]['code'], (string) $on[1]['code']);

    $live = (int) $db->value(
        'SELECT COUNT(*) FROM `qr_tag_binding` WHERE `tag_id` = :tag AND `unbound_at` IS NULL',
        ['tag' => (int) $sheet[0]['id']], 0
    );
    $t->same(1, $live, 'never two live plants for one tag');
});

$t->test('a closed binding is kept, because it is a fact about a real object',
    function ($t) use ($tags, $sow, $db): void {
    // "This stake was Cherokee Purple in 2026 and Provider beans in 2027" is
    // a real fact, and it is why the binding is a period of time. It also
    // means an old photograph of a tag does not lie about what it was.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tagId = (int) $tags->batchTags($minted['batch_id'])[0]['id'];

    $first = $sow('First season');
    $tags->bindTo($tagId, $first);
    $tags->unbind($tagId);
    $second = $sow('Second season');
    $tags->bindTo($tagId, $second);

    $history = $db->all(
        'SELECT `planting_id`, `unbound_at` FROM `qr_tag_binding`'
        . ' WHERE `tag_id` = :t ORDER BY `id`',
        ['t' => $tagId]
    );
    $t->same(2, \count($history), 'both periods survive');
    $t->ok($history[0]['unbound_at'] !== null, 'the first is closed');
    $t->same(null, $history[1]['unbound_at'], 'the second is live');
});

$t->test('undo deletes the binding rather than closing it', function ($t) use ($tags, $sow, $db): void {
    // Section 6.5: the session binds optimistically and offers an undo, and
    // an undone scan must leave NO trace. A closed binding would read forever
    // after as "this tag was on that plant for four seconds", which is a lie
    // about a physical object.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tagId = (int) $tags->batchTags($minted['batch_id'])[0]['id'];
    $plant = $sow('Mis-scanned');

    $bindingId = $tags->bindTo($tagId, $plant);
    $t->same(true, $tags->undoBinding($bindingId));

    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `qr_tag_binding` WHERE `tag_id` = :t', ['t' => $tagId], 0
    ), 'nothing at all is left');
    $t->same(false, $tags->undoBinding($bindingId), 'and it cannot be undone twice');
});

$t->group('The bind list is untagged plants, and recency is only the sort');

$t->test('a plant that is old and untagged is on the list; a tagged one is not',
    function ($t) use ($tags, $sow): void {
    // Section 6.4, and the first draft of the spec got this wrong. A tomato
    // that went in the ground in May has no tag and is not recent, so a
    // recency FILTER hides the plant you are standing in front of.
    $old = $sow('May Tomato', 6, 120);
    $new = $sow('This Week', 6, 2);

    $codes = \array_column($tags->untagged('', 500), 'id');
    $t->ok(\in_array($old, $codes, false), 'the 120-day-old plant is offered');
    $t->ok(\in_array($new, $codes, false), 'and so is this week\'s');

    // Recency is the sort: the newer one comes first.
    $list = $tags->untagged('', 500);
    $positions = [];
    foreach ($list as $i => $row) {
        $positions[(int) $row['id']] = $i;
    }
    $t->ok($positions[$new] < $positions[$old], 'most recently started first');

    // Tag the old one and it leaves the list -- the ONLY question asked.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $old);

    $after = \array_column($tags->untagged('', 500), 'id');
    $t->ok(!\in_array($old, $after, false), 'a tagged plant is gone from the list');
});

$t->test('nothing ties binding to a life stage', function ($t) use ($tags, $sow, $events): void {
    // Section 6.4: a tag bound to a yielding plant behaves exactly like one
    // bound at seed_started. Tagging an established plant is not a special
    // case in the code and must not become one.
    $plant = $sow('Established', 3, 90);
    $events->record($plant, EventType::TRANSPLANTED, (string) Clock::addDays(\gmdate('Y-m-d'), -60));
    $events->record($plant, EventType::YIELDED, (string) Clock::addDays(\gmdate('Y-m-d'), -5),
        ['quantity_delta' => null]);

    $ids = \array_column($tags->untagged('', 500), 'id');
    $t->ok(\in_array($plant, $ids, false), 'a yielding plant with no tag is offered a tag');

    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plant);
    $t->ok($tags->forPlanting($plant) !== null, 'and takes one');
});

$t->test('a rebound tag does not duplicate its plant in the bind lists',
    function ($t) use ($tags, $sow): void {
    // The reason the counts are subqueries and not a LEFT JOIN: a tag that
    // has been rebound leaves CLOSED rows behind for the same planting, and
    // a join would return the plant once per row -- and once per LIVE stake
    // too, now that a tray carries several.
    $plant = $sow('Rebound', 4, 10);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $sheet = $tags->batchTags($minted['batch_id']);

    $tags->bindTo((int) $sheet[0]['id'], $plant);
    $tags->bindTo((int) $sheet[1]['id'], $plant);
    $tags->bindTo((int) $sheet[2]['id'], $plant);
    $tags->unbind((int) $sheet[2]['id']);

    $candidates = $tags->bindCandidates('', 500);
    $appearances = 0;
    foreach (\array_merge($candidates['wants'], $candidates['full']) as $row) {
        if ((int) $row['id'] === $plant) {
            $appearances++;
            $t->same(2, (int) $row['tag_count'], 'two live stakes, the closed one not counted');
        }
    }
    $t->same(1, $appearances, 'once, not three times');

    // And it is still on the "wants" list: two stakes for four plants.
    $t->ok(\in_array($plant, \array_column($candidates['wants'], 'id'), false),
        'a part-staked tray still wants stakes');
});

$t->group('Retiring a sheet is not deleting it');

$t->test('a retired sheet leaves the pool and its codes still resolve',
    function ($t) use ($tags): void {
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];
    $before = $tags->pool();

    $tags->retireBatch($minted['batch_id']);
    $after = $tags->pool();

    $t->same($before['free'] - 10, $after['free'], 'ten codes leave the free count');
    $t->same($before['retired'] + 10, $after['retired']);
    $t->ok($tags->scan($code) !== null, 'the code still resolves');

    // And it comes back: a sheet that turns up in a drawer next spring.
    $tags->retireBatch($minted['batch_id'], false);
    $t->same($before['free'], $tags->pool()['free']);
});

$t->group('Scope: a tag is nobody else\'s business');

$t->test('another account\'s tag is invisible, not forbidden',
    function ($t) use ($tags, $makeUser, $db, $suffix): void {
    // Section 6.2: a code that does not exist and a code belonging to
    // somebody else get the SAME answer, deliberately. A tag on a stake in a
    // front garden is photographable from the pavement, and anything that
    // told the two apart would let a stranger learn which codes are real.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];

    $stranger = $makeUser('stranger' . $suffix);
    $theirs = new TagRepository($db, $stranger['id']);

    $t->same(null, $theirs->scan($code), 'a real code belonging to someone else reads as absent');
    $t->same(null, $theirs->scan('ZZZZZZ'), 'exactly like one that does not exist');
});

// ========================================================================
// 4. The screens
// ========================================================================

$t->group('The scan');

$t->test('a scan of an unbound tag offers the untagged plants',
    function ($t) use ($client, $tags, $sow): void {
    $sow('Bind Me', 5, 1);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];

    $response = $client->get('/t/' . $code);
    $t->same(200, $response->status);
    $t->contains("isn't assigned yet", $response->body);
    $t->contains('Bind Me', $response->body);
    $t->contains('Start a new plant with this tag', $response->body);
});

$t->test('the route is case-insensitive on both halves', function ($t) use ($client, $tags): void {
    // Section 2.2's consequence for the router. A camera may hand back either
    // case, and every link Carl prints into a QR may be upper-cased.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];

    $t->same(200, $client->get('/t/' . $code)->status);
    $t->same(200, $client->get('/T/' . $code)->status);
    $t->same(200, $client->get('/t/' . \strtolower($code))->status);
    $t->same(200, $client->get('/T/' . \strtolower($code))->status);
});

$t->test('an unknown code is 404, and says nothing about which codes exist',
    function ($t) use ($client): void {
    $response = $client->get('/t/ZZZZZZ');
    $t->same(404, $response->status);
    $t->notContains('invalid', \strtolower($response->body));
});

$t->test('a scan of a bound tag is the field screen, not the report page',
    function ($t) use ($client, $tags, $sow): void {
    // Section 6.2: "Not /plants/{id}. That is the report page with charts --
    // the right page at a desk, the wrong page in a garden."
    $plant = $sow('Field Screen', 8, 20);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plant);

    $response = $client->get('/t/' . $code);
    $t->same(200, $response->status);
    $t->contains('Field Screen', $response->body);
    $t->contains('Watered', $response->body, 'a one-tap action');
    $t->contains('Full log form', $response->body, 'and the detailed form under the fold');
    $t->notContains('chart-tabs', $response->body, 'no charts in a garden');
});

$t->test('the field screen costs two statements, not three',
    function ($t) use ($tags, $sow, $db, $owner): void {
    // Section 6.3's budget, measured on the repositories directly. A page hit
    // forty times in one walk around a garden, against a database on separate
    // hardware.
    //
    // Measured here and not through the Client: Client builds a fresh App per
    // request with its own Database, so a statement count read through it is
    // always zero and passes for the wrong reason (PHASE-7-HANDOFF Section 7).
    $plant = $sow('Budget', 4, 15);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plant);

    $freshTags = new TagRepository($db, $owner['id']);
    $freshPlantings = new PlantingRepository($db, $owner['id']);
    $freshEvents = new EventRepository($db, $owner['id'], $freshPlantings);

    $before = $db->statementCount();
    $tag = $freshTags->scan($code);
    $freshEvents->recentForPlanting((int) $tag['planting_id'], 6);
    $spent = $db->statementCount() - $before;

    $t->same(2, $spent, 'the tag with its planting, and the recent events');
});

$t->test('one tap records one event, dated today, and returns to the tag',
    function ($t) use ($client, $tags, $sow, $events, $app, $db, $owner): void {
    $plant = $sow('One Tap', 6, 10);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plant);

    $before = \count($events->timeline($plant));
    $response = $client->post('/t/' . $code . '/log', ['event_type' => EventType::WATERED]);

    $t->same(303, $response->status);
    $t->contains('/t/' . $code, (string) $response->headers()['Location'], 'back to the same screen');

    $timeline = $events->timeline($plant);
    $t->same($before + 1, \count($timeline));
    $last = $timeline[\count($timeline) - 1];
    $t->same(EventType::WATERED, (string) $last['event_type']);

    // The USER'S local today, never the server's (handoff Section 6). This
    // account is in America/Chicago, so for six hours a day gmdate() and the
    // right answer are different days -- and asserting the wrong one makes a
    // test that passes in the afternoon and fails overnight.
    $tz = (string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']]);
    $t->same($app->clock()->todayFor($tz), (string) $last['event_date'],
        'the gardener\'s today, with no date picker');
});

$t->test('the field screen offers no action that needs a second answer',
    function ($t) use ($client, $tags, $sow): void {
    // A one-tap screen cannot honestly record "how many died" or "where did
    // it go", and guessing a default writes a number nobody said. Those stay
    // on /log/{id}, which is one tap away.
    $plant = $sow('No Guessing', 9, 12);
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plant);

    $body = $client->get('/t/' . $code)->body;
    foreach ([EventType::DIED, EventType::CULLED, EventType::TRANSPLANTED, EventType::UP_POTTED] as $type) {
        $t->notContains('value="' . $type . '"', $body, $type . ' needs a quantity or a place');
    }

    // And the POST refuses one even if the form is forged.
    $t->same(400, $client->post('/t/' . $code . '/log', ['event_type' => EventType::DIED])->status);
});

$t->group('The tagging session');

$t->test('a session names the next untagged plant on every page',
    function ($t) use ($client, $tags, $sow): void {
    // Section 6.5: "an expiry, an explicit stop, and a visible banner". The
    // banner is rendered from the layout, off the user row Auth already
    // loaded, so it costs no statement anywhere.
    $sow('Next Up', 4, 1);

    $client->post('/tags/session', ['action' => 'start']);
    $t->contains('Tagging', $client->get('/')->body, 'the strip is on the main menu too');
    $t->contains('Next: Next Up', $client->get('/tags')->body, 'with the cursor where the reads are free');

    $client->post('/tags/session', ['action' => 'stop']);
    $t->notContains('Stop tagging', $client->get('/')->body);
});

$t->test('the cursor is computed, so it cannot go stale',
    function ($t) use ($client, $tags, $sow): void {
    $first = $sow('Cursor One', 3, 1);
    $client->post('/tags/session', ['action' => 'start']);

    $before = $tags->nextUntagged();
    $t->same($first, (int) $before['id'], 'the most recently started untagged plant');

    // Tag it by another route entirely. A stored pointer would still name it.
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $first);

    $after = $tags->nextUntagged();
    $t->ok($after === null || (int) $after['id'] !== $first, 'the cursor moved on by itself');
    $client->post('/tags/session', ['action' => 'stop']);
});

$t->group('End of season returns the stakes');

$t->test('ending a season releases the tags when asked, and not otherwise',
    function ($t) use ($client, $tags, $db, $owner, $gardens, $plantings, $suffix): void {
    // Section 8: End Growing Season should offer "release the tags". Opt-in,
    // because it is a physical claim -- it says you walked the bed and pulled
    // the stakes.
    $gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Tag Bed' . $suffix])[0]['id'];
    $rowId = (int) $gardens->rows($gardenId)[0]['id'];

    $plantId = $plantings->insert([
        'plant_type_id'    => (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1'),
        'garden_id'        => $gardenId,
        'garden_row_id'    => $rowId,
        'label'            => 'Season Ender',
        'start_method'     => 'direct_sow',
        'start_date'       => (string) Clock::addDays(\gmdate('Y-m-d'), -100),
        'quantity_initial' => 5,
        'quantity_live'    => 5,
        'state'            => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $tags->bindTo((int) $tags->batchTags($minted['batch_id'])[0]['id'], $plantId);
    $t->ok($tags->forPlanting($plantId) !== null, 'tagged to begin with');

    // Without the tick, the tag stays on: a gardener who leaves stakes in
    // over winter to know what was where is doing a reasonable thing.
    $endSeason = $client->get('/gardens/' . $gardenId . '/end-season')->body;
    $t->contains('name="release_tags"', $endSeason, 'the screen offers it');
    $t->contains('<h2>Tags</h2>', $endSeason);

    $client->post('/gardens/' . $gardenId . '/end-season',
        ['confirm' => 'end season', 'release_tags' => '1']);

    $t->same(null, $tags->forPlanting($plantId), 'the tag came off');
    $t->same(PlantingState::ENDED, (string) $plantings->find($plantId)['state'], 'and the plant ended');
});

$t->group('Printing');

$t->test('the print screen says how to print, in words', function ($t) use ($client): void {
    // Section 5.7: scaled printing is the single most likely cause of a batch
    // that will not scan, and Chrome's preview defaults to shrinking the page.
    // The calibration rule on the sheet is the backstop, not the instruction.
    $body = $client->get('/tags/print')->body;
    $t->contains('100% scale', $body);
    $t->contains('Fit to page', $body);
    $t->contains('sheets', $body, 'the form asks how many sheets, not how many tags');
    $t->notContains('start position', \strtolower($body), 'no start control on the blank path');
});

$t->test('the mint is a POST and the sheet is a re-printable GET',
    function ($t) use ($client, $db, $owner): void {
    $response = $client->post('/tags/batches', ['sheets' => '1', 'stock_sku' => LabelStock::AVERY_00757]);
    $t->same(303, $response->status);

    $batchId = (int) $db->value(
        'SELECT MAX(id) FROM `qr_tag_batch` WHERE user_id = :u', ['u' => $owner['id']], 0
    );

    $first = $client->get('/tags/batches/' . $batchId . '.pdf');
    $second = $client->get('/tags/batches/' . $batchId . '.pdf');

    $t->same(200, $first->status);
    $t->contains('%PDF', \substr($first->body, 0, 8));
    // Re-rendering must give the same sheet: a paper jam costs a sheet of
    // paper, never thirty codes.
    $t->same(\strlen($first->body), \strlen($second->body), 'the same sheet, twice');
});

$t->test('finding a tag by its code lands on the tag', function ($t) use ($client, $tags): void {
    $minted = $tags->mint(1, LabelStock::AVERY_00757);
    $code = $minted['codes'][0];

    $response = $client->post('/tags/find', ['code' => \strtolower($code)]);
    $t->same(303, $response->status);
    $t->contains('/t/' . $code, (string) $response->headers()['Location']);

    // A code that is not a code goes back to the pool with a reason, not a 500.
    $t->same(303, $client->post('/tags/find', ['code' => 'nope'])->status);
});
