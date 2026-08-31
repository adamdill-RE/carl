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
 *
 * derive() is the one piece of logic every other feature's correctness rests
 * on, which is why the split (docs/PLANTING-SPLIT-SPEC.md) changed it as
 * little as it could: a dispersal is a negative delta the sum already
 * handled, and what had to be added was the ability to say WHY a planting
 * reached zero. See Section 4.4 of the spec, and the ended_reason branch
 * below.
 */
final class PlantingState
{
    public const SEED_STARTED = 'seed_started';
    public const HARDENING    = 'hardening';
    public const PLANTED      = 'planted';
    public const YIELDING     = 'yielding';
    public const ENDED        = 'ended';

    /**
     * Why a planting ended. Not a sixth state: adding one to the state ENUM
     * would touch every switch on state and every label map, where a nullable
     * reason changes the sentence the UI prints and nothing else
     * (spec Section 4.4).
     */
    public const ENDED_BY_ATTRITION = 'attrition';
    public const ENDED_BY_DISPERSAL = 'dispersed';

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
     * BACKDATING A CONTRADICTION. Carl backdates everything, so this happens:
     * six plants are split out on 14 April, and on 20 April the gardener
     * records that twenty died on 12 April -- before the split. The child is
     * never retroactively resized; the six physically moved and no later
     * bookkeeping un-moves them. The parent's deltas simply sum, and a total
     * that goes below zero is CLAMPED to zero rather than kept negative or
     * thrown on. The clamp is deliberate and is asserted by 20_split_test.php:
     * an unstated clamp is a bug waiting to be "fixed" (spec Section 4.7).
     *
     * quantity_lost is clamped the same way and for the same reason: it is
     * what the survival rate divides, and a contradictory backdate must
     * degrade to "all of them" rather than to more than all of them.
     *
     * @param array{start_method:string,start_date:string,quantity_initial:int} $planting
     * @param list<array<string,mixed>> $events sorted by event_date, recorded_at
     * @return array{
     *   state:string, quantity_live:int, quantity_lost:int, in_ground_date:?string,
     *   ended_at:?string, ended_reason:?string,
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

        $initial = (int) $planting['quantity_initial'];
        $live = $initial;
        $lost = 0;
        $germinatedAt = null;
        $hardeningStartedAt = null;
        $endedAt = null;
        $endedReason = null;
        $hasYielded = false;

        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            $date = (string) $event['event_date'];

            $delta = $event['quantity_delta'];
            if ($delta !== null) {
                $live += (int) $delta;
                if (EventType::isAttrition($type)) {
                    $lost += -(int) $delta;
                }
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
            // route: culled, died, failed to germinate -- or moved out.
            //
            // The two routes are recorded apart. Before the split existed
            // this read isAttrition() alone, and a tray every plant of which
            // had been transplanted came back state=ended with ended_at=null
            // -- an inconsistent pair, and a UI that called a fully planted
            // tray "ended", which reads as dead.
            if ($live <= 0 && $endedAt === null) {
                if (EventType::isAttrition($type)) {
                    $endedAt = $date;
                    $endedReason = self::ENDED_BY_ATTRITION;
                } elseif (EventType::isDispersal($type)) {
                    $endedAt = $date;
                    $endedReason = self::ENDED_BY_DISPERSAL;
                }
            }
        }

        if ($live < 0) {
            $live = 0;
        }
        $lost = \max(0, \min($lost, $initial));

        if ($live === 0) {
            $state = self::ENDED;
        } elseif ($endedAt !== null) {
            // A later event brought it back above zero (an edit, or a
            // backdated correction); it is not ended after all.
            $endedAt = null;
            $endedReason = null;
            if ($state === self::ENDED) {
                $state = $hasYielded ? self::YIELDING : self::PLANTED;
            }
        }

        return [
            'state'                => $state,
            'quantity_live'        => $live,
            'quantity_lost'        => $lost,
            'in_ground_date'       => $inGround,
            'ended_at'             => $endedAt,
            'ended_reason'         => $endedReason,
            'germinated_at'        => $germinatedAt,
            'hardening_started_at' => $hardeningStartedAt,
        ];
    }

    /**
     * Survival as a whole percentage of what was started, or null when there
     * is nothing to divide by.
     *
     * ONE expression, in one place, because there were two and a split would
     * have made them disagree (spec Section 4.5). The denominator is what was
     * started; the numerator is what was started MINUS ATTRITION, and
     * deliberately not what is still on this row. A tray of a hundred with
     * ninety-four still in it and six transplanted into a bed lost nothing,
     * and reads 100%.
     */
    public static function survivalPercent(int $initial, int $lost): ?int
    {
        if ($initial <= 0) {
            return null;
        }
        return (int) \round(\max(0, $initial - $lost) / $initial * 100);
    }

    /**
     * The sentence a plant page prints for a planting that has ended.
     *
     * A null reason is attrition: it is what every row that ended before the
     * split existed carries, and nothing could have dispersed then.
     */
    public static function endedLabel(?string $reason): string
    {
        return $reason === self::ENDED_BY_DISPERSAL ? 'Fully moved out' : 'Ended';
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
                // MOVED was in the vocabulary from the first design and
                // implemented nowhere until the split gave it something to
                // mean: a plant that changes bed without being lifted out of
                // the ground for the first time (spec Section 3, fact 3).
                EventType::MOVED,
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
