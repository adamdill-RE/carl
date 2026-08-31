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
