<?php

declare(strict_types=1);

namespace Carl\Planting;

use Carl\Support\Clock;

/**
 * Succession planting (handoff Section 15, deferred to v2 since Phase 1).
 *
 * "Sow a short row every fortnight rather than a long one in April." All the
 * pieces were already in the database when Phase 6 started -- `plant_region`
 * has the sowing windows, `plant_type` has the days to maturity, and
 * `region.first_frost_avg` says when the season stops paying out. What was
 * missing was only the arithmetic, and it is small enough to live in one
 * class with no database access at all.
 *
 * **Nothing here reads or writes.** The planner screen feeds it rows from a
 * repository and the digest feeds it rows it had already fetched for the
 * other twelve reminder kinds, so adding succession cost the digest no
 * statement. A calculator that opened its own connection could not have been
 * shared that way.
 *
 * **The research says when to stop sowing, not this class.** The last round
 * offered is the one at `window_end`, because a sowing window is precisely
 * the extension's answer to "when is it too late". Maturity that lands past
 * the average first frost is reported as a fact on the row rather than used
 * to hide it: a gardener with a row cover and a mild autumn is right more
 * often than a filter would be, and the ones who are not can see why.
 */
final class Succession
{
    /** The conventional gap between rounds of a quick crop. */
    public const INTERVAL_DAYS = 14;

    /**
     * How long after a sowing the "time for the next round" nudge stays open.
     *
     * A week, so the reminder is keyed to the sowing it follows up on rather
     * than to a date: it fires once, some morning in that week, and then the
     * chain only continues if another round actually went in. A rule that
     * fires on a fixed calendar keeps talking to somebody who stopped
     * gardening in July.
     */
    public const FOLLOW_UP_DAYS = 7;

    /**
     * The sowing dates a window still allows, from today onward.
     *
     * @param string      $today       the user's own local calendar day
     * @param string      $windowStart MM-DD from `plant_region`
     * @param string|null $windowEnd   MM-DD; a window with no end gives one round
     * @param int|null    $dtmMin      days to maturity, low end
     * @param int|null    $dtmMax      days to maturity, high end
     * @param string|null $firstFrost  MM-DD from `region.first_frost_avg`
     * @param int         $interval    days between rounds
     * @param int         $limit       most rounds to return
     *
     * @return list<array{sow_on:string,harvest_from:?string,harvest_to:?string,
     *                    after_frost:bool}>
     */
    public static function schedule(
        string $today,
        ?string $windowStart,
        ?string $windowEnd,
        ?int $dtmMin,
        ?int $dtmMax,
        ?string $firstFrost = null,
        int $interval = self::INTERVAL_DAYS,
        int $limit = 12,
    ): array {
        $open = self::windowDates($today, $windowStart, $windowEnd);
        if ($open === null) {
            return [];
        }
        [$start, $end] = $open;

        // Rounds start today when the window is already open, and at the
        // window's own start when it has not opened yet. Offering a date in
        // the past is offering something nobody can do.
        $first = $start > $today ? $start : $today;
        if ($end !== null && $first > $end) {
            return [];
        }

        $frost = $firstFrost === null ? null : Clock::recurringOn($firstFrost, (int) \substr($first, 0, 4));
        // A first frost that reads before the sowing belongs to next winter,
        // which is the one this sowing has to beat.
        if ($frost !== null && $frost < $first) {
            $frost = Clock::recurringOn((string) $firstFrost, (int) \substr($first, 0, 4) + 1);
        }

        $out = [];
        $sow = $first;
        for ($i = 0; $i < $limit; $i++) {
            if ($end !== null && $sow > $end) {
                break;
            }

            $harvestFrom = $dtmMin === null ? null : Clock::addDays($sow, $dtmMin);
            $harvestTo = $dtmMax === null ? null : Clock::addDays($sow, $dtmMax);

            $out[] = [
                'sow_on'       => $sow,
                'harvest_from' => $harvestFrom,
                'harvest_to'   => $harvestTo,
                // Measured from the EARLIEST maturity: a round whose first
                // pick is already past the frost has nothing to offer, while
                // one whose last pick is past it simply gets cut short.
                'after_frost'  => $frost !== null && $harvestFrom !== null && $harvestFrom > $frost,
            ];

            $next = Clock::addDays($sow, $interval);
            if ($next === null) {
                break;
            }
            $sow = $next;

            if ($end === null) {
                break;   // no end date means the research offers one round, not a series
            }
        }

        return $out;
    }

    /**
     * Is it time to suggest another round after a sowing?
     *
     * True for the week that starts INTERVAL_DAYS after the last one went in
     * -- and only while the window is still open with a round left in it.
     *
     * @return bool
     */
    public static function isFollowUpDue(string $today, string $lastSownOn, int $interval = self::INTERVAL_DAYS): bool
    {
        $since = Clock::daysBetween($lastSownOn, $today);
        return $since !== null
            && $since >= $interval
            && $since < $interval + self::FOLLOW_UP_DAYS;
    }

    /**
     * A recurring MM-DD window resolved to the season it is currently in.
     *
     * The subtlety is a window that wraps the new year -- "11-01" to "02-15"
     * is one winter, not a fortnight-long window read backward. Comparing the
     * two month-days is what tells them apart, and getting it wrong turns
     * every winter crop into a window that is never open.
     *
     * `Clock::inRecurringWindow()` already answers "is today inside one of
     * these", and callers wanting only that should keep using it. This
     * returns the concrete calendar dates instead, because a schedule has to
     * be STEPPED THROUGH and a boolean cannot be.
     *
     * @return array{0:string,1:?string}|null [start, end] as YYYY-MM-DD
     */
    public static function windowDates(string $today, ?string $windowStart, ?string $windowEnd): ?array
    {
        if (!Clock::isMonthDay($windowStart)) {
            return null;
        }
        $year = (int) \substr($today, 0, 4);
        $start = Clock::recurringOn((string) $windowStart, $year);
        if ($start === null) {
            return null;
        }

        if (!Clock::isMonthDay($windowEnd)) {
            // No end: the window is a single opening date. If this year's has
            // passed, the next one is next year.
            return [$start >= $today ? $start : (string) Clock::recurringOn((string) $windowStart, $year + 1), null];
        }

        $wraps = (string) $windowEnd < (string) $windowStart;
        $end = Clock::recurringOn((string) $windowEnd, $wraps ? $year + 1 : $year);
        if ($end === null) {
            return null;
        }

        if ($today > $end) {
            // This season is over; the next one starts next year.
            $start = (string) Clock::recurringOn((string) $windowStart, $year + 1);
            $end = (string) Clock::recurringOn((string) $windowEnd, $wraps ? $year + 2 : $year + 1);
        } elseif ($wraps && $today < $start) {
            // Mid-winter, inside a window that opened LAST year.
            $earlier = Clock::recurringOn((string) $windowStart, $year - 1);
            $earlierEnd = Clock::recurringOn((string) $windowEnd, $year);
            if ($earlier !== null && $earlierEnd !== null && $today <= $earlierEnd) {
                $start = $earlier;
                $end = $earlierEnd;
            }
        }

        return [$start, $end];
    }
}
