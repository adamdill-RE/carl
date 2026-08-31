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
        [$regex, $names] = self::compile($pattern);

        $this->routes[] = new Route(
            \strtoupper($method),
            $pattern,
            $regex,
            $names,
            $controller,
            $action,
            $access,
            $keyName,
            $name === '' ? $controller . '::' . $action : $name,
        );

        return $this;
    }

    /**
     * Turn a pattern into a regex, escaping everything that is not a
     * placeholder.
     *
     * The literal text has to be escaped, not interpolated: '/export/plants.csv'
     * with a raw dot would also match '/export/plantsXcsv', and a route that
     * answers to a URL nobody wrote down is exactly the kind of thing that is
     * discovered years later. Placeholder constraints (\d+) are regex on
     * purpose and are spliced in unescaped.
     *
     * @return array{0:string,1:list<string>} the anchored regex and the
     *         placeholder names, in order
     */
    private static function compile(string $pattern): array
    {
        $names = [];
        $regex = '';
        $offset = 0;

        while (\preg_match('/\{([a-z_]+)(?::([^}]+))?\}/', $pattern, $m, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $m[0][1];
            $regex .= \preg_quote(\substr($pattern, $offset, $start - $offset), '#');
            $names[] = (string) $m[1][0];
            // An absent optional group reports offset -1, not null, so the
            // default cannot be reached with ?? alone.
            $constraint = isset($m[2]) && (int) $m[2][1] !== -1 ? (string) $m[2][0] : '[^/]+';
            $regex .= '(' . $constraint . ')';
            $offset = $start + \strlen((string) $m[0][0]);
        }

        $regex .= \preg_quote(\substr($pattern, $offset), '#');

        return ['#^' . $regex . '$#', $names];
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
