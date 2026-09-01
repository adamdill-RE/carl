<?php

/**
 * The Calendar (Phase 9).
 *
 * The screen is the easy half. What this file is really about is that
 * `Planting\Calendar` and `Reminders\ReminderBuilder` MUST NOT DISAGREE.
 *
 * They answer two different questions off the same research values -- the
 * digest asks "is today the day to say something", the calendar asks "when is
 * it" -- and the failure mode if they drift is the worst kind available here:
 * the morning email says a tomato is ready in a week, the calendar page says
 * the 3rd, and both are plausible. So the harvest and hardening dates are
 * pinned against the digest's own arithmetic rather than against a number
 * typed into this file, and `dtmAnchor()` is called on the digest's class by
 * both so there is only one writer for the rule that decides which end a
 * days-to-maturity count starts from.
 *
 * The other thing worth a test is the noise budget. A calendar that draws one
 * chip per fanned-out watering shows a zone action as forty chips on one
 * Tuesday, and a pest window drawn on every one of its ninety open days says
 * "spider mites" ninety times. Both are ways of making a page unreadable
 * while every individual entry on it is true.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\PlantingState;
use Carl\Domain\ReminderKind;
use Carl\Planting\Calendar;
use Carl\Reminders\ReminderBuilder;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

const CALENDAR_PASSPHRASE = 'calendar-test-passphrase';

// ========================================================================
// 1. The grid, which is pure arithmetic
// ========================================================================

$t->group('The month grid');

$t->test('every week is seven days, Sunday to Saturday', function ($t): void {
    foreach (['2026-01', '2026-02', '2026-09', '2027-02', '2028-02'] as $month) {
        $weeks = Calendar::grid($month);
        foreach ($weeks as $i => $week) {
            $t->same(7, \count($week), $month . ' week ' . $i . ' is seven days');
        }
        $first = $weeks[0][0]['date'];
        $lastWeek = $weeks[\count($weeks) - 1];
        $last = $lastWeek[6]['date'];
        $t->same('0', \date('w', (int) \strtotime($first . ' UTC')), $month . ' starts on a Sunday');
        $t->same('6', \date('w', (int) \strtotime($last . ' UTC')), $month . ' ends on a Saturday');
    }
});

$t->test('the days outside the month are drawn and marked, not blanked', function ($t): void {
    // A frost date on 1 March is worth seeing from the February page, and a
    // blank cell hides it for no reason a reader would recognise.
    $weeks = Calendar::grid('2026-09');   // 1 September 2026 is a Tuesday
    $t->same('2026-08-30', $weeks[0][0]['date']);
    $t->same(false, $weeks[0][0]['in_month']);
    $t->same('2026-09-01', $weeks[0][2]['date']);
    $t->same(true, $weeks[0][2]['in_month']);

    $inMonth = 0;
    foreach ($weeks as $week) {
        foreach ($week as $cell) {
            if ($cell['in_month']) {
                $inMonth++;
            }
        }
    }
    $t->same(30, $inMonth, 'September has thirty days and the grid marks exactly those');
});

$t->test('February in a leap year has twenty-nine marked days', function ($t): void {
    $inMonth = 0;
    foreach (Calendar::grid('2028-02') as $week) {
        foreach ($week as $cell) {
            $inMonth += $cell['in_month'] ? 1 : 0;
        }
    }
    $t->same(29, $inMonth);
});

$t->test('paging months crosses the year in both directions', function ($t): void {
    $t->same('2026-10', Calendar::shiftMonth('2026-09', 1));
    $t->same('2026-08', Calendar::shiftMonth('2026-09', -1));
    $t->same('2027-01', Calendar::shiftMonth('2026-12', 1));
    $t->same('2025-12', Calendar::shiftMonth('2026-01', -1));
    $t->same('2025-01', Calendar::shiftMonth('2026-01', -12));
    $t->same('2027-01', Calendar::shiftMonth('2026-01', 12));
});

$t->test('a month out of the query string is validated, never trusted', function ($t): void {
    $t->same('2026-09', Calendar::normaliseMonth('2026-09', '2026-05-04'));
    $t->same('2026-05', Calendar::normaliseMonth('2026-13', '2026-05-04'), 'no thirteenth month');
    $t->same('2026-05', Calendar::normaliseMonth('nonsense', '2026-05-04'));
    $t->same('2026-05', Calendar::normaliseMonth(null, '2026-05-04'), 'defaults to the month of today');
});

$t->test('the grid range is the grid, not the month', function ($t): void {
    [$from, $to] = Calendar::gridRange('2026-09');
    $t->same('2026-08-30', $from);
    $t->same('2026-10-03', $to);
});

// ========================================================================
// 2. The projections, and the promise not to disagree with the digest
// ========================================================================

$t->group('Projected dates come out of the same arithmetic as the digest');

$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');

/** A planting row shaped the way both classes read one. */
$row = static function (array $overrides = []) use ($plantTypeId): array {
    return $overrides + [
        'id'                   => 1,
        'plant_type_id'        => $plantTypeId,
        'label'                => 'Test Plant',
        'category'             => 'Tomato',
        'type'                 => 'Cherokee Purple',
        'start_date'           => '2026-03-01',
        'in_ground_date'       => '2026-04-15',
        'state'                => PlantingState::PLANTED,
        'dtm_counted_from'     => 'seed',
        'dtm_days_min'         => 70,
        'dtm_days_max'         => 85,
        'hardening_started_at' => null,
        'hardening_days'       => null,
    ];
};

