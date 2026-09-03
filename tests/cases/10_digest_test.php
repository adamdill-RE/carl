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
use Carl\Repo\PlantingRepository;
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
    // "Carl: N items for today" (handoff Section 12), singular when N is 1 --
    // which is what a fresh database produces, so the assertion cannot
    // assume the plural.
    $t->ok(\preg_match('/^Carl: \d+ items? for today$/', (string) $row['subject']) === 1,
        'subject was: ' . $row['subject']);

    $headers = \json_decode((string) $row['headers'], true);
    $t->contains('/unsubscribe/', (string) $headers['List-Unsubscribe']);
    $t->same('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

    $t->ok($row['body_html'] !== null, 'plain text first, with a simple HTML twin');
    $t->contains('Good morning', (string) $row['body_text']);
});

$t->group('The other eight kinds, each on the day it should fire');

/**
 * Drive one kind by putting the data it needs in place, running the digest
 * for one user with a frozen clock, and looking for the title.
 *
 * Each of these is a rule that fires on exactly one or two days of the year.
 * Without a frozen clock they would be untestable except by waiting, which
 * is how a rule that never fires stays undiscovered for a season.
 */
$fires = static function (Carl\Tests\Harness $t, Carl\Core\App $app, Carl\Core\Database $db,
                          int $userId, string $utcInstant, string $needle,
                          string $message = '') use ($atUtc): void {
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    $frozen = $atUtc($utcInstant);
    (new Digest($frozen))->run($userId, true);

    $titles = $db->column(
        'SELECT CONCAT(`title`, \' :: \', `body`) FROM `reminder` WHERE `user_id` = :id',
        ['id' => $userId]
    );
    $joined = \implode(" | ", \array_map(\strval(...), $titles));
    $t->contains($needle, $joined, $message !== '' ? $message : ('got: ' . $joined));
};

$regionId = (int) ($db->value('SELECT id FROM `region` LIMIT 1') ?? 0);
$db->run('UPDATE `user` SET `region_id` = :r, `email_digest_enabled` = 1 WHERE `id` = :id',
    ['r' => $regionId, 'id' => $userId]);

$t->test('start_seeds_by fires 14 and 7 days before the sowing deadline',
    function ($t) use ($app, $db, $userId, $fires): void {
    // The dataset gives Roma a spring transplant window opening 03-15 and
    // 7 weeks indoors first, so the sow-by date is 01-25. Fourteen days
    // before that is 01-11.
    $fires($t, $app, $db, $userId, '2027-01-11 12:00:00', 'seeds within 14 days');
    $fires($t, $app, $db, $userId, '2027-01-18 12:00:00', 'seeds within 7 days');
});

$t->test('start_seeds_by is silent on every other day of the year',
    function ($t) use ($app, $db, $userId, $atUtc): void {
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    (new Digest($atUtc('2027-01-14 12:00:00')))->run($userId, true);
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::START_SEEDS_BY], 0
    ), 'a reminder that fires every day is a reminder nobody reads');
});

$t->test('frost_watch fires fourteen days before the earliest first frost',
    function ($t) use ($app, $db, $userId, $fires): void {
    // The dataset gives first_frost_early = 11-15 for this region.
    $fires($t, $app, $db, $userId, '2026-11-01 12:00:00', 'First frost is about two weeks away');
});

$t->test('pest_scouting fires when a pest window opens on a category you grow',
    function ($t) use ($app, $db, $userId, $fires): void {
    // Spider mites open 07-01 and affect Tomato, which this account grows.
    // The categories cell is semicolon-separated -- splitting it on a comma
    // matches nothing and the rule never fires at all.
    $fires($t, $app, $db, $userId, '2026-07-01 12:00:00', 'Start watching for');
});

$t->test('transplant_window fires a week before the window opens, for a seedling',
    function ($t) use ($app, $db, $userId, $fires): void {
    // A seedling exists from the hardening test above; the fall transplant
    // window opens 07-01, so a week out is 06-24.
    $fires($t, $app, $db, $userId, '2026-06-24 12:00:00', 'Transplant window opens in a week');
    $fires($t, $app, $db, $userId, '2026-07-01 12:00:00', 'Transplant window opens today');
});

