<?php

declare(strict_types=1);

namespace Carl\Mail;

use RuntimeException;

/**
 * A send that did not happen. The outbox records the message on the row and
 * schedules a retry; nothing here is ever shown to a user.
 *
 * $permanent marks the failures a retry cannot help -- a rejected address, a
 * refused authentication -- so the drain stops spending attempts on them.
 */
final class MailException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function permanent(string $message): self
    {
        return new self($message, true);
    }
}
