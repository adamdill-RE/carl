<?php

declare(strict_types=1);

namespace Carl\Domain;

/**
 * State derivation (handoff Section 5.3).
 *
 * State is a CACHE on planting.state, recomputed after any event insert, edit
 * or delete -- including a backdated one. The timeline is always sorted by
 * event_date then recorded_at, so a backdated event lands in the right place
 * and the state is re-derived from the whole ordered log rather than nudged
 * from wherever it happened to be.
 */
final class PlantingState
{
    public const SEED_STARTED = 'seed_started';
    public const HARDENING    = 'hardening';
    public const PLANTED      = 'planted';
    public const YIELDING     = 'yielding';
    public const ENDED        = 'ended';

    private const LABELS = [
        self::SEED_STARTED => 'Seed started',
        self::HARDENING    => 'Hardening off',
        self::PLANTED      => 'Planted',
        self::YIELDING     => 'Yielding',
        self::ENDED        => 'Ended',
    ];

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return \array_keys(self::LABELS);
    }

    /**
     * Recompute state, live quantity and the milestone dates from the whole
     * ordered event log.
     *
     * @param array{start_method:string,start_date:string,quantity_initial:int} $planting
     * @param list<array<string,mixed>> $events sorted by event_date, recorded_at
     * @return array{
     *   state:string, quantity_live:int, in_ground_date:?string, ended_at:?string,
     *   germinated_at:?string, hardening_started_at:?string
     * }
     */
    public static function derive(array $planting, array $events): array
    {
        $startMethod = $planting['start_method'];

        // An indoor seed start begins as a seed; anything sown or bought in is
        // already in the ground on day one.
        $state = $startMethod === 'indoor_seed' ? self::SEED_STARTED : self::PLANTED;
        $inGround = $startMethod === 'indoor_seed' ? null : $planting['start_date'];

        $live = (int) $planting['quantity_initial'];
        $germinatedAt = null;
        $hardeningStartedAt = null;
        $endedAt = null;
        $hasYielded = false;

        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            $date = (string) $event['event_date'];

            $delta = $event['quantity_delta'];
            if ($delta !== null) {
                $live += (int) $delta;
            }

            switch ($type) {
                case EventType::GERMINATED:
                    $germinatedAt ??= $date;
                    break;

                case EventType::HARDENING_STARTED:
                    $hardeningStartedAt = $date;
                    if ($state === self::SEED_STARTED) {
                        $state = self::HARDENING;
                    }
                    break;

                case EventType::TRANSPLANTED:
                case EventType::DIRECT_SOWN:
                case EventType::TRANSPLANTED_IN:
                    $inGround ??= $date;
                    if ($state !== self::YIELDING && $state !== self::ENDED) {
                        $state = self::PLANTED;
                    }
                    break;

                case EventType::YIELDED:
                    $hasYielded = true;
                    if ($state !== self::ENDED) {
                        $state = self::YIELDING;
                    }
                    break;
            }

            // Ended the moment the last living plant is gone, by whatever
            // route: culled, died, or failed to germinate.
            if ($live <= 0 && $endedAt === null && EventType::isAttrition($type)) {
                $endedAt = $date;
            }
        }

        if ($live < 0) {
            $live = 0;
        }

        if ($live === 0) {
            $state = self::ENDED;
        } elseif ($endedAt !== null) {
            // A later event brought it back above zero (an edit, or a
            // backdated correction); it is not ended after all.
            $endedAt = null;
            if ($state === self::ENDED) {
                $state = $hasYielded ? self::YIELDING : self::PLANTED;
            }
        }

        return [
            'state'                => $state,
            'quantity_live'        => $live,
            'in_ground_date'       => $inGround,
            'ended_at'             => $endedAt,
            'germinated_at'        => $germinatedAt,
            'hardening_started_at' => $hardeningStartedAt,
        ];
    }

    /**
     * The actions offered for a state (handoff Section 4.4). Photos and notes
     * are attachable to any event and are offered everywhere.
     *
     * @return list<string>
     */
    public static function actionsFor(string $state): array
    {
        return match ($state) {
            self::SEED_STARTED => [
                EventType::WATERED,
                EventType::GERMINATED,
                EventType::GERMINATION_FAILED,
                EventType::DIED,
                EventType::UP_POTTED,
                EventType::HARDENING_STARTED,
                EventType::TRANSPLANTED,
                EventType::PHOTO_ADDED,
                EventType::NOTE,
            ],
            self::HARDENING => [
                EventType::HARDENING_SCHEDULE_SET,
                EventType::WATERED,
                EventType::TRANSPLANTED,
                EventType::DIED,
                EventType::PHOTO_ADDED,
                EventType::NOTE,
            ],
            self::PLANTED, self::YIELDING => [
                EventType::WATERED,
                EventType::YIELDED,
                EventType::PEST_OBSERVED,
                EventType::PEST_TREATED,
                EventType::FERTILIZED,
                EventType::AMENDED,
                EventType::MULCHED,
                EventType::CULLED,
                EventType::DIED,
                EventType::PHOTO_ADDED,
                EventType::NOTE,
            ],
            // An ended planting still accepts a photo or a note: the log is
            // append-only and people write things up afterwards.
            default => [EventType::PHOTO_ADDED, EventType::NOTE],
        };
    }
}
