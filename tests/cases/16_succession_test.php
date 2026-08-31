<?php

/**
 * Succession planting (handoff Section 15, deferred to v2 since Phase 1).
 *
 * Phase 6 handoff Section 3.2: "What is missing is a decision about what the
 * screen IS." It is both -- a planner at `/succession` that lays the whole
 * season out, and a digest reminder that is the one line of it true today.
 * They share `Carl\Planting\Succession` and there is no third copy of the
 * arithmetic, which is what these tests are mostly here to keep true.
 *
 * The subtle part is the window that wraps the new year. "11-01" to "02-15"
 * is one winter; read as a plain string comparison it is a window that is
 * never open, and every winter crop silently vanishes from the planner.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\ReminderKind;
use Carl\Planting\Succession;
use Carl\Reminders\Digest;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$t->group('A recurring window resolved onto the calendar');

$t->test('a normal window resolves to the occurrence today is in, or the next',
    function ($t): void {
    $t->same(['2026-03-15', '2026-06-30'],
        Succession::windowDates('2026-04-01', '03-15', '06-30'), 'inside it');
    $t->same(['2026-03-15', '2026-06-30'],
        Succession::windowDates('2026-01-10', '03-15', '06-30'), 'before it');
    $t->same(['2027-03-15', '2027-06-30'],
        Succession::windowDates('2026-08-01', '03-15', '06-30'), 'after it');
});

$t->test('a window that wraps the new year is one season, not a backward one',
    function ($t): void {
    // Read in January, the window that is OPEN started last November.
    $t->same(['2025-11-01', '2026-02-15'],
        Succession::windowDates('2026-01-10', '11-01', '02-15'), 'mid-winter');
    // Read in December, it started this November.
    $t->same(['2026-11-01', '2027-02-15'],
        Succession::windowDates('2026-12-05', '11-01', '02-15'), 'december');
    // Read in June, the next one has not opened yet.
    $t->same(['2026-11-01', '2027-02-15'],
        Succession::windowDates('2026-06-15', '11-01', '02-15'), 'summer');
});

$t->test('a window with no end is a single opening date', function ($t): void {
    $t->same(['2026-03-15', null], Succession::windowDates('2026-01-01', '03-15', null));
    $t->same(['2027-03-15', null], Succession::windowDates('2026-06-01', '03-15', null));
    $t->same(null, Succession::windowDates('2026-01-01', null, '06-30'), 'no start, no window');
    $t->same(null, Succession::windowDates('2026-01-01', 'nonsense', '06-30'));
});

$t->group('The schedule');

$t->test('rounds start today when the window is open, and never in the past',
    function ($t): void {
    $rounds = Succession::schedule('2026-07-01', '03-15', '08-15', 50, 60);
    $t->ok($rounds !== []);
    $t->same('2026-07-01', $rounds[0]['sow_on'], 'the first round offered is today');
    foreach ($rounds as $round) {
        $t->ok($round['sow_on'] >= '2026-07-01', 'no round is in the past');
        $t->ok($round['sow_on'] <= '2026-08-15', 'no round is past the window');
    }
});

$t->test('rounds start at the window when it has not opened yet', function ($t): void {
    $rounds = Succession::schedule('2026-01-10', '03-15', '08-15', 50, 60);
    $t->same('2026-03-15', $rounds[0]['sow_on']);
});

$t->test('the interval is the gap, and it is honoured', function ($t): void {
    $rounds = Succession::schedule('2026-03-15', '03-15', '05-15', 50, 60, null, 21);
    $t->same('2026-03-15', $rounds[0]['sow_on']);
    $t->same('2026-04-05', $rounds[1]['sow_on'], 'three weeks later');
    $t->same('2026-04-26', $rounds[2]['sow_on']);
});

$t->test('harvest dates are the sowing plus days to maturity', function ($t): void {
    $rounds = Succession::schedule('2026-04-01', '03-15', '04-01', 50, 60);
    $t->same('2026-04-01', $rounds[0]['sow_on']);
    $t->same('2026-05-21', $rounds[0]['harvest_from'], '50 days on');
    $t->same('2026-05-31', $rounds[0]['harvest_to'], '60 days on');
});

$t->test('a crop with no days to maturity still gets its sowing dates',
    function ($t): void {
    // Every research value is nullable, and a planner that renders nothing
    // because one column is empty is worse than one that renders the dates
    // it does know.
    $rounds = Succession::schedule('2026-04-01', '03-15', '05-01', null, null);
    $t->ok($rounds !== []);
    $t->same(null, $rounds[0]['harvest_from']);
    $t->same(false, $rounds[0]['after_frost'], 'unknown maturity cannot be after the frost');
});

$t->test('a round that ripens past the average first frost is flagged, not hidden',
    function ($t): void {
    // Sown 29 September, 60 days to first pick, average frost 15 November:
    // the first pick lands 28 November and the round is a gamble. The
    // planner says so and still offers it -- a row cover and a mild autumn
    // beat an average more often than a filter would.
    $rounds = Succession::schedule('2026-09-01', '09-01', '10-15', 60, 70, '11-15');
    $flagged = \array_values(\array_filter($rounds,
        static fn (array $r): bool => $r['after_frost']));
    $kept = \array_values(\array_filter($rounds,
        static fn (array $r): bool => !$r['after_frost']));

    $t->ok($kept !== [], 'the early rounds are fine');
    $t->ok($flagged !== [], 'the late ones are marked');
    $t->same('2026-09-29', $flagged[0]['sow_on']);
    $t->ok($flagged[0]['harvest_from'] > '2026-11-15', 'and it is marked because of the date');
});

$t->test('a closed window rolls to next year rather than offering a past date',
    function ($t): void {
    // The calculator answers the question it was asked -- "when may this be
    // sown next" -- and the answer in September is next March. Declining to
    // DRAW that is the planner's policy, tested against the screen below,
    // and it lives there because the digest uses the same calculator and
    // wants no horizon at all.
    $rounds = Succession::schedule('2026-09-01', '03-15', '06-30', 50, 60);
    $t->ok($rounds !== []);
    $t->same('2027-03-15', $rounds[0]['sow_on']);
    foreach ($rounds as $round) {
        $t->ok($round['sow_on'] > '2026-09-01', 'nothing in the past');
    }
});

$t->test('the follow-up is due for one week, a fortnight after sowing',
    function ($t): void {
    $t->same(false, Succession::isFollowUpDue('2026-06-10', '2026-06-01'), '9 days: too soon');
    $t->same(true, Succession::isFollowUpDue('2026-06-15', '2026-06-01'), '14 days: due');
    $t->same(true, Succession::isFollowUpDue('2026-06-20', '2026-06-01'), '19 days: still due');
    $t->same(false, Succession::isFollowUpDue('2026-06-22', '2026-06-01'), '21 days: closed');
    $t->same(false, Succession::isFollowUpDue('2026-09-01', '2026-06-01'), 'months later: no');
});

$t->group('The planner screen');

$repo = new UserRepository($db);
$username = 'succ' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Succession Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);
$userId = (int) $created['id'];

$client = new Client($root);
$client->forgetCookies();
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'succession-test-passphrase', 'password_confirm' => 'succession-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Succession Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Succession Bed ' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$t->test('the page renders and names the crops the research gives windows for',
    function ($t) use ($client): void {
    $response = $client->get('/succession');
    $t->same(200, $response->status);
    $t->contains('Succession planting', $response->body);
    $t->contains('Days between rounds', $response->body);
});

$t->test('every sowing date is a link into Start a New Plant, already filled in',
    function ($t) use ($client): void {
    $response = $client->get('/succession');
    // A planner whose output has to be retyped into another screen is a
    // planner nobody opens twice.
    $t->contains('plants/new/direct_sow?', $response->body);
    $t->contains('plant_type_id=', $response->body);
    $t->contains('start_date=', $response->body);
});

$t->test('the prefilled link arrives with the date and crop in the form',
    function ($t) use ($client, $db): void {
    $type = $db->one(
        'SELECT pt.id, pt.category FROM `plant_type` pt JOIN `plant_region` pr'
        . ' ON pr.plant_type_id = pt.id LIMIT 1'
    );
    $response = $client->get('/plants/new/direct_sow', [
        'plant_type_id' => (string) $type['id'],
        'category'      => (string) $type['category'],
        'start_date'    => '2026-07-04',
    ]);
    $t->same(200, $response->status);
    $t->contains('value="2026-07-04"', $response->body, 'the date came through');
    $t->ok(\preg_match(
        '/<option value="' . (int) $type['id'] . '"[^>]*\\bselected\\b/', $response->body) === 1,
        'the crop came through selected');
});

$t->test('the prefill is three named fields, not any parameter it is handed',
    function ($t) use ($client): void {
    // A form that echoes whatever the query string carries is a form somebody
    // can build a misleading link to.
    $response = $client->get('/plants/new/direct_sow', [
        'label'  => 'PREFILL-INJECTION-PROBE',
        'notes'  => 'PREFILL-INJECTION-PROBE',
    ]);
    $t->same(200, $response->status);
    $t->notContains('PREFILL-INJECTION-PROBE', $response->body);
});

$t->test('the interval is bounded, because it is a loop counter too',
    function ($t) use ($client): void {
    $t->same(200, $client->get('/succession', ['every' => '99999'])->status);
    $t->same(200, $client->get('/succession', ['every' => '-4'])->status);
    $t->same(200, $client->get('/succession', ['every' => 'nonsense'])->status);

    $wide = $client->get('/succession', ['every' => '99999']);
    $t->contains('value="35"', $wide->body, 'clamped to the maximum');
});

$t->test('the page costs one statement per thing it needs, not one per crop',
    function ($t) use ($client, $db): void {
    // The working agreement (Section 17) caps a hot path at five statements.
    // The planner draws every crop in the region on one page, so a per-crop
    // query would be one round trip per table on it.
    $before = $db->statementCount();
    $client->get('/succession');
    $cost = $db->statementCount() - $before;
    $t->ok($cost <= 8, 'the page cost ' . $cost . ' statements');
});

$t->group('The digest half of it');

/** An App whose clock is frozen at a known UTC instant. */
$atUtc = static function (string $instant) use ($app): Carl\Core\App {
    $frozen = new Carl\Core\App($app->config(), $app->root());
    $frozen->setClock(new Clock(new DateTimeImmutable($instant, new DateTimeZone('UTC'))));
    return $frozen;
};

