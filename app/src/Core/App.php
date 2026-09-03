<?php

declare(strict_types=1);

namespace Carl\Core;

use Carl\Auth\Auth;
use Carl\Support\Clock;
use Carl\Support\Units;
use Throwable;

/**
 * The application: a small container plus the request kernel.
 *
 * Hosting Section 5.2 -- every internal link, form action, redirect, asset URL
 * and cookie path is built here from one configured base_path, and the value
 * appears in exactly one file (config/app.php).
 */
final class App
{
    private ?Database $database = null;
    private ?Session $session = null;
    private ?Csrf $csrf = null;
    private ?View $view = null;
    private ?Auth $auth = null;
    private ?Router $router = null;
    private ?Clock $clock = null;
    private ?Units $units = null;
    private ?Request $request = null;
    private ?string $publicPath = null;
    private ?\Carl\Mail\Outbox $outbox = null;
    private ?\Carl\Analysis\Analyst $analyst = null;
    /** The api_token row id behind a BEARER_ACCESS request, or null. */
    private ?int $bearerTokenId = null;

    public function __construct(private Config $config, private string $root)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function varPath(string $sub = ''): string
    {
        $path = $this->root . '/var';
        return $sub === '' ? $path : $path . '/' . \ltrim($sub, '/');
    }

    public function db(): Database
    {
        return $this->database ??= new Database($this->config);
    }

    public function clock(): Clock
    {
        return $this->clock ??= new Clock();
    }

    public function setClock(Clock $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * The mail queue (handoff Section 5.8). Pages queue; only the drain cron
     * sends, so a mail server being down cannot reach a request
     * (Phase 3 handoff Section 4.1).
     */
    public function outbox(): \Carl\Mail\Outbox
    {
        return $this->outbox ??= new \Carl\Mail\Outbox($this);
    }

    /** @var callable|null */
    private static $pushTransport = null;

    /**
     * How a push reaches a push service (Phase 17). Null is the real thing:
     * curl, inside Push\WebPush. The suite installs a function that records
     * the request instead, and both the timer cron and the "send a test
     * notification" button read it from here, so neither can quietly go
     * live in a test and the two cannot drift apart.
     *
     * STATIC, DELIBERATELY. The test client builds a fresh App for every
     * request it sends (tests/Client.php), so an override held on one
     * instance never reaches the request that needs it -- the first version
     * of this pushed to web.push.apple.com for real from inside the suite,
     * and Apple answered 400 BadDeviceToken. Process-wide is what "the suite
     * installs a transport" has to mean here.
     */
    public function pushTransport(): ?callable
    {
        return self::$pushTransport;
    }

    public function setPushTransport(?callable $transport): void
    {
        self::$pushTransport = $transport;
    }

    /**
     * The analysis queue (Phase 5 handoff Section 3.1). Pages queue a
     * request; only the drain cron calls the API, for the same reason mail
     * and weather work that way -- no third-party call on the request path
     * (Phase 3 handoff Section 5).
     */
    public function analyst(): \Carl\Analysis\Analyst
    {
        return $this->analyst ??= new \Carl\Analysis\Analyst($this);
    }

    /** Store SI, convert at display, in one helper (weather.md Section 6.3). */
    public function units(): Units
    {
        return $this->units ??= new Units($this->config->string('units', 'us'));
    }

    public function basePath(): string
    {
        $base = $this->config->string('base_path', '/');
        if ($base === '' || $base[0] !== '/') {
            $base = '/' . $base;
        }
        return \rtrim($base, '/') . '/';
    }

    /** Build an internal URL. Never hard-code a site-root path. */
    public function url(string $path = '', array $query = []): string
    {
        $url = $this->basePath() . \ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . \http_build_query($query);
        }
        return $url;
    }

    /**
     * An asset URL with a ?v=<mtime> stamp so a one-year Expires is safe and
     * a changed file busts itself (hosting Section 9).
     */
    public function asset(string $path): string
    {
        $relative = \ltrim($path, '/');
        $url = $this->basePath() . $relative;
        $file = $this->publicPath() . '/' . $relative;
        $mtime = \is_file($file) ? \filemtime($file) : false;
        return $mtime === false ? $url : $url . '?v=' . $mtime;
    }

