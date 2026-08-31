<?php

declare(strict_types=1);

namespace Carl\Mail;

/**
 * One outbound message, as both drivers need it.
 *
 * Header injection is checked here rather than in each driver: a CR or LF in
 * a subject or an address ends the header and begins another, which is how a
 * message acquires a second Bcc. Every value that reaches a header goes
 * through assertNoBreaks(), and the check is in the constructor so a driver
 * cannot be written that forgets it.
 */
final class Message
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?string $bodyHtml = null,
        public readonly array $headers = [],
    ) {
        self::assertNoBreaks($toEmail, 'recipient address');
        self::assertNoBreaks($toName ?? '', 'recipient name');
        self::assertNoBreaks($subject, 'subject');
        foreach ($headers as $name => $value) {
            self::assertNoBreaks($name, 'header name');
            self::assertNoBreaks($value, 'header value for ' . $name);
        }
        if (!self::isEmail($toEmail)) {
            throw new MailException('Not a usable email address: ' . $toEmail);
        }
    }

    public static function isEmail(string $value): bool
    {
        return \filter_var($value, \FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function assertNoBreaks(string $value, string $what): void
    {
        if (\strpbrk($value, "\r\n") !== false) {
            throw new MailException('A line break in the ' . $what . ' would forge a header.');
        }
    }

    /** `Name <address>`, or the bare address when there is no name. */
    public static function address(string $email, ?string $name): string
    {
        if ($name === null || \trim($name) === '') {
            return $email;
        }
        // RFC 5322 quoted-string, so a comma or a dot in a display name
        // cannot be read as an address separator.
        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '" <' . $email . '>';
    }
}
