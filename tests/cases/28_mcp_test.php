<?php

/**
 * The MCP server (Phase 16; Phase 15 handoff Section 3.1).
 *
 * Five things here can only be caught by a test:
 *
 *  1. **The token is the whole credential.** No session, no cookie, no CSRF
 *     -- so the test that matters is that a request WITHOUT one gets nothing,
 *     a wrong one learns nothing, and a revoked one stops the same second.
 *  2. **Isolation.** A tool that returns another account's plant is the same
 *     bug as a page that does, and it is the easiest bug to write here
 *     because a tool is a function with an id in it. Every tool that takes
 *     an id is tried with a stranger's.
 *  3. **The transport is exactly one shape.** One POST, one message, JSON
 *     back; a GET is 405, a batch is 400, a notification is 202 with no
 *     body. Claude Code is the client and it expects precisely this.
 *  4. **The bounds hold.** A result over the cap is an error, not a
 *     truncated document; a token over its minute is 429, not a slower
 *     server.
 *  5. **The statement count.** Each tool is one ordinary request, and the
 *     one that lists plants must not cost a statement per plant.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\ApiTokenStore;
use Carl\Auth\Password;
use Carl\Mcp\Server;
use Carl\Mcp\Tools;
use Carl\Repo\GardenRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$makeUser = static function (string $username) use ($db, $app): array {
    $repo = new UserRepository($db);
    $created = $repo->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => (int) $created['id'], 'username' => $username, 'password' => $created['temporary_password']];
};

$owner = $makeUser('mcpowner' . $suffix);
$stranger = $makeUser('mcpother' . $suffix);

$client = new Client($root);
$plantTypeId = (int) ($db->value('SELECT id FROM `plant_type` ORDER BY id LIMIT 1') ?? 0);

/** Sign in, reset, onboard at 76692, and plant one thing with a known label. */
$onboard = static function (array $user, string $label) use ($client, $plantTypeId): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $user['username'], 'password' => $user['password']]);
    $client->post('/password/reset', [
        'current_password' => $user['password'],
        'password' => 'mcp-test-passphrase', 'password_confirm' => 'mcp-test-passphrase',
    ]);
    $client->post('/onboarding/profile', ['name' => 'MCP Tester', 'zip' => '76692']);
    $client->post('/onboarding/garden', ['name' => 'Bed of ' . $label, 'row_count' => '2', 'soil_type' => 'loam']);
    $client->post('/onboarding/finish', []);
    $client->post('/plants', [
        'start_method' => 'indoor_seed', 'plant_type_id' => (string) $plantTypeId,
        'quantity_initial' => '6', 'label' => $label,
    ]);
};

$onboard($stranger, 'Stranger Pepper');
$onboard($owner, 'Mcp Tomato');

$ownerPlantings = new PlantingRepository($db, $owner['id']);
$ownerPlantId = (int) $ownerPlantings->where('', [], '`id` DESC', 1)[0]['id'];
$strangerPlantId = (int) (new PlantingRepository($db, $stranger['id']))->where('', [], '`id` DESC', 1)[0]['id'];
$ownerGardenId = (int) (new GardenRepository($db, $owner['id']))->where('`name` = :n', ['n' => 'Bed of Mcp Tomato'])[0]['id'];
$strangerGardenId = (int) (new GardenRepository($db, $stranger['id']))->where('`name` = :n', ['n' => 'Bed of Stranger Pepper'])[0]['id'];

// The owner logs one garden-level action, so garden_actions has a row.
$client->post('/gardens/' . $ownerGardenId . '/actions', [
    'event_type' => 'mulched', 'mulch_new' => 'Straw', 'narrative' => 'North end only',
]);
$client->forgetCookies();

$store = new ApiTokenStore($db);

// ========================================================================
// 1. The token
// ========================================================================

$t->group('The bearer token');

