<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;

/**
 * Watering timers (Phase 16; migration 027).
 *
 * The user-scoped half is what the pages use: start, list, cancel, show.
 * The cron's half is static and global by necessity -- it fires every
 * account's due timers in one pass -- and takes the ids it needs
 * explicitly, the way WateringRepository::forUsersOnDate() does, rather
 * than pretending to be scoped.
 */
final class TimerRepository extends Repository
{
    protected function table(): string
    {
        return 'water_timer';
    }

    protected function writable(): array
    {
        return ['garden_id', 'water_zone_id', 'minutes', 'started_at', 'ends_at', 'log_when_done'];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;
    }

    private const DETAIL = 'SELECT `water_timer`.*, g.name AS garden_name, z.name AS zone_name'
        . ' FROM `water_timer`'
        . ' LEFT JOIN `garden` g ON g.id = `water_timer`.`garden_id`'
        . ' LEFT JOIN `water_zone` z ON z.id = `water_timer`.`water_zone_id`';

    /**
     * Every timer still counting, soonest first, with its garden and zone
     * named. One statement for the menu and one for a garden page.
     *
     * @return list<array<string,mixed>>
     */
    public function running(?int $gardenId = null): array
    {
        $extra = '`water_timer`.`fired_at` IS NULL AND `water_timer`.`cancelled_at` IS NULL';
        $params = [];
        if ($gardenId !== null) {
            $extra .= ' AND `water_timer`.`garden_id` = :garden_id';
            $params['garden_id'] = $gardenId;
        }
        return $this->db->all(
            self::DETAIL . ' WHERE ' . $this->scoped($extra) . ' ORDER BY `water_timer`.`ends_at`',
            $this->bind($params)
        );
    }

    /** @return array<string,mixed>|null the timer with its names, this user's only */
    public function findDetailed(int $id): ?array
    {
        return $this->db->one(
            self::DETAIL . ' WHERE ' . $this->scoped('`water_timer`.`id` = :id'),
            $this->bind(['id' => $id])
        );
    }

    /**
     * The most recent finished timers, for the garden actions page: the one
     * that fired while the phone was in a pocket is the one to log.
     *
     * @return list<array<string,mixed>>
     */
    public function recentlyFinished(int $gardenId, int $limit = 3): array
    {
        return $this->db->all(
            self::DETAIL . ' WHERE ' . $this->scoped('`water_timer`.`garden_id` = :garden_id'
                . ' AND `water_timer`.`fired_at` IS NOT NULL')
            . ' ORDER BY `water_timer`.`fired_at` DESC LIMIT ' . (int) $limit,
            $this->bind(['garden_id' => $gardenId])
        );
    }

    /** Stop a timer that has not fired. False when it already had, or was not this user's. */
    public function cancel(int $id): bool
    {
        return $this->db->run(
            'UPDATE `water_timer` SET `cancelled_at` = UTC_TIMESTAMP()'
            . ' WHERE ' . $this->scoped('`id` = :id AND `fired_at` IS NULL AND `cancelled_at` IS NULL'),
            $this->bind(['id' => $id])
        )->rowCount() === 1;
    }

    /** The watering was logged after the fact, from the landing page. */
    public function markLogged(int $id, int $eventId): bool
    {
        return $this->db->run(
            'UPDATE `water_timer` SET `logged_event_id` = :event_id'
            . ' WHERE ' . $this->scoped('`id` = :id AND `logged_event_id` IS NULL'),
            $this->bind(['id' => $id, 'event_id' => $eventId])
        )->rowCount() === 1;
    }

    // -- The cron's half: every account, one pass -----------------------------

    /**
     * What is due at $nowUtc: unfired, uncancelled, past its end. The clock
     * is the application's, not the database's, so a frozen clock in the
     * suite fires exactly the timers it means to.
     *
     * @return list<array<string,mixed>>
     */
    public static function due(Database $db, string $nowUtc, int $limit = 50): array
    {
        return $db->all(
            'SELECT t.*, g.name AS garden_name, z.name AS zone_name, z.water_method_id AS zone_method_id,'
            . ' u.timezone, u.email, u.name AS user_name'
            . ' FROM `water_timer` t'
            . ' JOIN `user` u ON u.id = t.user_id'
            . ' LEFT JOIN `garden` g ON g.id = t.garden_id'
            . ' LEFT JOIN `water_zone` z ON z.id = t.water_zone_id'
            . ' WHERE t.fired_at IS NULL AND t.cancelled_at IS NULL AND t.ends_at <= :now'
            . ' ORDER BY t.ends_at LIMIT ' . (int) $limit,
            ['now' => $nowUtc]
        );
    }

    /**
     * Claim a timer: a compare-and-swap on fired_at, so two runs that both
     * read it as due fire it once (hosting Section 7 -- the database decides,
     * not a check that was true a millisecond ago).
     */
    public static function claim(Database $db, int $id, string $nowUtc): bool
    {
        return $db->run(
            'UPDATE `water_timer` SET `fired_at` = :now WHERE `id` = :id AND `fired_at` IS NULL',
            ['now' => $nowUtc, 'id' => $id]
        )->rowCount() === 1;
    }

    public static function finish(Database $db, int $id, string $via, ?int $eventId, ?string $error): void
    {
        $db->run(
            'UPDATE `water_timer` SET `notified_via` = :via, `logged_event_id` = :event_id, `fire_error` = :error'
            . ' WHERE `id` = :id',
            ['via' => \substr($via, 0, 16), 'event_id' => $eventId,
             'error' => $error === null ? null : \substr($error, 0, 500), 'id' => $id]
        );
    }

    /**
     * For /status: what is counting, what is late, and when the cron last
     * fired anything -- the one line that says whether the per-minute entry
     * is in place.
     *
     * @return array{running:int,overdue:int,last_fired:?string,fired_total:int}
     */
    public static function health(Database $db): array
    {
        $row = $db->one(
            'SELECT SUM(fired_at IS NULL AND cancelled_at IS NULL) AS running,'
            . ' SUM(fired_at IS NULL AND cancelled_at IS NULL AND ends_at < (UTC_TIMESTAMP() - INTERVAL 3 MINUTE)) AS overdue,'
            . ' MAX(fired_at) AS last_fired, SUM(fired_at IS NOT NULL) AS fired_total'
            . ' FROM `water_timer`'
        );
        return [
            'running'     => (int) ($row['running'] ?? 0),
            'overdue'     => (int) ($row['overdue'] ?? 0),
            'last_fired'  => \is_string($row['last_fired'] ?? null) ? $row['last_fired'] : null,
            'fired_total' => (int) ($row['fired_total'] ?? 0),
        ];
    }
}
