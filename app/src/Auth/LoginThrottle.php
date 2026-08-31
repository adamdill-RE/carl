<?php

declare(strict_types=1);

namespace Carl\Auth;

use Carl\Core\Database;

/**
 * Hosting Section 8.4: on a low-entropy credential the rate limit and lockout
 * do the real work; the hash only protects the database if it leaks.
 *
 * The numbers are deliberately loose (10 attempts / 15 minute window / 60 s
 * lockout) because a locked-out gardener with a phone in a field is a worse
 * failure than a brute-force attempt on a hobby tool.
 */
final class LoginThrottle
{
    public function __construct(
        private Database $db,
        private int $maxAttempts = 10,
        private int $windowSeconds = 900,
        private int $lockoutSeconds = 60,
    ) {
    }

    /** Seconds the caller must wait, or 0 when they may try now. One statement. */
    public function retryAfter(string $username, string $ip): int
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_at'
            . ' FROM login_attempt'
            . ' WHERE succeeded = 0'
            . '   AND attempted_at > (UTC_TIMESTAMP() - INTERVAL :window SECOND)'
            . '   AND (username = :username OR ip = :ip)',
            [
                'window'   => $this->windowSeconds,
                'username' => $username,
                'ip'       => $ip,
            ]
        );

        if ($row === null || (int) $row['failures'] < $this->maxAttempts) {
            return 0;
        }

        $lastAt = $row['last_at'];
        if (!\is_string($lastAt)) {
            return $this->lockoutSeconds;
        }

        $elapsed = \time() - (int) \strtotime($lastAt . ' UTC');
        $remaining = $this->lockoutSeconds - $elapsed;

        return $remaining > 0 ? $remaining : 0;
    }

    public function record(string $username, string $ip, bool $succeeded): void
    {
        $this->db->run(
            'INSERT INTO login_attempt (username, ip, succeeded, attempted_at)'
            . ' VALUES (:username, :ip, :succeeded, UTC_TIMESTAMP())',
            [
                'username'  => \substr($username, 0, 64),
                'ip'        => \substr($ip, 0, 45),
                'succeeded' => $succeeded ? 1 : 0,
            ]
        );
    }

    /** A successful sign-in clears the slate for that name. */
    public function clear(string $username): void
    {
        $this->db->run('DELETE FROM login_attempt WHERE username = :username', ['username' => $username]);
    }

    /** Called from the nightly job; an unpruned log table is a slow-growing bug. */
    public function prune(int $keepDays = 30): int
    {
        return $this->db->run(
            'DELETE FROM login_attempt WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $keepDays]
        )->rowCount();
    }
}
