<?php

/**
 * The watering timer and the push that reaches a phone (Phase 16; Phase 15
 * handoff Section 3.2).
 *
 * The encryption is tested the way the SMTP client is -- against published
 * arithmetic, not a mock: RFC 8291 Appendix A gives every key, the salt and
 * the ciphertext, and the sender here must produce those bytes exactly. Then
 * the live path is driven with a transport that records the request, and
 * the body it recorded is DECRYPTED with the RFC's receiver key, which is
 * the only way to know a phone would show what was meant.
 *
 * The rest is time. A timer is a row with an end; the cron fires what is
 * due against the APPLICATION clock, so a frozen clock fires exactly the
 * timers it means to, and the compare-and-swap fires each once.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\Password;
use Carl\Domain\EventType;
use Carl\Domain\PlantingState;
use Carl\Push\Vapid;
use Carl\Push\WebPush;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\PushSubscriptionRepository;
use Carl\Repo\TimerRepository;
use Carl\Repo\UserRepository;
use Carl\Support\Clock;
use Carl\Tests\Client;
use Carl\Timers\TimerService;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

// RFC 8291 Appendix A, verbatim.
const RFC_PLAINTEXT = 'When I grow up, I want to be a watermelon';
const RFC_UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
const RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
const RFC_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
const RFC_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const RFC_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
const RFC_BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

// ========================================================================
// 1. RFC 8291, byte for byte
// ========================================================================

$t->group('Web Push encryption against RFC 8291 Appendix A');

$t->test('every intermediate and the final body match the published vector', function ($t): void {
    $r = WebPush::encrypt(RFC_PLAINTEXT, RFC_UA_PUBLIC, RFC_AUTH, RFC_AS_PRIVATE, Vapid::b64urlDecode(RFC_SALT));
    $t->same(RFC_AS_PUBLIC, Vapid::b64url($r['as_public']), 'the sender key from its scalar');
    $t->same('kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', Vapid::b64url($r['ecdh_secret']), 'ECDH');
    $t->same('S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg', Vapid::b64url($r['ikm']), 'IKM');
    $t->same('oIhVW04MRdy2XN9CiKLxTg', Vapid::b64url($r['cek']), 'CEK');
    $t->same('4h_95klXJ5E_qnoN', Vapid::b64url($r['nonce']), 'NONCE');
    $t->same(RFC_BODY, Vapid::b64url($r['body']), 'the whole message');
    $t->same(86 + \strlen(RFC_PLAINTEXT) + 1 + 16, \strlen($r['body']), 'header, plaintext, delimiter, tag');
});

$t->test('the receiver side of the same RFC gets the plaintext back', function ($t): void {
    $t->same(RFC_PLAINTEXT, WebPush::decrypt(Vapid::b64urlDecode(RFC_BODY), RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH));
});

$t->test('a fresh salt and key each time, and the receiver still reads it', function ($t): void {
    $a = WebPush::encrypt('{"web_push":8030}', RFC_UA_PUBLIC, RFC_AUTH);
    $b = WebPush::encrypt('{"web_push":8030}', RFC_UA_PUBLIC, RFC_AUTH);
    $t->ok($a['body'] !== $b['body'], 'never the same bytes twice');
    $t->same('{"web_push":8030}', WebPush::decrypt($a['body'], RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH));
    $t->same('{"web_push":8030}', WebPush::decrypt($b['body'], RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH));
});

$t->test('a subscription whose keys are not the shape a browser sends is refused', function ($t): void {
    $t->throws(InvalidArgumentException::class, static fn () => WebPush::encrypt('x', 'AAAA', RFC_AUTH));
    $t->throws(InvalidArgumentException::class, static fn () => WebPush::encrypt('x', RFC_UA_PUBLIC, 'AAAA'));
});

$t->group('VAPID (RFC 8292)');

$t->test('a generated pair signs a token the public half verifies, with the claims Apple wants',
    function ($t): void {
    $pair = Vapid::generate();
    $t->same(65, \strlen(Vapid::b64urlDecode($pair['public'])));
    $t->same(32, \strlen(Vapid::b64urlDecode($pair['private'])));

    $header = Vapid::authorization('https://web.push.apple.com/QF2r4', 'mailto:carl@example.test', $pair, 1_800_000_000);
    $t->same(1, \preg_match('/^vapid t=([^,]+), k=(\S+)$/', $header, $m));
    $t->same($pair['public'], $m[2], 'k is the public key');
    [$h, $c, $s] = \explode('.', $m[1]);
    $t->same(['typ' => 'JWT', 'alg' => 'ES256'], \json_decode(Vapid::b64urlDecode($h), true));
    $claims = \json_decode(Vapid::b64urlDecode($c), true);
    $t->same('https://web.push.apple.com', $claims['aud'], 'the audience is the push service origin, no path');
    $t->same('mailto:carl@example.test', $claims['sub']);
    $t->ok($claims['exp'] > 1_800_000_000 && $claims['exp'] <= 1_800_000_000 + 24 * 3600, 'inside 24 h');
    $t->same(64, \strlen(Vapid::b64urlDecode($s)), 'a raw r||s, not DER');
    $t->ok(Vapid::verify($h . '.' . $c, Vapid::b64urlDecode($s), $pair['public']), 'and it verifies');
    $t->ok(!Vapid::verify($h . '.' . $c . 'x', Vapid::b64urlDecode($s), $pair['public']), 'over exactly that input');
});

$t->test('the pair is made once, in the database, and never replaced', function ($t) use ($db): void {
    $db->run('DELETE FROM `push_key`');
    $first = Vapid::ensure($db);
    $second = Vapid::ensure($db);
    $t->same($first, $second, 'ensure() is idempotent');
    $t->same($first, Vapid::existing($db));
});

// ========================================================================
// 2. A timer is a row with an end
// ========================================================================

$t->group('Starting and cancelling');

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => (int) $created['id'], 'username' => $username, 'password' => $created['temporary_password']];
};

$owner = $makeUser('timerowner' . $suffix);
$stranger = $makeUser('timerother' . $suffix);

$client = new Client($root);
$onboard = static function (array $user, string $bed) use ($client): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => $user['password']]);
    $client->post('/password/reset', [
        'current_password' => $user['password'],
        'password' => 'timer-test-passphrase', 'password_confirm' => 'timer-test-passphrase',
    ]);
    $client->post('/onboarding/profile', ['name' => 'Timer Tester', 'zip' => '76692']);
    $client->post('/onboarding/garden', ['name' => $bed, 'row_count' => '2', 'soil_type' => 'loam']);
    $client->post('/onboarding/finish', []);
};
$onboard($stranger, 'Stranger bed');
$onboard($owner, 'Timer bed');

$gardens = new GardenRepository($db, $owner['id']);
$gardenId = (int) $gardens->where('`name` = :n', ['n' => 'Timer bed'])[0]['id'];
$rows = $gardens->rows($gardenId);
// A drip zone that knows its emitters: half a gallon every 12 in, lines
// 12 in apart, 80% -- about 20 mm/h gross, 16 net.
$zoneId = $gardens->createZone($gardenId, 'Drip east', null, [
    'emitter_gph' => 0.5, 'emitter_spacing_in' => 12.0, 'line_spacing_in' => 12.0, 'efficiency_pct' => 80,
]);
$gardens->setZoneRows($zoneId, [(int) $rows[0]['id']]);

$plantings = new PlantingRepository($db, $owner['id']);
$plantTypeId = (int) $db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1');
$today = $app->clock()->todayFor((string) $db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => $owner['id']]));
$inZone = $plantings->insert([
    'plant_type_id' => $plantTypeId, 'garden_id' => $gardenId, 'garden_row_id' => (int) $rows[0]['id'],
    'label' => 'In the zone', 'start_method' => 'direct_sow',
    'start_date' => (string) Clock::addDays($today, -20), 'in_ground_date' => (string) Clock::addDays($today, -20),
    'quantity_initial' => 3, 'quantity_live' => 3, 'state' => PlantingState::PLANTED,
    'state_changed_at' => \gmdate('Y-m-d H:i:s'),
]);
$outOfZone = $plantings->insert([
    'plant_type_id' => $plantTypeId, 'garden_id' => $gardenId, 'garden_row_id' => (int) $rows[1]['id'],
    'label' => 'Other row', 'start_method' => 'direct_sow',
    'start_date' => (string) Clock::addDays($today, -20), 'in_ground_date' => (string) Clock::addDays($today, -20),
    'quantity_initial' => 2, 'quantity_live' => 2, 'state' => PlantingState::PLANTED,
    'state_changed_at' => \gmdate('Y-m-d H:i:s'),
]);

$timers = new TimerRepository($db, $owner['id']);

/** An App whose clock is frozen at a known UTC instant. */
$atUtc = static function (string $instant) use ($app): Carl\Core\App {
    $frozen = new Carl\Core\App($app->config(), $app->root());
    $frozen->setClock(new Clock(new DateTimeImmutable($instant, new DateTimeZone('UTC'))));
    return $frozen;
};

