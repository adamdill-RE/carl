<?php

declare(strict_types=1);

namespace Carl\Reminders;

use Carl\Core\App;
use Carl\Core\Database;
use Carl\Domain\ReminderKind;
use Carl\Mail\Outbox;
use Carl\Support\Clock;
use Throwable;

/**
 * The daily digest (handoff Section 12).
 *
 * **Hourly, per-user local time.** The job runs every hour and sends to each
 * user whose OWN local time is between 06:00 and 07:00, computed from
 * `user.timezone` through Clock::localHour(). The server clock never enters
 * into it -- which matters here more than anywhere else in the codebase,
 * because the cron clock on this host is US Eastern while PHP is pinned to
 * UTC, and neither is any user's morning (Phase 3 handoff Section 1.1).
 *
 * **Silence is the default.** Reminders are computed and stored whether or
 * not anything is worth sending; an email is queued only when there is
 * something to say. An empty digest trains people to ignore a full one.
 *
 * **It queues; it does not send.** The mail drain sends (handoff Section
 * 5.8), so a mail server being slow cannot make this job slow, and a job
 * that fails halfway has still stored the reminders the menu will show.
 */
final class Digest
{
    /** The hour, in the user's own timezone, that counts as morning. */
    public const SEND_HOUR = 6;

    private Database $db;

    /** @var list<string> */
    private array $log = [];

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @param bool $force ignore the hour and the once-a-day rule; for the
     *        browser fallback and for tests, never for the cron
     * @return array{due:int,reminders:int,queued:int,silent:int,failures:int,log:list<string>}
     */
    public function run(?int $onlyUserId = null, bool $force = false): array
    {
        $startedAt = $this->app->clock()->utcStamp();

        $users = $this->candidates($onlyUserId);
        $due = [];
        $todayByUser = [];

        foreach ($users as $user) {
            $timezone = (string) ($user['timezone'] ?? 'UTC');
            $today = $this->app->clock()->todayFor($timezone);
            $todayByUser[(int) $user['id']] = $today;

            if (!$force && $this->app->clock()->localHour($timezone) !== self::SEND_HOUR) {
                continue;
            }
            $due[] = $user;
        }

        if ($due === []) {
            $this->recordRun($startedAt, 0, 0, 0, 0, 'ok');
            $this->note('nobody is at ' . self::SEND_HOUR . ':00 local right now');
            return ['due' => 0, 'reminders' => 0, 'queued' => 0, 'silent' => 0,
                    'failures' => 0, 'log' => $this->log];
        }

        $built = (new ReminderBuilder($this->db))->build($due, $todayByUser);

        $stored = 0;
        $queued = 0;
        $silent = 0;
        $failures = 0;

        foreach ($due as $user) {
            $userId = (int) $user['id'];
            $today = $todayByUser[$userId];

            try {
                $stored += $this->store($userId, $built[$userId] ?? []);

                // Read back rather than sending what was just built: a
                // reminder that already existed and was already sent must not
                // go out twice, and the database is the only thing that knows.
                $unsent = $this->unsentFor($userId, $today);

                if ($unsent === []) {
                    $silent++;
                    $this->note('user ' . $userId . ': nothing to say');
                    continue;
                }

                if ($this->queueEmail($user, $today, $unsent)) {
                    $queued++;
                }
                $this->markSent($unsent);
                $this->note('user ' . $userId . ': ' . \count($unsent) . ' items');
            } catch (Throwable $e) {
                // One user's failure must not stop the rest; tomorrow's run
                // recomputes from the same data.
                $failures++;
                $this->note('user ' . $userId . ' failed: ' . $e->getMessage());
            }
        }

        $this->recordRun($startedAt, \count($due), $stored, $queued, $silent,
            $failures === 0 ? 'ok' : ($queued > 0 ? 'partial' : 'failed'));

        return ['due' => \count($due), 'reminders' => $stored, 'queued' => $queued,
                'silent' => $silent, 'failures' => $failures, 'log' => $this->log];
    }

