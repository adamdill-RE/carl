<?php

declare(strict_types=1);

namespace Carl\Mail;

/**
 * The RFC 5322 message SmtpMailer puts on the wire.
 *
 * ApiMailer does not need it -- Brevo assembles the message from JSON fields
 * -- so this lives in its own class where the encoding rules are stated once
 * and can be tested without opening a socket.
 *
 * Plain text first with a simple HTML twin (handoff Section 12), so it is
 * multipart/alternative whenever HTML is present, text part first, which is
 * the order that tells a reader the HTML is the alternative.
 *
 * Bodies are base64, not quoted-printable. PHP's quoted_printable_encode()
 * encodes every newline as =0A rather than leaving a literal CRLF, so the
 * result decodes to bare LFs and the 76-character rule has to be re-checked
 * by hand anyway. Base64 in 76-character lines is unambiguous, cannot
 * produce a lone "." at the start of a line, and every reader handles it.
 */
final class Mime
{
    /** @param array<string,string> $extraHeaders */
    public static function build(
        Message $message,
        string $fromEmail,
        ?string $fromName,
        ?string $replyTo = null,
        array $extraHeaders = [],
    ): string {
        $headers = [
            'Date'         => \gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID'   => '<' . \bin2hex(\random_bytes(16)) . '@' . self::domainOf($fromEmail) . '>',
            'From'         => Message::address($fromEmail, $fromName),
            'To'           => Message::address($message->toEmail, $message->toName),
            'Subject'      => self::encodeHeader($message->subject),
            'MIME-Version' => '1.0',
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $headers['Reply-To'] = $replyTo;
        }

        // Per-message headers last, so List-Unsubscribe cannot be silently
        // dropped by a default of the same name.
        foreach ($extraHeaders + $message->headers as $name => $value) {
            $headers[$name] = $value;
        }

        $lines = [];

        if ($message->bodyHtml === null || $message->bodyHtml === '') {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            $headers['Content-Transfer-Encoding'] = 'base64';
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $lines[] = '';
            $lines[] = self::base64Body($message->bodyText);
        } else {
            $boundary = 'carl-' . \bin2hex(\random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $lines[] = '';
            $lines[] = 'This is a message in MIME format. Your reader should not be showing you this.';
            $lines[] = '';
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/plain; charset=utf-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = self::base64Body($message->bodyText);
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/html; charset=utf-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = self::base64Body($message->bodyHtml);
            $lines[] = '--' . $boundary . '--';
        }

        return \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * RFC 2047 for a header that is not plain ASCII. A subject reading
     * "Carl: 3 items for today" never needs it; a plant nickname in one does.
     */
    public static function encodeHeader(string $value): string
    {
        if (\preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . \base64_encode($value) . '?=';
    }

    /** Base64 in 76-character CRLF lines, with no trailing break of its own. */
    public static function base64Body(string $body): string
    {
        $normalised = \str_replace(["\r\n", "\r"], "\n", $body);
        return \rtrim(\chunk_split(\base64_encode($normalised), 76, "\r\n"), "\r\n");
    }

    private static function domainOf(string $email): string
    {
        $at = \strrchr($email, '@');
        return $at === false ? 'localhost' : \substr($at, 1);
    }
}
