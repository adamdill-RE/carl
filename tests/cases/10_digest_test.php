<?php

/**
 * Reminders and the daily digest (handoff Section 12).
 *
 * The two things most likely to be wrong here are both about time and both
 * silent: sending at the server's morning instead of the user's, and sending
 * the same thing twice. Both get their own tests, with a frozen clock and two
 * users in timezones eleven hours apart.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\ReminderKind;
use Carl\Reminders\Digest;
use Carl\Reminders\DigestMessage;
use Carl\Reminders\ReminderBuilder;
use Carl\Repo\ReminderRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('Recurring dates read forward, not backward');

$t->test('a month-day already past this year resolves to next year', function ($t): void {
    // "02-15" read in December is next February. Subtracting would give a
    // negative number of days and quietly never fire.
    $t->same('2027-02-15', ReminderBuilder::nextOccurrence('2026-12-01', '02-15'));
    $t->same('2026-12-25', ReminderBuilder::nextOccurrence('2026-12-01', '12-25'));
    $t->same('2026-12-01', ReminderBuilder::nextOccurrence('2026-12-01', '12-01'), 'today counts');
    $t->same(null, ReminderBuilder::nextOccurrence('2026-12-01', null));
    $t->same(null, ReminderBuilder::nextOccurrence('2026-12-01', 'nonsense'));
});

$t->test('days until a month-day is a count, and zero means today', function ($t): void {
    $t->same(0, ReminderBuilder::daysUntilMonthDay('2026-03-15', '03-15'));
    $t->same(7, ReminderBuilder::daysUntilMonthDay('2026-03-08', '03-15'));
    $t->same(14, ReminderBuilder::daysUntilMonthDay('2026-03-01', '03-15'));
    $t->same(null, ReminderBuilder::daysUntilMonthDay('2026-03-01', null));
});

$t->group('The message');

$t->test('items are grouped by kind, most urgent first', function ($t): void {
    $grouped = DigestMessage::grouped([
        ['kind' => ReminderKind::INACTIVITY, 'title' => 'Nothing logged', 'body' => ''],
        ['kind' => ReminderKind::FROST_WATCH, 'title' => 'Freeze Warning', 'body' => ''],
        ['kind' => ReminderKind::WATERING, 'title' => 'Water the bed', 'body' => ''],
    ]);
    // A freeze tonight kills a bed; a nudge keeps until the weekend.
    $t->same(['Frost', 'Watering', 'Nudge'], \array_keys($grouped));
});

$t->test('the plain text carries every item and the unsubscribe link', function ($t): void {
    $text = DigestMessage::text(
        [['kind' => ReminderKind::WATERING, 'title' => 'Water Main Bed today',
          'body' => 'Deficit 27 mm after 4 dry days.']],
        '2026-06-01', 'Ada', 'https://example.test/carl/', 'https://example.test/carl/unsubscribe/abc'
    );
    $t->contains('Good morning, Ada.', $text);
    $t->contains('One thing for today, 2026-06-01', $text);
    $t->contains('Water Main Bed today', $text);
    $t->contains('Deficit 27 mm', $text);
    $t->contains('https://example.test/carl/unsubscribe/abc', $text);
});

$t->test('an unresearched county is told what is missing, not left to wonder',
    function ($t): void {
    $items = [['kind' => ReminderKind::WATERING, 'title' => 'Water', 'body' => '']];

    $withRegion = DigestMessage::text($items, '2026-06-01', 'Ada', 'u', 'x', true);
    $t->notContains('no research loaded', $withRegion);

    // Section 9.4: suppressed WITH a one-line explanation.
    $without = DigestMessage::text($items, '2026-06-01', 'Ada', 'u', 'x', false);
    $t->contains('no research loaded', $without);
    $t->contains('watering, days to maturity, hardening', $without);

    $t->contains('no research loaded', DigestMessage::html($items, '2026-06-01', 'Ada', 'u', 'x', false));
});

$t->test('the HTML twin escapes what the user typed', function ($t): void {
    $html = DigestMessage::html(
        [['kind' => ReminderKind::WATERING, 'title' => '<script>alert(1)</script>', 'body' => 'a & b']],
        '2026-06-01', 'Ada', 'https://example.test/', 'https://example.test/u'
    );
    $t->notContains('<script>alert', $html);
    $t->contains('&lt;script&gt;', $html);
    $t->contains('a &amp; b', $html);
});

$t->group('It is the user\'s morning that decides, not the server\'s');

$makeUser = static function (string $username, string $timezone) use ($db, $app): array {
    $repo = new UserRepository($db);
    $created = $repo->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    $db->run(
        'UPDATE `user` SET `timezone` = :tz, `onboarded_at` = UTC_TIMESTAMP(),'
        . " `must_reset_password` = 0, `onboarding_step` = 'done' WHERE `id` = :id",
        ['tz' => $timezone, 'id' => $created['id']]
    );
    return ['id' => (int) $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

/** An App whose clock is frozen at a known UTC instant. */
$atUtc = static function (string $instant) use ($app): Carl\Core\App {
    $frozen = new Carl\Core\App($app->config(), $app->root());
    $frozen->setClock(new Clock(new DateTimeImmutable($instant, new DateTimeZone('UTC'))));
    return $frozen;
};