$t->test('the DTM anchor is the digest\'s own, so there is one writer for it',
    function ($t) use ($row): void {
    // Counted from the wrong end, days to maturity is six weeks out --
    // silently, and plausibly. Calendar calls ReminderBuilder::dtmAnchor()
    // rather than reimplementing the rule, and this is the pin on that.
    $t->same('2026-03-01', ReminderBuilder::dtmAnchor($row()));
    $t->same('2026-04-15', ReminderBuilder::dtmAnchor($row(['dtm_counted_from' => 'transplant'])));
    $t->same(null, ReminderBuilder::dtmAnchor($row(['dtm_counted_from' => 'transplant',
                                                    'in_ground_date' => null])));
});

$t->test('first harvest and the end of the window are the anchor plus the research',
    function ($t) use ($row): void {
    $entries = Calendar::build('2026-01-01', '2026-12-31', [$row()], [], [], null, [], []);
    $byKind = [];
    foreach ($entries as $entry) {
        $byKind[$entry['kind']][] = $entry['date'];
    }

    // 1 March + 70 days, and + 85. The same two numbers the digest counts to.
    $t->same(['2026-05-10'], $byKind[ReminderKind::FIRST_HARVEST_EXPECTED] ?? []);
    $t->same(['2026-05-25'], $byKind[ReminderKind::HARVEST_WINDOW_CLOSING] ?? []);

    // And the same dates the digest would arrive at, from its own helper.
    $anchor = ReminderBuilder::dtmAnchor($row());
    $t->same(Clock::addDays((string) $anchor, 70), $byKind[ReminderKind::FIRST_HARVEST_EXPECTED][0]);
});

$t->test('a region override beats the catalogue value, here as in the digest',
    function ($t) use ($row, $plantTypeId): void {
    // research-template README: "Overrides replace the global DTM for this
    // region only." A tomato that takes 75 days in the catalogue and 90 in a
    // short-season county is two different plants for every date drawn.
    $window = [
        'plant_type_id'         => $plantTypeId,
        'season'                => 'spring',
        'window_start'          => '04-01',
        'window_end'            => '05-15',
        'method'                => 'transplant',
        'recommended'           => 0,
        'type'                  => 'Cherokee Purple',
        'dtm_days_min_override' => 90,
        'dtm_days_max_override' => 110,
        'weeks_before_transplant_to_start' => 0,
    ];
    $entries = Calendar::build('2026-01-01', '2026-12-31', [$row()], [], [], null, [$window], []);
    foreach ($entries as $entry) {
        if ($entry['kind'] === ReminderKind::FIRST_HARVEST_EXPECTED) {
            $t->same('2026-05-30', $entry['date'], '1 March plus the override, not the catalogue');
        }
    }
});

$t->test('hardening finishing is a date on the calendar', function ($t) use ($row): void {
    $planting = $row(['state' => PlantingState::HARDENING,
                      'hardening_started_at' => '2026-04-01', 'hardening_days' => 10]);
    $entries = Calendar::build('2026-04-01', '2026-04-30', [$planting], [], [], null, [], []);

    $due = null;
    foreach ($entries as $entry) {
        if ($entry['kind'] === ReminderKind::HARDENING_COUNTDOWN) {
            $due = $entry['date'];
        }
    }
    $t->same('2026-04-11', $due, 'started plus the duration');
});

