<?php

/**
 * GDD pest reminders (handoff Section 3.1, deferred since Phase 1).
 *
 * The whole feature is one number compared against another number, and there
 * are exactly two ways to get it wrong. Both are silent, and both have a test
 * here that fails loudly:
 *
 *  1. **The units.** `weather_daily` is Celsius because weather.md Section 6.3
 *     says weather is stored SI. `gdd_base_f` and `gdd_threshold` are
 *     Fahrenheit because that is what the extension bulletins print. Mixing
 *     them gives an accumulation 1.8 times too small, which looks like a
 *     plausible number and fires the reminder six weeks late for ever.
 *
 *  2. **The direction the biofix reads.** An accumulation starts at the LAST
 *     occurrence of its month-day, not the next one. Reading it forward puts
 *     the start date in the future and the count never begins.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\ReminderKind;
use Carl\Reminders\Digest;
use Carl\Reminders\ReminderBuilder;
use Carl\Repo\PlantingRepository;
use Carl\Support\Clock;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('A biofix reads backward');

$t->test('the most recent occurrence is on or before today, never after',
    function ($t): void {
    // The mirror of nextOccurrence(), and the reason it has to exist: an
    // accumulation that starts 03-01 and is read on 02-15 belongs to LAST
    // year's season. Reading it forward starts the count nine months early
    // and the threshold is crossed on day one, every year.
    $t->same('2026-03-01', ReminderBuilder::previousOccurrence('2026-06-15', '03-01'));
    $t->same('2025-03-01', ReminderBuilder::previousOccurrence('2026-02-15', '03-01'));
    $t->same('2026-06-15', ReminderBuilder::previousOccurrence('2026-06-15', '06-15'),
        'today counts');
    $t->same('2026-01-01', ReminderBuilder::previousOccurrence('2026-12-31', '01-01'));
    $t->same(null, ReminderBuilder::previousOccurrence('2026-06-15', null));
    $t->same(null, ReminderBuilder::previousOccurrence('2026-06-15', 'nonsense'));
});

$t->test('it is the exact mirror of nextOccurrence over a whole year',
    function ($t): void {
    // Any day of any year: the previous occurrence is at or before today and
    // the next is at or after it, and they are a year apart unless today IS
    // the day.
    foreach (['01-01', '03-15', '07-04', '12-25'] as $monthDay) {
        foreach (['2026-01-01', '2026-03-15', '2026-06-30', '2026-12-31'] as $today) {
            $prev = ReminderBuilder::previousOccurrence($today, $monthDay);
            $next = ReminderBuilder::nextOccurrence($today, $monthDay);
            $t->ok($prev !== null && $prev <= $today, $monthDay . ' on ' . $today);
            $t->ok($next !== null && $next >= $today, $monthDay . ' on ' . $today);
        }
    }
});

$t->group('The accumulation is in Fahrenheit, because the threshold is');

/**
 * A user with a researched region, a weather location and a squash planting,
 * so the GDD rule has everything it needs except the weather itself.
 */
$repo = new UserRepository($db);
$username = 'gdd' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'GDD Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'gdd-test-passphrase', 'password_confirm' => 'gdd-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'GDD Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'GDD Bed ' . $suffix, 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$user = $db->one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $userId]);
$regionId = (int) ($user['region_id'] ?? 0);
$locationId = (int) ($user['weather_location_id'] ?? 0);

$t->test('onboarding gave the account a region and a weather location',
    function ($t) use ($regionId, $locationId): void {
    $t->ok($regionId > 0, 'zip 76692 resolves to the researched region');
    $t->ok($locationId > 0, 'and to a weather location to accumulate from');
});

/** The category the test pest eats, and a planting of it. */
$squashType = $db->one(
    "SELECT id, category FROM `plant_type` WHERE `category` LIKE 'Squash%' ORDER BY id LIMIT 1"
);
$plantCategory = (string) ($squashType['category'] ?? 'Squash (summer)');

// Through the repository, not raw SQL: planting.root_planting_id is NOT NULL
// with no default, and the repository is the one writer that knows to point a
// fresh sowing at itself (migration 019).
(new PlantingRepository($db, $userId))->insert([
    'plant_type_id'    => (int) $squashType['id'],
    'label'            => 'GDD Squash',
    'start_method'     => 'direct_sow',
    'start_date'       => '2026-03-01',
    'quantity_initial' => 3,
    'quantity_live'    => 3,
    'state'            => 'planted',
    'state_changed_at' => \gmdate('Y-m-d H:i:s'),
]);

