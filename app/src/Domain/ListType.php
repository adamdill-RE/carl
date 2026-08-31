<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * The user-set variables of handoff Section 4.9, stored in one generic table
 * (Section 5.6). Containers and hardening schedules are NOT here: other rows
 * hold foreign keys to them, so they keep their own tables and the Lists
 * screen edits them alongside these.
 */
final class ListType
{
    public const SEED_SOURCE          = 'seed_source';
    public const SEED_STARTING_SOIL   = 'seed_starting_soil';
    public const SEED_STARTING_VESSEL = 'seed_starting_vessel';
    public const UP_POT_SOIL          = 'up_pot_soil';
    public const UP_POT_CONTAINER     = 'up_pot_container';
    public const FERTILIZER_SOW       = 'fertilizer_sow';
    public const FERTILIZER_GARDEN    = 'fertilizer_garden';
    public const NURSERY              = 'nursery';
    public const WATER_METHOD         = 'water_method';
    public const SHADE_CLOTH          = 'shade_cloth';
    public const SOIL_AMENDMENT       = 'soil_amendment';
    public const PEST_TREATMENT       = 'pest_treatment';
    public const PEST_DISEASE         = 'pest_disease';
    public const CULL_REASON          = 'cull_reason';
    public const MULCH_TYPE           = 'mulch_type';

    /**
     * label, the singular used in "+ Add new ...", and what attr_1 / attr_2
     * mean for that list. A null attribute is not shown on the form.
     *
     * @var array<string,array{label:string,singular:string,attr_1:?string,attr_2:?string}>
     */
    private const META = [
        self::SEED_SOURCE => [
            'label' => 'Seed sources', 'singular' => 'seed source',
            'attr_1' => null, 'attr_2' => 'Notes',
        ],
        self::SEED_STARTING_SOIL => [
            'label' => 'Seed-starting soils', 'singular' => 'seed-starting soil',
            'attr_1' => 'Brand', 'attr_2' => 'Notes',
        ],
        self::SEED_STARTING_VESSEL => [
            'label' => 'Seed-starting vessels', 'singular' => 'seed-starting vessel',
            'attr_1' => 'Cell count or size', 'attr_2' => 'Notes',
        ],
        self::UP_POT_SOIL => [
            'label' => 'Up-pot soils', 'singular' => 'up-pot soil',
            'attr_1' => 'Brand', 'attr_2' => 'Notes',
        ],
        self::UP_POT_CONTAINER => [
            'label' => 'Up-pot containers', 'singular' => 'up-pot container',
            'attr_1' => 'Size', 'attr_2' => 'Notes',
        ],
        self::FERTILIZER_SOW => [
            'label' => 'Fertilisers (sowing)', 'singular' => 'fertiliser',
            'attr_1' => 'N-P-K', 'attr_2' => 'Notes',
        ],
        self::FERTILIZER_GARDEN => [
            'label' => 'Fertilisers (garden)', 'singular' => 'fertiliser',
            'attr_1' => 'N-P-K', 'attr_2' => 'Notes',
        ],
        self::NURSERY => [
            'label' => 'Nurseries', 'singular' => 'nursery',
            'attr_1' => 'Town', 'attr_2' => 'Notes',
        ],
        self::WATER_METHOD => [
            'label' => 'Water methods', 'singular' => 'water method',
            'attr_1' => 'Flow rate', 'attr_2' => 'Notes',
        ],
        self::SHADE_CLOTH => [
            'label' => 'Shade cloths', 'singular' => 'shade cloth',
            'attr_1' => 'Brand', 'attr_2' => 'Percent shade',
        ],
        self::SOIL_AMENDMENT => [
            'label' => 'Soil amendments', 'singular' => 'soil amendment',
            'attr_1' => 'Brand', 'attr_2' => 'Notes',
        ],
        self::PEST_TREATMENT => [
            'label' => 'Pest and disease treatments', 'singular' => 'treatment',
            'attr_1' => 'Active ingredient', 'attr_2' => 'Notes',
        ],
        self::PEST_DISEASE => [
            'label' => 'Pests and diseases', 'singular' => 'pest or disease',
            'attr_1' => null, 'attr_2' => 'Notes',
        ],
        self::CULL_REASON => [
            'label' => 'Cull reasons', 'singular' => 'cull reason',
            'attr_1' => null, 'attr_2' => 'Notes',
        ],
        self::MULCH_TYPE => [
            'label' => 'Mulch types', 'singular' => 'mulch type',
            'attr_1' => null, 'attr_2' => 'Notes',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::META);
    }

    public static function isValid(string $type): bool
    {
        return isset(self::META[$type]);
    }

    public static function label(string $type): string
    {
        return self::META[$type]['label'] ?? $type;
    }

    public static function singular(string $type): string
    {
        return self::META[$type]['singular'] ?? 'item';
    }

    public static function attr1Label(string $type): ?string
    {
        return self::META[$type]['attr_1'] ?? null;
    }

    public static function attr2Label(string $type): ?string
    {
        return self::META[$type]['attr_2'] ?? null;
    }

    /**
     * Cull reasons are seeded per user so the dropdown is not empty on the
     * first cull (handoff Section 5.6). Pests come from the global reference
     * table instead, so they are not listed here.
     *
     * @return list<string>
     */
    public static function seedCullReasons(): array
    {
        return [
            'Disease',
            'Pest damage',
            'Poor performance',
            'Overcrowding',
            'End of season',
            'Weather damage',
            'Thinning',
        ];
    }
}
