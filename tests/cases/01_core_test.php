<?php

/**
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Core\Config;
use Carl\Core\HttpException;
use Carl\Core\Migrator;
use Carl\Core\Request;
use Carl\Core\Route;
use Carl\Core\Router;
use Carl\Support\Clock;

/** Every view template, for the policy checks at the end of this file. */
final class TemplateFiles
{
    /** @return list<string> */
    public static function all(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/app/views', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (\str_ends_with((string) $file, '.php')) {
                $out[] = (string) $file;
            }
        }
        \sort($out);
        return $out;
    }
}

$t->group('Migrator::split');

$t->test('splits on semicolons between statements', function ($t): void {
    $statements = Migrator::split("CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n");
    $t->same(2, \count($statements));
    $t->contains('TABLE a', $statements[0]);
    $t->contains('TABLE b', $statements[1]);
});

$t->test('ignores a semicolon inside a string literal', function ($t): void {
    $statements = Migrator::split("INSERT INTO a VALUES ('one; two');\nSELECT 1;");
    $t->same(2, \count($statements));
    $t->contains('one; two', $statements[0]);
});

$t->test('ignores a semicolon inside a line comment', function ($t): void {
    $statements = Migrator::split("-- a; comment\nSELECT 1;");
    $t->same(1, \count($statements));
});

$t->test('ignores a semicolon inside a block comment', function ($t): void {
    $statements = Migrator::split("/* a; comment */ SELECT 1;");
    $t->same(1, \count($statements));
});

$t->test('handles a doubled quote inside a string', function ($t): void {
    $statements = Migrator::split("INSERT INTO a VALUES ('it''s; fine');");
    $t->same(1, \count($statements));
    $t->contains("it''s; fine", $statements[0]);
});

$t->test('handles a backslash-escaped quote', function ($t): void {
    $statements = Migrator::split("INSERT INTO a VALUES ('it\\'s; fine');");
    $t->same(1, \count($statements));
});

$t->test('handles a semicolon inside a backquoted identifier', function ($t): void {
    $statements = Migrator::split('SELECT `odd;name` FROM a;');
    $t->same(1, \count($statements));
});

$t->test('drops a trailing empty statement', function ($t): void {
    $t->same(1, \count(Migrator::split('SELECT 1;   ')));
});

$t->group('Migrations on disk');

$t->test('every migration declares a kind and is numbered uniquely', function ($t) use ($app): void {
    $migrator = new Migrator($app->db(), $app->root() . '/db/migrations');
    $available = $migrator->available();
    $t->ok(\count($available) >= 11, 'expected at least eleven migrations');

    $versions = [];
    foreach ($available as $migration) {
        $t->ok(
            \in_array($migration['kind'], ['ddl', 'dml'], true),
            $migration['filename'] . ' has kind ' . $migration['kind']
        );
        $t->ok(!isset($versions[$migration['version']]), 'duplicate version ' . $migration['version']);
        $versions[$migration['version']] = true;
    }
});

$t->test('no migration mixes DDL and DML', function ($t) use ($app): void {
    // Hosting Section 7: MySQL commits implicitly on DDL, so a mixed
    // migration cannot be rolled back and is never safe to retry.
    $migrator = new Migrator($app->db(), $app->root() . '/db/migrations');
    foreach ($migrator->available() as $migration) {
        if (\str_ends_with($migration['path'], '.php')) {
            continue;
        }
        $statements = Migrator::split((string) \file_get_contents($migration['path']));
        $sawDdl = false;
        $sawDml = false;
        foreach ($statements as $statement) {
            $verb = \strtoupper(\strtok(\ltrim($statement), " \n\t("));
            if (\in_array($verb, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true)) {
                $sawDdl = true;
            }
            if (\in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) {
                $sawDml = true;
            }
        }
        $t->ok(!($sawDdl && $sawDml), $migration['filename'] . ' mixes DDL and DML');
        if ($sawDml) {
            $t->same('dml', $migration['kind'], $migration['filename'] . ' should declare kind dml');
        }
    }
});

$t->test('every table names utf8mb4_unicode_ci explicitly', function ($t) use ($app): void {
    // Hosting Section 2.2: the engine's own default is utf8mb4_0900_ai_ci.
    foreach ((array) \glob($app->root() . '/db/migrations/*.sql') as $path) {
        $sql = (string) \file_get_contents((string) $path);
        $creates = \preg_match_all('/CREATE TABLE/i', $sql);
        $collations = \preg_match_all('/COLLATE=utf8mb4_unicode_ci/i', $sql);
        $t->same($creates, $collations, \basename((string) $path) . ' collation count');
    }
});