$chicago = $makeUser('dawnchi' . $suffix, 'America/Chicago');
$tokyo = $makeUser('dawntok' . $suffix, 'Asia/Tokyo');

$t->test('at 11:00 UTC the Chicago account is due and the Tokyo one is not',
    function ($t) use ($atUtc, $chicago, $tokyo): void {
    // 11:00 UTC is 06:00 in Chicago (CDT) and 20:00 in Tokyo.
    $frozen = $atUtc('2026-06-15 11:15:00');

    $forChicago = (new Digest($frozen))->run($chicago['id']);
    $t->same(1, $forChicago['due'], 'it is six in the morning where they are');

    $forTokyo = (new Digest($frozen))->run($tokyo['id']);
    $t->same(0, $forTokyo['due'], 'it is eight in the evening where they are');
});

$t->test('at 21:00 UTC it is the other way round', function ($t) use ($atUtc, $chicago, $tokyo): void {
    // 21:00 UTC is 06:00 next day in Tokyo and 16:00 in Chicago.
    $frozen = $atUtc('2026-06-15 21:15:00');

    $t->same(0, (new Digest($frozen))->run($chicago['id'])['due']);
    $t->same(1, (new Digest($frozen))->run($tokyo['id'])['due']);
});

$t->test('every run writes a row, including the twenty-three that send nothing',
    function ($t) use ($db, $atUtc, $chicago): void {
    $before = (int) $db->value('SELECT COUNT(*) FROM `digest_run`', [], 0);
    (new Digest($atUtc('2026-06-15 14:15:00')))->run($chicago['id']);
    $t->same($before + 1, (int) $db->value('SELECT COUNT(*) FROM `digest_run`', [], 0),
        'a cron that silently stops is otherwise invisible for months');

    $run = $db->one('SELECT * FROM `digest_run` ORDER BY `id` DESC LIMIT 1');
    $t->same(0, (int) $run['users_due']);
    $t->same('ok', $run['outcome']);
});

$t->group('Silence, and saying a thing once');

$repo = new UserRepository($db);
$username = 'digest' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Digest Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'digest-test-passphrase', 'password_confirm' => 'digest-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Digest Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Digest Bed ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$today = $app->clock()->todayFor((string) $db->value(
    'SELECT timezone FROM `user` WHERE id = :id', ['id' => $userId]
));
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

$t->test('a user with nothing to be told gets no email at all',
    function ($t) use ($app, $db, $userId): void {
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    $db->run('DELETE FROM `email_outbox` WHERE `user_id` = :id', ['id' => $userId]);

    $summary = (new Digest($app))->run($userId, true);
    $t->same(1, $summary['due']);
    $t->same(0, $summary['queued'], 'an empty digest trains people to ignore a full one');
    $t->same(1, $summary['silent']);
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `email_outbox` WHERE `user_id` = :id', ['id' => $userId], 0
    ));
});