$t->test('the garden actions form starts one, and the page lists it with its end time',
    function ($t) use ($client, $gardenId, $zoneId, $timers): void {
    $response = $client->post('/timers', [
        'garden_id' => (string) $gardenId, 'water_zone_id' => (string) $zoneId,
        'minutes' => '45', 'log_when_done' => '1',
    ]);
    $t->same(303, $response->status);
    $t->contains('gardens/' . $gardenId . '/actions', (string) $response->headers()['Location']);

    $running = $timers->running($gardenId);
    $t->same(1, \count($running));
    $timer = $running[0];
    $t->same(45, (int) $timer['minutes']);
    $t->same('Drip east', $timer['zone_name']);
    $t->same(45 * 60, \strtotime($timer['ends_at'] . ' UTC') - \strtotime($timer['started_at'] . ' UTC'));
    $t->same(1, (int) $timer['log_when_done']);

    $page = $client->follow($response);
    $t->contains('Timer started: 45 min on Drip east', $page->body);
    $t->contains('logs itself', $page->body);
    $t->contains('id="timers"', $page->body);
});

$t->test('a silly length, a zone from another garden, and a stranger\'s garden are all refused',
    function ($t) use ($client, $gardenId, $zoneId, $stranger, $db): void {
    $t->same(400, $client->post('/timers', ['garden_id' => (string) $gardenId, 'minutes' => '0'])->status);
    $t->same(400, $client->post('/timers', ['garden_id' => (string) $gardenId, 'minutes' => '9999'])->status);

    $strangerGarden = (int) (new GardenRepository($db, $stranger['id']))->where('`name` = :n', ['n' => 'Stranger bed'])[0]['id'];
    $t->same(404, $client->post('/timers', ['garden_id' => (string) $strangerGarden, 'minutes' => '10'])->status,
        'not one of your gardens');

    $strangerGardens = new GardenRepository($db, $stranger['id']);
    $strangerZone = $strangerGardens->createZone($strangerGarden, 'Not yours', null);
    $t->same(400, $client->post('/timers', [
        'garden_id' => (string) $gardenId, 'water_zone_id' => (string) $strangerZone, 'minutes' => '10',
    ])->status, 'a zone that is not in this garden');
});

