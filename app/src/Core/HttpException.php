<?php

declare(strict_types=1);

namespace Carl\Core;

use RuntimeException;

/**
 * Thrown to end a request with a status. 403 is deliberately absent from the
 * places that hide their existence: those throw 404 instead (hosting 6.3).
 */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message === '' ? self::defaultMessage($status) : $message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'That request could not be understood.',
            403 => 'You do not have access to that.',
            404 => 'Not found.',
            405 => 'That method is not allowed here.',
            409 => 'That conflicts with something already saved.',
            413 => 'That upload was too large.',
            419 => 'Your session expired. Please try again.',
            429 => 'Too many attempts. Wait a moment and try again.',
            default => 'Something went wrong.',
        };
    }
}