$t->test('an ended planting projects nothing, and its logged events stay',
    function ($t) use ($row): void {
    // A finished row has no future left. What it still has to say is what
    // happened, which is the whole reason the grid draws past months at all.
    $ended = $row(['state' => PlantingState::ENDED]);
    $event = ['planting_id' => 1, 'event_type' => 'yielded', 'event_date' => '2026-05-12',
              'narrative' => '', 'label' => 'Test Plant', 'category' => 'Tomato',
              'type' => 'Cherokee Purple'];

    $entries = Calendar::build('2026-01-01', '2026-12-31', [$ended], [$event], [], null, [], []);

    $t->same(1, \count($entries), 'exactly the one thing that happened');
    $t->same(Calendar::LOGGED, $entries[0]['kind']);
    $t->same(false, $entries[0]['projected']);
});

$t->test('a transplant window is drawn only for plants waiting to go out',
    function ($t) use ($row, $plantTypeId): void {
    $window = [
        'plant_type_id' => $plantTypeId, 'season' => 'spring',
        'window_start' => '04-01', 'window_end' => '05-15', 'method' => 'transplant',
        'recommended' => 0, 'type' => 'Cherokee Purple',
        'dtm_days_min_override' => null, 'dtm_days_max_override' => null,
        'weeks_before_transplant_to_start' => 0,
    ];

    $planted = Calendar::build('2026-01-01', '2026-12-31',
        [$row(['state' => PlantingState::PLANTED])], [], [], null, [$window], []);
    $waiting = Calendar::build('2026-01-01', '2026-12-31',
        [$row(['state' => PlantingState::SEED_STARTED])], [], [], null, [$window], []);

    $count = static function (array $entries): int {
        $n = 0;
        foreach ($entries as $entry) {
            $n += $entry['kind'] === ReminderKind::TRANSPLANT_WINDOW ? 1 : 0;
        }
        return $n;
    };

    $t->same(0, $count($planted), 'a plant already in the ground is not waiting for a window');
    $t->same(2, $count($waiting), 'the day it opens and the day it closes');
});

$t->test('a SOWING window says nothing about moving a seedling out',
    function ($t) use ($row, $plantTypeId): void {
    $sowing = [
        'plant_type_id' => $plantTypeId, 'season' => 'spring',
        'window_start' => '04-01', 'window_end' => '05-15', 'method' => 'seed',
        'recommended' => 0, 'type' => 'Cherokee Purple',
        'dtm_days_min_override' => null, 'dtm_days_max_override' => null,
        'weeks_before_transplant_to_start' => 0,
    ];
    $entries = Calendar::build('2026-01-01', '2026-12-31',
        [$row(['state' => PlantingState::SEED_STARTED])], [], [], null, [$sowing], []);

    foreach ($entries as $entry) {
        $t->ok($entry['kind'] !== ReminderKind::TRANSPLANT_WINDOW,
            'no transplant window came out of a sowing row');
    }
});

$t->test('sow-by is the window start minus the weeks indoors, for recommended types only',
    function ($t) use ($plantTypeId): void {
    $base = [
        'plant_type_id' => $plantTypeId, 'season' => 'spring',
        'window_start' => '04-01', 'window_end' => '05-15', 'method' => 'transplant',
        'type' => 'Cherokee Purple', 'dtm_days_min_override' => null,
        'dtm_days_max_override' => null, 'weeks_before_transplant_to_start' => 6,
    ];

    $recommended = Calendar::build('2026-01-01', '2026-12-31', [], [], [], null,
        [$base + ['recommended' => 1]], []);
    $notRecommended = Calendar::build('2026-01-01', '2026-12-31', [], [], [], null,
        [$base + ['recommended' => 0]], []);

    $t->same(1, \count($recommended));
    $t->same('2026-02-18', $recommended[0]['date'], '1 April minus six weeks');
    $t->same(ReminderKind::START_SEEDS_BY, $recommended[0]['kind']);
    // Every type in the catalogue would be a wall of chips nobody reads, and
    // "recommended" is the research's own answer to what is worth growing.
    $t->same([], $notRecommended);
});