$t->test('the landing page shows a running timer to its owner and nothing to anyone else',
    function ($t) use ($client, $timers, $gardenId, $stranger, $owner): void {
    $id = (int) $timers->running($gardenId)[0]['id'];
    $page = $client->get('/timers/' . $id);
    $t->same(200, $page->status);
    $t->contains('Still running', $page->body);
    $t->contains('45 min on Drip east', $page->body);

    $client->forgetCookies();
    $client->post('/login', ['username' => $stranger['username'], 'password' => 'timer-test-passphrase']);
    $t->same(404, $client->get('/timers/' . $id)->status);
    $t->same(404, $client->post('/timers/' . $id . '/cancel', [])->status);
    $client->forgetCookies();
    $client->post('/login', ['username' => $owner['username'], 'password' => 'timer-test-passphrase']);
});

$t->test('cancelling stops it, and a cancelled timer never fires',
    function ($t) use ($client, $timers, $gardenId, $atUtc, $db): void {
    $id = (int) $timers->running($gardenId)[0]['id'];
    $response = $client->post('/timers/' . $id . '/cancel', []);
    $t->same(303, $response->status);
    $t->same([], $timers->running($gardenId));

    // Row-specific, not a count: a previous run of this file may have left a
    // timer of its own counting, and firing it here is not this test's
    // business.
    $endsAt = (string) $db->value('SELECT `ends_at` FROM `water_timer` WHERE `id` = :id', ['id' => $id]);
    (new TimerService($atUtc($endsAt)))->fire();
    $t->same(null, $timers->findDetailed($id)['fired_at'], 'a cancelled timer is not due');
    $t->contains('Cancelled before it finished', $client->get('/timers/' . $id)->body);
});

