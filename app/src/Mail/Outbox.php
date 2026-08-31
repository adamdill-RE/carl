<?php

declare(strict_types=1);

namespace Carl\Mail;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Core\HttpClient;
use Throwable;

/**
 * The mail queue and the job that drains it (handoff Section 5.8).
 *
 * Two rules, and they are the same two the weather sync follows:
 *
 *  1. **Nothing sends inline in a request.** A page calls queue() and
 *     returns. A third-party outage can then make the drain slow; it cannot
 *     make a page slow or 500 (Phase 3 handoff Section 4.1).
 *  2. **Every run writes a mail_send_run row**, success, failure or nothing
 *     to do -- because a cron that silently stops is otherwise invisible for
 *     months.
 *
 * With no driver configured -- the state until the owner completes Section
 * 12.1 -- messages queue and stay queued. They are not failed and not
 * dropped: the day the credentials land, the backlog goes out. That is why
 * queue() is safe to call now.
 */
final class Outbox
{
    public const KIND_DIGEST = 'digest';
    public const KIND_TEMPORARY_PASSWORD = 'temporary_password';
    public const KIND_TEST = 'test';

    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /**
     * Put one message in the queue.
     *
     * $dedupeKey is enforced by a unique index rather than by reading first
     * (hosting Section 7): two hourly digest runs racing on the same user
     * produce one row, and the loser learns that from the duplicate-key
     * error rather than from a check that was true a millisecond ago.
     *
     * @param array<string,string> $headers
     * @return int the row id, or 0 when an identical message was already queued
     */
    public function queue(
        ?int $userId,
        string $kind,
        string $toEmail,
        ?string $toName,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        array $headers = [],
        ?string $dedupeKey = null,
    ): int {
        if (!Message::isEmail($toEmail)) {
            throw MailException::permanent('Not a usable email address: ' . $toEmail);
        }

        $now = $this->app->clock()->utcStamp();

        try {
            $this->db->run(
                'INSERT INTO `email_outbox`'
                . ' (user_id, kind, to_email, to_name, subject, body_text, body_html, headers,'
                . '  dedupe_key, status, attempts, next_attempt_at, created_at)'
                . " VALUES (:user_id, :kind, :to_email, :to_name, :subject, :body_text, :body_html,"
                . "  :headers, :dedupe_key, 'queued', 0, :next_attempt_at, :created_at)",
                [
                    'user_id'         => $userId,
                    'kind'            => \substr($kind, 0, 32),
                    'to_email'        => \substr($toEmail, 0, 190),
                    'to_name'         => $toName === null ? null : \substr($toName, 0, 120),
                    'subject'         => \substr($subject, 0, 255),
                    'body_text'       => $bodyText,
                    'body_html'       => $bodyHtml,
                    'headers'         => $headers === []
                        ? null
                        : \json_encode($headers, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                    'dedupe_key'      => $dedupeKey === null ? null : \substr($dedupeKey, 0, 120),
                    'next_attempt_at' => $now,
                    'created_at'      => $now,
                ]
            );
        } catch (\PDOException $e) {
            // 23000 is the integrity-constraint class; on this statement the
            // only unique index is the dedupe key, so it means "already
            // queued", which is the outcome the caller wanted.
            if ($e->getCode() === '23000') {
                return 0;
            }
            throw $e;
        }

        return $this->db->insertId();
    }

    /**
     * Send what is due, oldest first.
     *
     * @return array{driver:string,considered:int,sent:int,failed:int,outcome:string,log:list<string>}
     */
    public function drain(?int $limit = null): array
    {
        $startedAt = $this->app->clock()->utcStamp();
        $limit ??= $this->app->config()->int('mail.batch', 25);
        $log = [];

        $mailer = $this->driver();
        if ($mailer === null) {
            // Not a failure. The messages wait for the mailbox, which is
            // exactly what should happen before Section 12.1 is done.
            $waiting = $this->queuedCount();
            $log[] = $waiting === 0
                ? 'no mail driver configured, and nothing waiting'
                : 'no mail driver configured; ' . $waiting . ' message'
                  . ($waiting === 1 ? '' : 's') . ' waiting for one';
            $this->recordRun($startedAt, 'none', 0, 0, 0, 'skipped',
                'mail.driver is "none" -- see handoff Section 12.1');
            return ['driver' => 'none', 'considered' => 0, 'sent' => 0, 'failed' => 0,
                    'outcome' => 'skipped', 'log' => $log];
        }

        $rows = $this->due($limit);
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            try {
                $mailer->send($this->toMessage($row));
                $this->markSent($id, $mailer->name());
                $sent++;
                $log[] = '#' . $id . ' ' . $row['kind'] . ' -> ' . $row['to_email'] . ': sent';
            } catch (Throwable $e) {
                $permanent = $e instanceof MailException && $e->permanent;
                $attempts = (int) $row['attempts'] + 1;
                $exhausted = $permanent || $attempts >= $this->app->config()->int('mail.max_attempts', 5);
                $this->markAttempt($id, $attempts, $e->getMessage(), $exhausted);
                $failed++;
                $log[] = '#' . $id . ' ' . $row['kind'] . ' -> ' . $row['to_email'] . ': '
                    . ($exhausted ? 'FAILED' : 'retry ' . $attempts) . ' -- ' . $e->getMessage();
            }
        }

        $this->prune();

        $outcome = match (true) {
            $failed === 0 => 'ok',
            $sent > 0     => 'partial',
            default       => 'failed',
        };

        $this->recordRun($startedAt, $mailer->name(), \count($rows), $sent, $failed, $outcome);

        return ['driver' => $mailer->name(), 'considered' => \count($rows), 'sent' => $sent,
                'failed' => $failed, 'outcome' => $outcome, 'log' => $log];
    }

