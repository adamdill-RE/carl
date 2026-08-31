<?php

declare(strict_types=1);

namespace Carl\Support;

/**
 * Weather is stored SI and converted at display, in one helper
 * (weather.md Section 6.3). Storing Fahrenheit because the UI shows
 * Fahrenheit would mean every future analysis and every threshold constant
 * has to know which convention the column follows.
 *
 * Agronomic values from the research tables are the exception: seed packets
 * are read in inches and degrees F, so those arrive that way and are shown
 * as they arrived (research-template README, "Units").
 */
final class Units
{
    public function __construct(private string $system = 'us')
    {
    }

    public function isUs(): bool
    {
        return $this->system === 'us';
    }

    public function temperature(int|float|string|null $celsius, int $decimals = 0): string
    {
        if ($celsius === null || $celsius === '') {
            return '--';
        }
        $c = (float) $celsius;
        return $this->isUs()
            ? \number_format($c * 9 / 5 + 32, $decimals) . "\u{00B0}F"
            : \number_format($c, $decimals) . "\u{00B0}C";
    }

    public function temperatureRange(int|float|string|null $max, int|float|string|null $min): string
    {
        if ($max === null && $min === null) {
            return '--';
        }
        return $this->temperature($max) . ' / ' . $this->temperature($min);
    }

    public function rain(int|float|string|null $mm, int $decimals = 2): string
    {
        if ($mm === null || $mm === '') {
            return '--';
        }
        $value = (float) $mm;
        return $this->isUs()
            ? \number_format($value / 25.4, $decimals) . ' in'
            : \number_format($value, 1) . ' mm';
    }

    /** ET0 is small, so it keeps a decimal place the rain total does not. */
    public function et0(int|float|string|null $mm): string
    {
        return $this->rain($mm, 2);
    }

    public function wind(int|float|string|null $kmh): string
    {
        if ($kmh === null || $kmh === '') {
            return '--';
        }
        $value = (float) $kmh;
        return $this->isUs()
            ? \number_format($value / 1.609344) . ' mph'
            : \number_format($value) . ' km/h';
    }

    public function percent(int|float|string|null $value): string
    {
        return $value === null || $value === '' ? '--' : \number_format((float) $value) . '%';
    }

    /**
     * Soil moisture arrives as a volumetric fraction (m3/m3). A gardener
     * reads a percentage, not a fraction.
     */
    public function soilMoisture(int|float|string|null $fraction): string
    {
        return $fraction === null || $fraction === ''
            ? '--'
            : \number_format((float) $fraction * 100, 1) . '%';
    }

    /** Harvest weight: grams in the database, pounds and ounces on the page. */
    public function weight(int|float|string|null $grams): string
    {
        if ($grams === null || $grams === '') {
            return '--';
        }
        $g = (float) $grams;
        if (!$this->isUs()) {
            return $g >= 1000 ? \number_format($g / 1000, 2) . ' kg' : \number_format($g) . ' g';
        }
        $ounces = $g / 28.349523125;
        if ($ounces < 16) {
            return \number_format($ounces, 1) . ' oz';
        }
        return \number_format($ounces / 16, 2) . ' lb';
    }

    /**
     * Static, unlike the weather helpers above: spacing and seed depth come
     * from the research tables, which carry inches by definition -- they are
     * the values printed on a seed packet, not SI measurements to convert
     * (research-template README, "Units").
     */
    public static function length(int|float|string|null $inches): string
    {
        return $inches === null || $inches === '' ? '--' : \number_format((float) $inches, 1) . ' in';
    }

    /** WMO weather codes, for a human-readable day summary (weather.md 5.1). */
    public static function weatherCode(int|string|null $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        return match ((int) $code) {
            0 => 'Clear',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45, 48 => 'Fog',
            51, 53, 55 => 'Drizzle',
            56, 57 => 'Freezing drizzle',
            61 => 'Light rain',
            63 => 'Rain',
            65 => 'Heavy rain',
            66, 67 => 'Freezing rain',
            71 => 'Light snow',
            73 => 'Snow',
            75 => 'Heavy snow',
            77 => 'Snow grains',
            80, 81 => 'Rain showers',
            82 => 'Violent showers',
            85, 86 => 'Snow showers',
            95 => 'Thunderstorm',
            96, 99 => 'Thunderstorm with hail',
            default => '',
        };
    }

    /** "3 days ago", "today", "in 4 days" -- the phrasing a countdown wants. */
    public static function relativeDays(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        if ($days === 0) {
            return 'today';
        }
        if ($days > 0) {
            return 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
        }
        $ago = -$days;
        return $ago . ' day' . ($ago === 1 ? '' : 's') . ' ago';
    }

    /** A date the way a person reads it, without needing intl (hosting 3). */
    public static function shortDate(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', \substr($ymd, 0, 10));
        return $date === false ? $ymd : $date->format('D j M');
    }

    public static function longDate(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', \substr($ymd, 0, 10));
        return $date === false ? $ymd : $date->format('j M Y');
    }

    /** A recurring MM-DD window, as "15 Mar - 10 Apr". */
    public static function monthDayRange(?string $from, ?string $to): string
    {
        $one = self::monthDay($from);
        $two = self::monthDay($to);
        if ($one === '' && $two === '') {
            return '';
        }
        return $one . ($two !== '' ? ' - ' . $two : '');
    }

    public static function monthDay(?string $md): string
    {
        if ($md === null || !Clock::isMonthDay($md)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!m-d', $md);
        return $date === false ? $md : $date->format('j M');
    }
}
