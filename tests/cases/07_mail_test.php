<?php

/**
 * Mail: the outbox, the two drivers and the drain (handoff Sections 5.8 and
 * 12.1; Phase 3 handoff Section 4.1).
 *
 * SmtpMailer is exercised against a real socket -- a listener on 127.0.0.1
 * that speaks just enough SMTP to accept or refuse a message. A driver that
 * has only ever been unit-tested against a mock is a driver whose multi-line
 * reply handling has never actually been tried, and that is the bug this
 * kind of code always has.
 *
 * @var Carl\Tests\Harness $t
 * @var Carl\Core\App $app
 */

declare(strict_types=1);

use Carl\Core\Config;
use Carl\Core\HttpClient;
use Carl\Mail\ApiMailer;
use Carl\Mail\MailException;
use Carl\Mail\Message;
use Carl\Mail\Mime;
use Carl\Mail\Outbox;
use Carl\Mail\SmtpMailer;

$db = $app->db();
$suffix = \substr(\bin2hex(\random_bytes(4)), 0, 8);

/**
 * A small SMTP server, forked so the driver can talk to it over a socket.
 *
 * pcntl is not on the production host and nothing in the application uses
 * it; it is only ever reached from this test, and the test skips itself
 * where it is absent.
 */
final class FakeSmtpServer
{
    public int $port = 0;
    private int $pid = 0;
    private string $logPath;

    /** @param array<string,string> $replies command prefix => reply */
    public function __construct(private array $replies = [])
    {
        $this->logPath = \sys_get_temp_dir() . '/carl-smtp-' . \bin2hex(\random_bytes(6)) . '.log';
    }

    public static function available(): bool
    {
        return \function_exists('pcntl_fork') && \function_exists('posix_kill');
    }

    public function start(): void
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('cannot listen: ' . $errstr);
        }
        $name = (string) \stream_socket_get_name($socket, false);
        $this->port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('cannot fork');
        }

        if ($pid === 0) {
            // Child: serve until idle, then leave without
            // running any of the parent's shutdown handlers.
            $this->serve($socket);
            exit(0);
        }

        $this->pid = $pid;
        \fclose($socket);
    }

    /**
     * Serve connections until five idle seconds pass. More than one, because
     * a drain sends several messages and each opens its own connection.
     *
     * @param resource $socket
     */
    private function serve($socket): void
    {
        while (($client = @\stream_socket_accept($socket, 5)) !== false) {
            $this->session($client);
        }
        \fclose($socket);
    }

    /** @param resource $client */
    private function session($client): void
    {
        \stream_set_timeout($client, 5);
        \fwrite($client, "220 fake.test ESMTP Carl\r\n");

        $log = '';
        $inData = false;
        $authLines = 0;

        while (($line = \fgets($client, 4096)) !== false) {
            $log .= $line;

            if ($inData) {
                if (\rtrim($line, "\r\n") === '.') {
                    $inData = false;
                    \fwrite($client, $this->replyFor('__EOD__', "250 2.0.0 Ok: queued\r\n"));
                }
                continue;
            }

            $command = \strtoupper(\substr(\trim($line), 0, 4));

            if ($command === 'EHLO') {
                // Deliberately multi-line: reading only the first line is the
                // classic hand-rolled-SMTP bug, and this is what catches it.
                \fwrite($client, $this->replyFor('EHLO',
                    "250-fake.test\r\n250-PIPELINING\r\n250-SIZE 52428800\r\n250-AUTH LOGIN PLAIN\r\n250 HELP\r\n"));
            } elseif ($command === 'AUTH') {
                \fwrite($client, $this->replyFor('AUTH', "334 VXNlcm5hbWU6\r\n"));
            } elseif ($command === 'MAIL') {
                \fwrite($client, $this->replyFor('MAIL', "250 2.1.0 Ok\r\n"));
            } elseif ($command === 'RCPT') {
                \fwrite($client, $this->replyFor('RCPT', "250 2.1.5 Ok\r\n"));
            } elseif ($command === 'DATA') {
                \fwrite($client, $this->replyFor('DATA', "354 End data with <CR><LF>.<CR><LF>\r\n"));
                $inData = true;
            } elseif ($command === 'QUIT') {
                \fwrite($client, "221 2.0.0 Bye\r\n");
                break;
            } else {
                // The two base64 lines of AUTH LOGIN land here: username
                // first, then password.
                $authLines++;
                \fwrite($client, $authLines === 1
                    ? "334 UGFzc3dvcmQ6\r\n"
                    : $this->replyFor('__AUTHLINE2__', "235 2.7.0 Accepted\r\n"));
            }
        }

        \file_put_contents($this->logPath, $log, \FILE_APPEND);
        \fclose($client);
    }

    private function replyFor(string $key, string $default): string
    {
        return $this->replies[$key] ?? $default;
    }

    /** What the client actually sent. */
    public function transcript(): string
    {
        for ($i = 0; $i < 50 && !\is_file($this->logPath); $i++) {
            \usleep(20000);
        }
        return \is_file($this->logPath) ? (string) \file_get_contents($this->logPath) : '';
    }

    public function stop(): void
    {
        if ($this->pid > 0) {
            \pcntl_waitpid($this->pid, $status, \WNOHANG);
            @\posix_kill($this->pid, \SIGTERM);
            \pcntl_waitpid($this->pid, $status);
            $this->pid = 0;
        }
        // A session the driver abandoned mid-conversation never got as far as
        // writing its log, so this file is often simply not there.
        if (\is_file($this->logPath)) {
            @\unlink($this->logPath);
        }
    }
}

