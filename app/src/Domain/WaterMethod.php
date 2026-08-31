<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * How much water a logged watering actually applied, in millimetres of depth
 * (handoff Section 11).
 *
 * There is no way to know this. A gardener logs "watered, 15 minutes" and
 * the model needs a depth. So:
 *
 *  - If the water method carries a flow rate the user entered, use it.
 *  - Otherwise fall back to a default depth per ten minutes, chosen by what
 *    the method is called: hand or can 3 mm, hose 6 mm, sprinkler 5 mm.
 *
 * **Either way the assumption goes in the reason text**, which is the part
 * Section 11 is emphatic about: the user is the only one who can correct it,
 * and they can only correct what they can see.
 *
 * An unrecognised method gets the smallest of the three. Under-estimating
 * irrigation leaves the model thinking the soil is drier than it is, so it
 * suggests watering when it need not have; over-estimating leaves it
 * suggesting a skip on a day the plant needed water. Of the two wrong
 * answers, the first is the one a gardener notices and ignores.
 */
final class WaterMethod
{
    /** Millimetres per ten minutes, by what the method is called. */
    private const DEFAULTS = [
        'sprinkler' => 5.0,
        'hose'      => 6.0,
        'wand'      => 6.0,
        'can'       => 3.0,
        'hand'      => 3.0,
        'bucket'    => 3.0,
    ];

    private const FALLBACK_MM_PER_10_MIN = 3.0;

    /**
     * @param string|null $methodName the user's name for the method
     * @param string|null $flowRate   whatever they typed in "Flow rate"
     * @return array{mm:float,basis:string} the depth, and how it was arrived at
     */
    public static function depth(int $durationMinutes, ?string $methodName, ?string $flowRate): array
    {
        $minutes = \max(0, $durationMinutes);
        if ($minutes === 0) {
            return ['mm' => 0.0, 'basis' => 'no duration logged'];
        }

        $perHour = self::parseFlowRate($flowRate);
        if ($perHour !== null) {
            return [
                'mm'    => \round($perHour * $minutes / 60, 2),
                'basis' => \sprintf('%s at the %.1f mm/h you configured', $methodName ?? 'watering', $perHour),
            ];
        }

        [$per10, $matched] = self::defaultPer10Minutes($methodName);

        return [
            'mm'    => \round($per10 * $minutes / 10, 2),
            'basis' => \sprintf('%s assumed at %.0f mm per 10 min%s',
                $methodName ?? 'watering', $per10,
                $matched ? '' : ' (method not recognised, so the lowest assumption)'),
        ];
    }

    /**
     * A flow rate in millimetres per hour, from free text.
     *
     * The field is free text because it was never going to be anything else
     * on a form a gardener fills in once. Three shapes are understood, and
     * anything else falls through to the per-method default rather than
     * guessing: "12 mm/h", "0.5 in/h", and a bare number read as mm/h.
     */
    public static function parseFlowRate(?string $flowRate): ?float
    {
        if ($flowRate === null) {
            return null;
        }
        $text = \strtolower(\trim($flowRate));
        if ($text === '') {
            return null;
        }

        if (\preg_match('/(-?\d+(?:\.\d+)?)\s*(mm|in|inch|inches)?\s*(?:\/|per\s*)?\s*(h|hr|hour)?/', $text, $m) !== 1) {
            return null;
        }

        $value = (float) $m[1];
        if ($value <= 0) {
            return null;
        }

        $unit = $m[2] ?? '';
        $isInches = $unit !== '' && $unit[0] === 'i';

        $perHour = $isInches ? $value * 25.4 : $value;

        // A plausibility bound. "50" meaning gallons per hour would otherwise
        // become 50 mm/h, which empties a checkbook in a single watering; a
        // number that large is more likely to be the wrong unit than a real
        // rate, and the default is the safer answer.
        return $perHour > 0.0 && $perHour <= 40.0 ? $perHour : null;
    }

    /** @return array{0:float,1:bool} the depth per 10 min, and whether the name matched */
    private static function defaultPer10Minutes(?string $methodName): array
    {
        $name = \strtolower((string) $methodName);
        foreach (self::DEFAULTS as $keyword => $mm) {
            if ($name !== '' && \str_contains($name, $keyword)) {
                return [$mm, true];
            }
        }
        return [self::FALLBACK_MM_PER_10_MIN, false];
    }
}
