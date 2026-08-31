<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Auth\Password;
use Carl\Core\Migrator;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Weather\WeatherSync;
use Throwable;

/**
 * The key-guarded operational routes (hosting Section 6.3).
 *
 * There is no shell on this account, so every administrative action needs a
 * browser route. Each is guarded by a key in the gitignored local config, and
 * an absent or wrong key is 404 -- never 403, which would confirm the route
 * exists.
 *
 * Output is plain text on purpose: these have to be readable when the app
 * itself is broken.
 */
final class SystemController extends Controller
{
    /**
     * The health page. Four numbers are the whole weather picture
     * (weather.md Section 3.2); the rest is what hosting Section 6.3 asks for.
     */
    public function status(Request $request): Response
    {
        $lines = [];
        $lines[] = 'Carl status';
        $lines[] = \str_repeat('=', 60);
        $lines[] = '';

        // -- Runtime --------------------------------------------------------
        $lines[] = 'RUNTIME';
        $lines[] = \sprintf('  php                %s (%s)', \PHP_VERSION, \PHP_SAPI);
        $lines[] = \sprintf('  server             %s', $request->server['SERVER_SOFTWARE'] ?? 'unknown');
        $lines[] = \sprintf('  timezone           %s', \date_default_timezone_get());
        $lines[] = \sprintf('  utc now            %s', \gmdate('Y-m-d H:i:s'));
        $lines[] = \sprintf('  base_path          %s', $this->app->basePath());
        $lines[] = \sprintf('  app root           %s', $this->app->root());
        $lines[] = \sprintf('  memory_limit       %s', \ini_get('memory_limit'));
        $lines[] = \sprintf('  max_execution_time %s', \ini_get('max_execution_time'));
        $lines[] = \sprintf('  upload_max_filesize %s', \ini_get('upload_max_filesize'));
        $lines[] = \sprintf('  post_max_size      %s', \ini_get('post_max_size'));
        $lines[] = \sprintf('  max_input_vars     %s', \ini_get('max_input_vars'));
        $lines[] = '';

        // -- Extensions -----------------------------------------------------
        $needed = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'session', 'curl',
                   'fileinfo', 'zip', 'gd'];
        $missing = \array_values(\array_filter($needed,
            static fn (string $e): bool => !\extension_loaded($e)));
        $lines[] = 'EXTENSIONS';
        $lines[] = '  required           ' . ($missing === [] ? 'all present' : 'MISSING: ' . \implode(', ', $missing));
        $lines[] = '  opcache            ' . (\extension_loaded('Zend OPcache') ? 'enabled' : 'absent (expected)');
        $lines[] = '';

        // -- Code placement (hosting Section 5.1) ---------------------------
        $documentRoot = (string) ($request->server['DOCUMENT_ROOT'] ?? '');
        $inDocRoot = $documentRoot !== ''
            && \str_starts_with(\realpath($this->app->root()) ?: $this->app->root(),
                \realpath($documentRoot) ?: $documentRoot);
        $lines[] = 'CODE PLACEMENT';
        $lines[] = '  document root      ' . ($documentRoot !== '' ? $documentRoot : 'unknown');
        $lines[] = '  app outside it     ' . ($inDocRoot ? 'NO -- application code is web-reachable' : 'yes');
        $lines[] = '';

        // -- Session (hosting Section 8.1) ----------------------------------
        $this->app->session()->start();
        $params = \session_get_cookie_params();
        $lines[] = 'SESSION';
        $lines[] = '  name               ' . \session_name();
        $lines[] = '  cookie path        ' . $params['path']
            . ($params['path'] === $this->app->basePath() ? '' : '  <-- expected ' . $this->app->basePath());
        $lines[] = '  httponly           ' . ($params['httponly'] ? 'yes' : 'NO');
        $lines[] = '  secure             ' . ($params['secure'] ? 'yes' : ($request->isSecure() ? 'NO' : 'no (http request)'));
        $lines[] = '  samesite           ' . ($params['samesite'] !== '' ? $params['samesite'] : 'UNSET');
        $lines[] = '  strict mode        ' . (\ini_get('session.use_strict_mode') === '1' ? 'yes' : 'NO');
        $lines[] = '  save path          ' . \session_save_path();
        $lines[] = '  gc_maxlifetime     ' . \ini_get('session.gc_maxlifetime') . ' s';
        $lines[] = '';

