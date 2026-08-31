<?php

declare(strict_types=1);

namespace Carl\Tests;

use Throwable;

/**
 * A test harness in one file. No Composer, so no PHPUnit (hosting Section 3).
 *
 * --strict turns every notice, warning and deprecation into a failure, which
 * is what "run the suite --strict" means in hosting Section 10.
 */
final class Harness
{
    /** @var list<array{name:string,error:string}> */
    private array $failures = [];
    private int $passed = 0;
    private int $assertions = 0;
    private string $currentGroup = '';

    public function __construct(private bool $strict)
    {
        if ($strict) {
            \error_reporting(\E_ALL);
            \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
        }
    }

    public function group(string $name): void
    {
        $this->currentGroup = $name;
        echo "\n" . $name . "\n";
    }

    public function test(string $name, callable $body): void
    {
        try {
            $body($this);
            $this->passed++;
            echo '  ok   ' . $name . "\n";
        } catch (Throwable $e) {
            $label = ($this->currentGroup !== '' ? $this->currentGroup . ' / ' : '') . $name;
            $this->failures[] = [
                'name'  => $label,
                'error' => $e->getMessage() . ' (' . \basename($e->getFile()) . ':' . $e->getLine() . ')',
            ];
            echo '  FAIL ' . $name . ' -- ' . $e->getMessage() . "\n";
        }
    }

    public function ok(bool $condition, string $message = 'expected true'): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . 'expected ' . self::render($expected) . ', got ' . self::render($actual)
            );
        }
    }

    public function equals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . 'expected ' . self::render($expected) . ', got ' . self::render($actual)
            );
        }
    }

    public function contains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!\str_contains($haystack, $needle)) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . 'expected to find ' . self::render($needle) . ' in ' . self::render(\substr($haystack, 0, 400))
            );
        }
    }

    public function notContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (\str_contains($haystack, $needle)) {
            throw new \RuntimeException(
                ($message !== '' ? $message . ': ' : '')
                . 'did not expect to find ' . self::render($needle)
            );
        }
    }

    public function throws(string $expectedClass, callable $body, string $message = ''): Throwable
    {
        $this->assertions++;
        try {
            $body();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new \RuntimeException(
                    ($message !== '' ? $message . ': ' : '')
                    . 'expected ' . $expectedClass . ', got ' . $e::class . ' (' . $e->getMessage() . ')'
                );
            }
            return $e;
        }
        throw new \RuntimeException(
            ($message !== '' ? $message . ': ' : '') . 'expected ' . $expectedClass . ', nothing was thrown'
        );
    }

    private static function render(mixed $value): string
    {
        if (\is_string($value)) {
            return '"' . $value . '"';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        return (string) \json_encode($value);
    }

    public function report(): int
    {
        echo "\n";
        if ($this->failures === []) {
            \printf("%d tests, %d assertions, all passing%s\n",
                $this->passed, $this->assertions, $this->strict ? ' (strict)' : '');
            return 0;
        }
        \printf("%d passed, %d FAILED (%d assertions)\n",
            $this->passed, \count($this->failures), $this->assertions);
        foreach ($this->failures as $failure) {
            echo '  - ' . $failure['name'] . ': ' . $failure['error'] . "\n";
        }
        return 1;
    }
}