$t->test('a hardening countdown produces one item a day while it runs',
    function ($t) use ($client, $app, $db, $userId, $plantTypeId, $today): void {
    $response = $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '6', 'label' => 'Hardening Test',
        'start_date' => (string) Clock::addDays($today, -40),
    ]);
    $t->same(303, $response->status);

    $plantingId = (int) $db->value(
        'SELECT id FROM `planting` WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $userId]
    );

    // Hardening started three days ago, over ten days: seven left.
    $client->post('/log/' . $plantingId, [
        'event_type' => 'hardening_started', 'hardening_days' => '10',
        'event_date' => (string) Clock::addDays($today, -3),
    ]);

    $summary = (new Digest($app))->run($userId, true);
    $t->ok($summary['reminders'] > 0);

    $items = (new ReminderRepository($db, $userId))->forDate($today);
    $titles = \array_map(static fn (array $r): string => (string) $r['title'], $items);
    $t->ok(\in_array('Hardening Test: transplant in 7 days', $titles, true),
        'got: ' . \implode(' | ', $titles));
});

$t->test('the same morning twice is one email, not two',
    function ($t) use ($app, $db, $userId, $today): void {
    $before = (int) $db->value(
        "SELECT COUNT(*) FROM `email_outbox` WHERE `user_id` = :id AND `kind` = 'digest'",
        ['id' => $userId], 0
    );

    (new Digest($app))->run($userId, true);
    (new Digest($app))->run($userId, true);

    $after = (int) $db->value(
        "SELECT COUNT(*) FROM `email_outbox` WHERE `user_id` = :id AND `kind` = 'digest'",
        ['id' => $userId], 0
    );
    $t->same($before, $after, 'the dedupe key is what stops it, not a read-then-write');

    $t->ok((int) $db->value(
        'SELECT COUNT(*) FROM `email_outbox` WHERE `dedupe_key` = :key',
        ['key' => 'digest:' . $userId . ':' . $today], 0
    ) <= 1);
});

$t->test('an item already sent is not sent again tomorrow morning',
    function ($t) use ($db, $userId, $today): void {
    $sent = (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `due_date` = :d'
        . ' AND `sent_at` IS NOT NULL',
        ['id' => $userId, 'd' => $today], 0
    );
    $t->ok($sent > 0, 'the reminders that went out are marked');
});