        // -- Private directories --------------------------------------------
        $lines[] = 'PRIVATE DIRECTORIES (0700, outside public_html)';
        foreach (['sessions', 'photos', 'imports', 'reports'] as $name) {
            $path = $this->app->varPath($name);
            $mode = \is_dir($path) ? \substr(\sprintf('%o', \fileperms($path)), -4) : '----';
            $lines[] = \sprintf('  %-18s %s  %s%s', $name, $mode,
                \is_dir($path) ? 'exists' : 'MISSING',
                \is_dir($path) && !\is_writable($path) ? '  NOT WRITABLE' : '');
        }
        $lines[] = '';

        // -- Database and migrations ----------------------------------------
        $lines[] = 'DATABASE';
        try {
            $started = \microtime(true);
            $version = $this->app->db()->value('SELECT VERSION()');
            $connectMs = (\microtime(true) - $started) * 1000;
            $lines[] = \sprintf('  engine             %s', $version);
            $lines[] = \sprintf('  host               %s', $this->app->config()->string('db.host'));
            $lines[] = \sprintf('  connect + query    %.1f ms', $connectMs);

            $pingStart = \microtime(true);
            $this->app->db()->value('SELECT 1');
            $lines[] = \sprintf('  round trip         %.2f ms', (\microtime(true) - $pingStart) * 1000);

            $migrator = new Migrator($this->app->db(), $this->app->root() . '/db/migrations');
            $applied = $migrator->applied();
            $pending = $migrator->pending();
            $lines[] = \sprintf('  migrations applied %d', \count($applied));
            if ($pending === []) {
                $lines[] = '  migrations pending none';
            } else {
                $lines[] = \sprintf('  migrations pending %d', \count($pending));
                foreach ($pending as $migration) {
                    $lines[] = '    - ' . $migration['filename'] . ' (' . $migration['kind'] . ')';
                }
                $lines[] = '    run them at ' . $this->app->url('setup') . '?key=<setup_key>';
            }
        } catch (Throwable $e) {
            $lines[] = '  ERROR              ' . $e->getMessage();
        }
        $lines[] = '';

        // -- Weather health (weather.md Section 3.2) -------------------------
        $lines[] = 'WEATHER';
        try {
            $health = $this->weather()->health();
            if ($health === []) {
                $lines[] = '  no active locations yet (nobody has finished onboarding)';
            }
            foreach ($health as $row) {
                $newest = $row['newest_obs'] ?? null;
                $gaps = 0;
                if (\is_string($newest)) {
                    $gaps = $this->weather()->gapCount(
                        (int) $row['id'], (string) $row['backfill_from'], $newest
                    );
                }
                $lines[] = \sprintf('  [%d] %s (%s)', $row['id'], $row['label'], $row['timezone']);
                $lines[] = \sprintf('      newest observation  %s', $newest ?? 'NONE -- has the cron ever run?');
                $lines[] = \sprintf('      days held           %d since %s', $row['days_held'], $row['backfill_from']);
                $lines[] = \sprintf('      missing in range    %d', $gaps);
                $lines[] = \sprintf('      newest forecast     %s', $row['newest_forecast'] ?? 'none');
                $lines[] = \sprintf('      last successful run %s', $row['last_ok'] ?? 'NEVER');
                if ($row['last_bad_status'] !== null) {
                    $lines[] = \sprintf('      last bad status     HTTP %s', $row['last_bad_status']);
                }
                if ($row['last_error'] !== null) {
                    $lines[] = \sprintf('      last error          %s', $row['last_error']);
                }
            }
        } catch (Throwable $e) {
            $lines[] = '  ERROR              ' . $e->getMessage();
        }
        $lines[] = '';

        $lines[] = \sprintf('statements this request: %d', $this->app->db()->statementCount());
        $lines[] = \sprintf('rendered in %.1f ms', (\microtime(true) - CARL_START) * 1000);

