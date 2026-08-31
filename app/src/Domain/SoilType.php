<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * Garden soil, which feeds the watering model in Phase 3.
 *
 * Total available water in the root zone (TAW, mm) and management-allowed
 * depletion (MAD = 50% of TAW) per handoff Section 11. Stored here now
 * because the Build Garden screen has to offer the same list the model will
 * later read, and a mismatch between the two is a silent wrong answer.
 */
final class SoilType
{
    /** @var array<string,array{label:string,taw_mm:int,mad_mm:int}> */
    private const SOILS = [
        'clay'           => ['label' => 'Clay',           'taw_mm' => 60, 'mad_mm' => 30],
        'loam'           => ['label' => 'Loam',           'taw_mm' => 50, 'mad_mm' => 25],
        'sandy'          => ['label' => 'Sandy',          'taw_mm' => 35, 'mad_mm' => 17],
        'raised_bed_mix' => ['label' => 'Raised-bed mix', 'taw_mm' => 45, 'mad_mm' => 22],
        'container'      => ['label' => 'Container',      'taw_mm' => 20, 'mad_mm' => 10],
    ];

    /** @return array<string,string> value => label, for a select */
    public static function options(): array
    {
        $out = [];
        foreach (self::SOILS as $key => $meta) {
            $out[$key] = $meta['label'];
        }
        return $out;
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && isset(self::SOILS[$key]);
    }

    public static function label(?string $key): string
    {
        return self::SOILS[$key]['label'] ?? 'Not set';
    }

    public static function taw(?string $key): int
    {
        return self::SOILS[$key]['taw_mm'] ?? 50;
    }

    public static function mad(?string $key): int
    {
        return self::SOILS[$key]['mad_mm'] ?? 25;
    }
}