$t->group('A message refuses to forge a header');

$t->test('a line break in the subject or an address is refused', function ($t): void {
    $t->throws(MailException::class, static fn () => new Message(
        'a@example.test', null, "Hello\r\nBcc: everyone@example.test", 'body'
    ), 'a CRLF in a subject would begin a second header');

    $t->throws(MailException::class, static fn () => new Message(
        "a@example.test\nBcc: x@example.test", null, 'Hello', 'body'
    ));

    $t->throws(MailException::class, static fn () => new Message(
        'a@example.test', null, 'Hello', 'body', null, ['X-Thing' => "one\r\nX-Other: two"]
    ));
});

$t->test('an address that is not an address is refused', function ($t): void {
    $t->throws(MailException::class, static fn () => new Message('not-an-address', null, 'S', 'b'));
});

$t->test('a display name is quoted so a comma cannot split it', function ($t): void {
    $t->same('"Grower, A" <a@example.test>', Message::address('a@example.test', 'Grower, A'));
    $t->same('a@example.test', Message::address('a@example.test', null));
    $t->same('"He said \\"hi\\"" <a@example.test>', Message::address('a@example.test', 'He said "hi"'));
});

/** The single-part body of a built message, decoded. */
$decodeBody = static function (string $mime): string {
    $parts = \explode("\r\n\r\n", $mime, 2);
    return (string) \base64_decode(\str_replace("\r\n", '', \trim($parts[1] ?? '')), true);
};

$t->group('The MIME message');

$t->test('text only is one part; text plus HTML is multipart/alternative',
    function ($t) use ($decodeBody): void {
    $plain = Mime::build(new Message('a@example.test', 'A', 'Subject', "line one\nline two"),
        'carl@example.test', 'Carl');
    $t->contains('Content-Type: text/plain; charset=utf-8', $plain);
    $t->notContains('multipart', $plain);
    $t->same("line one\nline two", $decodeBody($plain));

    $both = Mime::build(new Message('a@example.test', 'A', 'Subject', 'text', '<p>html</p>'),
        'carl@example.test', 'Carl');
    $t->contains('multipart/alternative; boundary="carl-', $both);
    // Text first: that is the order that tells a reader the HTML is the
    // alternative rather than the other way round.
    $t->ok(\strpos($both, 'text/plain') < \strpos($both, 'text/html'));
});

$t->test('a non-ASCII subject is RFC 2047 encoded, an ASCII one is left alone', function ($t): void {
    $t->same('Carl: 3 items for today', Mime::encodeHeader('Carl: 3 items for today'));
    $encoded = Mime::encodeHeader('Jalapeño');
    $t->contains('=?UTF-8?B?', $encoded);
    $t->same('Jalapeño', \base64_decode(\substr($encoded, 10, -2), true));
});

