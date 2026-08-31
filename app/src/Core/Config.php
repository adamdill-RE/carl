<?php

declare(strict_types=1);

namespace Carl\Core;

/**
 * Configuration: committed defaults, then the gitignored local overrides,
 * then environment variables under the CARL_ prefix.
 */
final class Config
{
    private const ENV_PREFIX = 'CARL_';

    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(string $root): self
    {
        $values = require $root . '/config/app.php';

        $localPath = $root . '/config/local.php';
        if (\is_file($localPath)) {
            // A ParseError here is caught in bootstrap.php and reported by
            // file and line (hosting Section 6.4).
            $local = require $localPath;
            if (\is_array($local)) {
                $values = self::mergeDeep($values, $local);
            }
        }

        return (new self($values))->withEnvOverrides();
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Environment overrides: CARL_DB_HOST maps to db.host, CARL_BASE_PATH to
     * base_path. Only keys that already exist can be overridden, so a typo
     * cannot invent configuration.
     */
    private function withEnvOverrides(): self
    {
        $values = $this->values;
        foreach (self::flatten($values) as $dotted => $_) {
            $env = self::ENV_PREFIX . \strtoupper(\str_replace('.', '_', $dotted));
            $raw = \getenv($env);
            if ($raw === false) {
                continue;
            }
            $values = self::setPath($values, \explode('.', $dotted), self::coerce($raw));
        }

        return new self($values);
    }

    private static function coerce(string $raw): mixed
    {
        $lower = \strtolower($raw);
        return match (true) {
            $lower === 'true'  => true,
            $lower === 'false' => false,
            $lower === 'null'  => null,
            \preg_match('/^-?\d+$/', $raw) === 1 => (int) $raw,
            default => $raw,
        };
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);
        return \is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path, $default);
        return \is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $value = $this->get($path, $default);
        return \is_bool($value) ? $value : $default;
    }

    /**
     * A secret that must be present AND non-empty to enable a route. Returns
     * null when unset, which the router turns into a 404 rather than a 403
     * (hosting Section 6.3).
     */
    public function secret(string $path): ?string
    {
        $value = $this->get($path);
        if (!\is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function mergeDeep(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])
                && !\array_is_list($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed> dotted path => scalar
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value) && !\array_is_list($value)) {
                $out += self::flatten($value, $dotted);
            } else {
                $out[$dotted] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $values
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function setPath(array $values, array $path, mixed $value): array
    {
        $key = \array_shift($path);
        if ($path === []) {
            $values[$key] = $value;
            return $values;
        }
        $child = \is_array($values[$key] ?? null) ? $values[$key] : [];
        $values[$key] = self::setPath($child, $path, $value);
        return $values;
    }
}