    /**
     * The configured driver, or null when there is none.
     *
     * A blank credential is the same as no driver: it is the state the
     * install is in before the owner completes Section 12.1, and treating it
     * as a misconfiguration would fail every queued message five times over.
     */
    public function driver(): ?Mailer
    {
        $config = $this->app->config();
        $fromEmail = $config->string('mail.from_email');
        $fromName = $config->string('mail.from_name', 'Carl');
        $replyTo = $config->get('mail.reply_to');
        $replyTo = \is_string($replyTo) && $replyTo !== '' ? $replyTo : null;

        return match ($config->string('mail.driver', 'none')) {
            'smtp' => $config->string('mail.smtp.password') === '' ? null : new SmtpMailer(
                $config->string('mail.smtp.host'),
                $config->int('mail.smtp.port', 465),
                $config->string('mail.smtp.encryption', 'tls'),
                $config->string('mail.smtp.username'),
                $config->string('mail.smtp.password'),
                $fromEmail,
                $fromName,
                $replyTo,
                $config->int('mail.smtp.timeout', 20),
            ),
            'api' => $config->string('mail.api.key') === '' ? null : new ApiMailer(
                new HttpClient(
                    $config->string('weather.user_agent'),
                    $config->int('weather.http_timeout', 20),
                ),
                $config->string('mail.api.url'),
                $config->string('mail.api.key'),
                $fromEmail,
                $fromName,
                $replyTo,
            ),
            default => null,
        };
    }

    /** One line for /status and for the mail-test page. Never a secret. */
    public function describeDriver(): string
    {
        $configured = $this->app->config()->string('mail.driver', 'none');
        $driver = $this->driver();
        if ($driver === null) {
            return $configured === 'none'
                ? 'none -- mail queues and waits (handoff Section 12.1)'
                : $configured . ' selected, but its credentials are blank in config/local.php';
        }
        return $driver->describe();
    }

    // -- Rows --------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function due(int $limit): array
    {
        return $this->db->all(
            "SELECT * FROM `email_outbox` WHERE `status` = 'queued'"
            . ' AND `next_attempt_at` <= UTC_TIMESTAMP()'
            . ' ORDER BY `id` LIMIT ' . (int) $limit
        );
    }

