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
 *
 * split_out also carries a negative delta and is NOT attrition. The plants it
 * takes off a planting are alive and somewhere else; counting them as losses
 * makes six tomatoes transplanted into a bed read as six tomatoes dying. The
 * sign is the same and the meaning is opposite, so the two are told apart by
 * name -- isAttrition() and isDispersal() -- and never by the sign
 * (docs/PLANTING-SPLIT-SPEC.md Section 4.3).
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
    public const SPLIT_OUT           = 'split_out';
    public const MEASURED            = 'measured';

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
        self::SPLIT_OUT              => 'Split out',
        self::MEASURED               => 'Measured',
    ];

    /** Types that take life off a planting: the plants are gone. */
    private const ATTRITION = [
        self::GERMINATION_FAILED,
        self::DIED,
        self::CULLED,
    ];

    /**
     * Types that take plants off a planting WITHOUT losing them: they went
     * somewhere else and are somebody else's row now.
     */
    private const DISPERSAL = [
        self::SPLIT_OUT,
    ];

    /**
     * The actions that move plants to a different place, and so are the only
     * ones a split can come out of.
     *
     * The rule is one line and it is the whole of when Carl splits: a split
     * happens when, and only when, a subset moves to a DIFFERENT location.
     * Watering, fertilising, dying, culling and yielding all apply to a
     * quantity within one location -- the plants do not go anywhere -- and
     * must never split (spec Section 4.1).
     */
    private const RELOCATION = [
        self::TRANSPLANTED,
        self::UP_POTTED,
        self::MOVED,
    ];

    /**
     * Actions that mean one plant and cannot be applied to a batch.
     *
     * A batch writes the SAME event to every selected planting, which is
     * right for "I watered all of them" and a lie for "they are fourteen
     * inches tall": a measurement belongs to the thing measured. Nothing else
     * on the log form has this property -- a duration, a fertiliser and a
     * cull reason are all genuinely shared -- so this is a list of one, and
     * it is a named list rather than an `if` in the controller because the
     * form and the POST handler both have to agree about it.
     */
    private const SINGLE_PLANT_ONLY = [
        self::MEASURED,
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

    /** A negative delta that is not a loss: the plants moved out. */
    public static function isDispersal(string $type): bool
    {
        return \in_array($type, self::DISPERSAL, true);
    }

    /**
     * Is this an action a batch must not offer? See SINGLE_PLANT_ONLY.
     */
    public static function isSinglePlantOnly(string $type): bool
    {
        return \in_array($type, self::SINGLE_PLANT_ONLY, true);
    }

    /** Does this action put the plants somewhere else? */
    public static function isRelocation(string $type): bool
    {
        return \in_array($type, self::RELOCATION, true);
    }

    /** @return list<string> */
    public static function relocations(): array
    {
        return self::RELOCATION;
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
