<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * What a drip zone puts down, from what is printed on the emitter packet
 * (Phase 14; migration 025).
 *
 * The checkbook (handoff Section 11) needs a depth in millimetres for every
 * watering. `WaterMethod` gets one from a flow rate typed in mm/h, or from a
 * guess by the method's name. This gets one from three numbers a gardener
 * actually has -- gallons per hour per emitter, inches between emitters,
 * inches between lines -- and one they can estimate, the share of that
 * water that reaches the roots.
 *
 * The arithmetic is the standard one, printed by every extension service
 * and every manufacturer's application-rate note:
 *
 *     rate (in/h) = 231 x gph / (emitter spacing in x line spacing in)
 *
 * Each emitter is responsible for the rectangle between it and its
 * neighbours, so gallons an hour spread over that rectangle is a depth an
 * hour. 231 is the cubic inches in a US gallon; 25.4 turns inches into the
 * millimetres the model is kept in. A half-gallon emitter every 12 inches on
 * lines 12 inches apart is 0.80 in/h, about 20 mm/h; the same line at a
 * 36-inch row spacing is a third of that.
 *
 * Then the net depth is `rate x hours x efficiency`. Efficiency is the
 * gross-to-net factor irrigation engineers call application efficiency,
 * measured at 80-95 per cent on drip in the field; 80 is the default, the
 * conservative end, and it errs toward thinking the soil is drier than it
 * is -- the mistake a gardener notices and ignores (see WaterMethod).
 *
 * Two of the four inputs may be missing, and each missing one is filled in
 * with a stated assumption rather than refused, because the alternative is
 * the per-method guess, which knows nothing at all:
 *
 *  - no emitter spacing: 12 inches, the most common inline spacing sold;
 *  - no line spacing: the garden's own row spacing, derived from its width
 *    and row count, or else the emitter spacing (a square grid).
 *
 * Every assumption is in the basis text, which the recommendation prints, so
 * the user can see it and correct it on the zone.
 */
final class DripLine
{
    public const DEFAULT_EFFICIENCY_PCT = 80;

    /** Inline drip is sold at 6, 9, 12, 18 and 24 inches; 12 is the common one. */
    public const ASSUMED_EMITTER_SPACING_IN = 12.0;

    /** Cubic inches in a US gallon. */
    private const CUBIC_INCHES_PER_GALLON = 231.0;

    private const MM_PER_INCH = 25.4;
    private const LITRES_PER_GALLON = 3.785411784;

    /** Bounds the form enforces. Outside them the value is far more likely a typo than a system. */
    public const GPH_MIN = 0.01;
    public const GPH_MAX = 60.0;
    public const SPACING_MIN_IN = 1.0;
    public const SPACING_MAX_IN = 240.0;
    public const EFFICIENCY_MIN_PCT = 10;
    public const EFFICIENCY_MAX_PCT = 100;

    /** Gross application rate in mm/h. Efficiency is not applied here. */
    public static function rateMmPerHour(float $gph, float $emitterSpacingIn, float $lineSpacingIn): float
    {
        if ($gph <= 0.0 || $emitterSpacingIn <= 0.0 || $lineSpacingIn <= 0.0) {
            return 0.0;
        }
        $inchesPerHour = self::CUBIC_INCHES_PER_GALLON * $gph / ($emitterSpacingIn * $lineSpacingIn);
        return \round($inchesPerHour * self::MM_PER_INCH, 3);
    }

    /**
     * The net depth a zone watering applied, or null when the zone carries
     * no emitter figure and the caller should fall back to the method.
     *
     * @param array<string,mixed> $zone            a `water_zone` row (or the four columns of one)
     * @param float|null          $gardenRowSpacingIn the garden's own row spacing, if derivable
     * @return array{mm:float,basis:string,rate_mm_h:float,efficiency_pct:int}|null
     */
    public static function depth(int $durationMinutes, array $zone, ?float $gardenRowSpacingIn): ?array
    {
        $spec = self::resolve($zone, $gardenRowSpacingIn);
        if ($spec === null) {
            return null;
        }

        $minutes = \max(0, $durationMinutes);
        $gross = $spec['rate_mm_h'] * $minutes / 60.0;
        $net = \round($gross * $spec['efficiency_pct'] / 100.0, 2);

        $name = \trim((string) ($zone['name'] ?? ''));
        $basis = ($name !== '' ? $name : 'the zone') . ': ' . $spec['description']
            . ($minutes > 0
                ? \sprintf(', so %d min put down about %.0f mm', $minutes, $net)
                : '');

        return [
            'mm'             => $minutes === 0 ? 0.0 : $net,
            'basis'          => $basis,
            'rate_mm_h'      => $spec['rate_mm_h'],
            'efficiency_pct' => $spec['efficiency_pct'],
        ];
    }

