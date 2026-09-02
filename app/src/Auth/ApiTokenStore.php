<?php

declare(strict_types=1);

namespace Carl\Auth;

use Carl\Core\Database;

/**
 * The bearer tokens the MCP server accepts (Phase 16; migration 026).
 *
 * The same discipline as TokenStore and InviteStore -- selector.verifier,
 * only a hash of the verifier stored, hash_equals on the way in -- and the
 * same reason it is a third class rather than a reuse of either: the login
 * token ROTATES on every use, which a config file cannot follow, and the
 * invite is single-use, which a credential in daily use is not. What is
 * reused is the discipline.
 *
 * A token reads `carl_<selector>.<verifier>`. The prefix costs nothing and
 * is what lets a secret scanner recognise one in a pasted file.
 *
 * THE RATE LIMIT LIVES ON THE ROW. Every call writes last_used_at anyway, so
 * the window counter rides in the same UPDATE, and resolving a token is two
 * statements whatever the traffic: one SELECT, one UPDATE. A log table like
 * login_attempt's would be a third, on every call (hosting Section 9).
 */
final class ApiTokenStore
{
    public const PREFIX = 'carl_';

    public const RESOLVED = 'ok';
    public const UNKNOWN = 'unknown';
    public const REVOKED = 'revoked';
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        private Database $db,
        private int $callsPerMinute = 60,
    ) {
    }

    /**
     * Mint a token. The plain token is returned ONCE, here, and never stored.
     *
     * @return array{id:int,token:string}
     */
    public function issue(int $userId, string $label): array
    {
        $selector = \bin2hex(\random_bytes(16));
        $verifier = \bin2hex(\random_bytes(32));

        $this->db->run(
            'INSERT INTO `api_token` (user_id, label, selector, verifier_hash, created_at)'
            . ' VALUES (:user_id, :label, :selector, :hash, UTC_TIMESTAMP())',
            [
                'user_id'  => $userId,
                'label'    => \substr(\trim($label) === '' ? 'Claude Code' : \trim($label), 0, 60),
                'selector' => $selector,
                'hash'     => \hash('sha256', $verifier),
            ]
        );

        return ['id' => $this->db->insertId(), 'token' => self::PREFIX . $selector . '.' . $verifier];
    }

    /**
     * Resolve a presented token and count the call.
     *
     * An unknown selector, a wrong verifier and a revoked token all come back
     * UNKNOWN or REVOKED without saying which part was wrong -- the same rule
     * InviteStore keeps, because a guessed selector must learn nothing. A
     * rate-limited call is NOT counted, so a client that keeps hammering
     * keeps being refused rather than pushing its own window forward.
     *
     * @return array{status:string,user_id:?int,id:?int,retry_after:int}
     */
    public function resolve(?string $token): array
    {
        $none = ['status' => self::UNKNOWN, 'user_id' => null, 'id' => null, 'retry_after' => 0];

        $token = \trim((string) $token);
        if (!\str_starts_with($token, self::PREFIX)) {
            return $none;
        }
        $parts = \explode('.', \substr($token, \strlen(self::PREFIX)), 2);
        if (\count($parts) !== 2 || \strlen($parts[0]) !== 32 || \strlen($parts[1]) !== 64) {
            return $none;
        }
        [$selector, $verifier] = $parts;
        if (\ctype_xdigit($selector) === false || \ctype_xdigit($verifier) === false) {
            return $none;
        }

        $row = $this->db->one(
            'SELECT `id`, `user_id`, `verifier_hash`, `revoked_at`, `window_started_at`, `window_calls`,'
            . ' UTC_TIMESTAMP() AS now_utc'
            . ' FROM `api_token` WHERE `selector` = :selector',
            ['selector' => $selector]
        );
        if ($row === null || !\hash_equals((string) $row['verifier_hash'], \hash('sha256', $verifier))) {
            return $none;
        }
        if ($row['revoked_at'] !== null) {
            return ['status' => self::REVOKED, 'user_id' => null, 'id' => (int) $row['id'], 'retry_after' => 0];
        }

        // The window is judged against the DATABASE clock, which both columns
        // were written from, so a PHP clock that disagrees by a second cannot
        // open or close it early.
        $now = (int) \strtotime((string) $row['now_utc'] . ' UTC');
        $windowStart = \is_string($row['window_started_at'])
            ? (int) \strtotime($row['window_started_at'] . ' UTC') : null;
        $inWindow = $windowStart !== null && $now - $windowStart < 60;

        if ($inWindow && (int) $row['window_calls'] >= $this->callsPerMinute) {
            return [
                'status'      => self::RATE_LIMITED,
                'user_id'     => (int) $row['user_id'],
                'id'          => (int) $row['id'],
                'retry_after' => \max(1, 60 - ($now - (int) $windowStart)),
            ];
        }

        $this->db->run(
            'UPDATE `api_token` SET `last_used_at` = UTC_TIMESTAMP(), `calls` = `calls` + 1,'
            . ' `window_calls` = :calls, `window_started_at` = :started'
            . ' WHERE `id` = :id',
            [
                'calls'   => $inWindow ? (int) $row['window_calls'] + 1 : 1,
                'started' => $inWindow ? $row['window_started_at'] : \gmdate('Y-m-d H:i:s', $now),
                'id'      => (int) $row['id'],
            ]
        );

        return ['status' => self::RESOLVED, 'user_id' => (int) $row['user_id'],
                'id' => (int) $row['id'], 'retry_after' => 0];
    }

    /**
     * This user's tokens, live first, newest first. Never the hash.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->db->all(
            'SELECT `id`, `label`, `selector`, `created_at`, `last_used_at`, `revoked_at`, `calls`'
            . ' FROM `api_token` WHERE `user_id` = :user_id'
            . ' ORDER BY `revoked_at` IS NOT NULL, `id` DESC',
            ['user_id' => $userId]
        );
    }

    /** Revoke one of this user's tokens. Scoped by user, like every write. */
    public function revoke(int $userId, int $id): bool
    {
        return $this->db->run(
            'UPDATE `api_token` SET `revoked_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id AND `user_id` = :user_id AND `revoked_at` IS NULL',
            ['id' => $id, 'user_id' => $userId]
        )->rowCount() === 1;
    }

    /** For /status: how many live tokens there are, and the last call anyone made. */
    public function health(): array
    {
        $row = $this->db->one(
            'SELECT SUM(`revoked_at` IS NULL) AS live, SUM(`revoked_at` IS NOT NULL) AS revoked,'
            . ' MAX(`last_used_at`) AS last_used, COALESCE(SUM(`calls`), 0) AS calls FROM `api_token`'
        );
        return [
            'live'      => (int) ($row['live'] ?? 0),
            'revoked'   => (int) ($row['revoked'] ?? 0),
            'last_used' => \is_string($row['last_used'] ?? null) ? $row['last_used'] : null,
            'calls'     => (int) ($row['calls'] ?? 0),
        ];
    }
}
