<?php

declare(strict_types=1);

namespace Carl\Mail;

use Carl\Core\HttpClient;

/**
 * Brevo's transactional endpoint, over HTTPS (handoff Section 12.1).
 *
 * It goes through HttpClient rather than round its own curl handle, so it
 * inherits the timeouts, the certificate verification and the quota
 * recognition already proved on the weather calls (Phase 3 handoff 1.3).
 *
 * The API is the second driver rather than the first because it needs an
 * account elsewhere; the cPanel mailbox needs nothing but DNS. Which of the
 * two actually lands in a Gmail inbox is spike 4, and the mail-test page
 * exists to answer it by sending one of each.
 */
final class ApiMailer implements Mailer
{
    public function __construct(
        private HttpClient $http,
        private string $url,
        private string $apiKey,
        private string $fromEmail,
        private ?string $fromName = null,
        private ?string $replyTo = null,
    ) {
    }

    public function name(): string
    {
        return 'api';
    }

    public function describe(): string
    {
        return 'api ' . $this->url . ' (key ' . (($this->apiKey === '') ? 'NOT SET' : 'set') . ')';
    }

    public function send(Message $message): void
    {
        if ($this->apiKey === '') {
            throw MailException::permanent('The API driver is selected but mail.api.key is blank.');
        }

        $payload = [
            'sender'      => \array_filter([
                'email' => $this->fromEmail,
                'name'  => $this->fromName,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'to'          => [\array_filter([
                'email' => $message->toEmail,
                'name'  => $message->toName,
            ], static fn ($v): bool => $v !== null && $v !== '')],
            'subject'     => $message->subject,
            'textContent' => $message->bodyText,
        ];

        if ($message->bodyHtml !== null && $message->bodyHtml !== '') {
            $payload['htmlContent'] = $message->bodyHtml;
        }
        if ($this->replyTo !== null && $this->replyTo !== '') {
            $payload['replyTo'] = ['email' => $this->replyTo];
        }
        if ($message->headers !== []) {
            // List-Unsubscribe and its One-Click twin ride here; Brevo passes
            // an unknown header straight through (handoff Section 12).
            $payload['headers'] = $message->headers;
        }

        $result = $this->http->postJson($this->url, $payload, ['api-key: ' . $this->apiKey]);

        if ($result->ok()) {
            return;
        }

        $reason = self::reasonFrom($result->json) ?? $result->error ?? 'no reason given';
        $detail = 'Brevo: HTTP ' . $result->status . ' -- ' . $reason;

        // 401 is a bad key and 400 is a malformed message: both send the same
        // thing to the same endpoint for the same answer on a retry. A 429 is
        // exactly what the outbox's backoff is for, so it stays retryable.
        if (\in_array($result->status, [400, 401, 403], true)) {
            throw MailException::permanent($detail);
        }
        throw new MailException($detail);
    }

    /** @param array<string,mixed>|null $json */
    private static function reasonFrom(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }
        $message = $json['message'] ?? null;
        $code = $json['code'] ?? null;
        if (!\is_string($message)) {
            return null;
        }
        return \is_string($code) ? $code . ': ' . $message : $message;
    }
}