    /**
     * Every account that could receive a digest.
     *
     * Onboarding is the line: a user with no timezone has no local morning to
     * be at, and a user with no email has nowhere to send it. The opt-out is
     * `email_digest_enabled`, which the unsubscribe route clears.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(?int $onlyUserId): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.email, u.name, u.timezone, u.region_id,'
            . ' u.weather_location_id, u.email_unsubscribe_token, r.research_status'
            . ' FROM `user` u LEFT JOIN `region` r ON r.id = u.region_id'
            . ' WHERE u.email_digest_enabled = 1 AND u.timezone IS NOT NULL'
            . "   AND u.email <> '' AND u.onboarded_at IS NOT NULL"
            . ($onlyUserId !== null ? ' AND u.id = :id' : ''),
            $onlyUserId !== null ? ['id' => $onlyUserId] : []
        );
    }

    /**
     * Store the computed reminders, letting the unique key deduplicate.
     *
     * Deduplicating in the database rather than by reading first (hosting
     * Section 7): two runs racing on the same user produce one row, and the
     * loser learns that from the key rather than from a check that was true a
     * millisecond ago.
     *
     * @param list<array<string,mixed>> $reminders
     * @return int rows considered
     */
    private function store(int $userId, array $reminders): int
    {
        if ($reminders === []) {
            return 0;
        }

        $now = $this->app->clock()->utcStamp();
        $rows = [];
        foreach ($reminders as $reminder) {
            $rows[] = [
                $userId,
                $reminder['planting_id'],
                $reminder['subject_key'],
                $reminder['kind'],
                $reminder['due_date'],
                \substr($reminder['title'], 0, 190),
                \substr($reminder['body'], 0, 700),
                ReminderKind::priority($reminder['kind']),
                $now,
            ];
        }

        foreach (\array_chunk($rows, 200) as $chunk) {
            // On a duplicate, refresh the text and leave sent_at and
            // dismissed_at alone: a reminder already sent this morning stays
            // sent, and one the user dismissed stays dismissed.
            $this->db->upsertChunk(
                'reminder',
                ['user_id', 'planting_id', 'subject_key', 'kind', 'due_date', 'title', 'body',
                 'priority', 'created_at'],
                $chunk,
                ['title', 'body', 'priority']
            );
        }

        return \count($rows);
    }

    /**
     * Today's reminders that have not been sent and have not been dismissed.
     *
     * @return list<array<string,mixed>>
     */
    private function unsentFor(int $userId, string $today): array
    {
        return $this->db->all(
            'SELECT * FROM `reminder`'
            . ' WHERE `user_id` = :user_id AND `due_date` = :due_date'
            . '   AND `sent_at` IS NULL AND `dismissed_at` IS NULL'
            . ' ORDER BY `priority`, `id`',
            ['user_id' => $userId, 'due_date' => $today]
        );
    }

    /** @param list<array<string,mixed>> $reminders */
    private function markSent(array $reminders): void
    {
        $ids = \array_map(static fn (array $r): int => (int) $r['id'], $reminders);
        if ($ids === []) {
            return;
        }
        $params = [];
        $names = [];
        foreach ($ids as $i => $id) {
            $names[] = ':r' . $i;
            $params['r' . $i] = $id;
        }
        $this->db->run(
            'UPDATE `reminder` SET `sent_at` = UTC_TIMESTAMP() WHERE `id` IN ('
            . \implode(', ', $names) . ')',
            $params
        );
    }