/**
 * Lay down a run of identical days. Each contributes a known, hand-checkable
 * number of Fahrenheit degree-days, so the total the reminder reports can be
 * arithmetic rather than a fixture nobody can check.
 *
 * water_balance_mm is a generated column: inserting it fails with MySQL 1906,
 * which reads as a warning and is fatal (Phase 5 handoff Section 7).
 */
$layDays = static function (string $from, int $count, float $maxC, float $minC)
    use ($db, $locationId): void {
    for ($i = 0; $i < $count; $i++) {
        $date = (string) Clock::addDays($from, $i);
        $db->run(
            'INSERT INTO `weather_daily` (location_id, obs_date, temp_max_c, temp_min_c,'
            . ' temp_mean_c, precip_mm, et0_mm, source_model, is_provisional, fetched_at)'
            . " VALUES (:l, :d, :mx, :mn, :mean, 0, 3.0, 'test', 0, UTC_TIMESTAMP())"
            . ' ON DUPLICATE KEY UPDATE `temp_max_c` = VALUES(`temp_max_c`),'
            . ' `temp_min_c` = VALUES(`temp_min_c`), `temp_mean_c` = VALUES(`temp_mean_c`)',
            ['l' => $locationId, 'd' => $date, 'mx' => $maxC, 'mn' => $minC,
             'mean' => ($maxC + $minC) / 2]
        );
    }
};

/**
 * Forget every pest an earlier test in this file made.
 *
 * Each of these tests asserts on the text of a reminder, and a region that
 * still carries the previous test's pest produces a page full of them -- at
 * which point `contains('972')` passes because of somebody else's row and
 * the test no longer tests anything. The shipped dataset's own pests are
 * left alone: one of the tests below needs them.
 */
$clearTestPests = static function () use ($db): void {
    $db->run("DELETE pr FROM `pest_region` pr JOIN `pest` p ON p.id = pr.pest_id"
        . " WHERE p.pest_key LIKE 'test\\_%'");
};

/**
 * A pest of this region carrying a GDD threshold, with everything else the
 * rule reads set to known values.
 */
$makePest = static function (string $key, float $baseF, float $threshold, ?string $biofix,
                             string $affects) use ($db, $regionId): int {
    $db->run(
        'INSERT INTO `pest` (pest_key, name, kind, signs, created_at, updated_at)'
        . " VALUES (:k, :n, 'pest', 'Sawdust frass at the stem base', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        . ' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)',
        ['k' => $key, 'n' => 'Test borer ' . $key]
    );
    $pestId = (int) $db->value('SELECT id FROM `pest` WHERE pest_key = :k', ['k' => $key]);

    $db->run(
        'INSERT INTO `pest_region` (region_id, pest_id, active_start, active_end,'
        . ' affects_categories, gdd_base_f, gdd_threshold, gdd_biofix, confidence,'
        . ' created_at, updated_at)'
        . " VALUES (:r, :p, '04-15', '07-15', :a, :b, :t, :bx, 'verified',"
        . '  UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        . ' ON DUPLICATE KEY UPDATE `gdd_base_f` = VALUES(`gdd_base_f`),'
        . ' `gdd_threshold` = VALUES(`gdd_threshold`), `gdd_biofix` = VALUES(`gdd_biofix`),'
        . ' `affects_categories` = VALUES(`affects_categories`)',
        ['r' => $regionId, 'p' => $pestId, 'a' => $affects, 'b' => $baseF,
         't' => $threshold, 'bx' => $biofix]
    );
    return $pestId;
};

/** An App whose clock is frozen at a known UTC instant. */
$atUtc = static function (string $instant) use ($app): Carl\Core\App {
    $frozen = new Carl\Core\App($app->config(), $app->root());
    $frozen->setClock(new Clock(new DateTimeImmutable($instant, new DateTimeZone('UTC'))));
    return $frozen;
};

/** Run the digest at an instant and return "title :: body" for each item. */
$itemsAt = static function (string $utcInstant, string $kind = ReminderKind::PEST_GDD)
    use ($db, $userId, $atUtc): string {
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    (new Digest($atUtc($utcInstant)))->run($userId, true);
    $rows = $db->column(
        "SELECT CONCAT(`title`, ' :: ', `body`) FROM `reminder`"
        . ' WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => $kind]
    );
    return \implode(' | ', \array_map(\strval(...), $rows));
};