$t->test('a fresh token resolves to its account, and the row is not a working token',
    function ($t) use ($store, $db, $owner): void {
    $issued = $store->issue($owner['id'], 'laptop');
    $t->ok(\str_starts_with($issued['token'], ApiTokenStore::PREFIX), 'prefixed, so a secret scanner can spot it');

    $resolved = $store->resolve($issued['token']);
    $t->same(ApiTokenStore::RESOLVED, $resolved['status']);
    $t->same($owner['id'], $resolved['user_id']);

    [$selector, $verifier] = \explode('.', \substr($issued['token'], \strlen(ApiTokenStore::PREFIX)), 2);
    $row = $db->one('SELECT * FROM `api_token` WHERE `selector` = :s', ['s' => $selector]);
    $t->ok($row !== null);
    $t->same(\hash('sha256', $verifier), (string) $row['verifier_hash'], 'only a hash is kept');
    $t->notContains($verifier, (string) $row['verifier_hash']);
    $t->ok($row['last_used_at'] !== null, 'a call writes last_used_at');
    $t->same(1, (int) $row['calls']);
});

$t->test('a wrong verifier, a made-up token and no token at all are all simply unknown',
    function ($t) use ($store, $owner): void {
    $issued = $store->issue($owner['id'], 'x');
    $t->same(ApiTokenStore::UNKNOWN, $store->resolve(\substr($issued['token'], 0, -4) . 'ffff')['status']);
    $t->same(ApiTokenStore::UNKNOWN, $store->resolve('carl_nothing.at.all')['status']);
    $t->same(ApiTokenStore::UNKNOWN, $store->resolve('')['status']);
    $t->same(ApiTokenStore::UNKNOWN, $store->resolve(null)['status']);
});

$t->test('revoking stops it, and only its owner can revoke it',
    function ($t) use ($store, $owner, $stranger): void {
    $issued = $store->issue($owner['id'], 'to revoke');
    $t->ok(!$store->revoke($stranger['id'], $issued['id']), 'not the stranger');
    $t->same(ApiTokenStore::RESOLVED, $store->resolve($issued['token'])['status'], 'still live');
    $t->ok($store->revoke($owner['id'], $issued['id']));
    $t->same(ApiTokenStore::REVOKED, $store->resolve($issued['token'])['status']);
    $t->ok(!$store->revoke($owner['id'], $issued['id']), 'and not twice');
});

$t->test('the rate limit is per token per minute, and a refused call is not counted',
    function ($t) use ($db, $owner): void {
    $tight = new ApiTokenStore($db, 3);
    $issued = $tight->issue($owner['id'], 'busy');
    for ($i = 0; $i < 3; $i++) {
        $t->same(ApiTokenStore::RESOLVED, $tight->resolve($issued['token'])['status'], 'call ' . ($i + 1));
    }
    $refused = $tight->resolve($issued['token']);
    $t->same(ApiTokenStore::RATE_LIMITED, $refused['status']);
    $t->ok($refused['retry_after'] >= 1 && $refused['retry_after'] <= 60, 'says when to come back');

    $row = $db->one('SELECT `calls`, `window_calls` FROM `api_token` WHERE `id` = :id', ['id' => $issued['id']]);
    $t->same(3, (int) $row['calls'], 'the refused call was not counted');

    // Another token of the same user is unaffected: the limit is the token's.
    $other = $tight->issue($owner['id'], 'other');
    $t->same(ApiTokenStore::RESOLVED, $tight->resolve($other['token'])['status']);
});

// ========================================================================
// 2. The transport
// ========================================================================

$t->group('POST /mcp is one message in, one message out');

$token = $store->issue($owner['id'], 'the suite')['token'];

/** One JSON-RPC message, as Claude Code sends it. */
$rpc = static function (string $method, array $params = [], mixed $id = 1, array $headers = [], ?string $bearer = null)
    use ($client, $token): \Carl\Core\Response {
    $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    if ($id !== null) {
        $message['id'] = $id;
    }
    return $client->postRaw('/mcp', (string) \json_encode($message), $headers + [
        'Authorization' => 'Bearer ' . ($bearer ?? $token),
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json, text/event-stream',
    ]);
};

$decode = static fn (\Carl\Core\Response $r): array => (array) \json_decode($r->body, true);

