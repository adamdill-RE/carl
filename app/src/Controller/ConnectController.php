<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Auth\ApiTokenStore;
use Carl\Core\Request;
use Carl\Core\Response;

/**
 * "Connect Claude Code" (Phase 16; Phase 15 handoff Section 3.1, step 1).
 *
 * Mints a bearer token, shows it ONCE, lists the live ones with the date
 * each was last used, and revokes. The token is carried from the POST to
 * the next GET in the session and taken on render, the way a flash is, so
 * a reload of the page does not show it twice and the back button does not
 * show it at all.
 */
final class ConnectController extends Controller
{
    private const FRESH = '_fresh_api_token';

    public function index(Request $request): Response
    {
        $store = $this->store();
        $fresh = $this->app->session()->pull(self::FRESH);
        $config = $this->app->config();

        return $this->render('connect/index', [
            'tokens'    => $store->forUser($this->userId()),
            'fresh'     => \is_array($fresh) ? $fresh : null,
            'endpoint'  => \rtrim($config->string('tags.origin'), '/') . $this->app->url('mcp'),
            'perMinute' => $config->int('mcp.calls_per_minute', 60),
            'pageTitle' => 'Connect Claude Code',
        ]);
    }

    public function mint(Request $request): Response
    {
        $label = (string) $request->input('label', '');
        $issued = $this->store()->issue($this->userId(), $label);

        $this->app->session()->set(self::FRESH, [
            'id'    => $issued['id'],
            'token' => $issued['token'],
            'label' => $label,
        ]);

        return $this->redirect('connect');
    }

    public function revoke(Request $request, array $params): Response
    {
        if ($this->store()->revoke($this->userId(), (int) $params['id'])) {
            $this->flash('Token revoked. Anything still using it gets "unauthorised" from now on.');
        } else {
            $this->flash('That token was not one of yours to revoke, or was already revoked.', 'error');
        }
        return $this->redirect('connect');
    }

    private function store(): ApiTokenStore
    {
        return new ApiTokenStore($this->app->db(), $this->app->config()->int('mcp.calls_per_minute', 60));
    }
}
