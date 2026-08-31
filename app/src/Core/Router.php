<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * A small explicit route table. Patterns use {name} for a segment and
 * {name:\d+} to constrain it.
 *
 * Paths here are site-root-relative; Request has already stripped the mount
 * point, so nothing in this file knows about /carl/ (hosting Section 5.2).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param class-string $controller */
    public function add(
        string $method,
        string $pattern,
        string $controller,
        string $action,
        string $access = Route::USER_ACCESS,
        ?string $keyName = null,
        string $name = '',
    ): self {
        $names = [];
        $regex = \preg_replace_callback(
            '/\{([a-z_]+)(?::([^}]+))?\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '(' . ($m[2] ?? '[^/]+') . ')';
            },
            $pattern
        );

        $this->routes[] = new Route(
            \strtoupper($method),
            $pattern,
            '#^' . $regex . '$#',
            $names,
            $controller,
            $action,
            $access,
            $keyName,
            $name === '' ? $controller . '::' . $action : $name,
        );

        return $this;
    }

    /** @param class-string $controller */
    public function get(string $pattern, string $controller, string $action, string $access = Route::USER_ACCESS, ?string $keyName = null): self
    {
        return $this->add('GET', $pattern, $controller, $action, $access, $keyName);
    }

    /** @param class-string $controller */
    public function post(string $pattern, string $controller, string $action, string $access = Route::USER_ACCESS, ?string $keyName = null): self
    {
        return $this->add('POST', $pattern, $controller, $action, $access, $keyName);
    }

    /**
     * @return array{route:Route,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = \strtoupper($method);
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (\preg_match($route->regex, $path, $m) !== 1) {
                continue;
            }
            if ($route->method !== $method) {
                $pathMatchedOtherMethod = true;
                continue;
            }
            $params = [];
            foreach ($route->parameterNames as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ['route' => $route, 'params' => $params];
        }

        if ($pathMatchedOtherMethod) {
            throw new HttpException(405);
        }

        return null;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
