<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Planting\Calendar;
use Carl\Support\Clock;

/**
 * The Calendar (Phase 9): a month of the garden, and the table of what is
 * coming.
 *
 * **Why it is a screen and not a report.** Everything on it was already
 * derivable -- the digest computes eight of these rules every morning and the
 * plant page counts down to a harvest -- but all of it was answered one plant
 * at a time or one morning at a time. "What does October look like" had no
 * page at all, and it is the question somebody asks in August when they are
 * deciding whether to start another round of beans.
 *
 * **It computes nothing the digest does not.** `Planting\Calendar` is the
 * calculator and its docblock is the argument: same research values, same
 * arithmetic, the digest's own `dtmAnchor()`. What differs is the question --
 * the digest decides when to SPEAK, this one draws WHEN IT IS -- so the
 * "seven days out" gates are absent here on purpose.
 *
 * **Statement budget: six of its own, and three for an unresearched county.**
 * One reads the plantings, two read what was logged over the drawn month, and
 * three read the research the projections come out of -- which are skipped
 * entirely without a region, because every one of them would return nothing.
 * Seven measured end to end, the seventh being the one `Auth::user()` makes
 * on every request in the application. The plant filter costs no statement at
 * all: the rows it filters and the options it offers are the same array.
 */
final class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $today = $this->today();
        $user = $this->user();

        $month = Calendar::normaliseMonth($request->query('month'), $today);
        [$gridFrom, $gridTo] = Calendar::gridRange($month);

        // Projections are computed over the union of the drawn month and the
        // window the table below looks at, so paging to March does not empty
        // the "upcoming" list and looking at "upcoming" does not need a
        // second pass over the same rules.
        $horizon = (string) Clock::addDays($today, Calendar::UPCOMING_DAYS);
        $from = \min($gridFrom, $today);
        $to = \max($gridTo, $horizon);

        // Ended plantings included: their logged events are half of what a
        // past month has to show, and Calendar::perPlanting() is what refuses
        // to project a future for them.
        $plantings = $this->plantings()->listWithDetail(['living' => false], 1000);

        $regionId = $user->regionId;
        $region = $regionId === null ? null : $this->reference()->findRegion($regionId);

        $entries = Calendar::build(
            $from,
            $to,
            $plantings,
            $this->events()->calendarEvents($gridFrom, $gridTo),
            $this->events()->calendarGardenEvents($gridFrom, $gridTo),
            $region,
            $this->reference()->windowsForRegion($regionId),
            $this->reference()->pestWindowsForRegion($regionId),
        );

        $filter = $this->readFilter($request, $plantings);
        $entries = self::applyFilter($entries, $filter);

        $grid = [];
        $upcoming = [];
        foreach ($entries as $entry) {
            if ($entry['date'] >= $gridFrom && $entry['date'] <= $gridTo) {
                $grid[] = $entry;
            }
            // "Upcoming" is what is still to come and still to be done, so a
            // logged event is never in it however recent: the grid is where
            // what happened lives.
            if ($entry['projected'] && $entry['date'] >= $today && $entry['date'] <= $horizon) {
                $upcoming[] = $entry;
            }
        }

        return $this->render('calendar/index', [
            'month'      => $month,
            'weeks'      => Calendar::grid($month),
            'byDate'     => Calendar::byDate($grid),
            'upcoming'   => \array_slice($upcoming, 0, Calendar::UPCOMING_LIMIT),
            'truncated'  => \count($upcoming) > Calendar::UPCOMING_LIMIT,
            'horizon'    => $horizon,
            'prev'       => Calendar::shiftMonth($month, -1),
            'next'       => Calendar::shiftMonth($month, 1),
            'thisMonth'  => \substr($today, 0, 7),
            'plantings'  => $plantings,
            'filter'     => $filter,
            'hasRegion'  => $region !== null && (string) $region['research_status'] === 'researched',
            'today'      => $today,
            'pageTitle'  => 'Calendar',
        ]);
    }

    /**
     * The plant filter, read off the query string and checked against the
     * plantings this account actually has.
     *
     * A posted id is not evidence of anything (handoff Section 5), and here
     * it costs nothing to prove: the plantings are already in memory, so the
     * check is an array lookup rather than a statement.
     *
     * `wide` is the awkward one. An unchecked checkbox sends nothing, so
     * "garden-wide dates off" and "the form was never submitted" look
     * identical in a query string. The hidden marker is what tells them
     * apart, and without it the default would flip the first time somebody
     * filtered by plant.
     *
     * @param list<array<string,mixed>> $plantings
     * @return array{plant_ids:list<int>,wide:bool}
     */
    private function readFilter(Request $request, array $plantings): array
    {
        $known = [];
        foreach ($plantings as $planting) {
            $known[(int) $planting['id']] = true;
        }

        $wanted = [];
        foreach ($request->queryIntList('plant_id') as $id) {
            if ($id > 0 && isset($known[$id])) {
                $wanted[$id] = true;
            }
        }

        $submitted = $request->query('f') !== null;

        return [
            'plant_ids' => \array_values(\array_map(\intval(...), \array_keys($wanted))),
            'wide'      => !$submitted || $request->query('wide') !== null,
        ];
    }

    /**
     * Two rules, and the second is the one worth stating.
     *
     * A plant filter keeps the entries about those plants. It does NOT keep
     * the dates that belong to no plant -- a frost date, a sow-by, a pest
     * window, a zone watering -- because those are about the garden and not
     * about the tomato you filtered to. They are kept or dropped by their own
     * tick box instead, which defaults to on, so filtering to one plant still
     * shows the frost that will kill it.
     *
     * @param list<array<string,mixed>> $entries
     * @param array{plant_ids:list<int>,wide:bool} $filter
     * @return list<array<string,mixed>>
     */
    private static function applyFilter(array $entries, array $filter): array
    {
        $wanted = \array_flip($filter['plant_ids']);
        $out = [];

        foreach ($entries as $entry) {
            if ($entry['planting_id'] === null) {
                if ($filter['wide']) {
                    $out[] = $entry;
                }
                continue;
            }
            if ($wanted === [] || isset($wanted[$entry['planting_id']])) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
