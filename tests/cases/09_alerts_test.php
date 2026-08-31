<?php

/**
 * The NWS alerts poll (handoff Section 8.4).
 *
 * Against a stub provider, not api.weather.gov: a test suite that called a
 * government service would be flaky, would be rude, and could not produce a
 * freeze warning on demand in August. The real service WAS driven once
 * during development -- a live Frost Advisory over Anchorage stored
 * correctly, and a live Air Quality Alert over Houston was correctly dropped
 * -- and the payload below is that response's shape, verbatim.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Core\HttpResult;
use Carl\Weather\AlertPoller;
use Carl\Weather\AlertProvider;

$db = $app->db();

/** A provider that answers with whatever the test hands it. */
final class StubAlertProvider implements AlertProvider
{
    /** @var list<array<string,mixed>> */
    public array $features = [];
    public ?HttpResult $override = null;
    public int $calls = 0;

    public function activeAt(float $latitude, float $longitude): HttpResult
    {
        $this->calls++;
        if ($this->override !== null) {
            return $this->override;
        }
        return (new HttpResult('stub://alerts', 200, '{}', 0.01, null))
            ->withJson(['features' => $this->features]);
    }
}

/**
 * One GeoJSON feature in the service's real shape. The id, the offset-bearing
 * timestamps and the separate expires/ends are all as api.weather.gov sends
 * them.
 */
$feature = static function (string $event, string $suffix, string $onset, string $expires,
                            ?string $ends = null, string $severity = 'Minor'): array {
    return [
        'id' => 'https://api.weather.gov/alerts/urn:oid:2.49.0.1.840.0.' . $suffix . '.001.1',
        'properties' => [
            'id'       => 'urn:oid:2.49.0.1.840.0.' . $suffix . '.001.1',
            'event'    => $event,
            'severity' => $severity,
            'headline' => $event . ' issued for the test county',
            'onset'    => $onset,
            'expires'  => $expires,
            'ends'     => $ends,
        ],
    ];
};

$makeLocation = static function () use ($db): int {
    $lat = \round(30 + (\random_int(0, 900000) / 100000), 5);
    $lon = \round(-100 + (\random_int(0, 900000) / 100000), 5);
    $db->run(
        'INSERT INTO `weather_location` (label, zip, latitude, longitude, timezone,'
        . ' backfill_from, is_active, created_at)'
        . " VALUES ('Alert test', '00000', :lat, :lon, 'America/Chicago', UTC_DATE(), 1, UTC_TIMESTAMP())",
        ['lat' => $lat, 'lon' => $lon]
    );
    return $db->insertId();
};

$t->group('Which alerts a gardener is told about');

$t->test('the event classes Section 8.4 lists are kept', function ($t): void {
    foreach (['Freeze Warning', 'Frost Advisory', 'Hard Freeze Warning', 'Heat Advisory',
              'Excessive Heat Warning', 'Flood Watch', 'Flood Warning',
              'Severe Thunderstorm Warning', 'High Wind Warning'] as $event) {
        $t->ok(AlertPoller::isKept($event), $event);
    }
    // Case and stray whitespace must not decide it.
    $t->ok(AlertPoller::isKept('  freeze warning  '));
});

$t->test('everything else is dropped', function ($t): void {
    // The service issues a long tail of these. A MOTD carrying all of them
    // is a MOTD nobody reads within a week.
    foreach (['Air Quality Alert', 'Special Weather Statement', 'Rip Current Statement',
              'Coastal Flood Statement', 'Beach Hazards Statement', 'Small Craft Advisory',
              'Test Message'] as $event) {
        $t->ok(!AlertPoller::isKept($event), $event . ' is not a gardener\'s problem');
    }
});

$t->test('frost, freeze and heat are the ones worth telling someone tonight',
    function ($t): void {
    $t->ok(AlertPoller::isUrgentToAGarden('Freeze Warning'));
    $t->ok(AlertPoller::isUrgentToAGarden('Excessive Heat Warning'));
    $t->ok(!AlertPoller::isUrgentToAGarden('High Wind Warning'), 'kept, but it can wait for the digest');
    $t->ok(!AlertPoller::isUrgentToAGarden('Flood Watch'));
});

$t->group('Reading the service response');

