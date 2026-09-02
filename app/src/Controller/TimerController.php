<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\EventType;
use Carl\Repo\TimerRepository;
use Carl\Timers\TimerService;

/**
 * The watering timer's screens (Phase 16; Phase 15 handoff Section 3.2).
 *
 * Start and cancel are POSTs from the garden actions page and the MOTD; the
 * landing page is what the notification opens, and its one button logs the
 * watering when the timer was started without "log it when done".
 */
final class TimerController extends Controller
{
    public function start(Request $request): Response
    {
        $gardenId = (int) ($request->intInput('garden_id') ?? 0);
        $zoneId = $request->intInput('water_zone_id');
        $minutes = (int) ($request->intInput('minutes') ?? 0);

        $service = new TimerService($this->app);
        $started = $service->start(
            $this->userId(),
            $gardenId,
            $zoneId !== null && $zoneId > 0 ? $zoneId : null,
            $minutes,
            $request->checkbox('log_when_done'),
        );

        $endsLocal = $this->app->clock()->zone($this->user()->tz());
        $ends = (new \DateTimeImmutable($started['ends_at'], new \DateTimeZone('UTC')))
            ->setTimezone($endsLocal)->format('H:i');

        $this->flash(\sprintf('Timer started: %d min on %s, done at %s. %s',
            $started['minutes'],
            $started['zone_name'] ?? $started['garden_name'],
            $ends,
            $request->checkbox('log_when_done')
                ? 'Carl will log the watering when it finishes.'
                : 'Tap the notification to log it when it finishes.'));

        return $this->backTo($request, $gardenId);
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $timers = new TimerRepository($this->app->db(), $this->userId());
        $timer = $timers->findDetailed($id);
        if ($timer === null) {
            throw HttpException::notFound('That is not one of your timers.');
        }
        $this->flash($timers->cancel($id)
            ? 'Timer cancelled. Nothing was logged.'
            : 'That timer had already finished.', $timers->cancel($id) ? 'ok' : 'error');

        return $this->backTo($request, (int) $timer['garden_id']);
    }

    /** The page the notification opens. */
    public function show(Request $request, array $params): Response
    {
        $timers = new TimerRepository($this->app->db(), $this->userId());
        $timer = $timers->findDetailed((int) $params['id']);
        if ($timer === null) {
            throw HttpException::notFound('That is not one of your timers.');
        }

        $zone = $this->app->clock()->zone($this->user()->tz());
        $local = static fn (?string $utc): ?string => $utc === null ? null
            : (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone)->format('H:i');

        $now = $this->app->clock()->nowUtc();
        $ends = new \DateTimeImmutable((string) $timer['ends_at'], new \DateTimeZone('UTC'));
        $left = (int) \ceil(($ends->getTimestamp() - $now->getTimestamp()) / 60);

        return $this->render('timers/show', [
            'timer'      => $timer,
            'place'      => TimerService::placeName($timer),
            'endsLocal'  => $local((string) $timer['ends_at']),
            'firedLocal' => $local($timer['fired_at']),
            'minutesLeft' => \max(0, $left),
            'running'    => $timer['fired_at'] === null && $timer['cancelled_at'] === null,
            'pageTitle'  => 'Timer',
        ]);
    }

    /**
     * Log the watering after the fact: the timer that ran without "log it
     * when done", or one whose logging failed. The same write the cron
     * makes, on the user's local today.
     */
    public function logNow(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $timers = new TimerRepository($this->app->db(), $this->userId());
        $timer = $timers->findDetailed($id);
        if ($timer === null) {
            throw HttpException::notFound('That is not one of your timers.');
        }
        if ($timer['logged_event_id'] !== null) {
            $this->flash('That watering is already logged.', 'error');
            return $this->redirect('timers/' . $id);
        }

        $zoneId = $timer['water_zone_id'] === null ? null : (int) $timer['water_zone_id'];
        $zoneMethod = null;
        if ($zoneId !== null) {
            foreach ($this->gardens()->zones((int) $timer['garden_id']) as $zone) {
                if ((int) $zone['id'] === $zoneId) {
                    $zoneMethod = $zone['water_method_id'] === null ? null : (int) $zone['water_method_id'];
                }
            }
        }
        $rowIds = $zoneId === null ? [] : $this->gardens()->zoneRowIds($zoneId);

        $result = $this->events()->recordGardenEvent(
            (int) $timer['garden_id'],
            EventType::WATERED,
            $this->today(),
            [
                'duration_min'     => (int) $timer['minutes'],
                'ref_list_item_id' => $zoneMethod,
                'narrative'        => 'Timer: ' . (int) $timer['minutes'] . ' min.',
            ],
            $rowIds,
            $zoneId,
            $zoneId !== null,
        );
        $timers->markLogged($id, $result['event_id']);

        $message = 'Watering recorded';
        if ($result['fanout'] > 0) {
            $message .= ', and logged against ' . $result['fanout'] . ' living plant'
                . ($result['fanout'] === 1 ? '' : 's') . ' in that zone';
        }
        $this->flash($message . '.');

        return $this->redirect('timers/' . $id);
    }

    /** Back to where the button was: the MOTD, or the garden's actions. */
    private function backTo(Request $request, int $gardenId): Response
    {
        if ($request->input('return') === 'menu') {
            return $this->redirect('/', [], 'timers');
        }
        return $this->redirect('gardens/' . $gardenId . '/actions', [], 'timers');
    }
}
