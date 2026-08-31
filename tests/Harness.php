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
                // Respect the suppression operator. Under @, error_reporting()
                // is lowered for the duration of the expression, and a handler
                // that ignores that turns every deliberate `@unlink` and
                // `@stream_socket_client` in the application into a failure --
                // which would mean the paths that use them could never be
                // tested at all. The point of --strict is the diagnostics
                // nobody looked at, not the ones somebody handled.
                if ((\error_reporting() & $severity) === 0) {
                    return false;
                }
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

    /**
     * Run a measurement script in a FRESH php process and read its JSON line.
     *
     * Some things cannot be measured from inside the process doing the
     * measuring. Resident memory is a high-water mark that a long-lived
     * process never gives back, so a delta taken around one operation in a
     * suite that has already peaked higher reads zero -- and PHP's own
     * counter cannot see GD's allocations at all. A child that boots, does
     * the one thing and exits is where the number is real, and it is also
     * what a web request actually is.
     *
     * Returns null when the platform will not spawn one, so a caller can say
     * the measurement was skipped rather than quietly pass.
     *
     * @param list<string> $arguments script path first, then its arguments
     * @return array<string,mixed>|null the decoded JSON line
     */
    public static function measureInChildProcess(array $arguments): ?array
    {
        if (!\function_exists('proc_open') || \PHP_BINARY === '') {
            return null;
        }

        $command = \array_merge([\PHP_BINARY], $arguments);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];

        $process = @\proc_open($command, $descriptors, $pipes, \dirname(__DIR__));
        if (!\is_resource($process)) {
            return null;
        }

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        if ($status !== 0) {
            throw new \RuntimeException(
                'measurement child exited ' . $status . ': ' . \trim($stderr !== '' ? $stderr : $stdout)
            );
        }

        $decoded = \json_decode(\trim($stdout), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('measurement child printed no JSON: ' . \trim($stdout));
        }
        return $decoded;
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
