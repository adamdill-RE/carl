<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * A response that has not been sent yet, so headers stay testable.
 */
final class Response
{
    /**
     * Set on a streamed response: a producer yielding the body in pieces, so
     * a large export never has to exist in memory all at once.
     *
     * @var (\Closure():iterable<string>)|null
     */
    private ?\Closure $producer = null;

    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private array $headers = [],
        /** @var list<array{name:string,value:string,expires:int}> */
        private array $cookies = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $encoded = \json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return new self(
            $status,
            $encoded === false ? '{"error":"encoding failed"}' : $encoded,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /**
     * Hosting Section 6.3: an unconfigured or wrong key returns 404, never
     * 403, so it gives nothing away.
     */
    public static function notFound(string $body = ''): self
    {
        return self::html($body !== '' ? $body : '<h1>Not found</h1>', 404);
    }

    public static function binary(string $body, string $contentType, ?string $filename = null): self
    {
        $headers = [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) \strlen($body),
        ];
        if ($filename !== null) {
            $headers['Content-Disposition'] =
                'attachment; filename="' . \str_replace('"', '', $filename) . '"';
        }
        return new self(200, $body, $headers);
    }

    /**
     * A body produced in pieces rather than built in memory.
     *
     * memory_limit is 128M and an export has no natural size bound (hosting
     * Section 4), so the rows are read a chunk at a time and each chunk is
     * written out before the next is fetched. Nothing accumulates.
     *
     * There is no Content-Length: the length is not known when the headers
     * go out, which is the point.
     *
     * @param callable():iterable<string> $producer
     */
    public static function streamed(callable $producer, string $contentType, ?string $filename = null): self
    {
        $headers = ['Content-Type' => $contentType];
        if ($filename !== null) {
            $headers['Content-Disposition'] =
                'attachment; filename="' . \str_replace(['"', "\r", "\n"], '', $filename) . '"';
        }
        $response = new self(200, '', $headers);
        $response->producer = \Closure::fromCallable($producer);
        return $response;
    }

    public function isStreamed(): bool
    {
        return $this->producer !== null;
    }

    /**
     * The whole body as one string. Only tests and error paths should want
     * this -- calling it is exactly the memory cost streaming exists to avoid.
     */
    public function collect(): string
    {
        if ($this->producer === null) {
            return $this->body;
        }
        $out = '';
        foreach (($this->producer)() as $chunk) {
            $out .= $chunk;
        }
        return $out;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withCookie(string $name, string $value, int $expires): self
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'expires' => $expires];
        return $clone;
    }

    /** @return list<array{name:string,value:string,expires:int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(string $cookiePath, bool $secure): void
    {
        if (!\headers_sent()) {
            \http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                \header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                \setcookie($cookie['name'], $cookie['value'], [
                    'expires'  => $cookie['expires'],
                    'path'     => $cookiePath,
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }

        if ($this->producer === null) {
            echo $this->body;
            return;
        }

        // LiteSpeed buffers, so a flush per chunk is what actually gets the
        // first rows to the browser while the rest are still being read.
        foreach (($this->producer)() as $chunk) {
            echo $chunk;
            if (\ob_get_level() > 0) {
                \ob_flush();
            }
            \flush();
        }
    }
}