    /**
     * One email, queued (handoff Section 12): plain text first with a simple
     * HTML twin, List-Unsubscribe and its One-Click twin pointing at the
     * tokenised route, subject "Carl: N items for today".
     *
     * @param array<string,mixed> $user
     * @param list<array<string,mixed>> $reminders
     * @return bool whether a row was written -- false when today's digest was
     *         already queued, which the dedupe key decides
     */
    private function queueEmail(array $user, string $today, array $reminders): bool
    {
        $count = \count($reminders);
        $unsubscribe = 'https://www.reshiftmanager.com'
            . $this->app->url('unsubscribe/' . (string) $user['email_unsubscribe_token']);

        $appUrl = 'https://www.reshiftmanager.com' . $this->app->url('');
        // Section 9.4: a user whose county is not researched is told, once
        // per digest, which kinds are missing and why -- rather than being
        // left to wonder why frost never comes up.
        $researched = ($user['research_status'] ?? '') === 'researched';

        $body = DigestMessage::text($reminders, $today, (string) $user['name'],
            $appUrl, $unsubscribe, $researched);
        $html = DigestMessage::html($reminders, $today, (string) $user['name'],
            $appUrl, $unsubscribe, $researched);

        return $this->app->outbox()->queue(
            (int) $user['id'],
            Outbox::KIND_DIGEST,
            (string) $user['email'],
            (string) $user['name'],
            'Carl: ' . $count . ' item' . ($count === 1 ? '' : 's') . ' for today',
            $body,
            $html,
            [
                'List-Unsubscribe'      => '<' . $unsubscribe . '>',
                // One-Click: the mail client can unsubscribe with a POST and
                // no confirmation page, which is what Gmail and Outlook now
                // expect from bulk mail (RFC 8058).
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            // One digest per user per day, enforced by the database.
            'digest:' . (int) $user['id'] . ':' . $today,
        ) > 0;
    }

    /**
     * An alert that arrives after this morning's digest has gone (handoff
     * Section 8.4): a freeze warning issued at noon is no use tomorrow
     * morning.
     *
     * Called by the alerts poller with the ids it has just inserted.
     *
     * @param list<int> $newAlertIds
     * @return int emails queued
     */
    public function sendUrgentAlerts(array $newAlertIds): int
    {
        if ($newAlertIds === []) {
            return 0;
        }

        $params = [];
        $names = [];
        foreach (\array_values($newAlertIds) as $i => $id) {
            $names[] = ':a' . $i;
            $params['a' . $i] = $id;
        }

        $rows = $this->db->all(
            'SELECT a.*, u.id AS user_id, u.email, u.name, u.timezone,'
            . ' u.email_unsubscribe_token'
            . ' FROM `weather_alert` a'
            . ' JOIN `user` u ON u.weather_location_id = a.location_id'
            . ' WHERE a.id IN (' . \implode(', ', $names) . ') AND a.is_active = 1'
            . "   AND u.email_digest_enabled = 1 AND u.email <> ''"
            . '   AND u.onboarded_at IS NOT NULL',
            $params
        );

        $queued = 0;
        foreach ($rows as $row) {
            $event = (string) $row['event'];
            if (!\Carl\Weather\AlertPoller::isUrgentToAGarden($event)) {
                continue;
            }

            $userId = (int) $row['user_id'];
            $today = $this->app->clock()->todayFor((string) ($row['timezone'] ?? 'UTC'));

            // Only if this morning's digest has already gone. Otherwise the
            // alert rides along in it, and one email beats two.
            if (!$this->digestAlreadySent($userId, $today)) {
                continue;
            }

            $unsubscribe = 'https://www.reshiftmanager.com'
                . $this->app->url('unsubscribe/' . (string) $row['email_unsubscribe_token']);

            $text = \implode("\n", [
                $event . ' is in force for your area.',
                '',
                (string) ($row['headline'] ?? ''),
                $row['expires'] !== null ? 'In force until ' . $row['expires'] . ' UTC.' : '',
                '',
                'Cover what you can, and bring in what will not survive it.',
                '',
                'Unsubscribe: ' . $unsubscribe,
            ]);

            $written = $this->app->outbox()->queue(
                $userId,
                Outbox::KIND_DIGEST,
                (string) $row['email'],
                (string) $row['name'],
                'Carl: ' . $event,
                $text,
                null,
                [
                    'List-Unsubscribe'      => '<' . $unsubscribe . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
                // One message per alert per user, ever.
                'alert:' . $userId . ':' . (int) $row['id'],
            );

            if ($written > 0) {
                $queued++;
                $this->note('urgent alert queued for user ' . $userId . ': ' . $event);
            }
        }

        return $queued;
    }

    private function digestAlreadySent(int $userId, string $today): bool
    {
        return $this->db->value(
            'SELECT 1 FROM `email_outbox` WHERE `dedupe_key` = :key LIMIT 1',
            ['key' => 'digest:' . $userId . ':' . $today]
        ) !== null;
    }

    private function recordRun(
        string $startedAt,
        int $due,
        int $reminders,
        int $queued,
        int $silent,
        string $outcome,
    ): void {
        $this->db->run(
            'INSERT INTO `digest_run`'
            . ' (started_at, finished_at, users_due, reminders, emails_queued, silent, outcome)'
            . ' VALUES (:started_at, UTC_TIMESTAMP(), :due, :reminders, :queued, :silent, :outcome)',
            [
                'started_at' => $startedAt,
                'due'        => $due,
                'reminders'  => $reminders,
                'queued'     => $queued,
                'silent'     => $silent,
                'outcome'    => $outcome,
            ]
        );

        // An unpruned log table on an HOURLY job is a faster-growing bug than
        // on a nightly one.
        $this->db->run(
            'DELETE FROM `digest_run` WHERE `started_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $this->app->config()->int('weather.run_retention_days', 90)]
        );
    }

    /** @return array<string,mixed>|null the last run, for /status */
    public function lastRun(): ?array
    {
        return $this->db->one('SELECT * FROM `digest_run` ORDER BY `id` DESC LIMIT 1');
    }

    private function note(string $message): void
    {
        $this->log[] = $message;
    }
}
