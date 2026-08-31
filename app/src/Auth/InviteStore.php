<?php

declare(strict_types=1);

namespace Carl\Auth;

use Carl\Core\Database;

/**
 * The one-shot set-password link (Phase 5 handoff Section 3.5, which is Phase
 * 3 handoff Section 9.4's outstanding item).
 *
 * Section 3.5 says to reuse `TokenStore`. It is the right PATTERN and the
 * wrong class, so this is the pattern and not the class:
 *
 *  - What is reused is the discipline. The credential is `selector.verifier`,
 *    the selector is the indexed lookup and useless on its own, only a
 *    SHA-256 of the verifier is stored, and the comparison is `hash_equals`.
 *    A copy of this table is not a set of working links.
 *  - What is NOT reused is `TokenStore` itself. Its rows are login sessions
 *    and `resolve()` rotates them on every use so a stolen cookie is
 *    detectable. Rotation is exactly wrong for an invitation: an invitation
 *    must work once and then never again, and a rotating one would hand back
 *    a fresh credential every time the link was opened.
 *
 * Two properties beyond the auth token's, both of which are the point:
 *
 *  - **Expiring.** `invite.lifetime_days`, seven by default. A set-password
 *    link that never expires is a standing credential sitting in an inbox,
 *    which is most of what was wrong with mailing the password.
 *  - **Single use**, and a used row is KEPT rather than deleted. "This link
 *    has already been used" and "this link is not valid" send a person to
 *    two different places, and only one of them is the login page.
 */
final class InviteStore
{
    /** Why a link did not work, in a shape a page can say out loud. */
    public const VALID   = 'valid';
    public const UNKNOWN = 'unknown';
    public const USED    = 'used';
    public const EXPIRED = 'expired';

    public function __construct(private Database $db, private int $lifetimeDays = 7)
    {
    }

    /**
     * Issue one, and revoke any earlier unused invitation for that account.
     *
     * Revoking the earlier one is what makes "send another" mean what an
     * administrator thinks it means. Two live links to the same account is
     * one more than anybody intended, and the older one is usually the one
     * that leaked -- it is the one that has been sitting in a mailbox
     * longest.
     *
     * @return string the token that goes in the URL
     */
    public function issue(int $userId, ?int $createdBy, string $ip): string
    {
        $this->revokeUnusedFor($userId);

        $selector = \bin2hex(\random_bytes(16));
        $verifier = \bin2hex(\random_bytes(32));

        $this->db->run(
            'INSERT INTO `password_invite`'
            . ' (user_id, selector, verifier_hash, expires_at, created_at, created_by, ip)'
            . ' VALUES (:user_id, :selector, :hash, :expires, UTC_TIMESTAMP(), :created_by, :ip)',
            [
                'user_id'    => $userId,
                'selector'   => $selector,
                'hash'       => \hash('sha256', $verifier),
                'expires'    => \gmdate('Y-m-d H:i:s', \time() + ($this->lifetimeDays * 86400)),
                'created_by' => $createdBy,
                'ip'         => \substr($ip, 0, 45),
            ]
        );

        return $selector . '.' . $verifier;
    }

    /**
     * Resolve a token to the account it sets a password on.
     *
     * @return array{status:string,user_id:?int,invite_id:?int}
     */
    public function resolve(string $token): array
    {
        $parts = \explode('.', $token, 2);
        if (\count($parts) !== 2) {
            return self::miss();
        }
        [$selector, $verifier] = $parts;
        if (\strlen($selector) !== 32 || \strlen($verifier) !== 64) {
            return self::miss();
        }

        $row = $this->db->one(
            'SELECT `id`, `user_id`, `verifier_hash`, `used_at`,'
            . ' (`expires_at` < UTC_TIMESTAMP()) AS is_expired'
            . ' FROM `password_invite` WHERE `selector` = :selector',
            ['selector' => $selector]
        );
        if ($row === null) {
            return self::miss();
        }

        // Constant time, and before the used/expired checks: a wrong verifier
        // must not be able to learn whether a selector it guessed is a real
        // invitation that happens to have been used.
        if (!\hash_equals((string) $row['verifier_hash'], \hash('sha256', $verifier))) {
            return self::miss();
        }

        $status = match (true) {
            $row['used_at'] !== null     => self::USED,
            (int) $row['is_expired'] === 1 => self::EXPIRED,
            default                      => self::VALID,
        };

        return ['status' => $status, 'user_id' => (int) $row['user_id'], 'invite_id' => (int) $row['id']];
    }

    /**
     * Spend it.
     *
     * A compare-and-swap on `used_at`, not a read-then-write: two submissions
     * of the same form -- a double tap, or a retried POST -- must set the
     * password once. The loser gets false and the page tells them the link is
     * already used, which by then it is, by them.
     */
    public function markUsed(int $inviteId): bool
    {
        return $this->db->run(
            'UPDATE `password_invite` SET `used_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id AND `used_at` IS NULL',
            ['id' => $inviteId]
        )->rowCount() === 1;
    }

    public function revokeUnusedFor(int $userId): int
    {
        return $this->db->run(
            'DELETE FROM `password_invite` WHERE `user_id` = :user_id AND `used_at` IS NULL',
            ['user_id' => $userId]
        )->rowCount();
    }

    /**
     * Expired rows that were never used are pruned; used ones stay for the
     * audit, and because they are what makes the "already used" page
     * possible.
     */
    public function pruneExpired(int $keepDays = 30): int
    {
        return $this->db->run(
            'DELETE FROM `password_invite` WHERE `used_at` IS NULL'
            . ' AND `expires_at` < (UTC_TIMESTAMP() - INTERVAL :days DAY)',
            ['days' => $keepDays]
        )->rowCount();
    }

    /** @return array{status:string,user_id:null,invite_id:null} */
    private static function miss(): array
    {
        return ['status' => self::UNKNOWN, 'user_id' => null, 'invite_id' => null];
    }
}
