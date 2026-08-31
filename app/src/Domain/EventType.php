<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * The plant_event vocabulary (handoff Section 5.3), with the labels the
 * timeline shows and the quantity semantics each type carries.
 *
 * Quantity attrition: quantity_live = quantity_initial + SUM(quantity_delta).
 * germinated has delta 0 but sets a marker; germination_failed, died and
 * culled carry negative deltas. Germination rate, survival rate and cull rate
 * are derived from these, never stored.
 */
final class EventType
{
    public const SEED_STARTED        = 'seed_started';
    public const DIRECT_SOWN         = 'direct_sown';
    public const TRANSPLANTED_IN     = 'transplanted_in';
    public const WATERED             = 'watered';
    public const GERMINATED          = 'germinated';
    public const GERMINATION_FAILED  = 'germination_failed';
    public const DIED                = 'died';
    public const UP_POTTED           = 'up_potted';
    public const HARDENING_STARTED   = 'hardening_started';
    public const HARDENING_SCHEDULE_SET = 'hardening_schedule_set';
    public const TRANSPLANTED        = 'transplanted';
    public const CULLED              = 'culled';
    public const YIELDED             = 'yielded';
    public const PEST_OBSERVED       = 'pest_observed';
    public const PEST_TREATED        = 'pest_treated';
    public const FERTILIZED          = 'fertilized';
    public const AMENDED             = 'amended';
    public const MULCHED             = 'mulched';
    public const PHOTO_ADDED         = 'photo_added';
    public const NOTE                = 'note';
    public const MOVED               = 'moved';

    private const LABELS = [
        self::SEED_STARTED           => 'Seed started',
        self::DIRECT_SOWN            => 'Direct sown',
        self::TRANSPLANTED_IN        => 'Brought in and transplanted',
        self::WATERED                => 'Watered',
        self::GERMINATED             => 'Germinated',
        self::GERMINATION_FAILED     => 'Failed to germinate',
        self::DIED                   => 'Died',
        self::UP_POTTED              => 'Up-potted',
        self::HARDENING_STARTED      => 'Hardening started',
        self::HARDENING_SCHEDULE_SET => 'Hardening schedule set',
        self::TRANSPLANTED           => 'Transplanted',
        self::CULLED                 => 'Culled',
        self::YIELDED                => 'Yield',
        self::PEST_OBSERVED          => 'Pest or disease observed',
        self::PEST_TREATED           => 'Pest or disease treated',
        self::FERTILIZED             => 'Fertilised',
        self::AMENDED                => 'Amended',
        self::MULCHED                => 'Mulched',
        self::PHOTO_ADDED            => 'Photo added',
        self::NOTE                   => 'Note',
        self::MOVED                  => 'Moved',
    ];

    /** Types whose quantity_delta is negative: they take life off a planting. */
    private const ATTRITION = [
        self::GERMINATION_FAILED,
        self::DIED,
        self::CULLED,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::LABELS);
    }

    public static function isValid(string $type): bool
    {
        return isset(self::LABELS[$type]);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? \ucfirst(\str_replace('_', ' ', $type));
    }

    public static function isAttrition(string $type): bool
    {
        return \in_array($type, self::ATTRITION, true);
    }

    /** The event types that put a planting in the ground for the first time. */
    public static function isInGround(string $type): bool
    {
        return \in_array($type, [self::DIRECT_SOWN, self::TRANSPLANTED_IN, self::TRANSPLANTED], true);
    }

    /** @return list<string> the types a garden_event may carry */
    public static function gardenTypes(): array
    {
        return [
            self::WATERED,
            self::PEST_OBSERVED,
            self::PEST_TREATED,
            self::FERTILIZED,
            self::AMENDED,
            self::MULCHED,
            self::PHOTO_ADDED,
            self::NOTE,
        ];
    }
}
