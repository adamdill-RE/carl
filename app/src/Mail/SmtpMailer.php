<?php

declare(strict_types=1);

namespace Carl\Mail;

/**
 * SMTP-AUTH over TLS to the cPanel mailbox, on stream_socket_client and
 * openssl (handoff Section 12.1). No Composer on this host, so no PHPMailer
 * (hosting Section 3) -- and SMTP for one recipient with no attachments is
 * a short enough conversation to hand-roll honestly.
 *
 * Port 465 is implicit TLS: the socket is ssl:// from the first byte.
 * Port 587 is STARTTLS: connect in the clear, EHLO, upgrade, EHLO again.
 * Both are offered because cPanel's "Connect Devices" page lists both and
 * which one a given account prefers is not knowable from here.
 *
 * Two details are the ones that bite:
 *
 *  - A multi-line reply ("250-PIPELINING" then "250 HELP") must be read to
 *    the line whose fourth character is a space. Stopping at the first line
 *    leaves the rest in the buffer and every later read is off by one reply.
 *  - A line of the message beginning with "." ends DATA. It has to be
 *    doubled. Base64 bodies cannot produce one, but a header could, and the
 *    stuffing costs nothing.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    /** @var list<string> the conversation, for the error text on a failure */
    private array $transcript = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $fromEmail,
        private ?string $fromName = null,
        private ?string $replyTo = null,
        private int $timeout = 20,
    ) {
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function describe(): string
    {
        return \sprintf('smtp %s:%d (%s) as %s',
            $this->host, $this->port, $this->encryption, $this->username);
    }

    public function send(Message $message): void
    {
        if ($this->host === '' || $this->username === '' || $this->password === '') {
            throw MailException::permanent('SMTP is selected but host, username or password is blank.');
        }

        $this->transcript = [];

        try {
            $this->connect();
            $this->expect(220);
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $this->fromEmail . '>');
            $this->expect(250);
            $this->command('RCPT TO:<' . $message->toEmail . '>');
            // 251 is "will forward"; both mean the recipient was accepted.
            $this->expect(250, 251);

            $this->command('DATA');
            $this->expect(354);

            $body = Mime::build($message, $this->fromEmail, $this->fromName, $this->replyTo);
            $this->write(self::stuffDots($body) . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT');
        } catch (MailException $e) {
            $this->close();
            throw $e;
        } catch (\Throwable $e) {
            $this->close();
            throw new MailException('SMTP: ' . $e->getMessage());
        }

        $this->close();
    }

    // -- The conversation -------------------------------------------------

    private function connect(): void
    {
        $scheme = $this->encryption === 'tls' ? 'ssl://' : 'tcp://';
        $context = \stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $errorNumber = 0;
        $errorText = '';
        $socket = @\stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errorNumber,
            $errorText,
            $this->timeout,
            \STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailException(\sprintf('cannot reach %s:%d -- %s (%d)',
                $this->host, $this->port, $errorText !== '' ? $errorText : 'no reason given', $errorNumber));
        }

        \stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function handshake(): void
    {
        $this->command('EHLO ' . $this->heloName());
        $capabilities = $this->expect(250);

        if ($this->encryption !== 'starttls') {
            return;
        }

        if (\stripos($capabilities, 'STARTTLS') === false) {
            throw MailException::permanent(
                'The server did not offer STARTTLS on port ' . $this->port
                . '. Use port 465 with encryption "tls" instead.'
            );
        }

        $this->command('STARTTLS');
        $this->expect(220);

        $socket = $this->socket;
        if ($socket === null || @\stream_socket_enable_crypto(
            $socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT
        ) !== true) {
            throw new MailException('STARTTLS negotiation failed.');
        }

        // Capabilities are re-announced after the upgrade, and only the ones
        // announced then may be trusted.
        $this->command('EHLO ' . $this->heloName());
        $this->expect(250);
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN');
        $this->expect(334);
        $this->command(\base64_encode($this->username), redact: true);
        $this->expect(334);
        $this->command(\base64_encode($this->password), redact: true);

        try {
            $this->expect(235);
        } catch (MailException $e) {
            // A refused login is not going to start working on a retry.
            throw MailException::permanent('SMTP authentication was refused: ' . $e->getMessage());
        }
    }

    private function command(string $line, bool $redact = false): void
    {
        $this->transcript[] = '> ' . ($redact ? '(credentials)' : $line);
        $this->write($line . "\r\n");
    }

    private function write(string $data): void
    {
        $socket = $this->socket;
        if ($socket === null) {
            throw new MailException('The connection closed before the message was sent.');
        }
        if (@\fwrite($socket, $data) === false) {
            throw new MailException('The connection dropped while writing.');
        }
    }

    /**
     * Read one whole reply and check its code.
     *
     * A reply is one or more lines; every line but the last has a '-' as its
     * fourth character. Reading only the first would leave the rest for the
     * next expect(), which then reads a stale reply and reports a nonsense
     * error several commands later.
     */
    private function expect(int ...$codes): string
    {
        $socket = $this->socket;
        if ($socket === null) {
            throw new MailException('The connection closed before a reply arrived.');
        }

        $reply = '';
        $code = 0;

        while (true) {
            $line = \fgets($socket, 1024);
            if ($line === false) {
                $meta = \stream_get_meta_data($socket);
                throw new MailException(($meta['timed_out'] ?? false)
                    ? 'The server stopped replying (timeout after ' . $this->timeout . ' s).'
                    : 'The connection closed mid-reply.');
            }
            $reply .= $line;
            $this->transcript[] = '< ' . \rtrim($line);

            if (\strlen($line) < 4 || $line[3] !== '-') {
                $code = (int) \substr($line, 0, 3);
                break;
            }
        }

        if (!\in_array($code, $codes, true)) {
            // 5xx is a refusal; retrying sends the same thing to the same
            // server for the same answer. 4xx is "not now", which is exactly
            // what the outbox's backoff is for.
            $message = 'SMTP expected ' . \implode('/', $codes) . ', got: ' . \trim($reply);
            throw $code >= 500 && $code < 600
                ? MailException::permanent($message)
                : new MailException($message);
        }

        return $reply;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @\fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * The name given in EHLO. It should be a name the sending host answers
     * to; the sending domain is the closest thing available here and is what
     * receivers actually check against.
     */
    private function heloName(): string
    {
        $at = \strrchr($this->fromEmail, '@');
        return $at === false ? 'localhost' : \substr($at, 1);
    }

    /** RFC 5321 transparency: a line starting with '.' would end DATA. */
    public static function stuffDots(string $body): string
    {
        if (\str_starts_with($body, '.')) {
            $body = '.' . $body;
        }
        return \str_replace("\r\n.", "\r\n..", $body);
    }

    /** @return list<string> the conversation, credentials redacted. */
    public function transcript(): array
    {
        return $this->transcript;
    }
}
