<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * The FAO-56 crop coefficient curve, from the numbers the research import
 * puts on `plant_type` (handoff Section 11).
 *
 * Four stages, three coefficients:
 *
 *   Kc_ini  ----.                     initial: emergence to ~10% cover
 *                \                    development: rising to full canopy
 *                 `---- Kc_mid ----.  mid-season: flowering to early maturity
 *                                   \ late season: falling to harvest
 *                                    `---- Kc_end
 *
 * A type with no curve loaded gets 1.0 flat, which is the reference crop --
 * the honest answer when nothing is known, and the one that neither
 * over- nor under-waters relative to ET0 itself.
 */
final class KcCurve
{
    private const DEFAULT_KC = 1.0;

    public function __construct(
        private ?float $kcIni,
        private ?float $kcMid,
        private ?float $kcEnd,
        private int $daysIni,
        private int $daysDev,
        private int $daysMid,
        private int $daysLate,
    ) {
    }

    /** @param array<string,mixed> $plantType a row of `plant_type` */
    public static function fromPlantType(array $plantType): self
    {
        return new self(
            self::floatOrNull($plantType['kc_ini'] ?? null),
            self::floatOrNull($plantType['kc_mid'] ?? null),
            self::floatOrNull($plantType['kc_end'] ?? null),
            (int) ($plantType['stage_days_ini'] ?? 0),
            (int) ($plantType['stage_days_dev'] ?? 0),
            (int) ($plantType['stage_days_mid'] ?? 0),
            (int) ($plantType['stage_days_late'] ?? 0),
        );
    }

    public function hasCurve(): bool
    {
        return $this->kcMid !== null && ($this->daysIni + $this->daysDev + $this->daysMid) > 0;
    }

    /**
     * Kc on a given day of the crop's life, counted from the day it went in
     * the ground -- or from the start date while it is still a seedling
     * (handoff Section 11).
     */
    public function at(int $dayOfLife): float
    {
        if (!$this->hasCurve()) {
            return $this->kcMid ?? $this->kcIni ?? self::DEFAULT_KC;
        }

        $day = \max(0, $dayOfLife);
        $ini = $this->kcIni ?? $this->kcMid ?? self::DEFAULT_KC;
        $mid = $this->kcMid ?? self::DEFAULT_KC;
        $end = $this->kcEnd ?? $mid;

        $endOfIni = $this->daysIni;
        $endOfDev = $endOfIni + $this->daysDev;
        $endOfMid = $endOfDev + $this->daysMid;
        $endOfLate = $endOfMid + $this->daysLate;

        if ($day <= $endOfIni) {
            return $ini;
        }
        if ($day <= $endOfDev && $this->daysDev > 0) {
            return self::lerp($ini, $mid, ($day - $endOfIni) / $this->daysDev);
        }
        if ($day <= $endOfMid) {
            return $mid;
        }
        if ($day <= $endOfLate && $this->daysLate > 0) {
            return self::lerp($mid, $end, ($day - $endOfMid) / $this->daysLate);
        }

        // Past the curve: a perennial or an over-run annual is still there
        // and still transpiring, at the end-of-season rate.
        return $end;
    }

    private static function lerp(float $from, float $to, float $fraction): float
    {
        $fraction = \max(0.0, \min(1.0, $fraction));
        return \round($from + ($to - $from) * $fraction, 3);
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
