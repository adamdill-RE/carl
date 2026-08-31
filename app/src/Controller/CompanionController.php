<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\Request;
use Carl\Core\Response;

/**
 * The companion planting reference (handoff Section 14 v2, Phase 6 handoff
 * Section 3.3).
 *
 * The whole table on one page, grouped by the crop you are looking up, with
 * the mechanism and the confidence on every line. It is a reference rather
 * than a recommendation: nothing here fires a reminder or changes a
 * countdown, because the evidence under most of it does not carry that much
 * weight and pretending otherwise is how a gardener stops believing the parts
 * that do.
 *
 * Two statements: the pairings and the categories this account actually
 * grows, so the crops in the garden can sort to the top.
 */
final class CompanionController extends Controller
{
    /** `/companions` -- every pairing the research carries. */
    public function index(Request $request): Response
    {
        $pairs = $this->reference()->allCompanions();

        // Each pair is stored once and read both ways, so it is listed under
        // both crops. That is a deliberate duplication: somebody looking up
        // "Onion" should not have to know the fact was filed under "Bean".
        $byCategory = [];
        foreach ($pairs as $pair) {
            foreach ([[$pair['category'], $pair['other_category']],
                      [$pair['other_category'], $pair['category']]] as [$subject, $other]) {
                $byCategory[(string) $subject][] = [
                    'other'        => (string) $other,
                    'relationship' => (string) $pair['relationship'],
                    'reason'       => $pair['reason'],
                    'confidence'   => $pair['confidence'],
                    'source'       => $pair['source'],
                ];
            }
        }
        \ksort($byCategory, \SORT_NATURAL | \SORT_FLAG_CASE);

        // filterOptions() already answers "which categories does this account
        // have plantings of", for the View Plants filters. Reusing it keeps
        // this page at two statements and keeps one query behind the question.
        $mine = [];
        foreach ($this->plantings()->filterOptions()['categories'] as $category) {
            $mine[\strtolower((string) $category)] = true;
        }

        return $this->render('companions/index', [
            'byCategory' => $byCategory,
            'mine'       => $mine,
            'pairCount'  => \count($pairs),
        ]);
    }
}
