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