// ========================================================================
// 3. The cron
// ========================================================================

$t->group('Firing');

$t->test('nothing fires before its time; at its time it fires once, logs the watering, and emails',
    function ($t) use ($client, $timers, $gardenId, $zoneId, $atUtc, $db, $owner, $inZone, $outOfZone): void {
    $client->post('/timers', [
        'garden_id' => (string) $gardenId, 'water_zone_id' => (string) $zoneId,
        'minutes' => '30', 'log_when_done' => '1',
    ]);
    $timer = $timers->running($gardenId)[0];
    $id = (int) $timer['id'];
    $endsAt = (string) $timer['ends_at'];

    (new TimerService($atUtc((string) Clock::addDays(\substr($endsAt, 0, 10), -1) . ' 12:00:00')))->fire();
    $t->same(null, $timers->findDetailed($id)['fired_at'], 'not yet');

    $due = (new TimerService($atUtc($endsAt)))->fire();
    $t->ok($due['fired'] >= 1, \implode(' | ', $due['log']));
    $t->same(0, $due['failures'], \implode(' | ', $due['log']));

    $row = $timers->findDetailed($id);
    $t->same($endsAt, $row['fired_at']);
    $t->same('email', $row['notified_via']);
    $t->ok($row['logged_event_id'] !== null, 'the watering was logged');

    $event = $db->one('SELECT * FROM `garden_event` WHERE `id` = :id', ['id' => $row['logged_event_id']]);
    $t->same(EventType::WATERED, $event['event_type']);
    $t->same(30, (int) $event['duration_min']);
    $t->same($zoneId, (int) $event['water_zone_id']);
    $t->same($owner['id'], (int) $event['user_id']);
    $t->same(1, (int) $event['fanout_count'], 'one living plant in the zone\'s row');
    $t->same(1, (int) $db->value('SELECT COUNT(*) FROM `plant_event` WHERE `planting_id` = :p AND `source_garden_event_id` = :e',
        ['p' => $inZone, 'e' => $row['logged_event_id']]), 'and it is the one in the zone');
    $t->same(0, (int) $db->value('SELECT COUNT(*) FROM `plant_event` WHERE `planting_id` = :p AND `source_garden_event_id` = :e',
        ['p' => $outOfZone, 'e' => $row['logged_event_id']]), 'not the one in the other row');

    $mail = $db->one("SELECT * FROM `email_outbox` WHERE `dedupe_key` = :k", ['k' => 'timer:' . $id]);
    $t->ok($mail !== null, 'queued, not sent: the drain sends');
    $t->same(TimerService::MAIL_KIND, $mail['kind']);
    $t->contains('30 min on Drip east', (string) $mail['subject']);
    $t->contains('logged it as a watering', (string) $mail['body_text']);
    $t->contains('/timers/' . $id, (string) $mail['body_text']);

    (new TimerService($atUtc($endsAt)))->fire();
    $t->same($endsAt, $timers->findDetailed($id)['fired_at'], 'fired once, whatever the cron does next');
    $t->same(1, (int) $db->value("SELECT COUNT(*) FROM `email_outbox` WHERE `dedupe_key` = :k", ['k' => 'timer:' . $id]));

    $page = $client->get('/timers/' . $id);
    $t->contains('Finished at', $page->body);
    $t->contains('Logged as a watering', $page->body);
});