        return Response::text(\implode("\n", $lines) . "\n");
    }

    /**
     * Apply pending migrations and set the first administrator's credential.
     *
     * This is a genuine administrative credential: whoever holds setup_key can
     * take the master admin account. Add it through File Manager only for as
     * long as the migration takes, then REMOVE THE LINE (hosting Section 6.3).
     */
    public function setup(Request $request): Response
    {
        $migrator = new Migrator($this->app->db(), $this->app->root() . '/db/migrations');

        $pending = [];
        $error = null;
        try {
            $pending = $migrator->pending();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->render('setup', [
            'layout'  => 'layout',
            'pending' => $pending,
            'error'   => $error,
            'key'     => (string) $request->query('key', ''),
            'applied' => $this->app->session()->pull('_setup_applied'),
        ]);
    }

    public function runSetup(Request $request): Response
    {
        $key = (string) $request->query('key', '');
        $action = (string) $request->input('action', 'migrate');

        if ($action === 'admin') {
            return $this->setAdminCredential($request, $key);
        }

        $migrator = new Migrator($this->app->db(), $this->app->root() . '/db/migrations');

        try {
            $applied = $migrator->migrate();
        } catch (Throwable $e) {
            $this->flash('Migration failed: ' . $e->getMessage(), 'error');
            return Response::redirect($this->app->url('setup', ['key' => $key]));
        }

        $this->app->session()->set('_setup_applied', $applied);
        $this->flash($applied === []
            ? 'Already up to date; nothing to apply.'
            : \count($applied) . ' migration(s) applied.');

        return Response::redirect($this->app->url('setup', ['key' => $key]));
    }

    /**
     * Set the master admin's credential. Needed because before the migrations
     * run there is no user table to log in against (hosting Section 6.3).
     */
    private function setAdminCredential(Request $request, string $key): Response
    {
        $username = \strtolower(\trim((string) $request->input('username', 'admin')));
        $password = $request->post['password'] ?? '';

        if (!\is_string($password) || $password === '') {
            $this->flash('Enter a password.', 'error');
            return Response::redirect($this->app->url('setup', ['key' => $key]));
        }

        $problems = Password::problems($password, $username);
        if ($problems !== []) {
            $this->flash(\implode(' ', $problems), 'error');
            return Response::redirect($this->app->url('setup', ['key' => $key]));
        }

        $passwords = $this->app->auth()->passwords();
        $existing = $this->accounts()->findByUsername($username);

        if ($existing === null) {
            $this->app->db()->run(
                'INSERT INTO `user` (username, email, name, role, password_hash,'
                . ' must_reset_password, email_unsubscribe_token, onboarding_step,'
                . ' created_at, updated_at)'
                . " VALUES (:username, :email, 'Administrator', 'admin', :hash, 0, :token,"
                . " 'profile', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [
                    'username' => $username,
                    'email'    => 'carl@reshiftmanager.com',
                    'hash'     => $passwords->hash($password),
                    'token'    => \bin2hex(\random_bytes(32)),
                ]
            );
            $this->flash('Administrator "' . $username . '" created. Remove setup_key now.');
        } else {
            $this->app->db()->run(
                'UPDATE `user` SET `password_hash` = :hash, `must_reset_password` = 0,'
                . " `role` = 'admin', `updated_at` = UTC_TIMESTAMP() WHERE `id` = :id",
                ['hash' => $passwords->hash($password), 'id' => (int) $existing['id']]
            );
            // A credential change revokes every existing token (hosting 8.3).
            $this->app->auth()->tokens()->revokeAllForUser((int) $existing['id']);
            $this->flash('Administrator "' . $username . '" password set. Remove setup_key now.');
        }

        return Response::redirect($this->app->url('setup', ['key' => $key]));
    }

    /**
     * The browser fallback for the nightly job (weather.md Section 3.2). It
     * exists so a human can force a resync after an outage, and so the same
     * code path is exercisable on an account with no shell.
     */
    public function weatherSync(Request $request): Response
    {
        // This runs under the web SAPI, so it inherits the 30 s ceiling. The
        // job chunks either way, which is what keeps the CLI and browser
        // forms interchangeable (weather.md Section 3.1).
        $kindParam = (string) ($request->query('kind', 'all') ?? 'all');
        $kinds = match ($kindParam) {
            'archive'  => ['archive'],
            'forecast' => ['forecast'],
            default    => ['archive', 'forecast'],
        };

        $locationId = $request->query('location');
        $sync = new WeatherSync($this->app);

        $started = \microtime(true);
        $summary = $sync->run($kinds, $locationId !== null && \ctype_digit($locationId)
            ? (int) $locationId : null);

        $lines = [
            'weather sync: ' . \implode(' + ', $kinds),
            \sprintf('locations %d, rows %d, failures %d, %.1f s',
                $summary['locations'], $summary['rows'], $summary['failures'],
                \microtime(true) - $started),
            '',
        ];
        foreach ($summary['log'] as $entry) {
            $lines[] = '  ' . $entry;
        }

        return Response::text(\implode("\n", $lines) . "\n");
    }

    /**
     * The Phase 0 spikes (handoff Section 14). One temporary key-guarded
     * diagnostic route, and the same discipline as every "Measured" row in
     * the hosting document: run it, record the numbers, then remove diag_key.
     */
    public function diag(Request $request): Response
    {
        $lines = ['Carl Phase 0 diagnostics', \str_repeat('=', 60), ''];

        // Spike 1 and 2: outbound HTTPS. If Open-Meteo fails, stop and
        // rescope (handoff Section 14, weather.md Section 11).
        $lines[] = 'OUTBOUND HTTPS';
        $targets = [
            'archive-api.open-meteo.com' =>
                $this->app->config()->string('weather.archive_url')
                . '?latitude=31.9597&longitude=-97.3304&start_date=2026-08-01&end_date=2026-08-02'
                . '&daily=temperature_2m_max&timezone=America%2FChicago',
            'api.open-meteo.com' =>
                $this->app->config()->string('weather.forecast_url')
                . '?latitude=31.9597&longitude=-97.3304&forecast_days=1&daily=temperature_2m_max'
                . '&timezone=America%2FChicago',
            'api.weather.gov' =>
                $this->app->config()->string('weather.alerts_url') . '?point=31.9597,-97.3304',
            'api.zippopotam.us' =>
                $this->app->config()->string('weather.zip_api_url') . '76692',
            'www.ncei.noaa.gov' =>
                $this->app->config()->string('weather.ncei_url')
                . '?dataset=daily-summaries&stations=USW00013959&startDate=2026-08-01'
                . '&endDate=2026-08-02&dataTypes=TMAX,TMIN,PRCP&format=json&units=metric',
        ];

        $http = new \Carl\Core\HttpClient(
            $this->app->config()->string('weather.user_agent'),
            $this->app->config()->int('weather.http_timeout', 20),
        );

        foreach ($targets as $label => $url) {
            $result = $http->get($url);
            $lines[] = \sprintf('  %-28s HTTP %-3d  %6d ms  %s',
                $label, $result->status, $result->ms(),
                $result->error !== null ? 'ERROR ' . $result->error : 'ok');
            $lines[] = '      ' . \str_replace(["\n", "\r"], ' ', \substr($result->body, 0, 200));
        }
        $lines[] = '';

        // Spike 2: the PHP CLI binary path for the cron entry
        // (weather.md Section 3.1 -- all three candidates are plausible).
        $lines[] = 'PHP CLI CANDIDATES (for the cron command line)';
        foreach (['/usr/local/bin/php', '/usr/local/bin/ea-php82', '/opt/alt/php82/usr/bin/php',
                  '/usr/bin/php', \PHP_BINARY] as $candidate) {
            $lines[] = \sprintf('  %-34s %s', $candidate,
                \is_file($candidate)
                    ? (\is_executable($candidate) ? 'exists, executable' : 'exists, NOT executable')
                    : 'not found');
        }
        $lines[] = '';

        // Spike 5: time a 200-row upsert against the remote database
        // (weather.md Section 11 spike 6). It writes to a temporary table so
        // the measurement leaves nothing behind.
        $lines[] = 'DATABASE TIMING';
        try {
            $db = $this->app->db();
            $db->pdo()->exec(
                'CREATE TEMPORARY TABLE `carl_diag_upsert` ('
                . ' `k` INT UNSIGNED NOT NULL, `v` INT NOT NULL, PRIMARY KEY (`k`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            foreach ([200, 2000] as $size) {
                $rows = [];
                for ($i = 0; $i < $size; $i++) {
                    $rows[] = [$i, $i];
                }
                $started = \microtime(true);
                $db->upsertChunk('carl_diag_upsert', ['k', 'v'], $rows, ['v']);
                $lines[] = \sprintf('  %4d-row upsert            %.1f ms', $size,
                    (\microtime(true) - $started) * 1000);
            }
            $db->pdo()->exec('DROP TEMPORARY TABLE `carl_diag_upsert`');

            $started = \microtime(true);
            for ($i = 0; $i < 10; $i++) {
                $db->value('SELECT 1');
            }
            $lines[] = \sprintf('  round trip (10 x SELECT 1) %.2f ms each',
                (\microtime(true) - $started) * 100);
        } catch (Throwable $e) {
            $lines[] = '  ERROR ' . $e->getMessage();
        }
        $lines[] = '';

        $lines[] = 'Record these numbers in docs/, then remove diag_key from config/local.php.';

        return Response::text(\implode("\n", $lines) . "\n");
    }
}
