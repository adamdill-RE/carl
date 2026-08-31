<?php

declare(strict_types=1);

namespace Carl\Core;

/** The outcome of one outbound call, in a shape a run-log row can store. */
final class HttpResult
{
    /** @param array<string,mixed>|null $json */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $body,
        public readonly float $seconds,
        public readonly ?string $error,
        public readonly ?array $json = null,
        public readonly int $attempts = 1,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /** @param array<string,mixed> $json */
    public function withJson(array $json): self
    {
        return new self($this->url, $this->status, $this->body, $this->seconds, $this->error, $json, $this->attempts);
    }

    public function withError(string $error): self
    {
        return new self($this->url, $this->status, $this->body, $this->seconds, $error, $this->json, $this->attempts);
    }

    public function withAttempts(int $attempts): self
    {
        return new self($this->url, $this->status, $this->body, $this->seconds, $this->error, $this->json, $attempts);
    }

    public function ms(): int
    {
        return (int) \round($this->seconds * 1000);
    }

    /** Fits weather_sync_run.error_text (VARCHAR(500)). */
    public function errorText(): ?string
    {
        return $this->error === null ? null : \substr($this->error, 0, 500);
    }
}