$t->test('a timer started without "log it" is not logged by the cron, and the landing page logs it',
    function ($t) use ($client, $timers, $gardenId, $atUtc, $db, $inZone): void {
    $client->post('/timers', ['garden_id' => (string) $gardenId, 'minutes' => '15']);
    $timer = $timers->running($gardenId)[0];
    $id = (int) $timer['id'];
    $t->same(0, (int) $timer['log_when_done']);

    (new TimerService($atUtc((string) $timer['ends_at'])))->fire();
    $row = $timers->findDetailed($id);
    $t->ok($row['fired_at'] !== null, 'fired');
    $t->same(null, $row['logged_event_id'], 'and not logged');

    $page = $client->get('/timers/' . $id);
    $t->contains('Not logged yet', $page->body);
    $t->contains('Log it as a watering', $page->body);

    $before = (int) $db->value('SELECT COUNT(*) FROM `garden_event` WHERE `garden_id` = :g', ['g' => $gardenId]);
    $logged = $client->post('/timers/' . $id . '/log', []);
    $t->same(303, $logged->status);
    $t->same($before + 1, (int) $db->value('SELECT COUNT(*) FROM `garden_event` WHERE `garden_id` = :g', ['g' => $gardenId]));
    $row = $timers->findDetailed($id);
    $t->ok($row['logged_event_id'] !== null);
    $event = $db->one('SELECT * FROM `garden_event` WHERE `id` = :id', ['id' => $row['logged_event_id']]);
    $t->same(15, (int) $event['duration_min']);
    $t->same(null, $event['water_zone_id'], 'the whole garden');
    // Only a ZONE watering fans out to plants (handoff Section 4.7); a
    // whole-garden one is the garden's own event, exactly as the garden
    // actions form records it.
    $t->same(0, (int) $event['fanout_count'], 'no fan-out for the whole garden, as the form does');

    $twice = $client->post('/timers/' . $id . '/log', []);
    $t->same(303, $twice->status);
    $t->contains('already logged', $client->follow($twice)->body);
});

