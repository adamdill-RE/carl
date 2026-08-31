<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * A response that has not been sent yet, so headers stay testable.
 */
final class Response
{
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
        echo $this->body;
    }
}
