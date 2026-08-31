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
     * Where public/ actually lives. Locally it is a sibling of app/; on the
     * server it is public_html/carl, which is not under the app root at all.
     */
    public function publicPath(): string
    {
        $local = $this->root . '/public';
        if (\is_dir($local)) {
            return $local;
        }
        $configured = $this->config->get('public_path');
        return \is_string($configured) ? $configured : $local;
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

            if ($request->isPost()) {
                if ($request->looksTruncated()) {
                    // Hosting Section 4: an over-size POST arrives with $_POST
                    // and $_FILES both empty and no error of its own.
                    throw new HttpException(413,
                        'That upload was larger than the server accepts (8 MB total, 2 MB per file). '
                        . 'Nothing was saved. Try a smaller photo.');
                }
                if ($route->access !== Route::KEY_ACCESS
                    && !$this->csrf()->isValid($request->input('_csrf'))) {
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
            return $this->decorate($this->errorResponse(500, '', $request), null);
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

        if ($route->access === Route::PUBLIC_ACCESS) {
            return null;
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

    private function errorResponse(int $status, string $message, Request $request): Response
    {
        if ($request->isAjax()) {
            return Response::json([
                'error'   => $status,
                'message' => $message !== '' ? $message : 'Something went wrong.',
            ], $status);
        }

        try {
            $body = $this->view()->render('error', [
                'status'  => $status,
                'message' => $message,
                'user'    => $this->auth()->userOrNull(),
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