$t->test('with a phone subscribed, the push goes out encrypted to that phone and no mail is queued',
    function ($t) use ($client, $timers, $gardenId, $zoneId, $atUtc, $db, $owner, $app): void {
    Vapid::ensure($db);

    // The browser's half, as push.js posts it: RFC 8291's receiver keys.
    $subscribed = $client->post('/push/subscribe', [
        'endpoint' => 'https://push.example.test/send/abc123', 'p256dh' => RFC_UA_PUBLIC, 'auth' => RFC_AUTH,
    ]);
    $t->same(200, $subscribed->status, $subscribed->body);
    $subs = new PushSubscriptionRepository($db, $owner['id']);
    $t->same(1, \count($subs->live()));

    // Subscribing the same endpoint again is the same row, not a second.
    $client->post('/push/subscribe', [
        'endpoint' => 'https://push.example.test/send/abc123', 'p256dh' => RFC_UA_PUBLIC, 'auth' => RFC_AUTH,
    ]);
    $t->same(1, \count($subs->live()));

    $client->post('/timers', ['garden_id' => (string) $gardenId, 'water_zone_id' => (string) $zoneId,
        'minutes' => '20', 'log_when_done' => '1']);
    $timer = $timers->running($gardenId)[0];
    $id = (int) $timer['id'];

    $sent = [];
    $transport = static function (string $url, string $body, array $headers) use (&$sent): array {
        $sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        return ['status' => 201, 'error' => null];
    };
    $summary = (new TimerService($atUtc((string) $timer['ends_at']), $transport))->fire();
    $t->ok($summary['pushed'] >= 1, \implode(' | ', $summary['log']));
    $t->same(1, \count($sent), 'one phone, one push');
    $t->same('https://push.example.test/send/abc123', $sent[0]['url']);

    $headers = \implode("\n", $sent[0]['headers']);
    $t->contains('Content-Encoding: aes128gcm', $headers);
    $t->contains('Content-Type: application/octet-stream', $headers);
    $t->contains('TTL: ', $headers);
    $t->contains('Urgency: high', $headers);
    $t->contains('Topic: carl-timer', $headers);
    $t->same(1, \preg_match('/Authorization: vapid t=([^,]+), k=(\S+)/', $headers, $m));
    [$h, $c, $s] = \explode('.', $m[1]);
    $t->ok(Vapid::verify($h . '.' . $c, Vapid::b64urlDecode($s), $m[2]), 'signed by the install\'s key');
    $t->same(Vapid::existing($db)['public'], $m[2]);
    $t->same('https://push.example.test', \json_decode(Vapid::b64urlDecode($c), true)['aud']);

    // What the phone would show: decrypt with the receiver's own key.
    $payload = \json_decode(WebPush::decrypt($sent[0]['body'], RFC_UA_PRIVATE, RFC_UA_PUBLIC, RFC_AUTH), true);
    $t->same(8030, $payload['web_push'], 'declarative Web Push');
    $t->same('Drip east is done', $payload['notification']['title']);
    $t->contains('20 min in Timer bed', $payload['notification']['body']);
    $t->contains('Logged as a watering', $payload['notification']['body']);
    $t->contains($app->config()->string('tags.origin'), $payload['notification']['navigate']);
    $t->contains('/timers/' . $id, $payload['notification']['navigate']);

    $t->same('push', $timers->findDetailed($id)['notified_via']);
    $t->same(null, $db->value("SELECT id FROM `email_outbox` WHERE `dedupe_key` = :k", ['k' => 'timer:' . $id]),
        'the phone took it, so no mail');
    $t->ok($subs->live()[0]['last_used_at'] !== null);
});

$t->test('a subscription the push service says is gone is marked, and the mail goes instead',
    function ($t) use ($client, $timers, $gardenId, $atUtc, $db, $owner): void {
    $client->post('/timers', ['garden_id' => (string) $gardenId, 'minutes' => '5', 'log_when_done' => '1']);
    $timer = $timers->running($gardenId)[0];
    $id = (int) $timer['id'];

    $gone = static fn (string $url, string $body, array $headers): array => ['status' => 410, 'error' => 'HTTP 410'];
    $summary = (new TimerService($atUtc((string) $timer['ends_at']), $gone))->fire();
    $t->same(0, $summary['pushed']);
    $t->same('email', $timers->findDetailed($id)['notified_via'], 'the fallback that cannot quietly stop existing');
    $t->ok($db->value("SELECT id FROM `email_outbox` WHERE `dedupe_key` = :k", ['k' => 'timer:' . $id]) !== null);

    $subs = new PushSubscriptionRepository($db, $owner['id']);
    $t->same([], $subs->live(), 'not tried again');
    $t->contains('410', (string) $subs->all()[0]['fail_reason']);

    // Subscribing again from the same phone brings it back.
    $client->post('/push/subscribe', [
        'endpoint' => 'https://push.example.test/send/abc123', 'p256dh' => RFC_UA_PUBLIC, 'auth' => RFC_AUTH,
    ]);
    $t->same(1, \count($subs->live()));
    $client->post('/push/unsubscribe', ['endpoint' => 'https://push.example.test/send/abc123']);
    $t->same([], $subs->all(), 'and asking to stop removes it');
});

$t->test('a subscription that is not the shape a browser sends is refused', function ($t) use ($client): void {
    $t->same(400, $client->post('/push/subscribe', ['endpoint' => 'http://plain.example/x', 'p256dh' => RFC_UA_PUBLIC, 'auth' => RFC_AUTH])->status);
    $t->same(400, $client->post('/push/subscribe', ['endpoint' => 'https://push.example.test/y', 'p256dh' => 'AAAA', 'auth' => RFC_AUTH])->status);
});

