<?php

declare(strict_types=1);

namespace Carl\Analysis;

/**
 * One answer, or one reason there is not one.
 *
 * `retryable` is the field that matters and it is decided at the point the
 * HTTP outcome is read, not at the point the row is updated. A 429 and a 400
 * both fail; only one of them is worth four attempts, and the difference is
 * knowable exactly once -- when the status code and the error type are still
 * in hand.
 */
final class Reply
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $truncated,
        public readonly ?string $error,
        public readonly bool $retryable,
    ) {
    }

    public static function ok(
        string $text,
        string $model,
        int $inputTokens,
        int $outputTokens,
        bool $truncated = false,
    ): self {
        return new self(true, $text, $model, $inputTokens, $outputTokens, $truncated, null, false);
    }

    public static function failed(string $error, bool $retryable): self
    {
        return new self(false, '', '', 0, 0, false, \substr($error, 0, 500), $retryable);
    }
}
