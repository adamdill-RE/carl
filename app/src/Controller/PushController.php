<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Push\Vapid;
use Carl\Repo\PushSubscriptionRepository;
use Carl\Timers\TimerService;

/**
 * A browser saying "tell this phone" (Phase 16).
 *
 * push.js subscribes with the install's public key and POSTs what the
 * browser handed back: the endpoint and the two key strings of RFC 8291.
 * Form-encoded, not JSON, so it carries the CSRF token like every other
 * POST and needs no exemption.
 */
final class PushController extends Controller
{
    public function subscribe(Request $request): Response
    {
        $endpoint = (string) $request->input('endpoint', '');
        $p256dh = (string) $request->input('p256dh', '');
        $auth = (string) $request->input('auth', '');

        if (!\str_starts_with($endpoint, 'https://') || \strlen($endpoint) > 1000) {
            throw HttpException::badRequest('Not a push endpoint.');
        }
        if (\strlen(Vapid::b64urlDecode($p256dh)) !== 65 || \strlen(Vapid::b64urlDecode($auth)) !== 16) {
            throw HttpException::badRequest('The subscription keys are not the shape a browser sends.');
        }

        $id = (new PushSubscriptionRepository($this->app->db(), $this->userId()))->save(
            $endpoint, $p256dh, $auth, (string) ($request->server['HTTP_USER_AGENT'] ?? '')
        );

        return Response::json(['ok' => true, 'id' => $id]);
    }

    public function unsubscribe(Request $request): Response
    {
        $endpoint = (string) $request->input('endpoint', '');
        $removed = (new PushSubscriptionRepository($this->app->db(), $this->userId()))->remove($endpoint);
        return Response::json(['ok' => true, 'removed' => $removed]);
    }

    /**
     * "Send a test notification" (Phase 17).
     *
     * A push, now, to this phone -- the endpoint push.js posts -- or to
     * every live phone on the account when it posts none, and the push
     * service's answer in words: "Apple answered 201, the notification is on
     * its way" or "Apple answered 403 BadJwtToken". The owner's report was
     * "nothing is coming up", and nothing in Carl could say which of the six
     * things between the button and the lock screen had failed. This is the
     * one that asks the push service directly.
     *
     * THE ONE THIRD-PARTY CALL ON A REQUEST PATH, on purpose. Weather, mail
     * and analysis are cron-only because a provider's bad day must not be
     * able to make a page slow (Phase 3 handoff Section 4.1); this is a
     * diagnostic a person presses, capped at five phones with a ten-second
     * socket, and the answer is worthless a minute later on a cron log.
     */
    public function test(Request $request): Response
    {
        $userId = $this->userId();
        $repository = new PushSubscriptionRepository($this->app->db(), $userId);

        $endpoint = (string) $request->input('endpoint', '');
        if ($endpoint !== '') {
            $one = $repository->findLiveByEndpoint($endpoint);
            $targets = $one === null ? [] : [$one];
            if ($targets === []) {
                return Response::json(['ok' => false,
                    'message' => 'This phone is not subscribed any more. Tap "Notify this phone" first.']);
            }
        } else {
            $targets = \array_slice($repository->live(), 0, 5);
            if ($targets === []) {
                return Response::json(['ok' => false, 'message' => 'No phone is subscribed yet.']);
            }
        }

        $user = $this->user();
        $navigate = \rtrim($this->app->config()->string('tags.origin'), '/') . $this->app->url('');
        $localTime = $this->app->clock()->localNow($user->tz())->format('H:i');

        try {
            $results = (new TimerService($this->app))->testPush($userId, $targets, $navigate, $localTime);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $lines = [];
        $anyOk = false;
        foreach ($results as $result) {
            if ($result['ok']) {
                $anyOk = true;
                $lines[] = $result['service'] . ' answered ' . $result['status']
                    . ': the notification is on its way and should show within a few seconds.';
            } else {
                $lines[] = $result['service'] . ' answered ' . ($result['error'] ?? ('HTTP ' . $result['status']))
                    . ($result['gone'] ? '. That subscription is dead: tap "Notify this phone" again.' : '.');
            }
        }

        return Response::json([
            'ok'      => $anyOk,
            'message' => \implode(' ', $lines),
            'results' => $results,
        ]);
    }
}
