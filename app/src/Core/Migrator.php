<?php

declare(strict_types=1);

namespace Carl\Core;

use RuntimeException;
use Throwable;

/**
 * Numbered .sql migrations, applied once each in order, immutable once
 * applied (hosting Section 7): the checksum is recorded and a changed file is
 * refused rather than silently re-run.
 *
 * MySQL commits implicitly on DDL, so only pure-data migrations can be
 * wrapped in a transaction. Each file declares which it is on its first line:
 *
 *     -- carl:kind=ddl      (schema; no transaction)
 *     -- carl:kind=dml      (data only; wrapped in a transaction)
 *
 * A migration may also be a .php file that returns
 * `function (Database $db): void`, for data too bulky to express as literal
 * SQL -- the 33,791-row ZIP table is the case that needs it (handoff 8.3).
 * It declares its kind the same way, in a `// carl:kind=dml` line.
 *
 * There is no staging on this plan -- a bad migration is discovered on the
 * live site -- so CI applies every migration twice and asserts the second run
 * reports "up to date" (hosting Section 10).
 */
final class Migrator
{
    public function __construct(private Database $db, private string $migrationsPath)
    {
    }

    public function ensureTable(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS `schema_migration` ('
            . ' `version` VARCHAR(16) NOT NULL,'
            . ' `filename` VARCHAR(190) NOT NULL,'
            . ' `checksum` CHAR(64) NOT NULL,'
            . ' `kind` VARCHAR(8) NOT NULL,'
            . ' `statements` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `applied_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return list<array{version:string,filename:string,path:string,checksum:string,kind:string}>
     */
    public function available(): array
    {
        $sql = \glob($this->migrationsPath . '/*.sql');
        $php = \glob($this->migrationsPath . '/*.php');
        $files = \array_merge($sql === false ? [] : $sql, $php === false ? [] : $php);
        if ($files === []) {
            return [];
        }
        \sort($files, \SORT_STRING);

        $out = [];
        $seen = [];
        foreach ($files as $path) {
            $filename = \basename($path);
            if (\preg_match('/^(\d{3,4})_/', $filename, $m) !== 1) {
                throw new RuntimeException("Migration {$filename} is not numbered NNN_name.sql");
            }
            if (isset($seen[$m[1]])) {
                throw new RuntimeException(
                    "Two migrations share version {$m[1]}: {$seen[$m[1]]} and {$filename}."
                );
            }
            $seen[$m[1]] = $filename;
            $body = (string) \file_get_contents($path);
            $out[] = [
                'version'  => $m[1],
                'filename' => $filename,
                'path'     => $path,
                'checksum' => \hash('sha256', $this->normalise($body)),
                'kind'     => $this->kindOf($body, $filename),
            ];
        }
        return $out;
    }

    /** @return array<string,array{checksum:string,filename:string,applied_at:string}> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->all(
            'SELECT version, filename, checksum, applied_at FROM schema_migration ORDER BY version'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['version']] = [
                'checksum'   => (string) $row['checksum'],
                'filename'   => (string) $row['filename'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{version:string,filename:string,path:string,checksum:string,kind:string}>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->available() as $migration) {
            $seen = $applied[$migration['version']] ?? null;
            if ($seen === null) {
                $pending[] = $migration;
                continue;
            }
            if (!\hash_equals($seen['checksum'], $migration['checksum'])) {
                throw new RuntimeException(
                    "Migration {$migration['filename']} changed after it was applied on "
                    . "{$seen['applied_at']}. Migrations are immutable: add a new one instead."
                );
            }
        }
        return $pending;
    }

    /**
     * Apply every pending migration in order.
     *
     * @return list<array{version:string,filename:string,statements:int,ms:int}>
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = [];

        foreach ($this->pending() as $migration) {
            $isPhp = \str_ends_with($migration['path'], '.php');
            $statements = $isPhp ? [] : self::split((string) \file_get_contents($migration['path']));
            $started = \microtime(true);

            $run = function () use ($statements, $migration, $isPhp): void {
                if ($isPhp) {
                    $callable = require $migration['path'];
                    if (!\is_callable($callable)) {
                        throw new RuntimeException(
                            "Migration {$migration['filename']} must return a callable taking a Database."
                        );
                    }
                    $callable($this->db);
                    return;
                }
                foreach ($statements as $statement) {
                    try {
                        $this->db->pdo()->exec($statement);
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            "Migration {$migration['filename']} failed: " . $e->getMessage()
                            . "\n\nStatement:\n" . \substr($statement, 0, 500)
                        );
                    }
                }
            };

            if ($migration['kind'] === 'dml') {
                $this->db->transaction($run);
            } else {
                $run();
            }

            $ms = (int) \round((\microtime(true) - $started) * 1000);
            $this->db->run(
                'INSERT INTO schema_migration'
                . ' (version, filename, checksum, kind, statements, duration_ms, applied_at)'
                . ' VALUES (:v, :f, :c, :k, :s, :d, UTC_TIMESTAMP())',
                [
                    'v' => $migration['version'],
                    'f' => $migration['filename'],
                    'c' => $migration['checksum'],
                    'k' => $migration['kind'],
                    's' => \count($statements),
                    'd' => $ms,
                ]
            );

            $done[] = [
                'version'    => $migration['version'],
                'filename'   => $migration['filename'],
                'statements' => \count($statements),
                'ms'         => $ms,
            ];
        }

        return $done;
    }

    private function kindOf(string $sql, string $filename): string
    {
        if (\preg_match('/^\s*(?:--|\/\/|\*)\s*carl:kind=(ddl|dml)\s*$/mi', $sql, $m) === 1) {
            return \strtolower($m[1]);
        }
        throw new RuntimeException(
            "Migration {$filename} must declare its kind on a line reading"
            . " 'carl:kind=ddl' or 'carl:kind=dml'. MySQL commits implicitly on DDL,"
            . " so only pure-data migrations can be wrapped in a transaction."
        );
    }

    /** Comments and trailing whitespace do not change a migration's identity. */
    private function normalise(string $sql): string
    {
        $sql = \str_replace("\r\n", "\n", $sql);
        $lines = [];
        foreach (\explode("\n", $sql) as $line) {
            $trimmed = \rtrim($line);
            $lead = \ltrim($trimmed);
            if ($trimmed === '' || \str_starts_with($lead, '--') || \str_starts_with($lead, '//')) {
                continue;
            }
            $lines[] = $trimmed;
        }
        return \implode("\n", $lines);
    }

    /**
     * Split a migration into statements on semicolons that are not inside a
     * string, an identifier or a comment. Migration files are ours, so this
     * does not need to handle DELIMITER or stored programs -- and must not
     * grow to, because a routine is not something this account can debug.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = \strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === '-' && $next === '-') {
                $end = \strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                $current .= "\n";
                continue;
            }
            if ($char === '#') {
                $end = \strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = \strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                $current .= ' ';
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $c = $sql[$i];
                    if ($c === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $current .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $current .= $c;
                    $i++;
                    if ($c === $quote) {
                        // A doubled quote is an escaped quote, not the end.
                        if ($i < $length && $sql[$i] === $quote) {
                            $current .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }
            if ($char === ';') {
                $trimmed = \trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = \trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
