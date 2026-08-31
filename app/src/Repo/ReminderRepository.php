<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Domain\ReminderKind;

/**
 * Reading reminders back (handoff Section 4.2: today's items on the main
 * menu, showing the same content as the daily email).
 *
 * Reading only, and no recomputation: the hourly digest job computes and
 * stores them, and the menu shows what is stored (Phase 3 handoff Section
 * 4.5). A menu that recomputed eleven rules over every planting would be the
 * slowest page in the application and would disagree with the email that
 * went out an hour earlier.
 */
final class ReminderRepository extends Repository
{
    protected function table(): string
    {
        return 'reminder';
    }

    protected function writable(): array
    {
        // Only dismissed_at is written from a request; the job writes the
        // rest, and the job is not a request.
        return ['dismissed_at'];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;
    }

    /**
     * Today's items, most urgent first, excluding anything dismissed.
     *
     * @return list<array<string,mixed>>
     */
    public function forDate(string $date, int $limit = 25): array
    {
        return $this->where(
            '`due_date` = :due_date AND `dismissed_at` IS NULL',
            ['due_date' => $date],
            '`priority`, `id`',
            $limit
        );
    }

    /** How many items today, for the menu's summary line. One statement. */
    public function countForDate(string $date): int
    {
        return $this->count('`due_date` = :due_date AND `dismissed_at` IS NULL',
            ['due_date' => $date]);
    }

    /**
     * Dismiss one reminder. It stays in the table -- it is a record of what
     * the model said and when -- but it stops being shown and will not be
     * re-sent.
     */
    public function dismiss(int $id): int
    {
        return $this->db->run(
            'UPDATE `reminder` SET `dismissed_at` = UTC_TIMESTAMP()'
            . ' WHERE ' . $this->scoped('`id` = :id AND `dismissed_at` IS NULL'),
            $this->bind(['id' => $id])
        )->rowCount();
    }

    /**
     * The kinds this user has been told about recently, for /status and for
     * anyone wondering whether the job is doing anything.
     *
     * @return array<string,int>
     */
    public function recentCountsByKind(int $days = 7): array
    {
        $rows = $this->db->all(
            'SELECT `kind`, COUNT(*) AS n FROM `reminder`'
            . ' WHERE ' . $this->scoped('`due_date` >= (UTC_DATE() - INTERVAL :days DAY)')
            . ' GROUP BY `kind`',
            $this->bind(['days' => $days])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['kind']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return list<string> the kinds, in the order the digest groups them */
    public static function kindOrder(): array
    {
        $kinds = ReminderKind::all();
        \usort($kinds, static fn (string $a, string $b): int
            => ReminderKind::priority($a) <=> ReminderKind::priority($b));
        return $kinds;
    }
}
