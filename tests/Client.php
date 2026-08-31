<?php

declare(strict_types=1);

namespace Carl\Tests;

use Carl\Core\App;
use Carl\Core\Config;
use Carl\Core\Request;
use Carl\Core\Response;

/**
 * Drives the application the way a browser does: a fresh App per request,
 * with the session and the auth cookie carried between them.
 *
 * This is what makes the alpha acceptance run (handoff Section 14) testable
 * without a web server -- the same kernel, the same guards, the same CSRF.
 */
final class Client
{
    /** @var array<string,string> */
    private array $cookies = [];

    private ?App $lastApp = null;

    public function __construct(private string $root)
    {
    }

    /** @param array<string,string> $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query, []);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,string> $query
     */
    public function post(string $path, array $post = [], array $query = []): Response
    {
        // A real form always carries the token, so the client does too --
        // the CSRF check itself is exercised by postWithoutCsrf().
        $post['_csrf'] = $this->csrfToken();
        return $this->send('POST', $path, $query, $post);
    }

    /** @param array<string,mixed> $post */
    public function postWithoutCsrf(string $path, array $post = []): Response
    {
        return $this->send('POST', $path, [], $post);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,array<string,mixed>> $files
     */
    public function postFiles(string $path, array $post, array $files): Response
    {
        $post['_csrf'] = $this->csrfToken();
        return $this->send('POST', $path, [], $post, $files);
    }

    public function csrfToken(): string
    {
        return $this->app()->csrf()->token();
    }

    /** The App that served the last request, for poking at repositories. */
    public function app(): App
    {
        return $this->lastApp ??= $this->makeApp();
    }

    public function session(): \Carl\Core\Session
    {
        return $this->app()->session();
    }

    public function forgetCookies(): void
    {
        $this->cookies = [];
        $_SESSION = [];
        $this->lastApp = null;
    }

    /**
     * @param array<string,string> $query
     * @param array<string,mixed> $post
     * @param array<string,array<string,mixed>> $files
     */
    private function send(string $method, string $path, array $query, array $post, array $files = []): Response
    {
        $app = $this->makeApp();
        $this->lastApp = $app;

        $_GET = $query;
        $_POST = $post;
        $_FILES = $files;
        $_COOKIE = $this->cookies;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $app->basePath() . \ltrim($path, '/')
            . ($query !== [] ? '?' . \http_build_query($query) : '');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_USER_AGENT'] = 'CarlTests/1.0';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['CONTENT_LENGTH'] = (string) ($post === [] && $files === [] ? 0 : 100);

        $request = Request::fromGlobals($app->basePath());
        $response = $app->handle($request);

        foreach ($response->cookies() as $cookie) {
            if ($cookie['expires'] < \time()) {
                unset($this->cookies[$cookie['name']]);
            } else {
                $this->cookies[$cookie['name']] = $cookie['value'];
            }
        }

        return $response;
    }

    private function makeApp(): App
    {
        return new App(Config::load($this->root), $this->root);
    }

    /** Follow a redirect the way a browser would, up to a few hops. */
    public function follow(Response $response, int $hops = 3): Response
    {
        $app = $this->app();
        while ($hops-- > 0 && $response->status >= 300 && $response->status < 400) {
            $location = $response->headers()['Location'] ?? null;
            if ($location === null) {
                break;
            }
            $path = (string) \parse_url($location, \PHP_URL_PATH);
            $queryString = (string) \parse_url($location, \PHP_URL_QUERY);
            $query = [];
            if ($queryString !== '') {
                \parse_str($queryString, $query);
            }
            $base = \rtrim($app->basePath(), '/');
            if ($base !== '' && \str_starts_with($path, $base)) {
                $path = \substr($path, \strlen($base));
            }
            $response = $this->get($path === '' ? '/' : $path, \array_map(\strval(...), $query));
        }
        return $response;
    }
}
