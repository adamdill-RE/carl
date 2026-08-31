<?php

/**
 * The tokenised set-password link (Phase 5 handoff Section 3.5, which is
 * Phase 3 handoff Section 9.4's outstanding item).
 *
 * Four things here can only be caught by a test:
 *
 *  1. **The password is not in the mail.** That is the whole change. A
 *     refactor that puts it back looks like a helpful convenience and is the
 *     exact exposure this exists to remove, and only a test that reads the
 *     queued body notices.
 *  2. **Single use, under a race.** Two submissions of the same form -- a
 *     double tap, a retried POST -- must set the password once. The
 *     compare-and-swap is invisible in review.
 *  3. **Expiry.** A link that never expires is a standing credential in an
 *     inbox, which is most of what was wrong with mailing the password.
 *  4. **A wrong verifier learns nothing.** Not whether the selector was real,
 *     not whose account it nearly was.
 *
 * The on-screen temporary password is asserted too, because Phase 3 handoff
 * Section 4.1 is explicit that it stays: it is the path that works with no
 * mailbox, and the only one that works the first time an install is stood up.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Auth\InviteStore;
use Carl\Auth\Password;
use Carl\Mail\Outbox;
use Carl\Repo\UserRepository;
use Carl\Tests\Client;

$root = $app->root();
$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

$invites = new InviteStore($db, $app->config()->int('invite.lifetime_days', 7));

$makeUser = static function (string $username) use ($db, $app): array {
    $created = (new UserRepository($db))->createWithTemporaryPassword(
        $username, $username . '@example.test', \ucfirst($username),
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'user'
    );
    return ['id' => $created['id'], 'username' => $username,
            'password' => $created['temporary_password']];
};

$t->group('The invite token itself');

$t->test('a fresh token resolves to its account', function ($t) use ($invites, $makeUser, $suffix): void {
    $user = $makeUser('invitee' . $suffix);
    $token = $invites->issue($user['id'], null, '203.0.113.10');

    $resolved = $invites->resolve($token);
    $t->same(InviteStore::VALID, $resolved['status']);
    $t->same($user['id'], $resolved['user_id']);
});

$t->test('the stored row is not a working link', function ($t) use ($db, $invites, $makeUser): void {
    // Selector.verifier, and only a SHA-256 of the verifier is kept -- the
    // discipline TokenStore uses for the login cookie (hosting Section 8.3).
    // A database copy has to be useless on its own.
    $user = $makeUser('hashed' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');
    [$selector, $verifier] = \explode('.', $token, 2);

    $row = $db->one('SELECT * FROM `password_invite` WHERE `selector` = :s', ['s' => $selector]);
    $t->ok($row !== null);
    $t->same(64, \strlen((string) $row['verifier_hash']));
    $t->notContains($verifier, (string) $row['verifier_hash'], 'the verifier itself is not stored');
    $t->same(\hash('sha256', $verifier), (string) $row['verifier_hash']);
});

$t->test('a wrong verifier on a real selector is simply unknown',
    function ($t) use ($invites, $makeUser): void {
    // Not "used", not "expired" -- unknown. A guessed selector must not learn
    // whether it named a real invitation.
    $user = $makeUser('guessed' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');
    [$selector] = \explode('.', $token, 2);

    $wrong = $invites->resolve($selector . '.' . \str_repeat('a', 64));
    $t->same(InviteStore::UNKNOWN, $wrong['status']);
    $t->same(null, $wrong['user_id']);
});

$t->test('nonsense is refused without touching the database',
    function ($t) use ($invites): void {
    foreach (['', 'nodot', 'short.short', \str_repeat('z', 200)] as $rubbish) {
        $t->same(InviteStore::UNKNOWN, $invites->resolve($rubbish)['status'], $rubbish);
    }
});

$t->test('it is single use, and a used link says so rather than going quiet',
    function ($t) use ($invites, $makeUser): void {
    $user = $makeUser('oneshot' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');
    $resolved = $invites->resolve($token);

    $t->same(true, $invites->markUsed((int) $resolved['invite_id']));
    // Compare-and-swap: the second submission of the same form loses.
    $t->same(false, $invites->markUsed((int) $resolved['invite_id']),
        'two submissions set the password once');

    $t->same(InviteStore::USED, $invites->resolve($token)['status'],
        '"already used" and "not valid" send a person to two different places');
});

$t->test('it expires', function ($t) use ($db, $invites, $makeUser): void {
    $user = $makeUser('stale' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');
    [$selector] = \explode('.', $token, 2);

    $db->run(
        'UPDATE `password_invite` SET `expires_at` = (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        . ' WHERE `selector` = :s',
        ['s' => $selector]
    );
    $t->same(InviteStore::EXPIRED, $invites->resolve($token)['status']);
});

$t->test('issuing a new one cancels the old one', function ($t) use ($invites, $makeUser): void {
    // What makes "send another" mean what an administrator thinks it means.
    // Two live links to one account is one more than anybody intended, and
    // the older one is the one that has been sitting in a mailbox longest.
    $user = $makeUser('resent' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $first = $invites->issue($user['id'], null, '');
    $second = $invites->issue($user['id'], null, '');

    $t->same(InviteStore::UNKNOWN, $invites->resolve($first)['status']);
    $t->same(InviteStore::VALID, $invites->resolve($second)['status']);
});

$t->test('the sweep takes unused expired rows and leaves used ones',
    function ($t) use ($db, $invites, $makeUser): void {
    $used = $makeUser('sweepa' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $unused = $makeUser('sweepb' . \substr(\bin2hex(\random_bytes(3)), 0, 6));

    $usedToken = $invites->issue($used['id'], null, '');
    $invites->markUsed((int) $invites->resolve($usedToken)['invite_id']);
    $invites->issue($unused['id'], null, '');

    $db->run(
        'UPDATE `password_invite` SET `expires_at` = (UTC_TIMESTAMP() - INTERVAL 90 DAY)'
        . ' WHERE `user_id` IN (:a, :b)',
        ['a' => $used['id'], 'b' => $unused['id']]
    );

    $invites->pruneExpired(30);

    $t->same(0, (int) $db->value(
        'SELECT COUNT(*) FROM `password_invite` WHERE `user_id` = :u', ['u' => $unused['id']], 0
    ));
    // Kept: it is what lets the page say "already used" instead of "not valid".
    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `password_invite` WHERE `user_id` = :u', ['u' => $used['id']], 0
    ));
});

$t->group('What the account-creation email carries');

$t->test('a created account still shows its temporary password on screen',
    function ($t) use ($root, $db, $app, $suffix): void {
    // Phase 3 handoff Section 4.1: the on-screen path is not replaced by
    // mail, only supplemented. It works with no mailbox, it works when a
    // message bounces, and it is the only path that works the first time an
    // install is stood up.
    $client = new Client($root);
    // A fresh Client has no cookies, but $_SESSION is a superglobal and the
    // previous test file left one signed in -- and AuthController::login()
    // short-circuits for an already-signed-in request.
    $client->forgetCookies();
    $admin = (new UserRepository($db))->createWithTemporaryPassword(
        'invadmin' . $suffix, 'invadmin' . $suffix . '@example.test', 'Invite Admin',
        new Password($app->config()->int('auth.bcrypt_cost', 11)), 'admin'
    );
    $client->post('/login', ['username' => 'invadmin' . $suffix, 'password' => $admin['temporary_password']]);
    $client->post('/password/reset',
        ['password' => 'invite admin passphrase', 'password_confirm' => 'invite admin passphrase']);

    $response = $client->post('/admin/users', [
        'username' => 'newhand' . $suffix, 'email' => 'newhand' . $suffix . '@example.test',
        'name' => 'New Hand', 'role' => 'user',
    ]);
    $t->same(303, $response->status);

    $html = $client->follow($response)->body;
    $t->contains('Temporary password', $html);
    $t->contains('class="credential"', $html);
});

$t->test('with a mail driver, the queued message carries a link and NOT a password',
    function ($t) use ($root, $db, $app, $suffix): void {
    // The whole point of Section 3.5. A refactor that puts the password back
    // in the body looks like a convenience and is the exposure this removed.
    // Drive AdminController through a real request, with a mail driver
    // configured for the life of this test only. Config reads CARL_-prefixed
    // environment variables over config/app.php, and Client builds a fresh
    // App per request -- so putenv() here is what a configured install looks
    // like, without a config/local.php in the working tree.
    $client = new Client($root);
    $client->forgetCookies();
    \putenv('CARL_MAIL_DRIVER=smtp');
    \putenv('CARL_MAIL_SMTP_PASSWORD=not-a-real-password');
    \putenv('CARL_MAIL_SMTP_HOST=127.0.0.1');

    try {
        $admin = (new UserRepository($db))->createWithTemporaryPassword(
            'invadm2' . $suffix, 'invadm2' . $suffix . '@example.test', 'Invite Admin Two',
            new Password($app->config()->int('auth.bcrypt_cost', 11)), 'admin'
        );
        $client->post('/login',
            ['username' => 'invadm2' . $suffix, 'password' => $admin['temporary_password']]);
        $client->post('/password/reset',
            ['password' => 'second admin passphrase', 'password_confirm' => 'second admin passphrase']);

        $client->post('/admin/users', [
            'username' => 'mailed' . $suffix, 'email' => 'mailed' . $suffix . '@example.test',
            'name' => 'Mailed Hand', 'role' => 'user',
        ]);
    } finally {
        \putenv('CARL_MAIL_DRIVER');
        \putenv('CARL_MAIL_SMTP_PASSWORD');
        \putenv('CARL_MAIL_SMTP_HOST');
    }

    $created = (new UserRepository($db))->findByUsername('mailed' . $suffix);
    $t->ok($created !== null, 'the account was created');

    $message = $db->one(
        'SELECT * FROM `email_outbox` WHERE `user_id` = :u AND `kind` = :k ORDER BY `id` DESC LIMIT 1',
        ['u' => (int) $created['id'], 'k' => Outbox::KIND_TEMPORARY_PASSWORD]
    );
    $t->ok($message !== null, 'a welcome message was queued');

    $body = (string) $message['body_text'];
    $t->contains('/carl/password/setup/', $body, 'it carries a set-password link');
    $t->contains('mailed' . $suffix, $body, 'and the username to sign in with');
    $t->notContains('Password:  ', $body, 'and no password line');

    // The temporary password is 10 characters plus a dash, from a fixed
    // alphabet. Assert the shape is absent rather than a specific value:
    // the point is that no credential of that kind is in the body at all.
    $t->same(0, \preg_match('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', $body),
        'nothing in the body looks like a temporary password');

    $t->same(1, (int) $db->value(
        'SELECT COUNT(*) FROM `password_invite` WHERE `user_id` = :u',
        ['u' => (int) $created['id']], 0
    ), 'and exactly one invitation was issued');
});

$t->group('Setting a password through the link');

$t->test('the link is reachable signed out, and every failure says which failure it is',
    function ($t) use ($root, $db, $invites, $makeUser): void {
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('landing' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    $response = $client->get('/password/setup/' . $token);
    $t->same(200, $response->status, 'no sign-in needed -- that is the point');
    $t->contains('Set your password', $response->body);
    $t->contains($user['username'], $response->body);

    $t->contains('not one Carl recognises',
        $client->get('/password/setup/' . \str_repeat('a', 32) . '.' . \str_repeat('b', 64))->body);

    $db->run(
        'UPDATE `password_invite` SET `expires_at` = (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        . ' WHERE `user_id` = :u',
        ['u' => $user['id']]
    );
    $t->contains('has expired', $client->get('/password/setup/' . $token)->body);
});

$t->test('setting a password through it signs the person in, with no forced reset after',
    function ($t) use ($root, $db, $invites, $makeUser): void {
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('arrival' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    // The GET is what puts a CSRF token in the session; PUBLIC_ACCESS means
    // the POST is checked like any other form (unlike One-Click unsubscribe,
    // which a mail client sends with no session at all).
    $client->get('/password/setup/' . $token);
    $response = $client->post('/password/setup/' . $token, [
        'password' => 'a chosen garden passphrase',
        'password_confirm' => 'a chosen garden passphrase',
    ]);
    $t->same(303, $response->status);

    $row = (array) (new UserRepository($db))->find($user['id']);
    $t->same(0, (int) $row['must_reset_password'],
        'they have just chosen their own password; making them do it again is theatre');

    // Signed in: onboarding is where a new account lands, and reaching it at
    // all means the session exists.
    $t->contains('/onboarding', (string) $response->headers()['Location']);
    $t->same(200, $client->get('/onboarding')->status);
});

$t->test('the new password works and the temporary one does not',
    function ($t) use ($root, $db, $invites, $makeUser): void {
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('swapped' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    $client->get('/password/setup/' . $token);
    $client->post('/password/setup/' . $token, [
        'password' => 'the replacement passphrase',
        'password_confirm' => 'the replacement passphrase',
    ]);

    $client->forgetCookies();
    $t->same(200, $client->post('/login',
        ['username' => $user['username'], 'password' => $user['password']])->status,
        'the temporary password is refused, and a refusal re-renders the form');
    $t->contains('do not match', $client->post('/login',
        ['username' => $user['username'], 'password' => $user['password']])->body);

    $client->forgetCookies();
    $t->same(303, $client->post('/login',
        ['username' => $user['username'], 'password' => 'the replacement passphrase'])->status);
});

$t->test('a weak password is refused and the link is not spent',
    function ($t) use ($root, $invites, $makeUser): void {
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('weakpw' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    $client->get('/password/setup/' . $token);
    $response = $client->post('/password/setup/' . $token,
        ['password' => 'short', 'password_confirm' => 'short']);
    $t->same(200, $response->status);
    $t->contains('at least 10 characters', $response->body);

    // Burning the one-shot link on a typo would be its own support request.
    $t->same(InviteStore::VALID, $invites->resolve($token)['status']);
});

$t->test('the link cannot be used twice', function ($t) use ($root, $invites, $makeUser): void {
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('twice' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    $client->get('/password/setup/' . $token);
    $client->post('/password/setup/' . $token,
        ['password' => 'the first passphrase here', 'password_confirm' => 'the first passphrase here']);

    $client->forgetCookies();
    $client->get('/password/setup/' . $token);
    $second = $client->post('/password/setup/' . $token,
        ['password' => 'a different passphrase', 'password_confirm' => 'a different passphrase']);
    $t->same(200, $second->status);
    $t->contains('already been used', $second->body);

    $client->forgetCookies();
    $t->same(303, $client->post('/login',
        ['username' => $user['username'], 'password' => 'the first passphrase here'])->status,
        'the first password stands');
});

$t->test('the POST still wants a CSRF token', function ($t) use ($root, $invites, $makeUser): void {
    // PUBLIC_ACCESS, not TOKEN_ACCESS. The unsubscribe route is exempt only
    // because a mail client POSTs it with no session and no rendered form;
    // a person reaching this page in a browser has both.
    $client = new Client($root);
    $client->forgetCookies();
    $user = $makeUser('nocsrf' . \substr(\bin2hex(\random_bytes(3)), 0, 6));
    $token = $invites->issue($user['id'], null, '');

    $client->get('/password/setup/' . $token);
    $response = $client->postWithoutCsrf('/password/setup/' . $token,
        ['password' => 'a forged passphrase here', 'password_confirm' => 'a forged passphrase here']);
    $t->same(419, $response->status);
    $t->same(InviteStore::VALID, $invites->resolve($token)['status'], 'and it was not spent');
});