$t->test('an offset-bearing timestamp is stored as UTC', function ($t): void {
    // The service sends local time with an offset. Storing the string as it
    // arrives would put a 02:00 freeze warning six hours out of place, which
    // is the difference between a warning and a post-mortem.
    $t->same('2026-09-01 10:00:00', AlertPoller::toUtc('2026-09-01T02:00:00-08:00'));
    $t->same('2026-03-01 08:00:00', AlertPoller::toUtc('2026-03-01T02:00:00-06:00'));
    $t->same('2026-03-01 02:00:00', AlertPoller::toUtc('2026-03-01T02:00:00Z'));
    $t->same(null, AlertPoller::toUtc(null));
    $t->same(null, AlertPoller::toUtc(''));
    $t->same(null, AlertPoller::toUtc('not a date'));
});

$t->test('the later of expires and ends is what hides the alert',
    function ($t) use ($feature): void {
    // 'expires' is when the MESSAGE goes stale and is often much sooner than
    // the weather. A freeze warning whose message expires at 03:00 is still
    // a freeze warning at 04:00.
    $parsed = AlertPoller::parse($feature('Freeze Warning', 'aaa',
        '2026-03-01T02:00:00Z', '2026-03-01T03:00:00Z', '2026-03-01T09:00:00Z'));
    $t->same('2026-03-01 09:00:00', $parsed['expires']);

    $noEnds = AlertPoller::parse($feature('Freeze Warning', 'bbb',
        '2026-03-01T02:00:00Z', '2026-03-01T03:00:00Z', null));
    $t->same('2026-03-01 03:00:00', $noEnds['expires']);
});

$t->test('a feature with no id, or a class we do not keep, is skipped',
    function ($t) use ($feature): void {
    $t->same(null, AlertPoller::parse($feature('Air Quality Alert', 'ccc',
        '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z')));

    $noId = $feature('Freeze Warning', 'ddd', '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z');
    unset($noId['id'], $noId['properties']['id']);
    $t->same(null, AlertPoller::parse($noId));

    $t->same(null, AlertPoller::parse('not an array'));
    $t->same(null, AlertPoller::parse(['properties' => 'wrong shape']));
});

$t->group('The poll');

$t->test('a kept alert is stored, an unkept one is not',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    $provider->features = [
        $feature('Freeze Warning', \bin2hex(\random_bytes(8)),
            '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z', null, 'Severe'),
        $feature('Air Quality Alert', \bin2hex(\random_bytes(8)),
            '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z'),
    ];

    $summary = (new AlertPoller($app, $provider))->run($locationId);

    $t->same(1, $summary['locations']);
    $t->same(1, $summary['stored']);
    $t->same(1, $summary['new']);
    $t->same(0, $summary['failures']);

    $rows = $db->all('SELECT * FROM `weather_alert` WHERE `location_id` = :id', ['id' => $locationId]);
    $t->same(1, \count($rows));
    $t->same('Freeze Warning', $rows[0]['event']);
    $t->same('Severe', $rows[0]['severity']);
    $t->same(1, (int) $rows[0]['is_active']);
});

$t->test('a second poll of the same alert is not a new alert',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    $provider->features = [$feature('Frost Advisory', \bin2hex(\random_bytes(8)),
        '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z')];

    $first = (new AlertPoller($app, $provider))->run($locationId);
    $second = (new AlertPoller($app, $provider))->run($locationId);

    $t->same(1, $first['new']);
    $t->same(0, $second['new'], 'telling someone twice about one frost is how a channel dies');
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `weather_alert` WHERE `location_id` = :id', ['id' => $locationId], 0
    ));
});

$t->test('an alert the service stops listing goes inactive but is not deleted',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    $provider->features = [$feature('Freeze Warning', \bin2hex(\random_bytes(8)),
        '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z')];

    (new AlertPoller($app, $provider))->run($locationId);

    $provider->features = [];
    $summary = (new AlertPoller($app, $provider))->run($locationId);

    $t->same(1, $summary['closed']);
    $row = $db->one('SELECT * FROM `weather_alert` WHERE `location_id` = :id', ['id' => $locationId]);
    $t->ok($row !== null, 'the row stays: it is history, and a plant report will want it');
    $t->same(0, (int) $row['is_active']);
});

$t->test('two locations in the same county each keep their own copy',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    // Migration 009 made nws_id unique on its own. Two users a few ZIPs
    // apart get the same alert id from the service, and that key would have
    // moved the single row from one to the other -- one of them silently
    // stops seeing a freeze warning that is genuinely over their garden.
    $one = $makeLocation();
    $two = $makeLocation();

    $shared = $feature('Hard Freeze Warning', \bin2hex(\random_bytes(8)),
        '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z');
    $provider = new StubAlertProvider();
    $provider->features = [$shared];

    $poller = new AlertPoller($app, $provider);
    $poller->run($one);
    $poller->run($two);

    foreach ([$one, $two] as $locationId) {
        $t->same(1, (int) $db->value(
            'SELECT COUNT(*) FROM `weather_alert` WHERE `location_id` = :id AND `is_active` = 1',
            ['id' => $locationId], 0
        ), 'location ' . $locationId . ' kept its copy');
    }
});