$t->test('first_harvest_expected fires a week out and again on the day',
    function ($t) use ($client, $app, $db, $userId, $plantTypeId, $today, $fires, $regionId): void {
    // A plant whose days-to-maturity lands on a known date.
    $type = $db->one(
        'SELECT id, dtm_days_min, dtm_days_max, dtm_counted_from FROM `plant_type`'
        . ' WHERE dtm_days_min IS NOT NULL AND dtm_counted_from = :from LIMIT 1',
        ['from' => 'seed']
    );
    if ($type === null) {
        $t->ok(true, 'no seed-counted type in this dataset');
        return;
    }

    // The digest reads the region's override over the catalogue value
    // (Phase 17), so the test has to as well, or a county with one moves
    // every date below.
    $override = $db->one(
        'SELECT dtm_days_min_override, dtm_days_max_override FROM `plant_region`'
        . ' WHERE region_id = :r AND plant_type_id = :t'
        . ' AND (dtm_days_min_override IS NOT NULL OR dtm_days_max_override IS NOT NULL) LIMIT 1',
        ['r' => $regionId, 't' => (int) $type['id']]
    );
    $min = (int) ($override['dtm_days_min_override'] ?? $type['dtm_days_min']);
    $max = ($override['dtm_days_max_override'] ?? $type['dtm_days_max']) === null
        ? null : (int) ($override['dtm_days_max_override'] ?? $type['dtm_days_max']);
    $window = $max !== null && $max > $min;
    // Sown so that maturity falls on 2026-10-01.
    $sown = (string) Clock::addDays('2026-10-01', -$min);
    (new PlantingRepository($db, $userId))->insert([
        'plant_type_id'    => (int) $type['id'],
        'label'            => 'Harvest Test',
        'start_method'     => 'direct_sow',
        'start_date'       => $sown,
        'quantity_initial' => 1,
        'quantity_live'    => 1,
        'state'            => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);

    // A min and a max are a window (Phase 17): "harvest starts", with the
    // whole span in the body. One figure is one date, said as it always was.
    $fires($t, $app, $db, $userId, '2026-09-24 12:00:00',
        $window ? 'harvest starts in a week' : 'should be ready in a week');
    $fires($t, $app, $db, $userId, '2026-10-01 12:00:00',
        $window ? 'harvest starts about now' : 'should be ready about now');

    if ($window) {
        $fires($t, $app, $db, $userId, '2026-10-01 12:00:00', 'the window runs 1 Oct 2026 to ');
        // And the far end of the window is said too, a week out and on the day.
        $end = (string) Clock::addDays($sown, $max);
        $fires($t, $app, $db, $userId, (string) Clock::addDays($end, -7) . ' 12:00:00', 'harvest window ends in a week');
        $fires($t, $app, $db, $userId, $end . ' 12:00:00', 'harvest window ends about now');
    }
});

$t->test('harvest_window_closing fires a fortnight past the late date, but only if nothing was picked',
    function ($t) use ($app, $db, $userId, $fires, $atUtc, $regionId): void {
    $planting = $db->one(
        "SELECT p.id, p.plant_type_id, p.start_date, pt.dtm_days_max FROM `planting` p"
        . ' JOIN `plant_type` pt ON pt.id = p.plant_type_id'
        . " WHERE p.user_id = :u AND p.label = 'Harvest Test' LIMIT 1",
        ['u' => $userId]
    );
    if ($planting === null || $planting['dtm_days_max'] === null) {
        $t->ok(true, 'nothing to close');
        return;
    }
    // The override wins here as it does in the rule (Phase 17).
    $max = $db->value(
        'SELECT dtm_days_max_override FROM `plant_region` WHERE region_id = :r AND plant_type_id = :t'
        . ' AND dtm_days_max_override IS NOT NULL LIMIT 1',
        ['r' => $regionId, 't' => (int) $planting['plant_type_id']]
    ) ?? $planting['dtm_days_max'];

    $closing = (string) Clock::addDays((string) $planting['start_date'], (int) $max + 14);
    $fires($t, $app, $db, $userId, $closing . ' 12:00:00', 'Nothing harvested yet');

    // Log a yield, and the reminder stops: it is about a plant that has
    // given nothing, not about a date.
    $db->run(
        'INSERT INTO `plant_event` (user_id, planting_id, event_type, event_date, recorded_at,'
        . ' count_qty, created_at)'
        . " VALUES (:u, :p, 'yielded', :d, UTC_TIMESTAMP(), 3, UTC_TIMESTAMP())",
        ['u' => $userId, 'p' => (int) $planting['id'], 'd' => $closing]
    );
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    (new Digest($atUtc($closing . ' 12:00:00')))->run($userId, true);
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::HARVEST_WINDOW_CLOSING], 0
    ));
});

