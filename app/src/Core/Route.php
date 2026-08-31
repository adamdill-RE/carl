<?php

declare(strict_types=1);

namespace Carl\Core;

/** One entry in the route table. */
final class Route
{
    public const PUBLIC_ACCESS = 'public';   // no login needed
    public const USER_ACCESS   = 'user';     // any logged-in, onboarded user
    public const SETUP_ACCESS  = 'setup';    // logged in, onboarding not finished
    public const ADMIN_ACCESS  = 'admin';    // role admin, and 404 to everyone else
    public const KEY_ACCESS    = 'key';      // guarded by a config key, 404 without it

    /**
     * No login, and no CSRF token: the credential is in the path.
     *
     * There is exactly one of these, the RFC 8058 One-Click unsubscribe. A
     * mail client POSTs it with no session and no page having been rendered,
     * so there is no token to carry -- and Gmail and Outlook now expect that
     * of bulk mail. It is safe only because of what the route can do: turn
     * one person's own email off. A forged request achieves precisely what
     * the link it forged was for.
     *
     * Do not reuse this for anything that writes anything else.
     */
    public const TOKEN_ACCESS  = 'token';

    /**
     * @param class-string $controller
     * @param list<string> $parameterNames
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $regex,
        public readonly array $parameterNames,
        public readonly string $controller,
        public readonly string $action,
        public readonly string $access,
        public readonly ?string $keyName = null,
        public readonly string $name = '',
    ) {
    }
}
