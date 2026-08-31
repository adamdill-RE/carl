<?php

declare(strict_types=1);

namespace Carl\Repo;

use Carl\Core\Database;
use LogicException;

/**
 * Handoff Section 5: every user-owned table carries user_id and every query
 * filters on it -- enforced in ONE base class, not per query.
 *
 * That is the whole point of this file. Nothing here lets a caller build a
 * statement without the scope: the user predicate is prepended by the class,
 * the bound value comes from the constructor, and a caller-supplied fragment
 * is appended after it. Reference data that is global by design (research,
 * pests, ZIP lookup) does not extend this class -- see ReferenceRepository.
 */
abstract class Repository
{
    protected const SCOPE = '__scope_user_id';

    public function __construct(protected Database $db, protected int $userId)
    {
        if ($userId <= 0) {
            throw new LogicException(static::class . ' needs a real user id to scope its queries.');
        }
    }

    /** @return non-empty-string */
    abstract protected function table(): string;

    /** Columns a caller is allowed to write. Anything else is rejected. */
    abstract protected function writable(): array;

    public function userId(): int
    {
        return $this->userId;
    }

    protected function scoped(string $extra = ''): string
    {
        $where = '`' . $this->table() . '`.`user_id` = :' . self::SCOPE;
        return $extra === '' ? $where : $where . ' AND (' . $extra . ')';
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    protected function bind(array $params): array
    {
        $params[self::SCOPE] = $this->userId;
        return $params;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM `' . $this->table() . '` WHERE ' . $this->scoped('`id` = :id'),
            $this->bind(['id' => $id])
        );
    }

    /** @return array<string,mixed> @throws LogicException when it is not this user's row */
    public function findOrFail(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \Carl\Core\HttpException(404, 'That is not one of your records.');
        }
        return $row;
    }

    public function exists(int $id): bool
    {
        return $this->db->value(
            'SELECT 1 FROM `' . $this->table() . '` WHERE ' . $this->scoped('`id` = :id'),
            $this->bind(['id' => $id])
        ) !== null;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function where(string $predicate = '', array $params = [], string $order = '', ?int $limit = null): array
    {
        $sql = 'SELECT * FROM `' . $this->table() . '` WHERE ' . $this->scoped($predicate);
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->all($sql, $this->bind($params));
    }

    /** @param array<string,mixed> $params */
    public function count(string $predicate = '', array $params = []): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE ' . $this->scoped($predicate),
            $this->bind($params),
            0
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return int the new id (no RETURNING on MySQL -- hosting Section 2.2)
     */
    public function insert(array $data): int
    {
        $data = $this->filter($data);
        $data['user_id'] = $this->userId;
        $data['created_at'] = $data['created_at'] ?? \gmdate('Y-m-d H:i:s');
        if ($this->hasUpdatedAt()) {
            $data['updated_at'] = $data['updated_at'] ?? $data['created_at'];
        }

        $columns = \array_keys($data);
        $sql = 'INSERT INTO `' . $this->table() . '` ('
            . \implode(', ', \array_map(static fn (string $c): string => '`' . $c . '`', $columns))
            . ') VALUES ('
            . \implode(', ', \array_map(static fn (string $c): string => ':' . $c, $columns))
            . ')';

        $this->db->run($sql, $data);
        return $this->db->insertId();
    }

    /** @param array<string,mixed> $data @return int rows affected */
    public function update(int $id, array $data): int
    {
        $data = $this->filter($data);
        if ($data === []) {
            return 0;
        }
        if ($this->hasUpdatedAt()) {
            $data['updated_at'] = \gmdate('Y-m-d H:i:s');
        }

        $assignments = [];
        foreach (\array_keys($data) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }

        $sql = 'UPDATE `' . $this->table() . '` SET ' . \implode(', ', $assignments)
            . ' WHERE ' . $this->scoped('`id` = :id');

        return $this->db->run($sql, $this->bind($data + ['id' => $id]))->rowCount();
    }

    public function delete(int $id): int
    {
        return $this->db->run(
            'DELETE FROM `' . $this->table() . '` WHERE ' . $this->scoped('`id` = :id'),
            $this->bind(['id' => $id])
        )->rowCount();
    }

    /**
     * Drop anything the caller is not allowed to write. A form posting
     * user_id, or a column that only the application derives, changes nothing.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function filter(array $data): array
    {
        $allowed = \array_flip($this->writable());
        return \array_intersect_key($data, $allowed);
    }

    protected function hasUpdatedAt(): bool
    {
        return true;
    }

    protected function now(): string
    {
        return \gmdate('Y-m-d H:i:s');
    }

    /**
     * Build "IN (...)" with one placeholder per value. With emulation off a
     * named placeholder cannot be reused, so each gets its own name
     * (hosting Section 7).
     *
     * @param list<int> $ids
     * @param array<string,mixed> $params
     */
    protected static function inClause(array $ids, string $prefix, array &$params): string
    {
        if ($ids === []) {
            return '0';
        }
        $names = [];
        foreach (\array_values($ids) as $i => $id) {
            $name = $prefix . $i;
            $names[] = ':' . $name;
            $params[$name] = $id;
        }
        return 'IN (' . \implode(', ', $names) . ')';
    }
}