$t->test('base64 lines stay within 76 characters', function ($t): void {
    $body = Mime::base64Body(\str_repeat('The quick brown fox. ', 60));
    foreach (\explode("\r\n", $body) as $line) {
        $t->ok(\strlen($line) <= 76, 'line of ' . \strlen($line) . ' characters');
    }
    $t->same(\str_repeat('The quick brown fox. ', 60),
        \base64_decode(\str_replace("\r\n", '', $body), true));
});

$t->test('a leading dot is stuffed so it cannot end DATA', function ($t): void {
    $t->same("..hidden\r\n..also\r\nfine\r\n",
        SmtpMailer::stuffDots(".hidden\r\n.also\r\nfine\r\n"));
});

$t->group('The SMTP driver, against a socket');

if (!FakeSmtpServer::available()) {
    $t->test('SKIPPED: pcntl is not available in this PHP', function ($t): void {
        $t->ok(true);
    });
} else {
    $t->test('a message is delivered, and the multi-line EHLO is read whole',
        function ($t): void {
        $server = new FakeSmtpServer();
        $server->start();

        $mailer = new SmtpMailer('127.0.0.1', $server->port, 'none', 'carl', 'secret',
            'carl@example.test', 'Carl', null, 5);
        $mailer->send(new Message('gardener@example.test', 'A Gardener',
            'Carl: 2 items for today', "Water bed 1.\nHarden off the peppers.\n"));

        $sent = $server->transcript();
        $server->stop();

        $t->contains('EHLO example.test', $sent);
        $t->contains('AUTH LOGIN', $sent);
        $t->contains('MAIL FROM:<carl@example.test>', $sent);
        $t->contains('RCPT TO:<gardener@example.test>', $sent);
        $t->contains('Subject: Carl: 2 items for today', $sent);
        $t->contains("\r\n.\r\n", $sent, 'DATA was terminated');

        // Had the driver stopped at the first line of the multi-line 250,
        // every later reply would have been read one behind and MAIL FROM
        // would have failed. Reaching QUIT is the proof it did not.
        $t->contains('QUIT', $sent);
    });

    $t->test('a 5xx refusal is permanent; a 4xx is worth retrying', function ($t): void {
        $refuse = new FakeSmtpServer(['RCPT' => "550 5.1.1 No such user here\r\n"]);
        $refuse->start();
        $mailer = new SmtpMailer('127.0.0.1', $refuse->port, 'none', 'carl', 'secret',
            'carl@example.test', 'Carl', null, 5);
        $e = $t->throws(MailException::class, static fn () => $mailer->send(
            new Message('nobody@example.test', null, 'S', 'b')
        ));
        $refuse->stop();
        $t->ok($e instanceof MailException && $e->permanent, '550 must not be retried forever');
        $t->contains('No such user', $e->getMessage());

        $defer = new FakeSmtpServer(['RCPT' => "451 4.3.0 Try again later\r\n"]);
        $defer->start();
        $mailer2 = new SmtpMailer('127.0.0.1', $defer->port, 'none', 'carl', 'secret',
            'carl@example.test', 'Carl', null, 5);
        $e2 = $t->throws(MailException::class, static fn () => $mailer2->send(
            new Message('someone@example.test', null, 'S', 'b')
        ));
        $defer->stop();
        $t->ok($e2 instanceof MailException && !$e2->permanent, '451 is exactly what backoff is for');
    });

    $t->test('a refused login is permanent, not retried five times', function ($t): void {
        $server = new FakeSmtpServer(['__AUTHLINE2__' => "535 5.7.8 Authentication failed\r\n"]);
        $server->start();
        $mailer = new SmtpMailer('127.0.0.1', $server->port, 'none', 'carl', 'wrong',
            'carl@example.test', 'Carl', null, 5);
        $e = $t->throws(MailException::class, static fn () => $mailer->send(
            new Message('a@example.test', null, 'S', 'b')
        ));
        $server->stop();
        $t->ok($e instanceof MailException && $e->permanent);
        $t->contains('authentication was refused', \strtolower($e->getMessage()));
    });

    $t->test('a server that is not there is a retryable failure, not a crash',
        function ($t): void {
        // Port 1 on loopback: nothing listens, and the refusal is immediate.
        $mailer = new SmtpMailer('127.0.0.1', 1, 'none', 'carl', 'secret',
            'carl@example.test', 'Carl', null, 2);
        $e = $t->throws(MailException::class, static fn () => $mailer->send(
            new Message('a@example.test', null, 'S', 'b')
        ));
        $t->ok($e instanceof MailException && !$e->permanent);
        $t->contains('cannot reach', $e->getMessage());
    });
}

