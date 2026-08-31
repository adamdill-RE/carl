<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * The thirteen reminder kinds of handoff Section 12, with the priority that
 * orders them in the digest and on the menu.
 *
 * Priority is not severity for its own sake: it is "how much does it cost to
 * miss this today". A freeze tonight kills a bed; a pest scouting note keeps
 * until the weekend.
 *
 * Eleven of these are Phase 3. Phase 6 added two: PEST_GDD, which is the
 * same warning as PEST_SCOUTING computed from accumulated heat rather than
 * from the calendar, and SUCCESSION, which is the sowing half of the
 * succession planner running in the digest that already exists.
 */
final class ReminderKind
{
    public const FROST_WATCH            = 'frost_watch';
    public const HEAT_WATCH             = 'heat_watch';
    public const WATERING               = 'watering';
    public const HARDENING_COUNTDOWN    = 'hardening_countdown';
    public const TRANSPLANT_WINDOW      = 'transplant_window';
    public const START_SEEDS_BY         = 'start_seeds_by';
    public const FIRST_HARVEST_EXPECTED = 'first_harvest_expected';
    public const HARVEST_WINDOW_CLOSING = 'harvest_window_closing';
    public const PEST_SCOUTING          = 'pest_scouting';
    public const PEST_GDD               = 'pest_gdd';
    public const SUCCESSION             = 'succession';
    public const RESEARCH_DIFF          = 'research_diff';
    public const INACTIVITY             = 'inactivity';

    /** @var array<string,array{label:string,priority:int}> */
    private const META = [
        self::FROST_WATCH            => ['label' => 'Frost',      'priority' => 10],
        self::HEAT_WATCH             => ['label' => 'Heat',       'priority' => 15],
        self::WATERING               => ['label' => 'Watering',   'priority' => 20],
        self::HARDENING_COUNTDOWN    => ['label' => 'Hardening',  'priority' => 30],
        self::TRANSPLANT_WINDOW      => ['label' => 'Transplant', 'priority' => 35],
        self::START_SEEDS_BY         => ['label' => 'Sow',        'priority' => 40],
        self::SUCCESSION             => ['label' => 'Sow',        'priority' => 42],
        self::FIRST_HARVEST_EXPECTED => ['label' => 'Harvest',    'priority' => 45],
        self::HARVEST_WINDOW_CLOSING => ['label' => 'Harvest',    'priority' => 50],
        // Ahead of the calendar note at 60: this one has a date behind it
        // and the action it asks for -- a row cover on before the moths fly
        // -- stops being possible a few days later.
        self::PEST_GDD               => ['label' => 'Pests',      'priority' => 55],
        self::PEST_SCOUTING          => ['label' => 'Pests',      'priority' => 60],
        self::RESEARCH_DIFF          => ['label' => 'Research',   'priority' => 70],
        self::INACTIVITY             => ['label' => 'Nudge',      'priority' => 90],
    ];

    /**
     * The kinds that need a researched region to mean anything.
     *
     * A user whose county has no research gets the global plant catalog, DTM
     * countdowns and the watering model, all of which work without it -- but
     * frost dates and planting windows are exactly what "not researched"
     * means is missing. Section 9.4 says to suppress these WITH a one-line
     * explanation rather than to silently produce nothing.
     */
    private const NEEDS_REGION = [
        self::FROST_WATCH,
        self::TRANSPLANT_WINDOW,
        self::START_SEEDS_BY,
        self::PEST_SCOUTING,
        self::PEST_GDD,
        self::SUCCESSION,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::META);
    }

    public static function isValid(string $kind): bool
    {
        return isset(self::META[$kind]);
    }

    public static function label(string $kind): string
    {
        return self::META[$kind]['label'] ?? \ucfirst(\str_replace('_', ' ', $kind));
    }

    public static function priority(string $kind): int
    {
        return self::META[$kind]['priority'] ?? 50;
    }

    public static function needsRegion(string $kind): bool
    {
        return \in_array($kind, self::NEEDS_REGION, true);
    }
}