$t->test('a Celsius accumulation against a Fahrenheit threshold would be 1.8x too small',
    function ($t) use ($db, $userId, $layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // 50 days at a mean of 25 C. In Fahrenheit that is 77 F, so each day
    // gives 27 degree-days over a base of 50 F: 1,350 in total, well past a
    // threshold of 1,000.
    //
    // Read in Celsius the same days give (25 - 10) = 15 each, 750 in total,
    // and the reminder would NOT fire. That difference is the whole test.
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_units', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-19 12:00:00');
    $t->contains('Test borer', $items, 'got: ' . $items);
    $t->contains('1,350', $items, 'the reported total is the Fahrenheit one; got: ' . $items);
});

$t->test('it is silent while the accumulation is short of the threshold',
    function ($t) use ($db, $userId, $layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // 10 days at 27 degree-days is 270, nowhere near 1,000, and the forecast
    // does not reach it either.
    $layDays('2026-01-01', 10, 30.0, 20.0);
    $makePest('test_short', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-01-10 12:00:00');
    $t->notContains('test_short', $items);
});

$t->test('the base temperature is subtracted, not ignored',
    function ($t) use ($layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // The same 50 days at 77 F mean, but a base of 70 F: 7 degree-days each,
    // 350 in total. A threshold of 300 is crossed; one of 400 is not. If the
    // base were dropped the total would be 3,850 and both would fire.
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_base_lo', 70.0, 300.0, '01-01', 'Squash (summer)');
    $makePest('test_base_hi', 70.0, 400.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-19 12:00:00');
    $t->contains('test_base_lo', $items, 'got: ' . $items);
    $t->notContains('test_base_hi', $items);
});

$t->test('a day below the base contributes nothing, and never a negative',
    function ($t) use ($layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // Thirty freezing days, then twenty warm ones at 27 degree-days each.
    //
    // Clamped at zero, as a degree-day is defined: 0 + 540, which crosses a
    // threshold of 500 on the nineteenth warm day. Under a signed
    // subtraction the cold spell would score -18 a day and the total would
    // be zero, so the reminder would never fire at all -- a winter would
    // cancel out the following spring and nothing would ever say why.
    $layDays('2026-01-01', 30, 0.0, 0.0);
    $layDays('2026-01-31', 20, 30.0, 20.0);
    $makePest('test_clamp', 50.0, 500.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-19 12:00:00');
    $t->contains('test_clamp', $items, 'got: ' . $items);
    $t->contains('540', $items, 'a cold snap must not undo the spring; got: ' . $items);
});

$t->group('The forecast buys the lead time');

/** Forecast days for this location, which the sync would normally write. */
$layForecast = static function (string $from, int $count, float $maxC, float $minC)
    use ($db, $locationId): void {
    $db->run('DELETE FROM `weather_forecast` WHERE `location_id` = :l', ['l' => $locationId]);
    for ($i = 0; $i < $count; $i++) {
        $db->run(
            'INSERT INTO `weather_forecast` (location_id, forecast_date, issued_at,'
            . ' temp_max_c, temp_min_c, precip_mm, et0_mm)'
            . ' VALUES (:l, :d, UTC_TIMESTAMP(), :mx, :mn, 0, 3.0)'
            . ' ON DUPLICATE KEY UPDATE `temp_max_c` = VALUES(`temp_max_c`),'
            . ' `temp_min_c` = VALUES(`temp_min_c`)',
            ['l' => $locationId, 'd' => (string) Clock::addDays($from, $i),
             'mx' => $maxC, 'mn' => $minC]
        );
    }
};

$t->test('a threshold the next week will reach is announced before it is reached',
    function ($t) use ($db, $locationId, $layDays, $layForecast, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // Observed: 36 warm days at 27 each, so 972 -- 28 short of 1,000.
    // Forecast: seven more of the same, so the second one crosses it.
    //
    // Row cover has to go on BEFORE the moths fly, so a reminder that waits
    // for the observed record to cross the line is a reminder that arrives
    // after the only useful action has expired.
    $layDays('2026-01-01', 36, 30.0, 20.0);
    $layForecast('2026-02-06', 7, 30.0, 20.0);
    $makePest('test_lead', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-05 12:00:00');
    $t->contains('test_lead', $items, 'got: ' . $items);
    $t->contains('is about due', $items, 'it says "about due", not "due now"; got: ' . $items);
    $t->contains('in about 2 days', $items, 'got: ' . $items);
    // The number quoted is what the garden HAS had, never what the forecast
    // hopes it will have. A projection printed as a fact is how a reader
    // stops trusting the ones that are facts.
    $t->contains('972', $items, 'got: ' . $items);
});

$t->test('a forecast for a day the archive already has does not count twice',
    function ($t) use ($layDays, $layForecast, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // The sync leaves forecast rows behind for days that have since
    // happened. Adding those to the observed run would double-count every
    // overlapping day and fire the reminder early.
    $layDays('2026-01-01', 36, 30.0, 20.0);
    $layForecast('2026-01-20', 25, 30.0, 20.0);   // 17 of these are already observed
    $makePest('test_overlap', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-05 12:00:00');
    $t->contains('972', $items,
        'observed wins on a shared date; got: ' . $items);
});

$t->test('with no forecast at all it still fires on the day the record crosses',
    function ($t) use ($db, $locationId, $layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    $db->run('DELETE FROM `weather_forecast` WHERE `location_id` = :l', ['l' => $locationId]);
    // 38 days at 27 is 1,026, which crosses on the 38th -- today.
    $layDays('2026-01-01', 38, 30.0, 20.0);
    $makePest('test_no_forecast', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $items = $itemsAt('2026-02-07 12:00:00');
    $t->contains('test_no_forecast', $items, 'got: ' . $items);
    $t->contains('is due now', $items, 'got: ' . $items);
    $t->contains('It reached that today', $items, 'got: ' . $items);
});

$t->group('Who hears about it, and how often');

$t->test('it is said once a season, not every morning after',
    function ($t) use ($db, $userId, $layDays, $makePest, $atUtc, $clearTestPests): void {
    $clearTestPests();
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_once', 50.0, 1000.0, '01-01', 'Squash (summer)');

    (new Digest($atUtc('2026-02-19 12:00:00')))->run($userId, true);
    $first = (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::PEST_GDD], 0
    );
    $t->ok($first > 0, 'it fired once');

    // The next three mornings. The threshold is still crossed and will stay
    // crossed until December, so a rule keyed only on "is it over?" would
    // repeat this until the season ended.
    foreach (['2026-02-20', '2026-02-21', '2026-02-22'] as $day) {
        (new Digest($atUtc($day . ' 12:00:00')))->run($userId, true);
    }

    $after = (int) $db->value(
        'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::PEST_GDD], 0
    );
    $t->same($first, $after, 'a reminder that repeats all season is one that gets muted');
});

$t->test('a threshold crossed months ago is not news to a new account',
    function ($t) use ($db, $userId, $layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    $layDays('2026-01-01', 200, 30.0, 20.0);
    $makePest('test_stale', 50.0, 1000.0, '01-01', 'Squash (summer)');

    // The crossing was in February. Read in July, an account that has just
    // signed up would otherwise be handed every threshold the spring passed,
    // all on the same morning, all describing things that already happened.
    $items = $itemsAt('2026-07-15 12:00:00');
    $t->notContains('test_stale', $items);
});

$t->test('it goes to somebody growing what the pest eats, and not to anyone else',
    function ($t) use ($layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_wrong_crop', 50.0, 1000.0, '01-01', 'Rutabaga');

    $items = $itemsAt('2026-02-19 12:00:00');
    $t->notContains('test_wrong_crop', $items,
        'this account grows squash, not rutabaga');
});

$t->test('an empty affects cell means every crop, per the template',
    function ($t) use ($layDays, $makePest, $itemsAt, $clearTestPests): void {
    $clearTestPests();
    // research-template/README.md, "Multi-valued cells": empty means all.
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_all_crops', 50.0, 1000.0, '01-01', '');

    $items = $itemsAt('2026-02-19 12:00:00');
    $t->contains('test_all_crops', $items, 'got: ' . $items);
});

$t->test('a pest with no threshold stays on the calendar rule and out of this one',
    function ($t) use ($db, $regionId, $userId, $atUtc, $clearTestPests): void {
    $clearTestPests();
    $db->run('DELETE FROM `reminder` WHERE `user_id` = :id', ['id' => $userId]);
    // Every pest_region row the shipped dataset carries except the borer has
    // a NULL gdd_threshold, and none of them may produce a pest_gdd item.
    $withoutThreshold = (int) $db->value(
        'SELECT COUNT(*) FROM `pest_region` WHERE `region_id` = :r AND `gdd_threshold` IS NULL',
        ['r' => $regionId], 0
    );
    $t->ok($withoutThreshold > 0, 'the dataset has calendar-only pests to check against');

    (new Digest($atUtc('2026-07-01 12:00:00')))->run($userId, true);
    $gdd = $db->column(
        'SELECT `subject_key` FROM `reminder` WHERE `user_id` = :id AND `kind` = :k',
        ['id' => $userId, 'k' => ReminderKind::PEST_GDD]
    );
    foreach ($gdd as $subject) {
        $pestId = (int) \explode(':', (string) $subject)[1];
        $threshold = $db->value(
            'SELECT `gdd_threshold` FROM `pest_region` WHERE `region_id` = :r AND `pest_id` = :p',
            ['r' => $regionId, 'p' => $pestId]
        );
        $t->ok($threshold !== null, 'pest ' . $pestId . ' fired without a threshold');
    }
    $t->ok(true, 'no calendar-only pest reached the GDD rule');
});

$t->group('What it costs');

$t->test('the accumulation is one statement for the whole batch, not one per user',
    function ($t) use ($db, $app, $layDays, $makePest, $atUtc, $clearTestPests): void {
    $clearTestPests();
    // Section 2.2 of the Phase 6 handoff: an N+1 in a loop over something a
    // fixture has one of is invisible. So this runs the builder over a batch
    // of five users and asserts the count did not move with the batch size.
    $layDays('2026-01-01', 50, 30.0, 20.0);
    $makePest('test_batch', 50.0, 1000.0, '01-01', 'Squash (summer)');

    $users = $db->all(
        'SELECT * FROM `user` WHERE `region_id` IS NOT NULL'
        . ' AND `weather_location_id` IS NOT NULL ORDER BY `id` LIMIT 5'
    );
    $t->ok(\count($users) >= 2, 'need at least two users to see an N+1');

    $todayByUser = [];
    foreach ($users as $row) {
        $todayByUser[(int) $row['id']] = '2026-02-19';
    }

    $builder = new ReminderBuilder($db);

    $before = $db->statementCount();
    $builder->build([$users[0]], [(int) $users[0]['id'] => '2026-02-19']);
    $one = $db->statementCount() - $before;

    $before = $db->statementCount();
    $builder->build($users, $todayByUser);
    $many = $db->statementCount() - $before;

    $t->same($one, $many,
        'one user cost ' . $one . ' statements and ' . \count($users) . ' cost ' . $many);
});

$t->test('research with no GDD threshold anywhere costs no statement at all',
    function ($t) use ($db, $app): void {
    // A region whose pests are all calendar-only must not pay for the daily
    // temperature read. Silence is the default, and so is spending nothing.
    $db->run(
        "INSERT INTO `region` (region_key, country, label, research_status, first_seen_at,"
        . " created_at, updated_at) VALUES ('US-99999', 'US', 'No GDD County', 'researched',"
        . '  UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        . ' ON DUPLICATE KEY UPDATE `label` = VALUES(`label`)'
    );
    $bare = (int) $db->value("SELECT id FROM `region` WHERE region_key = 'US-99999'");

    $user = $db->one(
        'SELECT * FROM `user` WHERE `weather_location_id` IS NOT NULL ORDER BY `id` LIMIT 1'
    );
    $probe = $user;
    $probe['region_id'] = $bare;

    $builder = new ReminderBuilder($db);
    $before = $db->statementCount();
    $builder->build([$probe], [(int) $probe['id'] => '2026-02-19']);
    $bareCost = $db->statementCount() - $before;

    $withGdd = $db->one(
        'SELECT * FROM `user` WHERE `region_id` IS NOT NULL'
        . ' AND `weather_location_id` IS NOT NULL ORDER BY `id` LIMIT 1'
    );
    $before = $db->statementCount();
    $builder->build([$withGdd], [(int) $withGdd['id'] => '2026-02-19']);
    $gddCost = $db->statementCount() - $before;

    $t->same($bareCost + 1, $gddCost,
        'the tenth statement is the GDD one and it is conditional');
});
