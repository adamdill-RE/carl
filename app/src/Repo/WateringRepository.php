<?php

declare(strict_types=1);

namespace Carl\Repo;

/**
 * Reading back the watering recommendation (handoff Section 11).
 *
 * Reading only. The model is computed nightly by
 * `bin/weather_sync.php --recommend`; **nothing here computes anything**,
 * because the MOTD and the digest must not do arithmetic over a season's
 * weather while somebody is waiting for a page.
 */
final class WateringRepository extends Repository
{
    protected function table(): string
    {
        return 'watering_recommendation';
    }

    protected function writable(): array
    {
        // Nothing on this table is written through a form; the model writes
        // it, and the model is a job, not a request.
        return [];
    }

    protected function hasUpdatedAt(): bool
    {
        return false;
    }

    /**
     * Today's recommendation for each of this user's gardens and containers,
     * with the place's name, in one statement.
     *
     * @return list<array<string,mixed>>
     */
    public function forDate(string $date): array
    {
        // Not aliased: Repository::scoped() writes the predicate with the
        // real table name, which is what keeps the user filter in one place
        // instead of in every query (handoff Section 5).
        return $this->db->all(
            'SELECT `watering_recommendation`.*, COALESCE(g.name, c.name) AS place_name,'
            . " CASE WHEN `watering_recommendation`.`container_id` IS NULL"
            . "      THEN 'garden' ELSE 'container' END AS place_kind"
            . ' FROM `watering_recommendation`'
            . ' LEFT JOIN `garden` g ON g.id = `watering_recommendation`.`garden_id`'
            . ' LEFT JOIN `container` c ON c.id = `watering_recommendation`.`container_id`'
            . ' WHERE ' . $this->scoped('`watering_recommendation`.`for_date` = :for_date')
            . " ORDER BY FIELD(`watering_recommendation`.`tier`, 'water', 'likely', 'skip'), place_name",
            $this->bind(['for_date' => $date])
        );
    }

    /**
     * The same, for every user due a digest, in one statement rather than one
     * per user. The digest is the first job that loops over users and
     * hosting Section 9's arithmetic applies (Phase 3 handoff Section 4.4).
     *
     * Global by necessity -- it spans users -- so it is static and takes the
     * ids explicitly rather than pretending to be scoped.
     *
     * @param list<int> $userIds
     * @return array<int,list<array<string,mixed>>> keyed by user_id
     */
    public static function forUsersOnDate(\Carl\Core\Database $db, array $userIds, string $date): array
    {
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = [];
        }
        if ($userIds === []) {
            return $out;
        }

        $params = ['for_date' => $date];
        $names = [];
        foreach (\array_values($userIds) as $i => $id) {
            $names[] = ':u' . $i;
            $params['u' . $i] = $id;
        }

        $rows = $db->all(
            'SELECT r.*, COALESCE(g.name, c.name) AS place_name'
            . ' FROM `watering_recommendation` r'
            . ' LEFT JOIN `garden` g ON g.id = r.garden_id'
            . ' LEFT JOIN `container` c ON c.id = r.container_id'
            . ' WHERE r.`for_date` = :for_date AND r.`user_id` IN (' . \implode(', ', $names) . ')'
            . " AND r.`tier` <> 'skip'"
            . " ORDER BY r.`user_id`, FIELD(r.`tier`, 'water', 'likely'), place_name",
            $params
        );

        foreach ($rows as $row) {
            $out[(int) $row['user_id']][] = $row;
        }
        return $out;
    }

    /** For /status: has the model run, and when. */
    public function lastComputedAt(): ?string
    {
        $value = $this->db->value(
            'SELECT MAX(`computed_at`) FROM `watering_recommendation` WHERE ' . $this->scoped(),
            $this->bind([])
        );
        return \is_string($value) ? $value : null;
    }
}
