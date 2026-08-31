<?php

declare(strict_types=1);

namespace Carl\Mail;

/**
 * A mail driver (handoff Section 12.1). Two implementations, chosen in
 * config/local.php: SmtpMailer to the cPanel mailbox, ApiMailer to Brevo.
 *
 * Only the outbox drain calls this. Nothing on the request path may
 * (Phase 3 handoff Section 5).
 */
interface Mailer
{
    /** @throws MailException when the message was not accepted. */
    public function send(Message $message): void;

    /** The driver's short name, recorded on the outbox row that it sent. */
    public function name(): string;

    /** One line for /status: what this driver is pointed at, no secrets. */
    public function describe(): string;
}
