<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * The incoming request, with the subpath already stripped so routes are
 * written site-root-relative (hosting Section 5.2).
 */
final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $server
     * @param array<string,string> $cookies
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        /** The raw body, for a JSON request; null until read (see rawBody()). */
        private ?string $rawBody = null,
    ) {
    }

    /**
     * @param string|null $rawBody the body, when the caller already has it
     *        (tests); otherwise php://input is read on first use
     */
    public static function fromGlobals(string $basePath, ?string $rawBody = null): self
    {
        $method = \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path   = \parse_url($uri, \PHP_URL_PATH);
        $path   = \is_string($path) ? \rawurldecode($path) : '/';

        // Strip the mount point; everything downstream is subpath-agnostic.
        //
        // CASE-INSENSITIVELY, and only here. A printed QR tag may carry an
        // all-uppercase URL, because uppercase is what keeps it in QR's
        // alphanumeric mode (docs/QR-TAGS-SPEC.md Section 2.2), and that
        // includes the mount segment. Whether /CARL/ ever reaches PHP is the
        // web server's decision -- Carl\Qr\TagUrl is the docblock about that
        // -- but when it does, the prefix must come off or nothing matches.
        //
        // This loosens the strip and nothing else: what is removed is a known
        // prefix, and every route below still matches case-sensitively, so
        // /ADMIN is as much a 404 as it ever was.
        $trimmedBase = \rtrim($basePath, '/');
        if ($trimmedBase !== '' && \strncasecmp($path, $trimmedBase, \strlen($trimmedBase)) === 0) {
            $path = \substr($path, \strlen($trimmedBase));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if ($path !== '/' ) {
            $path = \rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            \array_map(static fn ($v): string => \is_string($v) ? $v : '', $_COOKIE),
            $rawBody,
        );
    }

    /**
     * The request body as bytes: a JSON-RPC message, not a form (Phase 16).
     *
     * php://input can be read once under some SAPIs, so it is read once here
     * and kept. Bounded at 1 MB: the largest legitimate MCP message is a tool
     * call with a search string in it, and post_max_size is 8M anyway.
     */
    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $body = @\file_get_contents('php://input', false, null, 0, 1048576);
            $this->rawBody = \is_string($body) ? $body : '';
        }
        return $this->rawBody;
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    public static function synthetic(string $method, string $path, array $query = [], array $post = []): self
    {
        return new self(\strtoupper($method), $path, $query, $post, [], [], []);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return \is_scalar($value) ? \trim((string) $value) : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;
        return \is_scalar($value) ? \trim((string) $value) : $default;
    }

    /** Trimmed, empty string collapses to null: an empty cell is NULL, never zero. */
    public function nullable(string $key): ?string
    {
        $value = $this->input($key);
        return ($value === null || $value === '') ? null : $value;
    }

    public function intInput(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if ($value === null || $value === '' || \preg_match('/^-?\d+$/', $value) !== 1) {
            return $default;
        }
        return (int) $value;
    }

    public function floatInput(string $key, ?float $default = null): ?float
    {
        $value = $this->input($key);
        if ($value === null || $value === '' || !\is_numeric($value)) {
            return $default;
        }
        return (float) $value;
    }

    public function checkbox(string $key): bool
    {
        $value = $this->post[$key] ?? null;
        return $value !== null && $value !== '' && $value !== '0';
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $out[] = \trim((string) $item);
            }
        }
        return $out;
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $out = [];
        foreach ($this->inputList($key) as $item) {
            if (\preg_match('/^\d+$/', $item) === 1) {
                $out[] = (int) $item;
            }
        }
        return $out;
    }

    /**
     * The same pair, for the query string.
     *
     * A FILTER IS A GET, so `?plant_id[]=3&plant_id[]=7` is a shape this
     * class has to read as well: a filtered calendar has to be bookmarkable,
     * shareable and reachable with the back button, none of which is true of
     * a POST. query() itself cannot serve it -- it trims a scalar and returns
     * the default for an array, which is right for every other caller.
     *
     * @return list<string>
     */
    public function queryList(string $key): array
    {
        $value = $this->query[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $out[] = \trim((string) $item);
            }
        }
        return $out;
    }

    /** @return list<int> */
    public function queryIntList(string $key): array
    {
        $out = [];
        foreach ($this->queryList($key) as $item) {
            if (\preg_match('/^\d+$/', $item) === 1) {
                $out[] = (int) $item;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return \is_array($file) ? $file : null;
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (\is_string($https) && $https !== '' && \strtolower($https) !== 'off') {
            return true;
        }
        return ((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if (!\is_string($value) && $key === 'HTTP_AUTHORIZATION') {
            // Apache and LiteSpeed strip Authorization from the CGI
            // environment unless told otherwise; public/.htaccess re-exports
            // it, and a rewrite can land it under the REDIRECT_ prefix.
            $value = $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        return \is_string($value) ? $value : null;
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '';
        return \is_string($ip) ? $ip : '';
    }

    /**
     * Hosting Section 4: PHP silently truncates past max_input_vars, and an
     * over-size POST arrives with both $_POST and $_FILES empty. Both are
     * indistinguishable from a valid empty form without this check.
     */
    public function looksTruncated(): bool
    {
        if (!$this->isPost()) {
            return false;
        }
        if ($this->post !== [] || $this->files !== []) {
            return false;
        }
        $length = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        return $length > 0;
    }
}