$t->test('no token is 401 with a challenge, and a wrong one is the same 401',
    function ($t) use ($client): void {
    $body = (string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    $bare = $client->postRaw('/mcp', $body, ['Content-Type' => 'application/json']);
    $t->same(401, $bare->status);
    $t->contains('Bearer', (string) ($bare->headers()['WWW-Authenticate'] ?? ''));
    $t->same(-32001, (int) ((array) \json_decode($bare->body, true))['error']['code']);
    $t->same([], $bare->cookies(), 'and no session was started for it');

    $wrong = $client->postRaw('/mcp', $body, ['Authorization' => 'Bearer carl_' . \str_repeat('0', 32) . '.' . \str_repeat('0', 64)]);
    $t->same(401, $wrong->status);
});

$t->test('a GET is 405: this server never opens a stream', function ($t) use ($client, $token): void {
    $response = $client->postRaw('/mcp', '', ['Authorization' => 'Bearer ' . $token], 'GET');
    $t->same(405, $response->status);
});

$t->test('initialize answers with the protocol version, the capabilities and no session id',
    function ($t) use ($rpc, $decode): void {
    $response = $rpc('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities'    => new stdClass(),
        'clientInfo'      => ['name' => 'claude-code', 'version' => '2.0'],
    ]);
    $t->same(200, $response->status);
    $t->contains('application/json', $response->headers()['Content-Type']);
    $t->ok(!isset($response->headers()['Mcp-Session-Id']), 'stateless: no session to hold');
    $t->contains('no-store', $response->headers()['Cache-Control']);

    $reply = $decode($response);
    $t->same('2.0', $reply['jsonrpc']);
    $t->same(1, $reply['id']);
    $t->same('2025-06-18', $reply['result']['protocolVersion']);
    $t->ok(isset($reply['result']['capabilities']['tools']), 'tools');
    $t->ok(isset($reply['result']['capabilities']['resources']), 'resources');
    $t->same('carl', $reply['result']['serverInfo']['name']);
    $t->contains('nothing here writes', $reply['result']['instructions']);
});

$t->test('an older client is answered in its own version; an unknown one gets the newest',
    function ($t) use ($rpc, $decode): void {
    $t->same('2025-03-26', $decode($rpc('initialize', ['protocolVersion' => '2025-03-26']))['result']['protocolVersion']);
    $t->same('2025-06-18', $decode($rpc('initialize', ['protocolVersion' => '1999-01-01']))['result']['protocolVersion']);
});

$t->test('an unsupported MCP-Protocol-Version header is 400; an absent one is fine',
    function ($t) use ($rpc, $decode): void {
    $bad = $rpc('ping', [], 1, ['MCP-Protocol-Version' => '1999-01-01']);
    $t->same(400, $bad->status);
    $t->same(-32600, $decode($bad)['error']['code']);

    $good = $rpc('ping', [], 1, ['MCP-Protocol-Version' => '2025-06-18']);
    $t->same(200, $good->status);
    $t->same([], (array) $decode($good)['result'], 'ping answers with an empty result');
});

$t->test('a notification is 202 with no body', function ($t) use ($rpc): void {
    $response = $rpc('notifications/initialized', [], null);
    $t->same(202, $response->status);
    $t->same('', $response->body);
});