$t->test('no migration uses RETURNING or a STORED generated column', function ($t) use ($app): void {
    // RETURNING is a MariaDB extension; the real host is MySQL 8 (hosting 2.2).
    // Comments are stripped first, or the prose in them trips the check.
    foreach ((array) \glob($app->root() . '/db/migrations/*.sql') as $path) {
        $code = \implode("\n", Migrator::split((string) \file_get_contents((string) $path)));
        $t->ok(\preg_match('/\bRETURNING\b/i', $code) !== 1, \basename((string) $path) . ' uses RETURNING');
        // A generated column is STORED only in the form  AS (expr) STORED.
        $t->ok(
            \preg_match('/\)\s*STORED\b/i', $code) !== 1,
            \basename((string) $path) . ' declares a STORED generated column'
        );
    }
});

$t->group('Clock');

$t->test('today is the user local calendar day, not the server day', function ($t): void {
    // 2026-03-01 04:30 UTC is still 2026-02-28 in Chicago.
    $clock = new Clock(new DateTimeImmutable('2026-03-01 04:30:00', new DateTimeZone('UTC')));
    $t->same('2026-02-28', $clock->todayFor('America/Chicago'));
    $t->same('2026-03-01', $clock->todayFor('UTC'));
});

$t->test('an unknown timezone falls back to UTC rather than throwing', function ($t): void {
    $clock = new Clock(new DateTimeImmutable('2026-03-01 04:30:00', new DateTimeZone('UTC')));
    $t->same('2026-03-01', $clock->todayFor('Not/AZone'));
});

$t->test('DST is crossed with a real timezone, never a fixed offset', function ($t): void {
    // 2026-03-08 07:30 UTC is 01:30 CST; 08:30 UTC is 03:30 CDT.
    $clock = new Clock(new DateTimeImmutable('2026-03-08 08:30:00', new DateTimeZone('UTC')));
    $t->same('03:30', $clock->localNow('America/Chicago')->format('H:i'));
});

$t->test('parseDate rejects a malformed or impossible date', function ($t): void {
    $t->same('2026-02-28', Clock::parseDate('2026-02-28'));
    $t->same(null, Clock::parseDate('2026-02-30'));
    $t->same(null, Clock::parseDate('28/02/2026'));
    $t->same(null, Clock::parseDate(''));
});

$t->test('daysBetween is signed', function ($t): void {
    $t->same(10, Clock::daysBetween('2026-03-01', '2026-03-11'));
    $t->same(-10, Clock::daysBetween('2026-03-11', '2026-03-01'));
});

$t->test('a recurring window that wraps the new year still matches', function ($t): void {
    $t->ok(Clock::inRecurringWindow('2026-01-05', '11-15', '02-01'));
    $t->ok(Clock::inRecurringWindow('2026-12-20', '11-15', '02-01'));
    $t->ok(!Clock::inRecurringWindow('2026-06-01', '11-15', '02-01'));
    $t->ok(Clock::inRecurringWindow('2026-04-01', '03-15', '05-01'));
    $t->ok(!Clock::inRecurringWindow('2026-06-01', '03-15', '05-01'));
});

$t->group('Request');

$t->test('the mount point is stripped so routes stay subpath-agnostic', function ($t): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/carl/plants/12?filter=all';
    $request = Request::fromGlobals('/carl/');
    $t->same('/plants/12', $request->path);
});

$t->test('the mount point root becomes /', function ($t): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/carl/';
    $t->same('/', Request::fromGlobals('/carl/')->path);
});

$t->test('the same routes work when mounted at the domain root', function ($t): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/plants/12';
    $t->same('/plants/12', Request::fromGlobals('/')->path);
});

$t->test('an over-size POST arriving empty is detected, not treated as a blank form', function ($t): void {
    // Hosting Section 4: past post_max_size, PHP hands over an empty $_POST
    // and $_FILES with no error of its own.
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x', 'CONTENT_LENGTH' => '9000000'];
    $_POST = [];
    $_FILES = [];
    $t->ok(Request::fromGlobals('/')->looksTruncated());

    $_POST = ['a' => 'b'];
    $t->ok(!Request::fromGlobals('/')->looksTruncated());
    $_POST = [];
    $_SERVER = [];
});

