<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Planting\Succession;
use Carl\Support\Clock;

/**
 * The succession planner (handoff Section 15, deferred to v2 since Phase 1
 * and built in Phase 6).
 *
 * Phase 6 handoff Section 3.2 asked what this screen IS -- "a planner that
 * proposes dates, or a reminder that says you could sow another round of
 * beans this week". It is both, and they are the same arithmetic seen from
 * two distances: `ReminderBuilder::succession()` is the one line of this
 * page that is true today, delivered without anybody having to open it.
 *
 * **It proposes and it does not store.** There is no accepted-plan table,
 * deliberately. A plan that lives in its own table is a second answer to
 * "when should I sow", and the moment it disagrees with the sowing window in
 * the research -- which a new dataset can move -- there is no way to tell
 * which one is stale. Accepting a round here means sowing it, and sowing it
 * means a `planting` row, which is the record Carl already believes. Each
 * date is a link into Start a New Plant with the crop and the date filled
 * in, so accepting is one tap and produces the real thing.
 *
 * Three statements: the region, its sowing windows, and what this account has
 * sown before. Under the five of the working agreement (Section 17).
 */
final class SuccessionController extends Controller
{
    /**
     * How far ahead a sowing window may open and still be worth drawing.
     *
     * `Succession::schedule()` is a pure calculator and will happily return
     * next March read in September, which is the right answer to the question
     * it was asked. It is the wrong thing to put on a planner: a page that
     * answers "what can I sow" with twenty crops six months out is a page
     * nobody scrolls to the two that are open now. The policy lives here
     * rather than in the calculator, which the digest also uses.
     */
    private const HORIZON_DAYS = 60;

    /**
     * `/succession` -- every round the research still allows this season.
     */
    public function index(Request $request): Response
    {
        $user = $this->user();
        $today = $this->today();

        if (!$user->hasRegion()) {
            // Section 9.4: say what is missing rather than render an empty
            // table that looks like "nothing to sow".
            return $this->render('succession/index', [
                'today' => $today, 'hasRegion' => false,
                'crops' => [], 'firstFrost' => null, 'interval' => Succession::INTERVAL_DAYS,
                'horizon' => self::HORIZON_DAYS,
            ]);
        }

        $region = $this->reference()->findRegion((int) $user->regionId);
        $firstFrost = \is_string($region['first_frost_avg'] ?? null)
            ? (string) $region['first_frost_avg']
            : null;

        $interval = $this->intervalFrom($request);
        $windows = $this->reference()->sowingWindows((int) $user->regionId);
        $sown = $this->plantings()->lastSownByType();

        // One entry per crop, carrying every season the research gives it:
        // a lettuce with a spring window and a fall window is one row of the
        // page with two schedules, not two unrelated rows a reader has to
        // notice are the same plant.
        $crops = [];
        foreach ($windows as $window) {
            $typeId = (int) $window['plant_type_id'];

            $rounds = Succession::schedule(
                $today,
                $window['window_start'] === null ? null : (string) $window['window_start'],
                $window['window_end'] === null ? null : (string) $window['window_end'],
                self::dtm($window, 'min'),
                self::dtm($window, 'max'),
                $firstFrost,
                $interval
            );
            if ($rounds === [] || Clock::daysBetween($today, $rounds[0]['sow_on']) > self::HORIZON_DAYS) {
                continue;
            }

            $crops[$typeId] ??= [
                'plant_type_id' => $typeId,
                'category'      => (string) $window['category'],
                'type'          => (string) $window['type'],
                'recommended'   => false,
                'last_sown'     => $sown[$typeId]['last_sown'] ?? null,
                'rounds_so_far' => $sown[$typeId]['rounds'] ?? 0,
                'seasons'       => [],
            ];
            $crops[$typeId]['recommended'] = $crops[$typeId]['recommended']
                || (int) ($window['recommended'] ?? 0) === 1;
            $crops[$typeId]['seasons'][] = [
                'season'       => (string) $window['season'],
                'window_start' => $window['window_start'],
                'window_end'   => $window['window_end'],
                'confidence'   => $window['confidence'],
                'source'       => $window['source'],
                'notes'        => $window['regional_notes'],
                'rounds'       => $rounds,
            ];
        }

        // What the gardener already grows first: a page that opens on
        // somebody else's crops is a page nobody scrolls.
        \uasort($crops, static function (array $a, array $b): int {
            return [$a['last_sown'] === null, !$a['recommended'], $a['category'], $a['type']]
               <=> [$b['last_sown'] === null, !$b['recommended'], $b['category'], $b['type']];
        });

        return $this->render('succession/index', [
            'today'      => $today,
            'hasRegion'  => true,
            'regionLabel' => (string) ($region['label'] ?? ''),
            'firstFrost' => $firstFrost,
            'interval'   => $interval,
            'horizon'    => self::HORIZON_DAYS,
            'crops'      => \array_values($crops),
        ]);
    }

    /**
     * The gap between rounds, which the reader can change.
     *
     * A fortnight suits beans and lettuce; three weeks suits a slower crop
     * and a smaller household. Bounded because it is a loop counter as well
     * as a display value.
     *
     * Read off the QUERY, not the post body: this is the one form in Carl
     * that submits with GET, so that a schedule can be linked to and
     * reloaded. `Request::intInput()` reads `$_POST` and would silently hand
     * back the default here, which looks exactly like a reader who changed
     * nothing.
     */
    private function intervalFrom(Request $request): int
    {
        $asked = $request->query('every');
        if ($asked === null || \preg_match('/^\d+$/', $asked) !== 1) {
            return Succession::INTERVAL_DAYS;
        }
        return \max(7, \min(35, (int) $asked));
    }

    /** @param array<string,mixed> $window */
    private static function dtm(array $window, string $end): ?int
    {
        $override = $window['dtm_days_' . $end . '_override'] ?? null;
        if ($override !== null) {
            return (int) $override;
        }
        $global = $window['dtm_days_' . $end] ?? null;
        return $global === null ? null : (int) $global;
    }
}
