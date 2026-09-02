<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Push\Vapid;
use Carl\Repo\PushSubscriptionRepository;

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
}
