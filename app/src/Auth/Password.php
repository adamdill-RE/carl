<?php

declare(strict_types=1);

namespace Carl\Auth;

/**
 * Hosting Section 8.4: bcrypt at cost 11.
 *
 * argon2id is available and stronger, but its default memory_cost is 64 MB
 * per hash against a memory_limit of 128 MB and an LVE cap on the account as
 * a whole. bcrypt is the measured choice here.
 */
final class Password
{
    public function __construct(private int $cost = 11)
    {
    }

    public function hash(string $plain): string
    {
        return \password_hash($plain, \PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    public function verify(string $plain, string $hash): bool
    {
        return \password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return \password_needs_rehash($hash, \PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    /**
     * A temporary password the admin reads off the screen and passes on.
     * Ambiguous characters are left out so it survives being written down.
     */
    public static function temporary(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = \strlen($alphabet) - 1;
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[\random_int(0, $max)];
            if ($i === 4) {
                $out .= '-';
            }
        }
        return $out;
    }

    /**
     * @return list<string> the reasons this password is refused, empty if fine
     */
    public static function problems(string $plain, string $username): array
    {
        $problems = [];
        $length = \strlen($plain);
        if ($length < 10) {
            $problems[] = 'Use at least 10 characters.';
        }
        if ($length > 200) {
            $problems[] = 'That is longer than 200 characters.';
        }
        if (\strtolower($plain) === \strtolower($username)) {
            $problems[] = 'It cannot be your username.';
        }
        if (\preg_match('/^\d+$/', $plain) === 1) {
            $problems[] = 'Use more than only digits.';
        }
        $common = ['password12', 'password123', 'letmein123', '1234567890', 'qwertyuiop', 'gardening1'];
        if (\in_array(\strtolower($plain), $common, true)) {
            $problems[] = 'That password is too easy to guess.';
        }
        return $problems;
    }
}