$t->group('Router');

$t->test('matches a parameterised route and captures the parameter', function ($t): void {
    $router = new Router();
    $router->get('/plants/{id:\d+}', Carl\Core\App::class, 'noop');
    $match = $router->match('GET', '/plants/42');
    $t->ok($match !== null);
    $t->same('42', $match['params']['id']);
});

$t->test('a numeric constraint is enforced', function ($t): void {
    $router = new Router();
    $router->get('/plants/{id:\d+}', Carl\Core\App::class, 'noop');
    $t->same(null, $router->match('GET', '/plants/abc'));
});

$t->test('a literal dot in a pattern is a dot, not a wildcard', function ($t): void {
    // '/export/plants.csv' with an unescaped dot would also answer to
    // '/export/plantsXcsv'. A route that responds to a URL nobody wrote down
    // is the kind of thing found years later.
    $router = new Router();
    $router->get('/export/plants.csv', Carl\Core\App::class, 'noop');
    $t->ok($router->match('GET', '/export/plants.csv') !== null);
    $t->same(null, $router->match('GET', '/export/plantsXcsv'));
});

$t->test('a constrained placeholder is still regex after the escaping', function ($t): void {
    $router = new Router();
    $router->get('/a.b/{id:\d+}/c.d', Carl\Core\App::class, 'noop');
    $match = $router->match('GET', '/a.b/7/c.d');
    $t->ok($match !== null);
    $t->same('7', $match['params']['id']);
    $t->same(null, $router->match('GET', '/aXb/7/c.d'));
});

$t->test('an unconstrained placeholder still defaults to one segment', function ($t): void {
    $router = new Router();
    $router->get('/lists/{type}', Carl\Core\App::class, 'noop');
    $match = $router->match('GET', '/lists/seed_source');
    $t->ok($match !== null);
    $t->same('seed_source', $match['params']['type']);
    $t->same(null, $router->match('GET', '/lists/a/b'), 'it must not cross a slash');
});

$t->test('a known path with the wrong method is 405, not 404', function ($t): void {
    $router = new Router();
    $router->post('/plants', Carl\Core\App::class, 'noop');
    $e = $t->throws(HttpException::class, static fn () => $router->match('GET', '/plants'));
    $t->same(405, $e->status);
});

$t->group('Config');

$t->test('a secret that is null or empty disables its route', function ($t): void {
    $config = Config::fromArray(['setup_key' => null, 'status_key' => '', 'cron_key' => 'abc']);
    $t->same(null, $config->secret('setup_key'));
    $t->same(null, $config->secret('status_key'));
    $t->same('abc', $config->secret('cron_key'));
});

$t->test('local values override committed ones without dropping siblings', function ($t): void {
    $config = Config::fromArray(['db' => ['host' => 'h', 'name' => 'n', 'pass' => 'p']]);
    $t->same('h', $config->string('db.host'));
    $t->same('n', $config->string('db.name'));
});

$t->group('Deployment shape');

