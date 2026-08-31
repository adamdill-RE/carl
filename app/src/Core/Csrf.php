<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * A CSRF token on every POST (hosting Section 8.5).
 *
 * One per-session secret, compared with hash_equals. random_bytes and
 * hash_equals are core; sodium is absent on this host (hosting Section 3).
 */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!\is_string($token) || $token === '') {
            $token = \bin2hex(\random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }
        $token = $this->session->get(self::KEY);
        if (!\is_string($token) || $token === '') {
            return false;
        }
        return \hash_equals($token, $candidate);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . \htmlspecialchars($this->token(), \ENT_QUOTES, 'UTF-8') . '">';
    }
}
