<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;

/**
 * The browsers that asked to be told (Phase 16; migration 027).
 *
 * A subscription is a thing that quietly stops existing -- a reinstalled
 * app, a cleared site, a phone replaced -- so a row is never trusted to be
 * live because it exists: the push service says 404 or 410 and the row is
 * marked, and the next timer falls back to email for that account.
 */
final class PushSubscriptionRepository extends Repository
{
    protected function table(): string
    {
        return 'push_subscription';
    }

    protected function writable(): array
    {
        return ['endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent'];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;
    }

    /**
     * Save a subscription, or -- the same endpoint again -- refresh it. The
     * same phone subscribing twice is one row; a phone that changed hands
     * moves to the account that is signed in on it now, which is the person
     * who will be holding it when it buzzes.
     */
    public function save(string $endpoint, string $p256dh, string $auth, string $userAgent): int
    {
        $this->db->run(
            'INSERT INTO `push_subscription`'
            . ' (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at, last_used_at)'
            . ' VALUES (:user_id, :endpoint, :hash, :p256dh, :auth, :ua, UTC_TIMESTAMP(), NULL)'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `user_id` = VALUES(`user_id`),'
            . ' `p256dh` = VALUES(`p256dh`), `auth` = VALUES(`auth`), `user_agent` = VALUES(`user_agent`),'
            . ' `failed_at` = NULL, `fail_reason` = NULL',
            [
                'user_id'  => $this->userId,
                'endpoint' => \substr($endpoint, 0, 1000),
                'hash'     => \hash('sha256', $endpoint),
                'p256dh'   => \substr($p256dh, 0, 120),
                'auth'     => \substr($auth, 0, 40),
                'ua'       => \substr($userAgent, 0, 190),
            ]
        );
        return $this->db->insertId();
    }

    /** The subscriptions a push can still reach. @return list<array<string,mixed>> */
    public function live(): array
    {
        return $this->where('`failed_at` IS NULL', [], '`id`');
    }

    /** Everything, live or not, for the page that says what is listening. @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->where('', [], '`failed_at` IS NOT NULL, `id` DESC');
    }

    /** The browser asked to stop. */
    public function remove(string $endpoint): bool
    {
        return $this->db->run(
            'DELETE FROM `push_subscription` WHERE ' . $this->scoped('`endpoint_hash` = :hash'),
            $this->bind(['hash' => \hash('sha256', $endpoint)])
        )->rowCount() === 1;
    }

    public function markFailed(int $id, string $reason): void
    {
        $this->db->run(
            'UPDATE `push_subscription` SET `failed_at` = UTC_TIMESTAMP(), `fail_reason` = :reason'
            . ' WHERE ' . $this->scoped('`id` = :id'),
            $this->bind(['id' => $id, 'reason' => \substr($reason, 0, 200)])
        );
    }

    public function touch(int $id): void
    {
        $this->db->run(
            'UPDATE `push_subscription` SET `last_used_at` = UTC_TIMESTAMP() WHERE ' . $this->scoped('`id` = :id'),
            $this->bind(['id' => $id])
        );
    }

    /** @return array{live:int,failed:int} for /status */
    public static function health(Database $db): array
    {
        $row = $db->one(
            'SELECT SUM(`failed_at` IS NULL) AS live, SUM(`failed_at` IS NOT NULL) AS failed FROM `push_subscription`'
        );
        return ['live' => (int) ($row['live'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }
}