    public function queuedCount(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM `email_outbox` WHERE `status` = 'queued'", [], 0
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM `email_outbox` WHERE `id` = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> the most recent rows, for a status page */
    public function recent(int $limit = 10): array
    {
        return $this->db->all(
            'SELECT `id`, `kind`, `to_email`, `subject`, `status`, `attempts`, `driver`,'
            . ' `last_error`, `created_at`, `sent_at`'
            . ' FROM `email_outbox` ORDER BY `id` DESC LIMIT ' . (int) $limit
        );
    }

    /** @return array{queued:int,sent:int,failed:int,oldest_queued:?string} */
    public function health(): array
    {
        $row = $this->db->one(
            "SELECT SUM(`status` = 'queued') AS queued, SUM(`status` = 'sent') AS sent,"
            . " SUM(`status` = 'failed') AS failed,"
            . " MIN(CASE WHEN `status` = 'queued' THEN `created_at` END) AS oldest_queued"
            . ' FROM `email_outbox`'
        );
        return [
            'queued'        => (int) ($row['queued'] ?? 0),
            'sent'          => (int) ($row['sent'] ?? 0),
            'failed'        => (int) ($row['failed'] ?? 0),
            'oldest_queued' => \is_string($row['oldest_queued'] ?? null) ? $row['oldest_queued'] : null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function toMessage(array $row): Message
    {
        $headers = [];
        if (\is_string($row['headers'] ?? null) && $row['headers'] !== '') {
            $decoded = \json_decode((string) $row['headers'], true);
            if (\is_array($decoded)) {
                foreach ($decoded as $name => $value) {
                    $headers[(string) $name] = (string) $value;
                }
            }
        }

        return new Message(
            (string) $row['to_email'],
            $row['to_name'] === null ? null : (string) $row['to_name'],
            (string) $row['subject'],
            (string) $row['body_text'],
            $row['body_html'] === null ? null : (string) $row['body_html'],
            $headers,
        );
    }

    private function markSent(int $id, string $driver): void
    {
        $this->db->run(
            "UPDATE `email_outbox` SET `status` = 'sent', `attempts` = `attempts` + 1,"
            . ' `driver` = :driver, `last_error` = NULL, `sent_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            ['driver' => $driver, 'id' => $id]
        );
    }

    private function markAttempt(int $id, int $attempts, string $error, bool $exhausted): void
    {
        $this->db->run(
            'UPDATE `email_outbox` SET `status` = :status, `attempts` = :attempts,'
            . ' `last_error` = :error, `next_attempt_at` = :next WHERE `id` = :id',
            [
                'status'   => $exhausted ? 'failed' : 'queued',
                'attempts' => $attempts,
                'error'    => \substr($error, 0, 500),
                'next'     => $this->nextAttemptAt($attempts),
                'id'       => $id,
            ]
        );
    }

    /**
     * Backoff from config, holding at the last step once it runs out.
     *
     * A configured 0 means "immediately", which only a test asks for; the
     * shipped steps start at two minutes.
     */
    private function nextAttemptAt(int $attempts): string
    {
        $steps = $this->app->config()->get('mail.retry_minutes');
        $steps = \is_array($steps) && $steps !== [] ? \array_values($steps) : [2, 10, 30, 120];
        $minutes = (int) ($steps[\min($attempts - 1, \count($steps) - 1)] ?? 120);

        return $this->app->clock()->nowUtc()
            ->modify('+' . \max(0, $minutes) . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Sent rows are pruned; failed ones stay, because a failure nobody ever
     * sees is the thing the run table exists to prevent.
     */
    public function prune(): int
    {
        $days = $this->app->config()->int('mail.retention_days', 30);
        $removed = $this->db->run(
            "DELETE FROM `email_outbox` WHERE `status` = 'sent'"
            . ' AND `sent_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $days]
        )->rowCount();

        $this->db->run(
            'DELETE FROM `mail_send_run` WHERE `started_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $this->app->config()->int('weather.run_retention_days', 90)]
        );

        return $removed;
    }

    private function recordRun(
        string $startedAt,
        string $driver,
        int $considered,
        int $sent,
        int $failed,
        string $outcome,
        ?string $error = null,
    ): void {
        $this->db->run(
            'INSERT INTO `mail_send_run`'
            . ' (started_at, finished_at, driver, considered, sent, failed, outcome, error_text)'
            . ' VALUES (:started_at, UTC_TIMESTAMP(), :driver, :considered, :sent, :failed,'
            . '  :outcome, :error)',
            [
                'started_at' => $startedAt,
                'driver'     => $driver,
                'considered' => $considered,
                'sent'       => $sent,
                'failed'     => $failed,
                'outcome'    => $outcome,
                'error'      => $error,
            ]
        );
    }

    /** @return array<string,mixed>|null the last drain, for /status */
    public function lastRun(): ?array
    {
        return $this->db->one('SELECT * FROM `mail_send_run` ORDER BY `id` DESC LIMIT 1');
    }
}