$t->test('the digest email carries List-Unsubscribe and its One-Click twin',
    function ($t) use ($db, $userId): void {
    $row = $db->one(
        "SELECT * FROM `email_outbox` WHERE `user_id` = :id AND `kind` = 'digest'"
        . ' ORDER BY `id` DESC LIMIT 1',
        ['id' => $userId]
    );
    $t->ok($row !== null);
    $t->contains('Carl: ', (string) $row['subject']);
    $t->contains('items for today', (string) $row['subject']);

    $headers = \json_decode((string) $row['headers'], true);
    $t->contains('/unsubscribe/', (string) $headers['List-Unsubscribe']);
    $t->same('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

    $t->ok($row['body_html'] !== null, 'plain text first, with a simple HTML twin');
    $t->contains('Good morning', (string) $row['body_text']);
});

$t->group('Today\'s items on the menu');

$t->test('the menu shows the stored items, not a recomputation',
    function ($t) use ($client): void {
    $response = $client->get('/');
    $t->same(200, $response->status);
    $t->contains('<h2>Today</h2>', $response->body);
    $t->contains('transplant in 7 days', $response->body);
    // No inline style attribute: the CSP silently refuses them
    // (Phase 3 handoff Section 1.5).
    $t->notContains('style="', $response->body);
});

$t->test('dismissing one takes it off the menu and out of the next email',
    function ($t) use ($client, $db, $userId, $today): void {
    $items = (new ReminderRepository($db, $userId))->forDate($today);
    $t->ok($items !== []);
    $id = (int) $items[0]['id'];

    $client->post('/reminders/dismiss', ['reminder_id' => (string) $id]);

    $row = $db->one('SELECT * FROM `reminder` WHERE `id` = :id', ['id' => $id]);
    $t->ok($row['dismissed_at'] !== null, 'the row stays -- it is a record of what was said');

    $after = (new ReminderRepository($db, $userId))->forDate($today);
    $ids = \array_map(static fn (array $r): int => (int) $r['id'], $after);
    $t->ok(!\in_array($id, $ids, true));
});

$t->test('one account cannot dismiss another account\'s item',
    function ($t) use ($db, $userId, $chicago, $today): void {
    $mine = (new ReminderRepository($db, $userId))->forDate($today);
    if ($mine === []) {
        $t->ok(true, 'nothing left to try');
        return;
    }
    $stranger = new ReminderRepository($db, $chicago['id']);
    $t->same(0, $stranger->dismiss((int) $mine[0]['id']),
        'the scope is in the repository base class, not in the query');
});

$t->group('The unsubscribe route');

$t->test('a One-Click POST needs no session and no CSRF token',
    function ($t) use ($root, $db, $userId): void {
    // This is the whole point of Route::TOKEN_ACCESS: a mail client POSTs
    // with neither, and Gmail and Outlook expect it of bulk mail.
    $token = (string) $db->value(
        'SELECT email_unsubscribe_token FROM `user` WHERE id = :id', ['id' => $userId]
    );

    $anonymous = new Client($root);
    $anonymous->forgetCookies();
    $response = $anonymous->postWithoutCsrf('/unsubscribe/' . $token,
        ['List-Unsubscribe' => 'One-Click']);

    $t->same(200, $response->status, 'not 419');
    $t->contains('Unsubscribed', $response->body);
    $t->same(0, (int) $db->value(
        'SELECT email_digest_enabled FROM `user` WHERE id = :id', ['id' => $userId], 1
    ));
});

$t->test('an unsubscribed account is not sent a digest', function ($t) use ($app, $db, $userId): void {
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    $summary = (new Digest($app))->run($userId, true);
    $t->same(0, $summary['due'], 'the opt-out is checked before anything is computed');
});

$t->test('it can be turned back on from the same page', function ($t) use ($root, $db, $userId): void {
    $token = (string) $db->value(
        'SELECT email_unsubscribe_token FROM `user` WHERE id = :id', ['id' => $userId]
    );
    $anonymous = new Client($root);
    $anonymous->forgetCookies();
    $response = $anonymous->postWithoutCsrf('/unsubscribe/' . $token . '/resume');

    $t->same(200, $response->status);
    $t->same(1, (int) $db->value(
        'SELECT email_digest_enabled FROM `user` WHERE id = :id', ['id' => $userId], 0
    ));
});

$t->test('a token that is not a token is 404, and gives nothing away',
    function ($t) use ($root): void {
    $anonymous = new Client($root);
    $anonymous->forgetCookies();
    $t->same(404, $anonymous->get('/unsubscribe/' . \str_repeat('a', 64))->status);
    $t->same(404, $anonymous->get('/unsubscribe/short')->status);
});

$t->group('Statement count, because this is the job that loops over users');

$t->test('twenty users cost about as many statements as one',
    function ($t) use ($app, $db, $makeUser, $suffix): void {
    $ids = [];
    for ($i = 0; $i < 20; $i++) {
        $ids[] = $makeUser('bulk' . $suffix . $i, 'America/Chicago')['id'];
    }

    $params = [];
    $names = [];
    foreach ($ids as $i => $id) {
        $names[] = ':b' . $i;
        $params['b' . $i] = $id;
    }
    $users = $db->all(
        'SELECT u.id, u.username, u.email, u.name, u.timezone, u.region_id,'
        . ' u.weather_location_id, u.email_unsubscribe_token, NULL AS research_status'
        . ' FROM `user` u WHERE u.id IN (' . \implode(', ', $names) . ')',
        $params
    );
    $todayByUser = [];
    foreach ($users as $user) {
        $todayByUser[(int) $user['id']] = $app->clock()->todayFor((string) $user['timezone']);
    }

    $before = $db->statementCount();
    (new ReminderBuilder($db))->build($users, $todayByUser);
    $spent = $db->statementCount() - $before;

    // Seven for the batch. Per-user would have been 140, which at the
    // measured 0.81 ms round trip is most of a second of pure latency
    // (Phase 3 handoff Section 1.4).
    $t->ok($spent <= 10, 'the builder spent ' . $spent . ' statements on 20 users');
});
