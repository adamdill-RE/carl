<?php

/**
 * The field-recording sheet (handoff Section 13.4), and the per-garden
 * prefilled version that has been the last unbuilt Phase 4 item since.
 *
 * There is no PDF rasteriser on the build machine, so "does it fit on one
 * page" cannot be answered by looking at it. It is answered here instead,
 * and it has to be: AutoPageBreak is off, so content past the bottom does
 * NOT spill onto a second page -- it is drawn off the paper and silently
 * does not print, and the first thing to go is the footer line that says
 * where to type it in. A review of the design found exactly that failure on
 * two of the four artboards, which is why the PDF measures itself.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Reports\FieldSheet;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);
// Read from config rather than written here: the deployment path belongs in
// exactly one committed file (01_core_test.php asserts it).
$base = $app->basePath();

/** A garden's worth of living plants, in the shape the sheet prints. */
$bed = static function (int $plants, int $rows = 3): array {
    $lines = [];
    $perRow = (int) \max(1, \ceil($plants / $rows));
    $made = 0;
    for ($r = 1; $r <= $rows && $made < $plants; $r++) {
        $lines[] = ['row' => 'Row ' . $r, 'label' => ''];
        for ($i = 0; $i < $perRow && $made < $plants; $i++, $made++) {
            $lines[] = ['row' => null, 'label' => 'Tomato Roma number ' . $made . ' - Tomato (6)'];
        }
    }
    return $lines;
};

$t->group('Every sheet is one page, and fits on A4 and on Letter');

$t->test('the blank plant sheet fits, with room under it', function ($t) use ($base): void {
    $sheet = new FieldSheet($base);
    $sheet->plantSheet();
    $pdf = $sheet->render();

    $t->ok(\str_starts_with($pdf, '%PDF-'), 'it is a PDF');
    $t->same(1, \preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'exactly one page');
    $t->ok($sheet->contentBottom() <= $sheet->bottomLimit() - 8,
        'content reached ' . $sheet->contentBottom() . ' mm against a '
        . ($sheet->bottomLimit() - 8) . ' mm limit');
});

$t->test('the garden actions sheet fits', function ($t) use ($base): void {
    $sheet = new FieldSheet($base);
    $sheet->gardenSheet();
    $pdf = $sheet->render();
    $t->same(1, \preg_match_all('#/Type\s*/Page[^s]#', $pdf));
    $t->ok($sheet->contentBottom() <= $sheet->bottomLimit() - 8,
        'content reached ' . $sheet->contentBottom() . ' mm');
});

$t->test('a prefilled sheet fits whatever the size of the garden',
    function ($t) use ($bed, $base): void {
    // The one that matters: the number of plants is the user's, not the
    // designer's, and a bed with sixty things in it must not push the footer
    // off the paper.
    foreach ([1, 9, 20, 60, 200] as $count) {
        $sheet = new FieldSheet($base);
        $sheet->plantSheet('Main Bed', $bed($count), '2026-08-31');
        $sheet->render();
        $t->ok($sheet->contentBottom() <= $sheet->bottomLimit() - 8,
            $count . ' plants reached ' . $sheet->contentBottom() . ' mm against '
            . ($sheet->bottomLimit() - 8));
    }
});

$t->test('plants that did not fit are counted and said, not dropped quietly',
    function ($t) use ($bed, $base): void {
    $sheet = new FieldSheet($base);
    $sheet->plantSheet('Main Bed', $bed(60), '2026-08-31');
    $sheet->render();

    $t->ok($sheet->truncatedCount() > 0, 'sixty plants do not fit on one sheet');

    $small = new FieldSheet($base);
    $small->plantSheet('Main Bed', $bed(6), '2026-08-31');
    $small->render();
    $t->same(0, $small->truncatedCount(), 'six do, and nothing is claimed missing');
});

$t->group('What is on it');

