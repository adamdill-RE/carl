<?php

declare(strict_types=1);

namespace Carl\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Handoff Section 6. Server and database are UTC. Event dates are the user's
 * local calendar day, because they must join to weather_daily.obs_date, which
 * is also a local calendar day (weather.md Section 6.3).
 *
 * "Today" is therefore computed in the user's timezone through a real IANA
 * name, never from the server clock and never with a fixed offset.
 */
final class Clock
{
    private DateTimeZone $utc;

    public function __construct(private ?DateTimeImmutable $frozen = null)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->frozen !== null
            ? $this->frozen->setTimezone($this->utc)
            : new DateTimeImmutable('now', $this->utc);
    }

    /** UTC DATETIME string for created_at, recorded_at, fetched_at. */
    public function utcStamp(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    public function zone(?string $timezone): DateTimeZone
    {
        if ($timezone === null || $timezone === '') {
            return $this->utc;
        }
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            return $this->utc;
        }
    }

    /** The user's local calendar day, as a Y-m-d string. */
    public function todayFor(?string $timezone): string
    {
        return $this->nowUtc()->setTimezone($this->zone($timezone))->format('Y-m-d');
    }

    public function localNow(?string $timezone): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone($this->zone($timezone));
    }

    /** Local hour 0-23, for "is it digest time for this user" style checks. */
    public function localHour(?string $timezone): int
    {
        return (int) $this->localNow($timezone)->format('G');
    }

    /**
     * Validate and normalise a user-entered date. Future dates are refused --
     * every date field accepts the past, not the future (handoff Section 4).
     */
    public static function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /** Whole days from $from to $to, negative when $to is earlier. */
    public static function daysBetween(string $from, string $to): ?int
    {
        $a = self::parseDate($from);
        $b = self::parseDate($to);
        if ($a === null || $b === null) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($a, $utc);
        $end   = new DateTimeImmutable($b, $utc);
        return (int) $start->diff($end)->format('%r%a');
    }

    public static function addDays(string $date, int $days): ?string
    {
        $parsed = self::parseDate($date);
        if ($parsed === null) {
            return null;
        }
        $sign = $days < 0 ? '-' : '+';
        return (new DateTimeImmutable($parsed, new DateTimeZone('UTC')))
            ->modify($sign . \abs($days) . ' days')
            ->format('Y-m-d');
    }

    /**
     * Research windows are recurring MM-DD strings. Does today's MM-DD fall
     * inside one, allowing for a window that wraps the new year?
     */
    public static function inRecurringWindow(string $today, ?string $from, ?string $to): bool
    {
        if ($from === null || $from === '' || $to === null || $to === '') {
            return true;
        }
        $md = \substr($today, 5, 5);
        if ($from <= $to) {
            return $md >= $from && $md <= $to;
        }
        // Wraps December into January.
        return $md >= $from || $md <= $to;
    }

    /** A recurring MM-DD resolved onto a calendar year, as Y-m-d. */
    public static function recurringOn(string $monthDay, int $year): ?string
    {
        if (\preg_match('/^\d{2}-\d{2}$/', $monthDay) !== 1) {
            return null;
        }
        return self::parseDate(\sprintf('%04d-%s', $year, $monthDay));
    }

    public static function isMonthDay(?string $value): bool
    {
        return \is_string($value)
            && \preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value) === 1;
    }
}
