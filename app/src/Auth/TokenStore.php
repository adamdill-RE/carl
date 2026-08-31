<?php

declare(strict_types=1);

namespace Carl\Auth;

use Carl\Core\Database;

/**
 * The DB-backed rotating login token from hosting Section 8.3.
 *
 * session.gc_maxlifetime is 1440 s and garbage collection on a shared host is
 * not ours to govern, so a long-lived login cannot be a PHP session. The
 * token row IS the session: it lives server-side, so it can be revoked, which
 * is what makes "changing your password signs out your other devices" take
 * effect immediately.
 *
 * The cookie is selector.verifier. The selector is the indexed lookup key and
 * is useless alone; only a SHA-256 of the verifier is stored, compared with
 * hash_equals.
 */
final class TokenStore
{
    public const COOKIE = 'CARLAUTH';

    public function __construct(private Database $db, private int $lifetimeDays = 30)
    {
    }

    /** @return array{cookie:string,expires:int} */
    public function issue(int $userId, string $userAgent, string $ip): array
    {
        $selector = \bin2hex(\random_bytes(16));
        $verifier = \bin2hex(\random_bytes(32));
        $expires = \time() + ($this->lifetimeDays * 86400);

        $this->db->run(
            'INSERT INTO auth_token'
            . ' (user_id, selector, verifier_hash, expires_at, created_at, rotated_at, user_agent, ip)'
            . ' VALUES (:user_id, :selector, :hash, :expires, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :ua, :ip)',
            [
                'user_id'  => $userId,
                'selector' => $selector,
                'hash'     => \hash('sha256', $verifier),
                'expires'  => \gmdate('Y-m-d H:i:s', $expires),
                'ua'       => \substr($userAgent, 0, 190),
                'ip'       => \substr($ip, 0, 45),
            ]
        );

        return ['cookie' => $selector . '.' . $verifier, 'expires' => $expires];
    }

    /**
     * Resolve a cookie to a user id, rotating the token as a compare-and-swap.
     *
     * Only the request that WINS the swap sends a new cookie: a page load and
     * a background request arriving together both read the same valid row,
     * one rotation lands, the other affects no rows and leaves the cookie
     * alone -- so the browser always ends up holding what the database holds.
     *
     * @return array{user_id:int,cookie:?string,expires:int}|null
     */
    public function resolve(string $cookie, string $userAgent, string $ip): ?array
    {
        $parts = \explode('.', $cookie, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        [$selector, $verifier] = $parts;
        if (\strlen($selector) !== 32 || $verifier === '') {
            return null;
        }

        $row = $this->db->one(
            'SELECT id, user_id, verifier_hash FROM auth_token'
            . ' WHERE selector = :selector AND expires_at > UTC_TIMESTAMP()',
            ['selector' => $selector]
        );
        if ($row === null) {
            return null;
        }

        if (!\hash_equals((string) $row['verifier_hash'], \hash('sha256', $verifier))) {
            // Hosting Section 8.3, a deliberate departure from the textbook:
            // a known selector with a wrong verifier does NOT revoke the token
            // family. It is the classic theft signal, but an in-flight request
            // that lost a rotation race lands in exactly this branch, and
            // signing someone out over a race they cannot see is the worse
            // failure. The cookie is refused for this request and the mismatch
            // is audited.
            \error_log('[carl] auth token verifier mismatch for selector ' . $selector);
            return null;
        }

        $newSelector = \bin2hex(\random_bytes(16));
        $newVerifier = \bin2hex(\random_bytes(32));
        $expires = \time() + ($this->lifetimeDays * 86400);

        $rotated = $this->db->run(
            'UPDATE auth_token SET selector = :new_selector, verifier_hash = :new_hash,'
            . ' expires_at = :expires, rotated_at = UTC_TIMESTAMP(), user_agent = :ua, ip = :ip'
            . ' WHERE id = :id AND selector = :old_selector',
            [
                'new_selector' => $newSelector,
                'new_hash'     => \hash('sha256', $newVerifier),
                'expires'      => \gmdate('Y-m-d H:i:s', $expires),
                'ua'           => \substr($userAgent, 0, 190),
                'ip'           => \substr($ip, 0, 45),
                'id'           => (int) $row['id'],
                'old_selector' => $selector,
            ]
        )->rowCount();

        return [
            'user_id' => (int) $row['user_id'],
            // Lost the swap: someone else rotated first, so leave the cookie be.
            'cookie'  => $rotated === 1 ? $newSelector . '.' . $newVerifier : null,
            'expires' => $expires,
        ];
    }

    public function revokeCookie(string $cookie): void
    {
        $selector = \explode('.', $cookie, 2)[0];
        if ($selector === '') {
            return;
        }
        $this->db->run('DELETE FROM auth_token WHERE selector = :selector', ['selector' => $selector]);
    }

    /** What makes a password change sign out every other device at once. */
    public function revokeAllForUser(int $userId): int
    {
        return $this->db->run('DELETE FROM auth_token WHERE user_id = :id', ['id' => $userId])->rowCount();
    }

    public function pruneExpired(): int
    {
        return $this->db->run('DELETE FROM auth_token WHERE expires_at < UTC_TIMESTAMP()')->rowCount();
    }
}
