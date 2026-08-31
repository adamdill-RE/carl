<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Domain\ListType;

/**
 * The user-set variables (handoff Section 4.9). Every dropdown in the app
 * reads through here, and every dropdown's "+ Add new ..." writes through
 * here without leaving the form.
 */
final class ListRepository extends Repository
{
    protected function table(): string
    {
        return 'user_list_item';
    }

    protected function writable(): array
    {
        return ['list_type', 'name', 'attr_1', 'attr_2', 'garden_id', 'water_zone_id',
                'pest_id', 'is_active', 'sort_order'];
    }

    /** @return list<array<string,mixed>> */
    public function ofType(string $listType, bool $activeOnly = true): array
    {
        $predicate = '`list_type` = :list_type';
        if ($activeOnly) {
            $predicate .= ' AND `is_active` = 1';
        }
        return $this->where($predicate, ['list_type' => $listType], '`sort_order`, `name`');
    }

    /**
     * Every list in one statement, so a form with a dozen dropdowns costs one
     * round trip rather than a dozen (hosting Section 9).
     *
     * @param list<string> $types
     * @return array<string,list<array<string,mixed>>> keyed by list_type
     */
    public function manyTypes(array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            $out[$type] = [];
        }
        if ($types === []) {
            return $out;
        }

        $params = [];
        $names = [];
        foreach (\array_values($types) as $i => $type) {
            $names[] = ':t' . $i;
            $params['t' . $i] = $type;
        }

        $rows = $this->db->all(
            'SELECT * FROM `user_list_item`'
            . ' WHERE ' . $this->scoped('`is_active` = 1 AND `list_type` IN (' . \implode(', ', $names) . ')')
            . ' ORDER BY `list_type`, `sort_order`, `name`',
            $this->bind($params)
        );

        foreach ($rows as $row) {
            $out[(string) $row['list_type']][] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findInType(int $id, string $listType): ?array
    {
        return $this->db->one(
            'SELECT * FROM `user_list_item` WHERE ' . $this->scoped('`id` = :id AND `list_type` = :list_type'),
            $this->bind(['id' => $id, 'list_type' => $listType])
        );
    }

    /**
     * Create if absent, return the existing id if present. This is what makes
     * an inline "+ Add new ..." safe to submit twice: the unique index on
     * (user_id, list_type, name) is what decides, not a read-then-write.
     */
    public function ensure(string $listType, string $name, ?string $attr1 = null, ?string $attr2 = null): int
    {
        $name = \trim($name);
        if ($name === '' || !ListType::isValid($listType)) {
            return 0;
        }

        $now = $this->now();
        // Let the database enforce the uniqueness rule rather than reading
        // first (hosting Section 7). The no-op update is what makes
        // lastInsertId return the existing row's id.
        $this->db->run(
            'INSERT INTO `user_list_item`'
            . ' (user_id, list_type, name, attr_1, attr_2, is_active, sort_order, created_at, updated_at)'
            . ' VALUES (:user_id, :list_type, :name, :attr_1, :attr_2, 1, 0, :created_at, :updated_at)'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `is_active` = 1',
            [
                'user_id'    => $this->userId,
                'list_type'  => $listType,
                'name'       => \substr($name, 0, 120),
                'attr_1'     => $attr1 === null || $attr1 === '' ? null : \substr($attr1, 0, 120),
                'attr_2'     => $attr2 === null || $attr2 === '' ? null : \substr($attr2, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->db->insertId();
    }

    /**
     * Resolve a select that may carry either an existing id or a new name
     * typed into the inline "+ Add new ..." field.
     */
    public function resolveChoice(string $listType, ?string $selectedId, ?string $newName): ?int
    {
        $newName = $newName === null ? '' : \trim($newName);
        if ($newName !== '') {
            $id = $this->ensure($listType, $newName);
            return $id > 0 ? $id : null;
        }
        if ($selectedId === null || $selectedId === '' || !\ctype_digit($selectedId)) {
            return null;
        }
        // Confirm it is this user's row of this type before it becomes a FK.
        return $this->findInType((int) $selectedId, $listType) === null ? null : (int) $selectedId;
    }

    /**
     * Seed the lists that must not be empty the first time they are opened
     * (handoff Section 5.6): pests and diseases from the global reference
     * table, plus a starting set of cull reasons.
     *
     * @return int rows created
     */
    public function seedForNewUser(): int
    {
        $now = $this->now();
        $rows = [];

        $pests = $this->db->all('SELECT id, name FROM `pest` ORDER BY name');
        foreach ($pests as $pest) {
            $rows[] = [
                $this->userId, ListType::PEST_DISEASE, \substr((string) $pest['name'], 0, 120),
                null, null, (int) $pest['id'], 1, 0, $now, $now,
            ];
        }
        foreach (ListType::seedCullReasons() as $index => $reason) {
            $rows[] = [
                $this->userId, ListType::CULL_REASON, $reason,
                null, null, null, 1, $index, $now, $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $columns = ['user_id', 'list_type', 'name', 'attr_1', 'attr_2', 'pest_id',
                    'is_active', 'sort_order', 'created_at', 'updated_at'];

        $created = 0;
        foreach (\array_chunk($rows, 200) as $chunk) {
            // Re-seeding an existing account changes nothing: name is the
            // natural key and the update touches no column that matters.
            $this->db->upsertChunk('user_list_item', $columns, $chunk, ['is_active']);
            $created += \count($chunk);
        }
        return $created;
    }

    /**
     * A pest the research import added after this account was created still
     * has to reach the dropdown.
     */
    public function syncPestsFromReference(): int
    {
        $missing = $this->db->all(
            'SELECT p.id, p.name FROM `pest` p'
            . ' LEFT JOIN `user_list_item` u'
            . '   ON u.pest_id = p.id AND u.user_id = :user_id AND u.list_type = :list_type'
            . ' WHERE u.id IS NULL ORDER BY p.name',
            ['user_id' => $this->userId, 'list_type' => ListType::PEST_DISEASE]
        );
        if ($missing === []) {
            return 0;
        }

        $now = $this->now();
        $rows = [];
        foreach ($missing as $pest) {
            $rows[] = [
                $this->userId, ListType::PEST_DISEASE, \substr((string) $pest['name'], 0, 120),
                (int) $pest['id'], 1, 0, $now, $now,
            ];
        }

        foreach (\array_chunk($rows, 200) as $chunk) {
            $this->db->upsertChunk(
                'user_list_item',
                ['user_id', 'list_type', 'name', 'pest_id', 'is_active', 'sort_order', 'created_at', 'updated_at'],
                $chunk,
                ['pest_id', 'is_active']
            );
        }
        return \count($rows);
    }
}
