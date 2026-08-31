<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Analysis\Scope;
use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Throwable;

/**
 * Recommendations (handoff Section 14, "v2 -- Recommendations (Claude
 * analysis)"; Phase 5 handoff Section 3.1).
 *
 * The screen is two things: the most recent answer, and a button that asks
 * for another. Nothing here calls an API. The button writes an `analysis` row
 * and redirects; the drain cron answers it; the answer is on the page next
 * time it is loaded. That is not a limitation of this implementation, it is
 * the rule -- no third-party call on the request path (Phase 3 handoff
 * Section 5) -- and it is why a rate-limited or down API cannot make this
 * page slow or 500 it.
 *
 * The waiting is therefore visible, and the page says so in words rather than
 * spinning: "asked at 09:14, the next run is within the hour."
 */
final class AdviceController extends Controller
{
    public function index(Request $request): Response
    {
        $analyst = $this->app->analyst();
        $userId = $this->userId();
        $today = $this->today();

        $history = $analyst->forUser($userId, 12);
        $latest = null;
        foreach ($history as $row) {
            if ((string) $row['status'] === 'done') {
                $latest = $row;
                break;
            }
        }

        // The id in the query string only ever selects from THIS user's own
        // rows: forUser() is scoped and the loop below matches inside it, so
        // there is no lookup that another account's id could satisfy.
        $wanted = $request->query('id');
        if ($wanted !== null && \ctype_digit($wanted)) {
            foreach ($history as $row) {
                if ((int) $row['id'] === (int) $wanted && (string) $row['status'] === 'done') {
                    $latest = $row;
                    break;
                }
            }
        }

        $askedToday = $analyst->countToday($userId, $today);
        $perDay = $this->app->config()->int('analysis.max_per_day', 3);

        return $this->render('advice/index', [
            'latest'     => $latest,
            'blocks'     => $latest === null
                ? [] : \Carl\Analysis\Prose::blocks((string) $latest['answer']),
            'history'    => $history,
            'pending'    => \array_values(\array_filter(
                $history,
                static fn (array $r): bool => \in_array((string) $r['status'], ['queued', 'sending'], true)
            )),
            'configured' => $analyst->driver() !== null,
            'describe'   => $analyst->describeDriver(),
            'askedToday' => $askedToday,
            'perDay'     => $perDay,
            'canAsk'     => $askedToday < $perDay,
            'lastRun'    => $analyst->lastRun(),
            'days'       => $this->app->config()->int('analysis.days', 365),
            // Phase 6: what a narrower analysis can be about. Two statements
            // the page was not making before, and both are lists this account
            // already owns -- a gardener looking at one struggling bed does
            // not want a review of the year.
            'gardens'    => $this->gardens()->activeGardens(),
            'plantings'  => $this->plantings()->listWithDetail(['living' => true], 60),
        ]);
    }

    /**
     * Ask for one. Writes a row; sends nothing.
     *
     * The per-day cap is checked here rather than only in the queue because
     * every row is a paid API call and the honest place to refuse is where
     * the person can see the refusal.
     */
    public function ask(Request $request): Response
    {
        $userId = $this->userId();
        $today = $this->today();
        $analyst = $this->app->analyst();

        $perDay = $this->app->config()->int('analysis.max_per_day', 3);
        if ($analyst->countToday($userId, $today) >= $perDay) {
            $this->flash(
                'That is ' . $perDay . ' analyses today, which is the daily limit. '
                . 'The ones you have asked for are still on their way.',
                'error'
            );
            return $this->redirect('advice');
        }

        $question = \trim((string) ($request->input('question', '') ?? ''));
        if (\mb_strlen($question) > 500) {
            $question = \mb_substr($question, 0, 500);
        }

        // Parsing a scope says what to filter to; it says nothing about who
        // may see it. This is where that is settled, against the user's own
        // repositories -- and a subject that is not theirs is refused here
        // rather than quietly producing an empty document they paid for.
        $scope = Scope::parse($request->input('scope'));
        if (!$scope->isSeason() && !$this->ownsSubject($scope)) {
            $this->flash('That is not one of your gardens or plants.', 'error');
            return $this->redirect('advice');
        }

        try {
            $id = $analyst->request($userId, $today, $question === '' ? null : $question, $scope);
        } catch (Throwable $e) {
            \error_log('[carl] analysis not queued: ' . $e->getMessage());
            $this->flash('That could not be queued. Try again in a moment.', 'error');
            return $this->redirect('advice');
        }

        $this->flash($id === 0
            ? 'You have already asked that today; the answer is on its way.'
            : 'Asked. Carl works this out on its next run -- come back in an hour or so.');

        return $this->redirect('advice');
    }

    /** Does this account own the thing a scope names? */
    private function ownsSubject(Scope $scope): bool
    {
        $id = (int) $scope->subjectId;
        if ($id <= 0) {
            return false;
        }
        return $scope->kind === Scope::GARDEN
            ? $this->gardens()->find($id) !== null
            : $this->plantings()->find($id) !== null;
    }

    /**
     * Ask again for one that failed.
     *
     * A failed row is not silently retried for ever (Analyst backs off and
     * gives up), but a failure whose cause has been fixed -- a key added, a
     * quota reset -- should not need a new request that says the same thing.
     */
    public function retry(Request $request, array $params): Response
    {
        $row = $this->app->analyst()->findForUser((int) $params['id'], $this->userId());
        if ($row === null) {
            throw HttpException::notFound('That is not one of your analyses.');
        }
        if ((string) $row['status'] !== 'failed') {
            return $this->redirect('advice');
        }

        $this->app->db()->run(
            "UPDATE `analysis` SET `status` = 'queued', `attempts` = 0,"
            . ' `next_attempt_at` = UTC_TIMESTAMP(), `last_error` = NULL, `completed_at` = NULL'
            . ' WHERE `id` = :id AND `user_id` = :user_id',
            ['id' => (int) $row['id'], 'user_id' => $this->userId()]
        );

        $this->flash('Queued again. It goes out on the next run.');
        return $this->redirect('advice');
    }
}