$t->test('a failed fetch writes a run row and changes nothing else',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    $provider->features = [$feature('Freeze Warning', \bin2hex(\random_bytes(8)),
        '2026-03-01T02:00:00Z', '2026-03-01T09:00:00Z')];
    (new AlertPoller($app, $provider))->run($locationId);

    // Now the service falls over. The alert already stored must not be
    // quietly closed by a failure to reach the API.
    $provider->override = (new HttpResult('stub://alerts', 503, '', 0.01, 'HTTP 503'));
    $summary = (new AlertPoller($app, $provider))->run($locationId);

    $t->same(0, $summary['stored']);
    $t->same(0, $summary['closed']);
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `weather_alert` WHERE `location_id` = :id AND `is_active` = 1',
        ['id' => $locationId], 0
    ), 'a broken API is not evidence that the freeze is over');

    $run = $db->one(
        "SELECT * FROM `weather_sync_run` WHERE `location_id` = :id AND `kind` = 'alerts'"
        . ' ORDER BY `id` DESC LIMIT 1',
        ['id' => $locationId]
    );
    $t->same('failed', $run['outcome']);
    $t->same(503, (int) $run['http_status']);
});

$t->test('every poll writes a run row, success or failure',
    function ($t) use ($app, $db, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    (new AlertPoller($app, $provider))->run($locationId);

    $run = $db->one(
        "SELECT * FROM `weather_sync_run` WHERE `location_id` = :id AND `kind` = 'alerts'"
        . ' ORDER BY `id` DESC LIMIT 1',
        ['id' => $locationId]
    );
    $t->ok($run !== null, 'a cron that silently stops is otherwise invisible for months');
    $t->same('ok', $run['outcome']);
    $t->same(0, (int) $run['rows_upserted']);
});

$t->test('a time budget stops the run, and the next one carries on',
    function ($t) use ($app, $db, $makeLocation): void {
    // The browser fallback inherits max_execution_time (30 s) and each
    // location is one HTTP call. Eighty-five test locations took 69 seconds
    // against the real service during development, so a bounded run has to
    // make progress rather than redo the same first locations.
    $ids = [$makeLocation(), $makeLocation(), $makeLocation()];

    $slow = new class implements AlertProvider {
        public int $calls = 0;
        public function activeAt(float $latitude, float $longitude): HttpResult
        {
            $this->calls++;
            \usleep(120000);
            return (new HttpResult('stub://alerts', 200, '{}', 0.12, null))
                ->withJson(['features' => []]);
        }
    };

    $first = (new AlertPoller($app, $slow))->run(null, 0.15);
    $t->ok($first['polled'] < $first['locations'], 'it stopped early');
    $t->ok($first['polled'] >= 1, 'and it got through at least one');
    $t->contains('run it again to continue', \implode(' | ', $first['log']));

    // The ones it did poll now have a run row, so the ordering puts them
    // last and the next call reaches different locations.
    $polledFirst = $db->column(
        "SELECT DISTINCT location_id FROM `weather_sync_run` WHERE kind = 'alerts'"
        . ' AND started_at >= (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)'
    );
    $t->ok(\count($polledFirst) >= 1);

    $second = (new AlertPoller($app, $slow))->run(null, 0.15);
    $t->ok($second['polled'] >= 1, 'the second call polls too, rather than stalling');
});

$t->group('Showing it');

$t->test('the MOTD sees the active alert and not the expired one',
    function ($t) use ($app, $db, $feature, $makeLocation): void {
    $locationId = $makeLocation();
    $provider = new StubAlertProvider();
    $provider->features = [
        $feature('Freeze Warning', \bin2hex(\random_bytes(8)),
            '2026-03-01T02:00:00Z', '2099-01-01T00:00:00Z'),
        $feature('High Wind Warning', \bin2hex(\random_bytes(8)),
            '2020-03-01T02:00:00Z', '2020-03-01T09:00:00Z'),
    ];
    (new AlertPoller($app, $provider))->run($locationId);

    $active = (new Carl\Repo\WeatherRepository($db))->activeAlerts($locationId);
    $events = \array_map(static fn (array $a): string => (string) $a['event'], $active);
    $t->ok(\in_array('Freeze Warning', $events, true));
    $t->ok(!\in_array('High Wind Warning', $events, true),
        'a warning that expired in 2020 is not news');
});