$t->test('the cron twin answers with a key and is 404 without one', function ($t) use ($client, $app): void {
    $key = $app->config()->secret('cron_key');
    if ($key === null) {
        $t->ok(true, 'no cron key configured; the twin cannot be tried');
        return;
    }
    $client->forgetCookies();
    $t->same(404, $client->get('/tasks/timers-fire')->status);
    $response = $client->get('/tasks/timers-fire', ['key' => $key]);
    $t->same(200, $response->status);
    $t->contains('timers fire', $response->body);
});

// ========================================================================
// 4. The one-tap on the MOTD
// ========================================================================

$t->group('The one-tap timer on the main menu');

$t->test('a recommendation to water offers the zone\'s refill minutes as a button, from the stored deficit',
    function ($t) use ($client, $owner, $gardenId, $zoneId, $db, $today): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $owner['username'], 'password' => 'timer-test-passphrase']);

    // The nightly model's row, as it would have stored it: a 12 mm deficit,
    // water today. "About 45 min on Drip east refills it" is what the
    // sentence would say at 16 mm/h net.
    $db->run(
        'INSERT INTO `watering_recommendation` (user_id, garden_id, container_id, place_key, for_date, tier,'
        . ' deficit_mm, taw_mm, mad_mm, reason_text, computed_at)'
        . " VALUES (:u, :g, NULL, :k, :d, 'water', 12.00, 50, 25, :reason, UTC_TIMESTAMP())"
        . ' ON DUPLICATE KEY UPDATE `deficit_mm` = 12.00, `tier` = \'water\'',
        ['u' => $owner['id'], 'g' => $gardenId, 'k' => 'g:' . $gardenId, 'd' => $today,
         'reason' => 'Root zone about 76% full; deficit 12 mm of an allowed 25. Water today.']
    );

    $expected = \Carl\Domain\DripLine::minutesFor(12.0, 20.117, 80);
    $page = $client->get('/');
    $t->same(200, $page->status);
    $t->contains('Start ' . $expected . ' min on Drip east', $page->body);
    $t->contains('name="water_zone_id" value="' . $zoneId . '"', $page->body);

    // And the button does what it says.
    $started = $client->post('/timers', [
        'garden_id' => (string) $gardenId, 'water_zone_id' => (string) $zoneId,
        'minutes' => (string) $expected, 'log_when_done' => '1', 'return' => 'menu',
    ]);
    $t->same(303, $started->status);
    $t->contains('#timers', (string) $started->headers()['Location']);
    $menu = $client->follow($started);
    $t->contains('Timer started: ' . $expected . ' min on Drip east', $menu->body);
    $t->contains('<h3 id="timers">Timers</h3>', $menu->body);
    $t->contains('logs itself', $menu->body);

    // Tidy: a timer left counting fires in the next run of this file.
    foreach ((new TimerRepository($db, $owner['id']))->running() as $left) {
        $client->post('/timers/' . $left['id'] . '/cancel', []);
    }
});

$t->test('a garden whose zones know no emitters offers no button', function ($t) use ($client, $db, $owner, $today): void {
    $gardens = new GardenRepository($db, $owner['id']);
    $bare = $gardens->insert(['name' => 'Bare bed ' . \substr(\bin2hex(\random_bytes(2)), 0, 4), 'row_count' => 1, 'soil_type' => 'loam']);
    $gardens->createZone($bare, 'Hose', null);
    $db->run(
        'INSERT INTO `watering_recommendation` (user_id, garden_id, container_id, place_key, for_date, tier,'
        . ' deficit_mm, taw_mm, mad_mm, reason_text, computed_at)'
        . " VALUES (:u, :g, NULL, :k, :d, 'water', 12.00, 50, 25, 'Water today.', UTC_TIMESTAMP())",
        ['u' => $owner['id'], 'g' => $bare, 'k' => 'g:' . $bare, 'd' => $today]
    );
    $page = $client->get('/');
    $t->notContains('min on Hose', $page->body);
    $client->forgetCookies();
});