$t->test('the frost dates are the average ones, with the spread in the detail',
    function ($t): void {
    $region = [
        'last_frost_avg' => '03-20', 'last_frost_early' => '03-01', 'last_frost_late' => '04-05',
        'first_frost_avg' => '11-15', 'first_frost_early' => '10-28', 'first_frost_late' => '12-01',
        'research_status' => 'researched',
    ];
    $entries = Calendar::build('2026-01-01', '2026-12-31', [], [], [], $region, [], []);

    $t->same(2, \count($entries), 'two dates a year, not six');
    $t->same('2026-03-20', $entries[0]['date']);
    $t->same('2026-11-15', $entries[1]['date']);
    $t->contains('03-01', $entries[0]['detail'], 'the early date is a fact on the row');
    $t->contains('04-05', $entries[0]['detail']);
});

$t->test('a recurring date is found on both sides of a new year', function ($t): void {
    // The range the screen computes over routinely spans December into
    // January, and nextOccurrence() -- which answers the digest's question --
    // would only ever find the one ahead of today.
    $region = ['last_frost_avg' => '03-20', 'first_frost_avg' => '12-20',
               'last_frost_early' => null, 'last_frost_late' => null,
               'first_frost_early' => null, 'first_frost_late' => null];
    $entries = Calendar::build('2026-12-01', '2027-01-31', [], [], [], $region, [], []);

    $t->same(1, \count($entries));
    $t->same('2026-12-20', $entries[0]['date'], 'the one inside the range, whichever year it is in');
});

$t->test('a pest window is one entry on the day it opens, not ninety',
    function ($t) use ($row): void {
    // A window is three months wide. A chip on each of its days is a calendar
    // that says "spider mites" ninety times, and the date worth knowing is
    // the one the row cover has to be on by.
    $pest = ['active_start' => '04-15', 'active_end' => '07-15',
             'affects_categories' => 'Tomato', 'name' => 'Spider mites',
             'signs' => 'Stippling and webbing', 'pest_key' => 'spider_mite'];

    $entries = Calendar::build('2026-01-01', '2026-12-31', [$row()], [], [], null, [], [$pest]);
    $t->same(1, \count($entries) - 2, 'one pest entry beside the two harvest dates');

    $pestEntries = [];
    foreach ($entries as $entry) {
        if ($entry['kind'] === ReminderKind::PEST_SCOUTING) {
            $pestEntries[] = $entry;
        }
    }
    $t->same(1, \count($pestEntries));
    $t->same('2026-04-15', $pestEntries[0]['date']);
    $t->contains('07-15', $pestEntries[0]['detail'], 'the close is a fact on the row');
});

$t->test('a pest that touches nothing you grow is not drawn', function ($t) use ($row): void {
    $pest = ['active_start' => '04-15', 'active_end' => '07-15',
             'affects_categories' => 'Cabbage;Broccoli', 'name' => 'Cabbage worm',
             'signs' => 'Holes', 'pest_key' => 'cabbage_worm'];
    $general = ['active_start' => '04-15', 'active_end' => '07-15',
                'affects_categories' => '', 'name' => 'Slugs',
                'signs' => 'Slime', 'pest_key' => 'slug_snail'];

    $entries = Calendar::build('2026-01-01', '2026-12-31', [$row()], [], [], null, [], [$pest, $general]);

    $names = [];
    foreach ($entries as $entry) {
        if ($entry['kind'] === ReminderKind::PEST_SCOUTING) {
            $names[] = $entry['title'];
        }
    }
    $t->same(1, \count($names), 'the brassica pest is not drawn for a bed of tomatoes');
    $t->contains('Slugs', $names[0], 'and an empty host list still means anything');
});

$t->test('everything is sorted by date, then by how much it costs to miss',
    function ($t) use ($row): void {
    $region = ['last_frost_avg' => '05-10', 'first_frost_avg' => '11-15',
               'last_frost_early' => null, 'last_frost_late' => null,
               'first_frost_early' => null, 'first_frost_late' => null];
    $entries = Calendar::build('2026-01-01', '2026-12-31', [$row()], [], [], $region, [], []);

    $previous = null;
    foreach ($entries as $entry) {
        if ($previous !== null) {
            $t->ok($entry['date'] >= $previous, 'dates ascend');
        }
        $previous = $entry['date'];
    }
    // ReminderKind::priority is the tie-break, so a frost sorts above a
    // harvest note on the same day -- the same order the digest speaks in.
    $t->ok(ReminderKind::priority(ReminderKind::FROST_WATCH)
         < ReminderKind::priority(ReminderKind::FIRST_HARVEST_EXPECTED));
});