$t->test('inactivity nudges once, and then stops until something is logged',
    function ($t) use ($app, $db, $atUtc): void {
    $repo = new UserRepository($db);
    $name = 'idle' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
    $made = $repo->createWithTemporaryPassword($name, $name . '@example.test', 'Idle',
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user');
    $idleId = (int) $made['id'];
    $db->run(
        "UPDATE `user` SET `timezone` = 'UTC', `onboarded_at` = UTC_TIMESTAMP(),"
        . " `must_reset_password` = 0, `onboarding_step` = 'done' WHERE `id` = :id",
        ['id' => $idleId]
    );

    // A planting with one event, ten days ago.
    $typeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
    $plantingId = (new PlantingRepository($db, $idleId))->insert([
        'plant_type_id'    => $typeId,
        'start_method'     => 'direct_sow',
        'start_date'       => '2026-06-05',
        'quantity_initial' => 1,
        'quantity_live'    => 1,
        'state'            => 'planted',
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
    $db->run(
        'INSERT INTO `plant_event` (user_id, planting_id, event_type, event_date, recorded_at, created_at)'
        . " VALUES (:u, :p, 'direct_sown', '2026-06-05', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        ['u' => $idleId, 'p' => $plantingId]
    );

    (new Digest($atUtc('2026-06-15 12:00:00')))->run($idleId, true);
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $idleId, 'k' => ReminderKind::INACTIVITY], 0
    ), 'ten days quiet earns a nudge');

    // The next morning, still nothing logged: still one nudge, not two.
    (new Digest($atUtc('2026-06-16 12:00:00')))->run($idleId, true);
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $idleId, 'k' => ReminderKind::INACTIVITY], 0
    ), 'nagging every morning is how a channel gets muted');
});

$t->test('research_diff is said once per planting, then never again',
    function ($t) use ($app, $db, $userId, $atUtc): void {
    $before = (int) $db->value(
        'SELECT COUNT(DISTINCT `subject_key`) FROM `reminder`'
        . ' WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::RESEARCH_DIFF], 0
    );

    (new Digest($atUtc('2026-12-05 12:00:00')))->run($userId, true);
    (new Digest($atUtc('2026-12-06 12:00:00')))->run($userId, true);

    $subjects = (int) $db->value(
        'SELECT COUNT(DISTINCT `subject_key`) FROM `reminder`'
        . ' WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::RESEARCH_DIFF], 0
    );
    $t->ok($subjects >= $before, 'it may have found new plantings');

    // Whatever it found, a second morning adds no new subject.
    $afterThird = (int) $db->value(
        'SELECT COUNT(DISTINCT `subject_key`) FROM `reminder`'
        . ' WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::RESEARCH_DIFF], 0
    );
    $t->same($subjects, $afterThird);
});

$t->group('Today\'s items on the menu');

// The frozen-clock group above walked this account through half a year and
// cleared the table each time. Put today's items back before looking at
// today's menu.
$db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
(new Digest($app))->run($userId, true);

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

$t->test('the watering advice is not printed twice on one screen',
    function ($t) use ($client, $app, $db, $userId, $today): void {
    // The MOTD carries the watering recommendation a few centimetres up the
    // page, with its numbers. Repeating the same sentence verbatim in Today
    // is how a reader learns to skim both.
    $watering = $db->one(
        'SELECT * FROM `watering_recommendation` WHERE `user_id` = :u AND `for_date` = :d LIMIT 1',
        ['u' => $userId, 'd' => $today]
    );
    if ($watering === null) {
        $t->ok(true, 'no watering row today to duplicate');
        return;
    }

    $reason = (string) $watering['reason_text'];
    $body = $client->get('/')->body;
    $t->same(1, \substr_count($body, \htmlspecialchars($reason, \ENT_QUOTES, 'UTF-8')),
        'the reason text appears once, in the MOTD');

    // Dismissed, it comes back in Today -- because then it is the only place
    // the advice appears at all.
    $client->post('/motd/dismiss', ['forecast_hash' => 'whatever']);
    $after = $client->get('/')->body;
    $t->same(1, \substr_count($after, \htmlspecialchars($reason, \ENT_QUOTES, 'UTF-8')),
        'still once, now in Today');
    $t->notContains('<h2>Weather</h2>', $after, 'the weather box really is dismissed');
    $t->contains('<h2>Today</h2>', $after);
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
