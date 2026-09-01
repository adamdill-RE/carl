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
     * The treatment shelf, seeded per user (Phase 9).
     *
     * The pests half of a pest log has come from the global catalogue since
     * Phase 1 and now actually has something in it
     * (db/migrations/022_pest_reference.sql). The TREATMENT half was still a
     * blank box, and it is asked at the same moment and for the same reason:
     * somebody standing at a plant with a bottle in their hand, deciding what
     * to write down. A blank box there gets "soap" one week and "insecticidal
     * soap" the next, and the two never join up again.
     *
     * ORDERED LEAST DRASTIC FIRST, which is not decoration -- it is the order
     * an IPM programme puts them in, so the dropdown reads as the sequence a
     * gardener is supposed to work through rather than as an alphabet.
     * "Watched, not treated" is therefore FIRST, and it is the honest answer
     * often enough to be worth a row of its own: a pest observed and
     * deliberately left alone is a decision, and a log that cannot record it
     * loses it.
     *
     * attr_1 is the ACTIVE INGREDIENT, because that is what a label carries
     * and what the reference entries name -- never a brand, and never a rate.
     * The label on the bottle is the legal authority on both
     * (db/migrations/022_pest_reference.sql).
     *
     * They are ordinary user rows: archive any of them, add your own.
     *
     * @return list<array{0:string,1:?string}>
     */
    public static function seedPestTreatments(): array
    {
        return [
            ['Watched, not treated',        null],
            ['Removed by hand',             null],
            ['Pruned out and binned',       null],
            ['Strong water spray',          null],
            ['Row cover or netting',        null],
            ['Trap or barrier',             null],
            ['Beneficial insects released', null],
            ['Insecticidal soap',           'Potassium salts of fatty acids'],
            ['Horticultural oil',           'Mineral or plant-derived oil'],
            ['Neem',                        'Azadirachtin / clarified neem oil'],
            ['Kaolin clay',                 'Kaolin'],
            ['Bt for caterpillars',         'Bacillus thuringiensis kurstaki'],
            ['Bt for beetle larvae',        'Bacillus thuringiensis tenebrionis'],
            ['Spinosad',                    'Spinosad'],
            ['Iron phosphate slug bait',    'Iron phosphate'],
            ['Diatomaceous earth',          'Diatomaceous earth'],
            ['Biofungicide',                'Bacillus subtilis'],
            ['Potassium bicarbonate',       'Potassium bicarbonate'],
            ['Sulfur',                      'Sulfur'],
            ['Copper fungicide',            'Copper'],
            ['Pyrethrin',                   'Pyrethrins'],
        ];
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