$t->test('the sheet carries the app\'s own action vocabulary', function ($t) use ($base): void {
    // FPDF compresses its content streams, so the assertion is on the
    // decompressed one. Without it the sheet could drift from
    // Carl\Domain\EventType and nothing would notice until somebody stood in
    // a garden holding a box that no longer exists in the app.
    $sheet = new FieldSheet($base);
    $sheet->plantSheet();
    $pdf = $sheet->render();

    $text = '';
    if (\preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) > 0) {
        foreach ($matches[1] as $stream) {
            $plain = @\gzuncompress($stream);
            if (\is_string($plain)) {
                $text .= $plain;
            }
        }
    }
    $t->ok($text !== '', 'a content stream was readable');

    foreach (['Watered', 'Fertilised', 'Pest treated', 'Hardening started', 'Up-potted'] as $label) {
        $t->contains($label, $text);
    }
    $t->contains('Field sheet', $text);
});

$t->test('a garden name with an apostrophe survives the encoding',
    function ($t) use ($bed, $base): void {
    // FPDF's core fonts are Windows-1252 and Carl's text is UTF-8. A garden
    // called "Ada's bed" is mojibake without the conversion, and only in the
    // PDF -- every screen shows it correctly.
    $sheet = new FieldSheet($base);
    $sheet->plantSheet("Ada\u{2019}s Bed", $bed(4), '2026-08-31');
    $pdf = $sheet->render();
    $t->ok(\strlen($pdf) > 1000, 'it rendered rather than throwing');
    $t->same(1, \preg_match_all('#/Type\s*/Page[^s]#', $pdf));
});

$t->group('The routes');

$repo = new UserRepository($db);
$username = 'sheet' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Sheet Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'field-sheet-passphrase', 'password_confirm' => 'field-sheet-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Sheet Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Sheet Bed ' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardenId = (int) $db->value(
    'SELECT id FROM `garden` WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $userId]
);

$t->test('the blank sheet downloads as a PDF', function ($t) use ($client, $base): void {
    $response = $client->get('/reports/field-sheet.pdf');
    $t->same(200, $response->status);
    $t->contains('application/pdf', (string) ($response->headers()['Content-Type'] ?? ''));
    $t->contains('field-sheet.pdf',
        (string) ($response->headers()['Content-Disposition'] ?? ''));
    $t->ok(\str_starts_with($response->body, '%PDF-'));
});

$t->test('so does the garden actions sheet', function ($t) use ($client, $base): void {
    $response = $client->get('/reports/field-sheet.pdf', ['kind' => 'garden']);
    $t->same(200, $response->status);
    $t->ok(\str_starts_with($response->body, '%PDF-'));
});

$t->test('the prefilled sheet is scoped to its owner',
    function ($t) use ($client, $gardenId, $db, $root, $app, $suffix, $base): void {
    $mine = $client->get('/reports/garden/' . $gardenId . '/field-sheet.pdf');
    $t->same(200, $mine->status);
    $t->ok(\str_starts_with($mine->body, '%PDF-'));

    // Somebody else's garden is not found, not rendered.
    $other = (int) ($db->value(
        'SELECT id FROM `garden` WHERE user_id <> :u ORDER BY id LIMIT 1',
        ['u' => (int) $db->value('SELECT user_id FROM `garden` WHERE id = :g', ['g' => $gardenId])]
    ) ?? 0);
    if ($other === 0) {
        $t->ok(true, 'no second account with a garden in this database');
        return;
    }
    $t->same(404, $client->get('/reports/garden/' . $other . '/field-sheet.pdf')->status);
});

$t->test('the Reports page links the sheets instead of apologising for them',
    function ($t) use ($client, $base): void {
    $response = $client->get('/reports');
    $t->same(200, $response->status);
    $t->contains('reports/field-sheet.pdf', $response->body);
    $t->contains('field-sheet.pdf', $response->body);
    // The paragraph Phase 5 left as a standing advertisement for the gap.
    $t->notContains('is not built yet', $response->body);
});