/**
 * A crop whose sowing window is wide open on the date the test reads, so the
 * follow-up has somewhere to land.
 */
$sowable = $db->one(
    'SELECT pt.id, pt.type, pt.category, pr.window_start, pr.window_end'
    . ' FROM `plant_type` pt JOIN `plant_region` pr ON pr.plant_type_id = pt.id'
    . " WHERE (pr.method IS NULL OR pr.method = 'seed') AND pr.window_start IS NOT NULL"
    . " AND pr.window_end IS NOT NULL AND pt.lifecycle = 'annual' AND pt.is_tree = 0"
    . ' AND pt.dtm_days_min IS NOT NULL'
    . ' ORDER BY pt.id LIMIT 1'
);

$t->test('the dataset has a sowable crop to follow up on', function ($t) use ($sowable): void {
    $t->ok($sowable !== null, 'no seed window in the research: the rest of this group is moot');
});

if ($sowable !== null) {
    // A date a fortnight into the window, so "sown 14 days ago" is still
    // inside it and another round is genuinely possible.
    $windowStart = (string) $sowable['window_start'];
    $opened = (string) Clock::recurringOn($windowStart, 2026);
    $sownOn = (string) Clock::addDays($opened, 2);
    $readOn = (string) Clock::addDays($sownOn, Succession::INTERVAL_DAYS);

    $t->test('a fortnight after a sowing, another round is suggested',
        function ($t) use ($db, $userId, $sowable, $sownOn, $readOn, $atUtc): void {
        $db->run('DELETE FROM `planting` WHERE `user_id` = :u', ['u' => $userId]);
        $db->run('DELETE FROM `reminder` WHERE `user_id` = :u', ['u' => $userId]);
        (new PlantingRepository($db, $userId))->insert([
            'plant_type_id'    => (int) $sowable['id'],
            'label'            => 'Round one',
            'start_method'     => 'direct_sow',
            'start_date'       => $sownOn,
            'quantity_initial' => 6,
            'quantity_live'    => 6,
            'state'            => 'planted',
            'state_changed_at' => \gmdate('Y-m-d H:i:s'),
        ]);

        (new Digest($atUtc($readOn . ' 12:00:00')))->run($userId, true);

        $titles = $db->column(
            'SELECT `title` FROM `reminder` WHERE `user_id` = :u AND `kind` = :k',
            ['u' => $userId, 'k' => ReminderKind::SUCCESSION]
        );
        $joined = \implode(' | ', \array_map(\strval(...), $titles));
        $t->contains('You could sow another round of ' . $sowable['type'], $joined,
            'got: ' . $joined);
    });

    $t->test('it is said once for that sowing, not every morning of the week',
        function ($t) use ($db, $userId, $readOn, $atUtc): void {
        $first = (int) $db->value(
            'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :u AND `kind` = :k',
            ['u' => $userId, 'k' => ReminderKind::SUCCESSION], 0
        );
        $t->ok($first > 0);

        foreach ([1, 2, 3] as $day) {
            (new Digest($atUtc((string) Clock::addDays($readOn, $day) . ' 12:00:00')))
                ->run($userId, true);
        }

        $t->same($first, (int) $db->value(
            'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :u AND `kind` = :k',
            ['u' => $userId, 'k' => ReminderKind::SUCCESSION], 0
        ), 'keyed to the sowing it follows up on, so it fires once for it');
    });

    $t->test('a nursery transplant is not a sowing and starts no chain',
        function ($t) use ($db, $userId, $sowable, $sownOn, $readOn, $atUtc): void {
        $db->run('DELETE FROM `planting` WHERE `user_id` = :u', ['u' => $userId]);
        $db->run('DELETE FROM `reminder` WHERE `user_id` = :u', ['u' => $userId]);
        // Buying a tomato start says nothing about whether there is seed
        // left in the packet.
        (new PlantingRepository($db, $userId))->insert([
            'plant_type_id'    => (int) $sowable['id'],
            'label'            => 'Bought in',
            'start_method'     => 'nursery_transplant',
            'start_date'       => $sownOn,
            'in_ground_date'   => $sownOn,
            'quantity_initial' => 2,
            'quantity_live'    => 2,
            'state'            => 'planted',
            'state_changed_at' => \gmdate('Y-m-d H:i:s'),
        ]);

        (new Digest($atUtc($readOn . ' 12:00:00')))->run($userId, true);

        $t->same(0, (int) $db->value(
            'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :u AND `kind` = :k',
            ['u' => $userId, 'k' => ReminderKind::SUCCESSION], 0
        ));
    });

    $t->test('nine days after sowing is too soon to hear about it',
        function ($t) use ($db, $userId, $sowable, $sownOn, $atUtc): void {
        $db->run('DELETE FROM `planting` WHERE `user_id` = :u', ['u' => $userId]);
        $db->run('DELETE FROM `reminder` WHERE `user_id` = :u', ['u' => $userId]);
        (new PlantingRepository($db, $userId))->insert([
            'plant_type_id'    => (int) $sowable['id'],
            'label'            => 'Round one',
            'start_method'     => 'direct_sow',
            'start_date'       => $sownOn,
            'quantity_initial' => 6,
            'quantity_live'    => 6,
            'state'            => 'planted',
            'state_changed_at' => \gmdate('Y-m-d H:i:s'),
        ]);

        (new Digest($atUtc((string) Clock::addDays($sownOn, 9) . ' 12:00:00')))
            ->run($userId, true);

        $t->same(0, (int) $db->value(
            'SELECT COUNT(*) FROM `reminder` WHERE `user_id` = :u AND `kind` = :k',
            ['u' => $userId, 'k' => ReminderKind::SUCCESSION], 0
        ));
    });
}

$t->test('adding succession cost the digest no statement of its own',
    function ($t) use ($db, $app): void {
    // Succession reads plantings, regions and windows -- all three of which
    // gather() had already fetched for the other kinds. That is the whole
    // reason the calculator takes rows rather than a Database.
    $users = $db->all(
        'SELECT * FROM `user` WHERE `region_id` IS NOT NULL'
        . ' AND `weather_location_id` IS NOT NULL ORDER BY `id` LIMIT 3'
    );
    $t->ok($users !== []);

    $todayByUser = [];
    foreach ($users as $row) {
        $todayByUser[(int) $row['id']] = '2026-05-01';
    }

    $builder = new Carl\Reminders\ReminderBuilder($db);
    $before = $db->statementCount();
    $builder->build($users, $todayByUser);
    $cost = $db->statementCount() - $before;

    // Nine always, plus the conditional GDD read.
    $t->ok($cost <= 10, 'the whole batch cost ' . $cost . ' statements');
});