$t->group('The API driver');

$t->test('a blank key is a permanent failure with a clear reason', function ($t): void {
    $mailer = new ApiMailer(new HttpClient('CarlTests/1.0', 2),
        'https://api.invalid/v3/smtp/email', '', 'carl@example.test', 'Carl');
    $e = $t->throws(MailException::class, static fn () => $mailer->send(
        new Message('a@example.test', null, 'S', 'b')
    ));
    $t->ok($e instanceof MailException && $e->permanent);
    $t->contains('mail.api.key', $e->getMessage());
});

$t->test('it says what it is pointed at without saying the key', function ($t): void {
    $mailer = new ApiMailer(new HttpClient('CarlTests/1.0', 2),
        'https://api.brevo.com/v3/smtp/email', 'super-secret-key', 'carl@example.test', 'Carl');
    $described = $mailer->describe();
    $t->contains('api.brevo.com', $described);
    $t->notContains('super-secret-key', $described);
});

$t->group('The outbox');

$t->test('queueing writes a row and sends nothing', function ($t) use ($app, $db, $suffix): void {
    $outbox = $app->outbox();
    $before = (int) $db->value('SELECT COUNT(*) FROM `email_outbox`', [], 0);

    $id = $outbox->queue(null, Outbox::KIND_TEST, 'queued' . $suffix . '@example.test', 'Tester',
        'Carl: a subject', "a body\n");
    $t->ok($id > 0);

    $t->same($before + 1, (int) $db->value('SELECT COUNT(*) FROM `email_outbox`', [], 0));
    $row = $outbox->find($id);
    $t->same('queued', $row['status']);
    $t->same(0, (int) $row['attempts']);
    $t->same(null, $row['sent_at']);
});

$t->test('the dedupe key is enforced by the database, not by reading first',
    function ($t) use ($app, $suffix): void {
    $outbox = $app->outbox();
    $key = 'digest:' . $suffix . ':2026-08-31';

    $first = $outbox->queue(null, Outbox::KIND_DIGEST, 'dedupe' . $suffix . '@example.test',
        null, 'Carl: 3 items for today', 'body', null, [], $key);
    $second = $outbox->queue(null, Outbox::KIND_DIGEST, 'dedupe' . $suffix . '@example.test',
        null, 'Carl: 3 items for today', 'body', null, [], $key);

    $t->ok($first > 0);
    $t->same(0, $second, 'the second is a no-op, reported as id 0');
});

$t->test('a bad address is refused at the queue, not five attempts later',
    function ($t) use ($app): void {
    $t->throws(MailException::class,
        static fn () => $app->outbox()->queue(null, Outbox::KIND_TEST, 'nonsense', null, 'S', 'b'));
});

