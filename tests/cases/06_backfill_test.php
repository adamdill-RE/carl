<?php

/**
 * Backfill-on-backdate, end to end (Phase 3 handoff Section 3.3).
 *
 * The pieces are each tested elsewhere -- chunkByYear in 04, the backfill
 * window moving in 03 -- but the value is in the chain, and the chain is
 * what Section 3.3 asks to see: a plant started 60 days ago pulls
 * weather_location.backfill_from back with it, the nightly run fetches the
 * gap in year chunks, and the plant report's weather section fills in.
 *
 * The live-install half of Section 3.3 (backdate on the real site, wait for
 * the 05:15 Eastern cron, watch the archive arrive) is an owner action; the
 * checklist for it is in docs/deploy.md.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Core\HttpResult;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;
use Carl\Weather\OpenMeteoClient;
use Carl\Weather\WeatherProvider;
use Carl\Weather\WeatherSync;

$db = $app->db();
$root = $app->root();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

/** Answers every archive range asked for, and records what it was asked. */
$provider = new class implements WeatherProvider {
    /** @var list<array{from:string,to:string}> */
    public array $archiveCalls = [];

    public function archive(float $lat, float $lon, string $tz, string $from, string $to): HttpResult
    {
        $this->archiveCalls[] = ['from' => $from, 'to' => $to];

        $daily = ['time' => []];
        foreach (OpenMeteoClient::ARCHIVE_DAILY as $variable) {
            $daily[$variable] = [];
        }

        $cursor = $from;
        $guard = 0;
        while ($cursor <= $to && $guard < 800) {
            $daily['time'][] = $cursor;
            foreach (OpenMeteoClient::ARCHIVE_DAILY as $variable) {
                $daily[$variable][] = match ($variable) {
                    'temperature_2m_max' => 29.0,
                    'temperature_2m_min' => 17.0,
                    'precipitation_sum'  => 0.0,
                    'et0_fao_evapotranspiration' => 5.0,
                    'weather_code'       => 1,
                    default              => 1.0,
                };
            }
            $cursor = (string) Clock::addDays($cursor, 1);
            $guard++;
        }

        return (new HttpResult('stub://archive', 200, '{}', 0.01, null))
            ->withJson(['daily' => $daily]);
    }

    public function forecast(float $lat, float $lon, string $tz): HttpResult
    {
        return (new HttpResult('stub://forecast', 200, '{}', 0.01, null))->withJson(['daily' => []]);
    }
};

$repo = new UserRepository($db);
$username = 'backfill' . $suffix;
$created = $repo->createWithTemporaryPassword(
    $username, $username . '@example.test', 'Backfill Tester',
    new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
);

$client = new Client($root);
$client->post('/login', ['username' => $username, 'password' => $created['temporary_password']]);
$client->post('/password/reset', [
    'current_password' => $created['temporary_password'],
    'password' => 'backfill-test-passphrase', 'password_confirm' => 'backfill-test-passphrase',
]);
$client->post('/onboarding/profile', ['name' => 'Backfill Tester', 'zip' => '76692']);
$client->post('/onboarding/garden', ['name' => 'Backfill Bed', 'row_count' => '1', 'soil_type' => 'loam']);
$client->post('/onboarding/finish', []);

$userId = (int) $created['id'];
$locationId = (int) ($db->value(
    'SELECT weather_location_id FROM `user` WHERE id = :id', ['id' => $userId]
) ?? 0);

$timezone = (string) $db->value(
    'SELECT timezone FROM `weather_location` WHERE id = :id', ['id' => $locationId]
);
// THE USER'S LOCAL TODAY, NEVER THE SERVER'S -- handoff Section 6, and the
// suite has to obey it as much as the application does. Every event Carl
// writes is dated in the account's own timezone; this account is in
// America/Chicago, so between UTC midnight and local midnight gmdate() and
// the right answer are DIFFERENT DAYS. Asserting the UTC one gives a suite
// that is green all afternoon and red for six hours every night, which is
// worse than a suite that is simply wrong.
// The fixture below and the assertion above it were written with two
// different "today"s -- gmdate() here, todayFor() there -- and agreed all
// afternoon.
$today = $app->clock()->todayFor($timezone);

