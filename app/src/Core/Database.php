<?php

declare(strict_types=1);

namespace Carl\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO access, exactly the shape hosting Section 7 proved right.
 *
 * The database is on separate hardware (hosting Section 2.1), so the number
 * that matters is statements per request, not query cost. Every method here
 * either runs one statement or says in its name that it batches.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $statementCount = 0;

    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config->string('db.host');
        $port = $this->config->int('db.port', 3306);
        $name = $this->config->string('db.name');
        $charset = $this->config->string('db.charset', 'utf8mb4');

        // Hosting Section 2.1: localhost reaches a real MySQL that has never
        // heard of this account and answers with a misleading plugin error.
        if ($host === 'localhost' || $host === '127.0.0.1') {
            if ($this->config->get('db.allow_local') !== true) {
                throw new RuntimeException(
                    'Refusing to connect to ' . $host . '. The application database is on '
                    . 'separate hardware (hosting Section 2.1). Set db.allow_local only for '
                    . 'local development or CI.'
                );
            }
        }

        $dsn = \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config->string('db.user'),
                $this->config->string('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real server-side prepares. A named placeholder therefore
                    // cannot be reused within one statement -- bind twice under
                    // distinct names (hosting Section 7).
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    // No persistent connections under LSAPI (hosting Section 7).
                    PDO::ATTR_PERSISTENT         => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND =>
                        "SET SESSION time_zone = '+00:00', "
                        . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
                ]
            );
        } catch (Throwable $e) {
            // Re-throw without the DSN: it names the database and the user,
            // and a stack trace in a log should not carry them (hosting Section 7).
            throw new RuntimeException(
                'Database connection failed (' . $e->getCode() . '). Check config/local.php.',
                0
            );
        }

        return $this->pdo;
    }

    /** @param array<string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $this->statementCount++;
        return $statement;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** No RETURNING on MySQL (hosting Section 2.2); this is the id route. */
    public function insertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * One multi-row INSERT ... ON DUPLICATE KEY UPDATE. Batching is about
     * statement count, not bytes (weather.md Section 11 spike 6).
     *
     * @param list<string>              $columns
     * @param list<array<int,mixed>>    $rows      positional, matching $columns
     * @param list<string>              $updatable columns to overwrite on conflict
     */
    public function upsertChunk(string $table, array $columns, array $rows, array $updatable): int
    {
        if ($rows === []) {
            return 0;
        }

        $quoted = \implode(', ', \array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $tuple  = '(' . \implode(', ', \array_fill(0, \count($columns), '?')) . ')';
        $values = \implode(', ', \array_fill(0, \count($rows), $tuple));

        $assignments = \implode(
            ', ',
            \array_map(static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', $updatable)
        );

        $sql = "INSERT INTO `{$table}` ({$quoted}) VALUES {$values}";
        if ($assignments !== '') {
            $sql .= " ON DUPLICATE KEY UPDATE {$assignments}";
        }

        $flat = [];
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $flat[] = $cell;
            }
        }

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($flat);
        $this->statementCount++;

        return $statement->rowCount();
    }

    /**
     * MySQL commits implicitly on DDL, so only pure-data work belongs in here
     * (hosting Section 7).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** The portable performance number (hosting Section 9). */
    public function statementCount(): int
    {
        return $this->statementCount;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }
}