    /**
     * Where public/ actually lives.
     *
     * Locally it is a sibling of app/. On the server it is
     * public_html/carl, which is NOT under the app root -- the two
     * directories are siblings of each other, not nested (hosting Section
     * 5.1). Guessing $root/public there silently costs every asset its
     * ?v=<mtime> stamp, and with a one-year Expires in .htaccess a changed
     * stylesheet would then not reach a browser for a year (hosting Section
     * 9). So the front controller, which is IN that directory, tells us.
     */
    public function publicPath(): string
    {
        if ($this->publicPath !== null) {
            return $this->publicPath;
        }
        $configured = $this->config->get('public_path');
        if (\is_string($configured) && \is_dir($configured)) {
            return $this->publicPath = $configured;
        }
        return $this->publicPath = $this->root . '/public';
    }

    /** Called by the front controller with its own directory. */
    public function setPublicPath(string $path): void
    {
        $this->publicPath = $path;
    }

    public function session(): Session
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $savePath = $this->config->get('session.save_path');
        return $this->session = new Session(
            $this->config->string('session.name', 'CARLSESS'),
            $this->config->string('session.path', $this->basePath()),
            $this->isSecure(),
            \is_string($savePath) && $savePath !== '' ? $savePath : $this->varPath('sessions'),
        );
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf($this->session());
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this);
    }

    public function view(): View
    {
        if ($this->view !== null) {
            return $this->view;
        }
        $view = new View($this->root . '/app/views', $this);
        $view->share('title', $this->config->string('app_title', 'Carl'));
        $view->share('units', $this->units());
        return $this->view = $view;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function isSecure(): bool
    {
        if ($this->request !== null) {
            return $this->request->isSecure();
        }
        $https = $_SERVER['HTTPS'] ?? '';
        return \is_string($https) && $https !== '' && \strtolower($https) !== 'off';
    }

    public function router(): Router
    {
        return $this->router ??= Routes::build();
    }

    // -- Kernel ----------------------------------------------------------

    public function handle(Request $request): Response
    {
        $this->request = $request;

        try {
            $match = $this->router()->match($request->method, $request->path);
            if ($match === null) {
                throw HttpException::notFound();
            }

            /** @var Route $route */
            $route  = $match['route'];
            $params = $match['params'];

            $guard = $this->guard($route, $request);
            if ($guard !== null) {
                return $this->decorate($guard, $route);
            }

            // A bearer request carries a JSON body, not a form, so the
            // empty-$_POST heuristic below would read every one as truncated.
            if ($request->isPost() && $route->access !== Route::BEARER_ACCESS) {
                if ($request->looksTruncated()) {
                    // Hosting Section 4: an over-size POST arrives with $_POST
                    // and $_FILES both empty and no error of its own.
                    throw new HttpException(413,
                        'That upload was larger than the server accepts (8 MB total, 2 MB per file). '
                        . 'Nothing was saved. Try a smaller photo.');
                }
                // Key-guarded routes are authenticated by the key itself,
                // and the single TOKEN_ACCESS route is a One-Click
                // unsubscribe that a mail client POSTs with no session to
                // carry a token in (see Route::TOKEN_ACCESS).
                $csrfExempt = $route->access === Route::KEY_ACCESS
                    || $route->access === Route::TOKEN_ACCESS
                    || $route->access === Route::BEARER_ACCESS;
                if (!$csrfExempt && !$this->csrf()->isValid($request->input('_csrf'))) {
                    throw new HttpException(419);
                }
            }

            $controller = new ($route->controller)($this);
            $response = $controller->{$route->action}($request, $params);

            if (!$response instanceof Response) {
                throw new \LogicException(
                    $route->controller . '::' . $route->action . ' did not return a Response.'
                );
            }

            return $this->decorate($response, $route);
        } catch (HttpException $e) {
            return $this->decorate($this->errorResponse($e->status, $e->getMessage(), $request), null);
        } catch (Throwable $e) {
            \error_log('[carl] ' . $e::class . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine());
            return $this->decorate(
                $this->errorResponse(500, '', $request, self::operatorHint($e)),
                null
            );
        }
    }

    /**
     * Access control is enforced here, on every request, rather than by
     * reaching a route (hosting Section 8.5).
     */
    private function guard(Route $route, Request $request): ?Response
    {
        if ($route->access === Route::KEY_ACCESS) {
            $expected = $this->config->secret((string) $route->keyName);
            $given = $request->query('key');
            // With no key configured the route does not exist; a wrong key is
            // 404 too, so it gives nothing away (hosting Section 6.3).
            if ($expected === null || $given === null || !\hash_equals($expected, $given)) {
                return Response::notFound();
            }
            return null;
        }

        if ($route->access === Route::PUBLIC_ACCESS || $route->access === Route::TOKEN_ACCESS) {
            return null;
        }

        if ($route->access === Route::BEARER_ACCESS) {
            return $this->guardBearer($request);
        }

        $user = $this->auth()->user();
        if ($user === null) {
            if ($request->isAjax()) {
                return Response::json(['error' => 'signed_out'], 401);
            }
            $this->session()->set('_intended', $request->path);
            return Response::redirect($this->url('login'));
        }

        // Admin routes require role admin AND are hidden to everyone else
        // (handoff Section 7).
        if ($route->access === Route::ADMIN_ACCESS && !$user->isAdmin()) {
            return Response::notFound();
        }

        // A forced reset outranks everything except the reset screen itself.
        if ($user->mustResetPassword && !\str_starts_with($route->pattern, '/password')) {
            return Response::redirect($this->url('password/reset'));
        }

        if ($route->access === Route::USER_ACCESS
            && !$user->isOnboarded()
            && !\str_starts_with($route->pattern, '/onboarding')) {
            return Response::redirect($this->url('onboarding'));
        }

        return null;
    }

    /**
     * The bearer token of a BEARER_ACCESS route (Phase 16, the MCP server).
     *
     * Two checks before the token, both from the MCP specification's
     * Streamable HTTP transport: the Origin header, when a client sends one,
     * must be this site's own -- the specification's one MUST for the
     * transport, against DNS rebinding -- and the method is POST, which the
     * router already enforces (a GET on the path is 405, because this server
     * never opens a stream; hosting Section 3 forbids held-open connections).
     *
     * Then the token. An unknown or revoked one is 401 with a
     * WWW-Authenticate challenge, a busy one is 429 with Retry-After, and a
     * good one signs the request in as its owner WITHOUT a session: the
     * repositories scope on the user id, not on the cookie, so nothing else
     * changes. The body of every refusal is a JSON-RPC error, because that is
     * what the client on the other end parses.
     */
    private function guardBearer(Request $request): ?Response
    {
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== '' && !$this->isOwnOrigin($origin, $request)) {
            return self::rpcRefusal(403, -32003, 'Origin not allowed.');
        }

        $authorization = (string) ($request->header('Authorization') ?? '');
        $token = \preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m) === 1 ? $m[1] : null;

        $store = new \Carl\Auth\ApiTokenStore($this->db(), $this->config->int('mcp.calls_per_minute', 60));
        $resolved = $store->resolve($token);

        if ($resolved['status'] === \Carl\Auth\ApiTokenStore::RATE_LIMITED) {
            return self::rpcRefusal(429, -32004, 'Too many calls on this token; try again in '
                . $resolved['retry_after'] . ' s.')
                ->withHeader('Retry-After', (string) $resolved['retry_after']);
        }
        if ($resolved['status'] !== \Carl\Auth\ApiTokenStore::RESOLVED || $resolved['user_id'] === null) {
            return self::rpcRefusal(401, -32001, 'A valid bearer token is required.')
                ->withHeader('WWW-Authenticate', 'Bearer realm="carl", error="invalid_token"');
        }

        $user = $this->auth()->assume((int) $resolved['user_id']);
        if ($user === null || !$user->isOnboarded()) {
            return self::rpcRefusal(401, -32001, 'A valid bearer token is required.')
                ->withHeader('WWW-Authenticate', 'Bearer realm="carl", error="invalid_token"');
        }
        $this->bearerTokenId = $resolved['id'];

        return null;
    }

    /** The api_token id a bearer request was made with, or null on a page. */
    public function bearerTokenId(): ?int
    {
        return $this->bearerTokenId;
    }

    /**
     * Is this Origin ours? The configured public origin, the host the request
     * arrived on, and anything listed under mcp.allowed_origins.
     */
    private function isOwnOrigin(string $origin, Request $request): bool
    {
        $allowed = [];
        $configured = $this->config->string('tags.origin');
        if ($configured !== '') {
            $allowed[] = \rtrim($configured, '/');
        }
        $host = (string) ($request->server['HTTP_HOST'] ?? '');
        if ($host !== '') {
            $allowed[] = ($request->isSecure() ? 'https://' : 'http://') . $host;
        }
        $extra = $this->config->get('mcp.allowed_origins');
        foreach (\is_array($extra) ? $extra : [] as $entry) {
            if (\is_string($entry) && $entry !== '') {
                $allowed[] = \rtrim($entry, '/');
            }
        }
        $origin = \rtrim($origin, '/');
        foreach ($allowed as $candidate) {
            if (\strcasecmp($candidate, $origin) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function rpcRefusal(int $status, int $code, string $message): Response
    {
        return Response::json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => $code, 'message' => $message],
        ], $status);
    }

    /**
     * A deploy copies code but never runs migrations (hosting Section 6.3),
     * so there is always a window in which the code is ahead of the schema
     * and every page touching a new table returns 500. The generic error page
     * sends whoever is diagnosing it looking for a routing or code fault --
     * which is exactly what happened on the Phase 3 deploy, when four pending
     * migrations presented as "there is nothing at that address".
     *
     * Naming the real cause turns that into a thirty-second fix. It is shown
     * to an ADMIN only: the table name and the schema state are not a user's
     * business, and /status already carries the detail.
     */
    private static function operatorHint(Throwable $e): string
    {
        // 42S02 is SQLSTATE "base table or view not found". PDO puts the
        // SQLSTATE in the exception's code, and repeats it in the message.
        $missingTable = $e instanceof \PDOException
            && ((string) $e->getCode() === '42S02' || \str_contains($e->getMessage(), '42S02'));

        $hint = $missingTable
            ? 'The database is missing a table this page needs. That almost always means a '
              . 'deploy added migrations that have not been run yet: open /status to see which '
              . 'are pending, then apply them at /setup. '
            : '';

        // The one line the error log would show, for the admin who cannot
        // read the error log (hosting Section 1: no shell, and the log is a
        // file under the home directory). The Phase 14 PDF failure was live
        // for a phase because the page said only "it has been logged" and
        // nobody could get at the log. Truncated, because an FPDF message
        // can carry an image body; and the DSN never reaches an exception
        // message (Database re-throws without it, hosting Section 7), so
        // nothing here can echo a credential.
        $detail = $e::class . ': ' . $e->getMessage();
        if (\strlen($detail) > 300) {
            $detail = \substr($detail, 0, 300) . '...';
        }
        return $hint . 'Error detail (shown to admins only): ' . $detail
            . ' at ' . \basename($e->getFile()) . ':' . $e->getLine();
    }

    private function errorResponse(
        int $status,
        string $message,
        Request $request,
        string $adminHint = '',
    ): Response {
        if ($request->isAjax()) {
            return Response::json([
                'error'   => $status,
                'message' => $message !== '' ? $message : 'Something went wrong.',
            ], $status);
        }

        try {
            $body = $this->view()->render('error', [
                'status'    => $status,
                'message'   => $message,
                'adminHint' => $adminHint,
                'user'      => $this->auth()->userOrNull(),
            ]);
            return Response::html($body, $status);
        } catch (Throwable) {
            return Response::text('Error ' . $status . ($message !== '' ? ': ' . $message : ''), $status);
        }
    }

    /** Global security headers on every response (hosting Section 8.5). */
    private function decorate(Response $response, ?Route $route): Response
    {
        // A rotated auth token only reaches the browser if it is attached here
        // (hosting Section 8.3).
        if ($this->auth !== null) {
            $response = $this->auth->decorate($response);
        }

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; "
                . "script-src 'self'; connect-src 'self'; form-action 'self'; "
                . "frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
            );

        $isAsset = $route === null || !\str_starts_with($route->pattern, '/assets');
        if ($isAsset && !isset($response->headers()['Cache-Control'])) {
            // Anything carrying personal data is no-store; that is nearly
            // every page here, so it is the default and pages opt out.
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function send(Response $response): void
    {
        $response->send($this->basePath(), $this->isSecure());
    }
}