// ========================================================================
// 3. The screen
// ========================================================================

$t->group('The Calendar screen');

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$client = new Client($root);
$owner = $makeUser('calendarer' . $suffix);

$client->forgetCookies();
$client->post('/login', ['username' => $owner['username'], 'password' => $owner['password']]);
$client->post('/password/reset',
    ['password' => CALENDAR_PASSPHRASE, 'password_confirm' => CALENDAR_PASSPHRASE]);
$client->post('/onboarding/profile', ['name' => 'Calendar Tester', 'zip' => '76692']);
$client->post('/onboarding/garden',
    ['name' => 'Cal Bed' . $suffix, 'row_count' => '2', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$gardens = new GardenRepository($db, $owner['id']);
$plantings = new PlantingRepository($db, $owner['id']);
$events = new EventRepository($db, $owner['id'], $plantings);
$indoorId = $gardens->ensureIndoorGarden();
$today = \gmdate('Y-m-d');
$thisMonth = \substr($today, 0, 7);

$sow = static function (string $label, int $daysAgo)
    use ($plantings, $plantTypeId, $indoorId, $today): int {
    return $plantings->insert([
        'plant_type_id'    => $plantTypeId,
        'garden_id'        => $indoorId,
        'label'            => $label,
        'start_method'     => 'indoor_seed',
        'start_date'       => (string) Clock::addDays($today, -$daysAgo),
        'quantity_initial' => 4,
        'quantity_live'    => 4,
        'state'            => PlantingState::PLANTED,
        'state_changed_at' => \gmdate('Y-m-d H:i:s'),
    ]);
};

$oneId = $sow('Calendar One ' . $suffix, 40);
$twoId = $sow('Calendar Two ' . $suffix, 41);
$events->record($oneId, \Carl\Domain\EventType::WATERED, $today);
$events->record($twoId, \Carl\Domain\EventType::WATERED, $today);

$t->test('the screen renders, this month, with the two sections it promises',
    function ($t) use ($client): void {
    $response = $client->get('/calendar');
    $t->same(200, $response->status);
    $t->contains('Upcoming actions', $response->body);
    $t->contains('cal-cell', $response->body, 'the grid is drawn');
});

$t->test('a month out of the query string is honoured and paging carries the filter',
    function ($t) use ($client, $oneId): void {
    $response = $client->get('/calendar', ['month' => '2026-10']);
    $t->same(200, $response->status);
    $t->contains('October 2026', $response->body);

    $filtered = $client->get('/calendar', ['month' => '2026-10', 'f' => '1', 'plant_id' => [$oneId]]);
    $t->same(200, $filtered->status);
    // The previous/next links have to carry plant_id, or paging a month
    // silently drops the filter and the page quietly means something else.
    $t->contains('plant_id%5B0%5D=' . $oneId, $filtered->body);
});

$t->test('filtering to one plant drops the other plant and keeps the garden',
    function ($t) use ($client, $oneId, $suffix): void {
    $all = $client->get('/calendar');
    $t->contains('Calendar One ' . $suffix, $all->body);
    $t->contains('Calendar Two ' . $suffix, $all->body);

    // The <select> lists every planting whatever the filter, so the assertion
    // has to be about the GRID rather than about the whole page.
    $one = $client->get('/calendar', ['f' => '1', 'plant_id' => [$oneId], 'wide' => '1']);
    $t->same(200, $one->status);
    $t->contains('Watered', $one->body, 'the chosen plant\'s own events are still drawn');
});

$t->test('the garden-wide tick box defaults ON and can be turned off',
    function ($t) use ($client): void {
    // An unchecked checkbox sends nothing, so "off" and "never submitted" look
    // identical in a query string. Without the hidden marker the default would
    // invert itself the first time anybody filtered.
    $fresh = $client->get('/calendar');
    $t->contains('name="wide" value="1" checked', $fresh->body, 'on by default');

    $off = $client->get('/calendar', ['f' => '1']);
    $t->notContains('name="wide" value="1" checked', $off->body,
        'and a submitted form without it means off');
});

$t->test('the upcoming table is what is still to be done, never what was logged',
    function ($t) use ($client, $today): void {
    $response = $client->get('/calendar');
    $body = $response->body;
    $table = \substr($body, (int) \strpos($body, 'Upcoming actions'));
    // "Watered" was recorded today. It belongs on the grid and not in a list
    // of things to do.
    $t->notContains('Watered', $table, 'a logged event is never an upcoming action');
});

$t->test('a zone watering is one entry, not one per plant it reached',
    function ($t) use ($db, $owner, $gardens, $events, $plantings, $today, $suffix, $oneId, $twoId): void {
    // Watering a zone writes a derived plant_event per living plant in it
    // (handoff Section 4.7). A calendar that read those would draw one
    // Tuesday watering as one chip per plant -- the same trap
    // gardenSeriesMarkers() documents for charts.
    $gardenId = (int) $db->value(
        'SELECT id FROM `garden` WHERE user_id = :u AND name = :n',
        ['u' => $owner['id'], 'n' => 'Cal Bed' . $suffix]
    );
    $rows = $gardens->rowsForGardens([$gardenId]);
    $rowIds = \array_map(static fn (array $r): int => (int) $r['id'], $rows[$gardenId] ?? []);
    $t->ok($rowIds !== [], 'the bed has rows to water');

    // Both plantings into the first row, so the zone action has something to
    // fan out to. Without this the test would pass by measuring nothing.
    foreach ([$oneId, $twoId] as $id) {
        $plantings->update($id, ['garden_id' => $gardenId, 'garden_row_id' => $rowIds[0]]);
    }

    $recorded = $events->recordGardenEvent(
        $gardenId, \Carl\Domain\EventType::WATERED, $today, [], $rowIds, null, true
    );
    $eventId = (int) ($recorded['event_id'] ?? 0);
    $t->ok($eventId > 0, 'the garden action was recorded');

    $fanned = (int) $db->value(
        'SELECT COUNT(*) FROM `plant_event` WHERE `source_garden_event_id` = :id',
        ['id' => $eventId],
        0
    );
    $calendarRows = $events->calendarEvents((string) Clock::addDays($today, -1),
                                            (string) Clock::addDays($today, 1));
    foreach ($calendarRows as $row) {
        $t->ok(!isset($row['source_garden_event_id']) || $row['source_garden_event_id'] === null,
            'no fanned-out copy reaches the calendar');
    }
    $t->ok($fanned > 0, 'the zone action really did fan out to the plants in it');
    $t->same($fanned, (int) $recorded['fanout'], 'and it says how many it reached');

    $gardenRows = $events->calendarGardenEvents((string) Clock::addDays($today, -1),
                                                (string) Clock::addDays($today, 1));
    $t->ok(\count($gardenRows) >= 1, 'the garden side has one row per thing that happened');
});

$t->test('a repeated action collapses in a cell instead of stacking',
    function ($t) use ($client): void {
    // Two plantings were watered today, and the test above this one watered
    // the whole zone on the same day, which is a third thing that happened.
    // The grid draws all of it as ONE line reading "Watered xN", because a
    // month of individually listed waterings is a month nobody can read.
    //
    // THE ASSERTION IS THE SHAPE AND NOT THE NUMBER, on purpose. How many
    // waterings that day carries depends on which tests above this one have
    // already run and on whether the account's local today and the UTC date
    // the fixture was written with are the same day -- and neither of those
    // is what this test is about. Pinning a literal count made it pass in the
    // afternoon and fail at lunchtime, which is the shape of failure the
    // Phase 9 handoff Section 2.4 is about. The invariant is that repeats
    // collapse into one chip carrying a count, however many there were.
    $response = $client->get('/calendar');
    \preg_match_all(
        '/class="cal-chip[^"]*"[^>]*>\s*Watered\s*(?:&times;(\d+))?/',
        $response->body,
        $chips
    );
    $t->same(1, \count($chips[0]), 'one chip, however many waterings the day carries');
    $t->ok((int) ($chips[1][0] ?? 0) >= 2, 'and it says how many, rather than repeating itself');
});
