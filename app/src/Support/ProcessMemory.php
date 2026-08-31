<?php

declare(strict_types=1);

namespace Carl\Support;

/**
 * How much memory this process has actually taken, as opposed to how much
 * PHP thinks it has.
 *
 * **`memory_get_peak_usage()` cannot see GD.** Measured 2026-08-31: five open
 * 1920x1440 truecolour images move the process's resident set by 53 MB and
 * move `memory_get_peak_usage(true)` by exactly zero, because libgd allocates
 * its pixel buffers outside the Zend allocator. (`deploy.md` Section 0.7 has
 * the numbers.)
 *
 * Two things follow, and they pull in opposite directions:
 *
 *  - `memory_limit` is enforced by the Zend allocator, so decoded images do
 *    not count against it. Twenty open photographs will not produce "Allowed
 *    memory size exhausted".
 *  - The host still has a per-process ceiling of its own, and when a shared
 *    host kills a process for exceeding it there is no PHP error to read --
 *    the page simply does not arrive.
 *
 * So the budget in handoff Section 13.2 has to be checked against the
 * process, not against PHP's own counter. This reads it where the platform
 * offers it and says so when it cannot: a wrong number is worse than none.
 */
final class ProcessMemory
{
    /**
     * Peak resident set size in bytes, or PHP's own peak where the platform
     * does not publish one.
     *
     * Linux only, which is this host (hosting Section 1) and this test suite.
     * Elsewhere it falls back to memory_get_peak_usage(), which is at least
     * never an overstatement.
     */
    public static function peakBytes(): int
    {
        $status = self::readStatus('VmHWM');
        return $status ?? \memory_get_peak_usage(true);
    }

    /** Current resident set size in bytes, or null where it is not published. */
    public static function currentBytes(): ?int
    {
        return self::readStatus('VmRSS');
    }

    /** Whether the real figure is available, so a caller can label its output. */
    public static function isReal(): bool
    {
        return self::readStatus('VmHWM') !== null;
    }

    private static function readStatus(string $field): ?int
    {
        if (!\is_readable('/proc/self/status')) {
            return null;
        }
        $contents = @\file_get_contents('/proc/self/status');
        if ($contents === false) {
            return null;
        }
        if (\preg_match('/^' . \preg_quote($field, '/') . ':\s+(\d+)\s+kB$/m', $contents, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 1024;
    }
}