    /**
     * Minutes this zone needs to run to put a depth into the root zone --
     * the deficit, usually. Rounded up to the next five minutes, because
     * nobody sets a timer for 37.
     */
    public static function minutesFor(float $netMm, float $rateMmPerHour, int $efficiencyPct): ?int
    {
        if ($netMm <= 0.0 || $rateMmPerHour <= 0.0 || $efficiencyPct <= 0) {
            return null;
        }
        $minutes = $netMm / ($rateMmPerHour * $efficiencyPct / 100.0) * 60.0;
        return (int) (\ceil($minutes / 5.0) * 5);
    }

    /**
     * The rate and the spelled-out assumptions, or null when there is no
     * emitter figure to reason from.
     *
     * @param array<string,mixed> $zone
     * @return array{rate_mm_h:float,efficiency_pct:int,emitter_spacing_in:float,
     *               line_spacing_in:float,description:string}|null
     */
    public static function resolve(array $zone, ?float $gardenRowSpacingIn): ?array
    {
        $gph = self::floatOrNull($zone['emitter_gph'] ?? null);
        if ($gph === null || $gph <= 0.0) {
            return null;
        }

        $emitter = self::floatOrNull($zone['emitter_spacing_in'] ?? null);
        $emitterAssumed = $emitter === null || $emitter <= 0.0;
        if ($emitterAssumed) {
            $emitter = self::ASSUMED_EMITTER_SPACING_IN;
        }

        $line = self::floatOrNull($zone['line_spacing_in'] ?? null);
        $lineSource = 'set';
        if ($line === null || $line <= 0.0) {
            if ($gardenRowSpacingIn !== null && $gardenRowSpacingIn > 0.0) {
                $line = $gardenRowSpacingIn;
                $lineSource = 'garden';
            } else {
                $line = $emitter;
                $lineSource = 'square';
            }
        }

        $efficiency = (int) ($zone['efficiency_pct'] ?? self::DEFAULT_EFFICIENCY_PCT);
        if ($efficiency <= 0 || $efficiency > 100) {
            $efficiency = self::DEFAULT_EFFICIENCY_PCT;
        }

        $rate = self::rateMmPerHour($gph, (float) $emitter, (float) $line);

        $description = \sprintf('%s gph emitters every %s in%s, lines %s in apart%s, %d%% reaching the roots,'
            . ' about %.1f mm/h',
            self::trimNumber($gph),
            self::trimNumber((float) $emitter),
            $emitterAssumed ? ' (assumed)' : '',
            self::trimNumber((float) $line),
            match ($lineSource) {
                'garden' => ' (this garden\'s row spacing)',
                'square' => ' (assumed, same as the emitters)',
                default  => '',
            },
            $efficiency,
            $rate
        );

        return [
            'rate_mm_h'          => $rate,
            'efficiency_pct'     => $efficiency,
            'emitter_spacing_in' => (float) $emitter,
            'line_spacing_in'    => (float) $line,
            'description'        => $description,
        ];
    }

    /**
     * The garden's row spacing in inches, from its width and row count.
     *
     * Rows running north-south are spaced across the east-west dimension.
     * Null when the garden has no dimensions or no rows, which is most
     * gardens built in a hurry: the caller then assumes instead.
     *
     * @param array<string,mixed> $garden
     */
    public static function rowSpacingIn(array $garden): ?float
    {
        $rows = (int) ($garden['row_count'] ?? 0);
        $across = (string) ($garden['row_orientation'] ?? 'ns') === 'ew'
            ? self::floatOrNull($garden['ns_ft'] ?? null)
            : self::floatOrNull($garden['ew_ft'] ?? null);
        if ($rows <= 0 || $across === null || $across <= 0.0) {
            return null;
        }
        return \round($across * 12.0 / $rows, 1);
    }

    // -- Units, for the form ----------------------------------------------

    public static function gphToLitresPerHour(float $gph): float
    {
        return \round($gph * self::LITRES_PER_GALLON, 2);
    }

    public static function litresPerHourToGph(float $lph): float
    {
        return \round($lph / self::LITRES_PER_GALLON, 3);
    }

    public static function inchesToCm(float $inches): float
    {
        return \round($inches * 2.54, 1);
    }

    public static function cmToInches(float $cm): float
    {
        return \round($cm / 2.54, 2);
    }

    public static function mmPerHourToInchesPerHour(float $mm): float
    {
        return \round($mm / self::MM_PER_INCH, 2);
    }

    /** "0.5", not "0.500"; "12", not "12.00". */
    public static function trimNumber(float $value): string
    {
        $text = \number_format($value, 3, '.', '');
        $text = \rtrim(\rtrim($text, '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