$t->test('a batch, a non-JSON body and an unknown method are each refused by name',
    function ($t) use ($client, $token, $rpc, $decode): void {
    $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

    $batch = $client->postRaw('/mcp', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', $headers);
    $t->same(400, $batch->status);
    $t->contains('batching', $decode($batch)['error']['message']);

    $garbage = $client->postRaw('/mcp', 'not json', $headers);
    $t->same(400, $garbage->status);
    $t->same(-32700, $decode($garbage)['error']['code']);

    $unknown = $rpc('sampling/createMessage', [], 7);
    $t->same(200, $unknown->status, 'a JSON-RPC error is a 200 with an error member');
    $t->same(-32601, $decode($unknown)['error']['code']);
    $t->same(7, $decode($unknown)['id']);
});

$t->test('a foreign Origin is refused before the token is even looked at; our own is fine',
    function ($t) use ($rpc, $decode, $app): void {
    $foreign = $rpc('ping', [], 1, ['Origin' => 'https://evil.example']);
    $t->same(403, $foreign->status);
    $t->same(-32003, $decode($foreign)['error']['code']);

    $own = $rpc('ping', [], 1, ['Origin' => \rtrim($app->config()->string('tags.origin'), '/')]);
    $t->same(200, $own->status);
});

$t->test('a revoked token is 401 from the next request', function ($t) use ($store, $owner, $rpc, $db): void {
    $issued = $store->issue($owner['id'], 'short-lived');
    $t->same(200, $rpc('ping', [], 1, [], $issued['token'])->status);
    $store->revoke($owner['id'], $issued['id']);
    $t->same(401, $rpc('ping', [], 1, [], $issued['token'])->status);
});

$t->test('over the minute it is 429 with Retry-After', function ($t) use ($db, $owner, $client): void {
    // The store the kernel builds reads mcp.calls_per_minute; the window is
    // written in the row, so pre-filling it is what the sixty-first call in
    // a minute would see.
    $issued = (new ApiTokenStore($db))->issue($owner['id'], 'flood');
    $db->run('UPDATE `api_token` SET `window_started_at` = UTC_TIMESTAMP(), `window_calls` = 999 WHERE `id` = :id',
        ['id' => $issued['id']]);
    $response = $client->postRaw('/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ['Authorization' => 'Bearer ' . $issued['token'], 'Content-Type' => 'application/json']);
    $t->same(429, $response->status);
    $t->ok((int) ($response->headers()['Retry-After'] ?? 0) >= 1);
});

// ========================================================================
// 3. The tools
// ========================================================================

$t->group('The tools read one account and nothing else');

/** Call a tool and decode the text it returned. */
$call = static function (string $name, array $arguments = []) use ($rpc, $decode): array {
    $reply = $decode($rpc('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new stdClass() : $arguments]));
    if (isset($reply['error'])) {
        return ['rpc_error' => $reply['error']];
    }
    $result = $reply['result'];
    $text = (string) ($result['content'][0]['text'] ?? '');
    $data = \json_decode($text, true);
    return ['isError' => (bool) ($result['isError'] ?? false), 'text' => $text,
            'data' => \is_array($data) ? $data : []];
};

$t->test('tools/list names the eight, every one read-only, each with a schema',
    function ($t) use ($rpc, $decode): void {
    $tools = $decode($rpc('tools/list'))['result']['tools'];
    $names = \array_column($tools, 'name');
    \sort($names);
    $t->same(['garden_actions', 'list_gardens', 'list_plants', 'pests', 'plant',
              'research_card', 'watering', 'weather'], $names);
    foreach ($tools as $tool) {
        $t->same('object', $tool['inputSchema']['type'], $tool['name'] . ' has a schema');
        $t->same(true, $tool['annotations']['readOnlyHint'], $tool['name'] . ' is read-only');
        $t->ok(\strlen((string) $tool['description']) > 40, $tool['name'] . ' says what it is');
    }
});

$t->test('list_plants returns the owner\'s plant with its place, and not the stranger\'s',
    function ($t) use ($call, $ownerPlantId): void {
    $result = $call('list_plants');
    $t->ok(!$result['isError']);
    $labels = \array_column($result['data']['plantings'], 'label');
    $t->ok(\in_array('Mcp Tomato', $labels, true), 'the owner\'s plant');
    $t->ok(!\in_array('Stranger Pepper', $labels, true), 'and not the other account\'s');
    $t->notContains('"user_id"', $result['text'], 'user_id is noise on a document about one user');

    $bySearch = $call('list_plants', ['search' => 'Tomato', 'limit' => 5]);
    $t->same(1, $bySearch['data']['count']);
    $t->same($ownerPlantId, (int) $bySearch['data']['plantings'][0]['id']);
    $t->same([], $bySearch['data']['plantings'][0]['tags'], 'no stakes on it yet');
});

$t->test('plant returns the record, the timeline, the yield and the research card',
    function ($t) use ($call, $ownerPlantId): void {
    $result = $call('plant', ['id' => $ownerPlantId]);
    $t->ok(!$result['isError'], $result['text']);
    $data = $result['data'];
    $t->same('Mcp Tomato', $data['planting']['label']);
    $t->ok($data['events']['total'] >= 1, 'the seed start is on the timeline');
    $t->same('seed_started', $data['events']['rows'][\count($data['events']['rows']) - 1]['event_type']);
    $t->ok(isset($data['yield']['weight_g']));
    $t->ok(isset($data['research']['plant']['type']), 'the catalogue values ride along');
});

$t->test('a stranger\'s id gets a result that says so, never their plant',
    function ($t) use ($call, $strangerPlantId, $strangerGardenId): void {
    foreach ([
        ['plant', ['id' => $strangerPlantId]],
        ['garden_actions', ['garden_id' => $strangerGardenId]],
        ['weather', ['plant_id' => $strangerPlantId]],
        ['weather', ['garden_id' => $strangerGardenId]],
    ] as [$tool, $arguments]) {
        $result = $call($tool, $arguments);
        $t->ok($result['isError'], $tool . ' is an error result');
        $t->notContains('Stranger', $result['text'], $tool . ' says nothing about whose it is');
        $t->contains('not one of your', $result['text']);
    }
});

$t->test('list_gardens, garden_actions, watering, research_card and pests each answer',
    function ($t) use ($call, $ownerGardenId, $plantTypeId, $db): void {
    $gardens = $call('list_gardens');
    $t->ok(!$gardens['isError']);
    // The bed, plus the indoor garden the seed start made for itself.
    $names = \array_column($gardens['data']['gardens'], 'name');
    $t->ok(\in_array('Bed of Mcp Tomato', $names, true), 'the owner\'s bed');
    $t->ok(!\in_array('Bed of Stranger Pepper', $names, true), 'not the stranger\'s');
    foreach ($gardens['data']['gardens'] as $garden) {
        if ($garden['name'] === 'Bed of Mcp Tomato') {
            $t->same(2, \count($garden['rows']), 'with its rows');
        }
    }

    $actions = $call('garden_actions', ['garden_id' => $ownerGardenId]);
    $t->ok(!$actions['isError']);
    $t->same('mulched', $actions['data']['events'][0]['event_type']);
    $t->same('North end only', $actions['data']['events'][0]['narrative']);

    $watering = $call('watering');
    $t->ok(!$watering['isError']);
    $t->ok(\array_key_exists('places', $watering['data']));

    $type = (string) $db->value('SELECT `type` FROM `plant_type` WHERE `id` = :id', ['id' => $plantTypeId]);
    $search = $call('research_card', ['search' => \substr($type, 0, 4)]);
    $t->ok(!$search['isError']);
    $t->ok(\count($search['data']['matches']) >= 1, 'a search finds the type');
    $card = $call('research_card', ['plant_type_id' => $plantTypeId]);
    $t->same($type, $card['data']['plant']['type']);
    $t->ok(\array_key_exists('windows', $card['data']));

    $pests = $call('pests', ['limit' => 3]);
    $t->ok(!$pests['isError']);
    $t->ok(\count($pests['data']['entries']) <= 3);
    $active = $call('pests', ['active_only' => true]);
    $t->ok(!$active['isError']);
    $t->ok(\array_key_exists('active', $active['data']));
});

$t->test('weather over a range is SI with its credit; a range backwards or too long is refused',
    function ($t) use ($call): void {
    $range = $call('weather', ['from' => '2026-05-01', 'to' => '2026-05-10']);
    $t->ok(!$range['isError'], $range['text']);
    $t->same('C', $range['data']['units']['temperature']);
    $t->ok(\array_key_exists('days', $range['data']));
    $t->ok(\array_key_exists('attribution', $range['data']));

    $backwards = $call('weather', ['from' => '2026-05-10', 'to' => '2026-05-01']);
    $t->same(-32602, $backwards['rpc_error']['code']);
    $long = $call('weather', ['from' => '2020-01-01', 'to' => '2026-01-01']);
    $t->same(-32602, $long['rpc_error']['code']);
});

$t->test('a bad argument is an invalid-params error, not a 500',
    function ($t) use ($call, $rpc, $decode): void {
    $t->same(-32602, $call('plant', ['id' => 'seven'])['rpc_error']['code']);
    $t->same(-32602, $call('plant')['rpc_error']['code']);
    $t->same(-32602, $call('list_plants', ['living' => 'maybe'])['rpc_error']['code']);
    $t->same(-32602, $call('no_such_tool')['rpc_error']['code']);
    $t->same(-32602, $decode($rpc('tools/call', ['arguments' => []]))['error']['code'], 'no name');
});

$t->test('the season summary is a resource, and an unknown uri is not found',
    function ($t) use ($rpc, $decode): void {
    $list = $decode($rpc('resources/list'))['result']['resources'];
    $t->same(Tools::SUMMARY_URI, $list[0]['uri']);

    $read = $decode($rpc('resources/read', ['uri' => Tools::SUMMARY_URI]));
    $t->ok(isset($read['result']['contents'][0]['text']), \json_encode($read));
    $document = \json_decode($read['result']['contents'][0]['text'], true);
    $t->same('carl-analysis', $document['document']);
    $t->same('MCP Tester', $document['gardener']['name']);
    $t->same(1, \count($document['plantings']), 'one planting, this account\'s');

    $missing = $decode($rpc('resources/read', ['uri' => 'carl://nothing']));
    $t->same(Server::RESOURCE_NOT_FOUND, $missing['error']['code']);
});

$t->test('list_plants does not cost a statement per plant', function ($t) use ($client, $db, $rpc): void {
    $rpc('tools/call', ['name' => 'list_plants', 'arguments' => new stdClass()]);
    $spent = $client->app()->db()->statementCount();
    // Two for the token, one for the user, one for the list, one for the tags.
    $t->ok($spent <= 6, 'spent ' . $spent . ' statements');
});

$t->test('a result over the cap is refused with a way to narrow it, never truncated',
    function ($t) use ($app, $owner, $db): void {
    $user = (new \Carl\Auth\Auth($app))->assume($owner['id']);
    $tools = new Tools($app, $user, '2026-06-01', 64);
    $result = $tools->call('list_gardens', []);
    $t->same(true, $result['isError']);
    $t->contains('over the 0 KB cap', $result['content'][0]['text']);
    $t->contains('Narrow it', $result['content'][0]['text']);
});

// ========================================================================
// 4. The Connect screen
// ========================================================================

$t->group('Connect Claude Code');

$t->test('the token is shown once, works, and then stops when revoked',
    function ($t) use ($client, $owner, $db): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $owner['username'], 'password' => 'mcp-test-passphrase']);

    $page = $client->get('/connect');
    $t->same(200, $page->status);
    $t->contains('Make a token', $page->body);

    $minted = $client->post('/connect/tokens', ['label' => 'the phone']);
    $t->same(303, $minted->status);
    $shown = $client->follow($minted);
    $t->same(200, $shown->status);
    $t->same(1, \preg_match('/carl_[0-9a-f]{32}\.[0-9a-f]{64}/', $shown->body, $m), 'the token is on the page');
    $t->contains('claude mcp add --transport http carl', $shown->body);
    $t->contains('the phone', $shown->body);
    $token = $m[0];

    $again = $client->get('/connect');
    $t->notContains($token, $again->body, 'and only once');
    $t->contains('never used', $again->body);

    // The token from the screen opens the endpoint; the browser session is
    // irrelevant to it, so the client's cookies are dropped first.
    $client->forgetCookies();
    $ping = $client->postRaw('/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']);
    $t->same(200, $ping->status);

    $client->post('/login', ['username' => $owner['username'], 'password' => 'mcp-test-passphrase']);
    $id = (int) $db->value('SELECT `id` FROM `api_token` WHERE `label` = :l AND `user_id` = :u',
        ['l' => 'the phone', 'u' => $owner['id']]);
    $revoked = $client->post('/connect/tokens/' . $id . '/revoke', []);
    $t->same(303, $revoked->status);
    $t->contains('revoked', $client->follow($revoked)->body);

    $client->forgetCookies();
    $t->same(401, $client->postRaw('/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'])->status);
});

$t->test('a stranger cannot revoke the owner\'s token by id', function ($t) use ($client, $stranger, $owner, $db): void {
    $client->forgetCookies();
    $client->post('/login', ['username' => $stranger['username'], 'password' => 'mcp-test-passphrase']);
    $id = (int) $db->value('SELECT `id` FROM `api_token` WHERE `user_id` = :u AND `revoked_at` IS NULL ORDER BY `id` DESC LIMIT 1',
        ['u' => $owner['id']]);
    $client->post('/connect/tokens/' . $id . '/revoke', []);
    $t->same(null, $db->value('SELECT `revoked_at` FROM `api_token` WHERE `id` = :id', ['id' => $id]));
    $client->forgetCookies();
});

$t->test('the Reports menu links to it, and a stranger to the site is sent to sign in',
    function ($t) use ($client, $owner): void {
    $client->forgetCookies();
    $t->same(303, $client->get('/connect')->status);

    $client->post('/login', ['username' => $owner['username'], 'password' => 'mcp-test-passphrase']);
    $t->contains('Connect Claude Code', $client->get('/reports')->body);
    $client->forgetCookies();
});