$t->test('base_path appears in exactly one committed file', function ($t) use ($app): void {
    // Hosting Section 5.2: the value appears in exactly one file.
    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($app->root(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = (string) $file;
        // docs/ and research-template/ are copied-in specification documents,
        // and tests/ is where the rule itself is asserted.
        if (\str_contains($path, '/.git/') || \str_contains($path, '/var/')
            || \str_contains($path, '/docs/') || \str_contains($path, '/research-template/')
            || \str_contains($path, '/tests/')) {
            continue;
        }
        if (!\preg_match('/\.(php|js|css|html)$/', $path)) {
            continue;
        }
        $contents = (string) \file_get_contents($path);
        if (\preg_match("#(['\"])/carl/\\1#", $contents) === 1) {
            $hits[] = \substr($path, \strlen($app->root()) + 1);
        }
    }
    $t->same(['config/app.php'], $hits, 'literal "/carl/" should only appear in config/app.php');
});

$t->group('The error page says what actually went wrong');

/** A User row, filled enough for the error template. */
$asUser = static function (string $role): Carl\Auth\User {
    return Carl\Auth\User::fromRow([
        'id' => 1, 'username' => 'someone', 'email' => 's@example.test', 'name' => 'Someone',
        'role' => $role, 'must_reset_password' => 0, 'zip' => null, 'county_fips' => null,
        'region_id' => null, 'latitude' => null, 'longitude' => null, 'timezone' => 'UTC',
        'weather_location_id' => null, 'email_digest_enabled' => 1,
        'onboarded_at' => '2026-01-01 00:00:00', 'onboarding_step' => 'done',
    ]);
};

$t->test('a 404 with no message of its own says the address is wrong',
    function ($t) use ($app, $asUser): void {
    $html = $app->view()->partial('error', [
        'status' => 404, 'message' => '', 'adminHint' => '', 'user' => $asUser('user'),
    ]);
    $t->contains('Not found', $html);
    $t->contains('There is nothing at that address.', $html);
});

$t->test('a 500 does NOT say the address is wrong', function ($t) use ($app, $asUser): void {
    // It used to. The headline said the server broke and the sentence under
    // it said the URL was wrong, and the wrong one is the one people act on:
    // four pending migrations on the Phase 3 deploy presented as "there is
    // nothing at that address", which sends you hunting for a routing fault.
    $html = $app->view()->partial('error', [
        'status' => 500, 'message' => '', 'adminHint' => '', 'user' => $asUser('user'),
    ]);
    $t->contains('Something went wrong', $html);
    $t->notContains('There is nothing at that address.', $html);
    $t->contains('The server hit an error', $html);
});

$t->test('the operator hint reaches an admin and nobody else',
    function ($t) use ($app, $asUser): void {
    $hint = 'The database is missing a table this page needs.';

    $forAdmin = $app->view()->partial('error', [
        'status' => 500, 'message' => '', 'adminHint' => $hint, 'user' => $asUser('admin'),
    ]);
    $t->contains($hint, $forAdmin);

    // A schema fault is not a user's business, and /status carries the detail.
    $forUser = $app->view()->partial('error', [
        'status' => 500, 'message' => '', 'adminHint' => $hint, 'user' => $asUser('user'),
    ]);
    $t->notContains($hint, $forUser);

    $signedOut = $app->view()->partial('error', [
        'status' => 500, 'message' => '', 'adminHint' => $hint, 'user' => null,
    ]);
    $t->notContains($hint, $signedOut);
});

$t->test('an explicit message still wins over the fallback',
    function ($t) use ($app, $asUser): void {
    $html = $app->view()->partial('error', [
        'status' => 404, 'message' => 'That is not one of your plants.',
        'adminHint' => '', 'user' => $asUser('user'),
    ]);
    $t->contains('That is not one of your plants.', $html);
    $t->notContains('There is nothing at that address.', $html);
});

$t->group('Templates stay inside the Content Security Policy');

$t->test('no template uses an inline style attribute', function ($t) use ($app): void {
    // The CSP is style-src 'self' with no 'unsafe-inline' (hosting Section
    // 8.5). An inline style attribute is refused silently under it -- the
    // element just renders unstyled -- so this has to be caught here.
    $offenders = [];
    foreach (TemplateFiles::all($app->root()) as $path) {
        if (\preg_match('/\sstyle\s*=\s*"/i', (string) \file_get_contents($path)) === 1) {
            $offenders[] = \substr($path, \strlen($app->root()) + 1);
        }
    }
    $t->same([], $offenders, 'use a utility class in carl.css instead');
});

$t->test('no template uses an inline event handler or inline script body',
    function ($t) use ($app): void {
    // Same policy, script-src 'self': onclick= and <script>...</script>
    // bodies are both refused. Scripts are files under assets/js/.
    $offenders = [];
    foreach (TemplateFiles::all($app->root()) as $path) {
        $contents = (string) \file_get_contents($path);
        if (\preg_match('/\son(click|change|submit|load|input|focus|blur)\s*=/i', $contents) === 1) {
            $offenders[] = \substr($path, \strlen($app->root()) + 1) . ' (event handler)';
        }
        // A <script src=...> tag is fine; a script with a body is not.
        if (\preg_match('/<script(?![^>]*\ssrc=)[^>]*>\s*\S/i', $contents) === 1) {
            $offenders[] = \substr($path, \strlen($app->root()) + 1) . ' (inline script)';
        }
    }
    $t->same([], $offenders, 'put it in a file under public/assets/js/');
});

$t->test('every script the templates load exists on disk', function ($t) use ($app): void {
    $missing = [];
    foreach (TemplateFiles::all($app->root()) as $path) {
        \preg_match_all(
            "/\\\$app->asset\('([^']+)'\)/",
            (string) \file_get_contents($path),
            $matches
        );
        foreach ($matches[1] as $asset) {
            if (!\is_file($app->root() . '/public/' . $asset)) {
                $missing[] = $asset . ' (in ' . \basename($path) . ')';
            }
        }
    }
    $t->same([], $missing);
});

$t->group('The deployed layout, where public/ is a sibling not a child');

$t->test('assets keep their ?v= stamp when public/ is not under the app root',
    function ($t) use ($app): void {
    // On the server the app root is ~/carl-app and the public directory is
    // ~/public_html/carl -- siblings, not nested (hosting Section 5.1).
    // Without the stamp the one-year Expires in .htaccess would freeze a
    // changed stylesheet in every browser that had already loaded it
    // (hosting Section 9).
    $elsewhere = \sys_get_temp_dir() . '/carl_public_' . \bin2hex(\random_bytes(4));
    \mkdir($elsewhere . '/assets/css', 0755, true);
    \file_put_contents($elsewhere . '/assets/css/carl.css', 'body{}');

    try {
        $isolated = new Carl\Core\App(
            Carl\Core\Config::fromArray(['base_path' => '/carl/']),
            '/nowhere/carl-app'
        );
        $isolated->setPublicPath($elsewhere);

        $url = $isolated->asset('assets/css/carl.css');
        $t->contains('?v=', $url);
        $t->contains('/carl/assets/css/carl.css', $url);
    } finally {
        @\unlink($elsewhere . '/assets/css/carl.css');
        @\rmdir($elsewhere . '/assets/css');
        @\rmdir($elsewhere . '/assets');
        @\rmdir($elsewhere);
    }
});

$t->test('an asset that does not exist still produces a usable URL', function ($t): void {
    $isolated = new Carl\Core\App(
        Carl\Core\Config::fromArray(['base_path' => '/carl/']),
        '/nowhere/carl-app'
    );
    $t->same('/carl/assets/css/missing.css', $isolated->asset('assets/css/missing.css'));
});

$t->test('the front controller tells the app where the public directory is',
    function ($t) use ($app): void {
    // If this call is ever dropped, the stamp silently disappears on the
    // server and nowhere else -- which is the hardest kind of bug to notice.
    $contents = (string) \file_get_contents($app->root() . '/public/index.php');
    $t->contains('setPublicPath(__DIR__)', $contents);
});

$t->group('The timezone cron actually uses');

$t->test('an /etc/localtime symlink resolves to a usable IANA name', function ($t): void {
    // This is NOT date.timezone. PHP's setting is whatever php.ini says and
    // the bootstrap overrides it to UTC on every request, so it says nothing
    // about what cron will do -- and a schedule written against the wrong one
    // fires hours off, silently.
    $cases = [
        '/usr/share/zoneinfo/Etc/UTC'               => 'Etc/UTC',
        '/usr/share/zoneinfo/America/Chicago'       => 'America/Chicago',
        // Relative links are common.
        '../usr/share/zoneinfo/America/Chicago'     => 'America/Chicago',
        // posix/ and right/ are part of the path, not the zone name, and
        // leaving them on makes DateTimeZone throw.
        '/usr/share/zoneinfo/posix/America/Chicago' => 'America/Chicago',
        '/usr/share/zoneinfo/right/UTC'             => 'UTC',
    ];

    foreach ($cases as $link => $expected) {
        $name = Carl\Controller\SystemController::zoneFromLinkPath($link);
        $t->same($expected, $name, $link);
        // Every name it returns must be one DateTimeZone accepts, or the
        // status page throws instead of reporting.
        new DateTimeZone((string) $name);
    }
});

$t->test('a path that is not a zoneinfo link is rejected rather than guessed at',
    function ($t): void {
    $t->same(null, Carl\Controller\SystemController::zoneFromLinkPath('/nonsense/path'));
    $t->same(null, Carl\Controller\SystemController::zoneFromLinkPath(''));
    $t->same(null, Carl\Controller\SystemController::zoneFromLinkPath('/usr/share/zoneinfo/'));
});

$t->test('the system timezone is reported with where it was read from', function ($t): void {
    $system = Carl\Controller\SystemController::systemTimezone();
    $t->ok(isset($system['name'], $system['source']), 'both keys present');
    if ($system['name'] !== 'unknown') {
        new DateTimeZone($system['name']);   // throws if unusable
    }
});