// The location is shared by everyone at this ZIP, and other tests plant
// there too. Park it far in the future so this test's backdate is the thing
// that moves it, and clear the series so "filled in" means filled by us.
$db->run('UPDATE `weather_location` SET `backfill_from` = :d WHERE `id` = :id',
    ['d' => $today, 'id' => $locationId]);
$db->run('DELETE FROM `weather_daily` WHERE `location_id` = :id', ['id' => $locationId]);
$backdatedTo = (string) Clock::addDays($today, -60);
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

$t->group('A plant started 60 days ago pulls the weather with it');

$t->test('the location starts with no history to speak of', function ($t) use ($db, $locationId, $today): void {
    $t->ok($locationId > 0, 'onboarding attached a weather location');
    $t->same($today, (string) $db->value(
        'SELECT backfill_from FROM `weather_location` WHERE id = :id', ['id' => $locationId]
    ));
    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE location_id = :id', ['id' => $locationId], 0
    ));
});

$plantingId = 0;

$t->test('backdating the start moves backfill_from back to it',
    function ($t) use ($client, $db, $userId, $locationId, $backdatedTo, $plantTypeId, &$plantingId): void {
    $gardens = new Carl\Repo\GardenRepository($db, $userId);
    $garden = $gardens->where('`name` = :n', ['n' => 'Backfill Bed'])[0];
    $row = $gardens->rows((int) $garden['id'])[0];

    $response = $client->post('/plants', [
        'start_method' => 'direct_sow', 'plant_type_id' => (string) $plantTypeId,
        'start_date' => $backdatedTo, 'quantity_initial' => '8',
        'garden_id' => (string) $garden['id'], 'garden_row_id' => (string) $row['id'],
    ]);
    $t->same(303, $response->status);

    $plantings = new PlantingRepository($db, $userId);
    $rows = $plantings->where('`start_date` = :d', ['d' => $backdatedTo], '`id` DESC', 1);
    $t->same(1, \count($rows));
    $plantingId = (int) $rows[0]['id'];

    $t->same($backdatedTo, (string) $db->value(
        'SELECT backfill_from FROM `weather_location` WHERE id = :id', ['id' => $locationId]
    ), 'the window reaches back to the planting, not to today');
});

$t->test('the plant report says the weather is missing rather than pretending',
    function ($t) use ($client, &$plantingId): void {
    $response = $client->get('/plants/' . $plantingId);
    $t->same(200, $response->status);
    $t->contains('fetched yet', $response->body,
        'a gap is rendered as a gap (handoff 8.1)');
});

$t->group('The nightly run fetches the gap');

$t->test('the fetch is chunked by calendar year and reaches back to backfill_from',
    function ($t) use ($app, $provider, $locationId, $backdatedTo, $today): void {
    $provider->archiveCalls = [];
    $sync = new WeatherSync($app, $provider);
    $sync->run(['archive'], $locationId);

    $t->ok($provider->archiveCalls !== [], 'the archive was asked for something');

    $earliest = \min(\array_map(static fn (array $c): string => $c['from'], $provider->archiveCalls));
    $t->same($backdatedTo, $earliest, 'the oldest range starts at backfill_from');

    // Nothing crosses a year boundary: max_execution_time is 30 s and the
    // browser fallback shares this code (weather.md Sections 2 and 3.1).
    foreach ($provider->archiveCalls as $call) {
        $t->same(\substr($call['from'], 0, 4), \substr($call['to'], 0, 4),
            'range ' . $call['from'] . '..' . $call['to'] . ' stays inside one year');
    }

    $held = (int) $app->db()->value(
        'SELECT COUNT(*) FROM `weather_daily` WHERE location_id = :id AND obs_date >= :from',
        ['id' => $locationId, 'from' => $backdatedTo],
        0
    );
    $expected = (int) Clock::daysBetween($backdatedTo, $today);   // through yesterday
    $t->same($expected, $held, 'every day from the planting to yesterday is held');
});

$t->test('and the plant report fills in', function ($t) use ($client, &$plantingId): void {
    $response = $client->get('/plants/' . $plantingId);
    $t->same(200, $response->status);
    $t->notContains('fetched yet', $response->body, 'the gap notice is gone');
    $t->contains('Days covered', $response->body);
});