$t->test('headers survive the round trip through the row', function ($t) use ($app, $suffix): void {
    $outbox = $app->outbox();
    $id = $outbox->queue(null, Outbox::KIND_DIGEST, 'headers' . $suffix . '@example.test', null,
        'S', 'b', null, [
            'List-Unsubscribe' => '<https://example.test/u/abc>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    $row = $outbox->find($id);
    $decoded = \json_decode((string) $row['headers'], true);
    $t->same('<https://example.test/u/abc>', $decoded['List-Unsubscribe']);
    $t->same('List-Unsubscribe=One-Click', $decoded['List-Unsubscribe-Post']);
});

$t->test('with no driver, a drain leaves everything queued and says so',
    function ($t) use ($app): void {
    $summary = $app->outbox()->drain();
    $t->same('none', $summary['driver']);
    $t->same('skipped', $summary['outcome']);
    $t->same(0, $summary['sent']);
    $t->same(0, $summary['failed']);
    $t->ok($app->outbox()->queuedCount() > 0, 'the messages are still there, waiting');

    // A run row is written even when there was nothing to do: a cron that
    // silently stops is otherwise invisible for months.
    $run = $app->outbox()->lastRun();
    $t->ok($run !== null);
    $t->same('skipped', $run['outcome']);
});

if (FakeSmtpServer::available()) {
    $t->test('with a driver, the drain sends and marks the row',
        function ($t) use ($app, $suffix): void {
        $server = new FakeSmtpServer();
        $server->start();

        // A second App over the same database, configured to send.
        $configured = Config::fromArray(\array_replace_recursive(
            (array) require $app->root() . '/config/app.php',
            [
                'db' => [
                    'host' => $app->config()->string('db.host'),
                    'port' => $app->config()->int('db.port', 3306),
                    'name' => $app->config()->string('db.name'),
                    'user' => $app->config()->string('db.user'),
                    'pass' => $app->config()->string('db.pass'),
                    'allow_local' => true,
                ],
                'mail' => [
                    'driver' => 'smtp',
                    'smtp' => [
                        'host' => '127.0.0.1', 'port' => $server->port,
                        'encryption' => 'none', 'username' => 'carl',
                        'password' => 'secret', 'timeout' => 5,
                    ],
                ],
            ]
        ));
        $sender = new Carl\Core\App($configured, $app->root());

        $address = 'drained' . $suffix . '@example.test';
        $id = $sender->outbox()->queue(null, Outbox::KIND_TEST, $address, 'Tester',
            'Carl: drained', "one item\n");

        $summary = $sender->outbox()->drain(50);
        $server->stop();

        $t->same('smtp', $summary['driver']);
        $t->ok($summary['sent'] >= 1, 'at least the message just queued went out');

        $row = $sender->outbox()->find($id);
        $t->same('sent', $row['status']);
        $t->same('smtp', $row['driver']);
        $t->ok($row['sent_at'] !== null);
        $t->same(null, $row['last_error']);
    });

    $t->test('a refused message is retried, then failed, and never silently dropped',
        function ($t) use ($app, $suffix): void {
        $configured = Config::fromArray(\array_replace_recursive(
            (array) require $app->root() . '/config/app.php',
            [
                'db' => [
                    'host' => $app->config()->string('db.host'),
                    'port' => $app->config()->int('db.port', 3306),
                    'name' => $app->config()->string('db.name'),
                    'user' => $app->config()->string('db.user'),
                    'pass' => $app->config()->string('db.pass'),
                    'allow_local' => true,
                ],
                'mail' => [
                    'driver' => 'smtp',
                    'max_attempts' => 2,
                    'retry_minutes' => [0],
                    'smtp' => [
                        // Nothing listens here, so every attempt fails the
                        // same retryable way.
                        'host' => '127.0.0.1', 'port' => 1,
                        'encryption' => 'none', 'username' => 'carl',
                        'password' => 'secret', 'timeout' => 2,
                    ],
                ],
            ]
        ));
        $sender = new Carl\Core\App($configured, $app->root());

        $id = $sender->outbox()->queue(null, Outbox::KIND_TEST,
            'refused' . $suffix . '@example.test', null, 'Carl: refused', "body\n");

        $sender->outbox()->drain(50);
        $row = $sender->outbox()->find($id);
        $t->same('queued', $row['status'], 'first failure schedules a retry');
        $t->same(1, (int) $row['attempts']);
        $t->contains('cannot reach', (string) $row['last_error']);

        $sender->outbox()->drain(50);
        $row = $sender->outbox()->find($id);
        $t->same('failed', $row['status'], 'the budget is spent, and the row says why');
        $t->same(2, (int) $row['attempts']);
        $t->ok($row['last_error'] !== null);
    });
}
