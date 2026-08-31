<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Auth\User;
use Carl\Core\App;
use Carl\Core\HttpClient;
use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Repo\EventRepository;
use Carl\Repo\GardenRepository;
use Carl\Repo\ListRepository;
use Carl\Repo\PhotoRepository;
use Carl\Repo\PlantingRepository;
use Carl\Repo\ReferenceRepository;
use Carl\Repo\UserRepository;
use Carl\Repo\WeatherRepository;
use Carl\Repo\ZctaRepository;

/**
 * Base controller: the repositories, the current user, and the two things
 * every screen needs -- the user's local today, and an escaped render.
 */
abstract class Controller
{
    private ?ListRepository $lists = null;
    private ?GardenRepository $gardens = null;
    private ?PlantingRepository $plantings = null;
    private ?EventRepository $events = null;
    private ?PhotoRepository $photos = null;
    private ?ReferenceRepository $reference = null;
    private ?UserRepository $accounts = null;
    private ?WeatherRepository $weather = null;
    private ?ZctaRepository $zcta = null;

    public function __construct(protected App $app)
    {
    }

    protected function user(): User
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new HttpException(401, 'You are not signed in.');
        }
        return $user;
    }

    protected function userId(): int
    {
        return $this->user()->id;
    }

    /** The user's local calendar day -- never the server's (handoff Section 6). */
    protected function today(): string
    {
        return $this->app->clock()->todayFor($this->user()->tz());
    }

    // -- Repositories ----------------------------------------------------

    protected function lists(): ListRepository
    {
        return $this->lists ??= new ListRepository($this->app->db(), $this->userId());
    }

    protected function gardens(): GardenRepository
    {
        return $this->gardens ??= new GardenRepository($this->app->db(), $this->userId());
    }

    protected function plantings(): PlantingRepository
    {
        return $this->plantings ??= new PlantingRepository($this->app->db(), $this->userId());
    }

    protected function events(): EventRepository
    {
        return $this->events ??= new EventRepository($this->app->db(), $this->userId(), $this->plantings());
    }

    protected function photos(): PhotoRepository
    {
        return $this->photos ??= new PhotoRepository($this->app->db(), $this->userId());
    }

    protected function reference(): ReferenceRepository
    {
        return $this->reference ??= new ReferenceRepository($this->app->db());
    }

    /**
     * Named accounts(), not users(), because AdminController has a users()
     * ACTION and a controller action must not collide with an accessor.
     */
    protected function accounts(): UserRepository
    {
        return $this->accounts ??= new UserRepository($this->app->db());
    }

    protected function weather(): WeatherRepository
    {
        return $this->weather ??= new WeatherRepository($this->app->db());
    }

    protected function zcta(): ZctaRepository
    {
        return $this->zcta ??= new ZctaRepository(
            $this->app->db(),
            $this->app->config(),
            new HttpClient(
                $this->app->config()->string('weather.user_agent'),
                $this->app->config()->int('weather.http_timeout', 20)
            )
        );
    }

    // -- Responses -------------------------------------------------------

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = []): Response
    {
        $user = $this->app->auth()->userOrNull();
        $view = $this->app->view();

        // Shared, not merely passed: a partial rendered from inside a template
        // gets only what that call hands it plus the shared values, and every
        // form partial needs the CSRF token (hosting Section 8.5).
        $view->share('user', $user);
        $view->share('csrf', $this->app->csrf()->token());
        $view->share('today', $user !== null ? $this->app->clock()->todayFor($user->tz()) : null);

        return Response::html($view->render($template, $data + [
            'flash'      => $this->app->session()->takeFlash(),
            'statements' => $this->app->db()->statementCount(),
        ]));
    }

    /** @param array<string,mixed> $query */
    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect($this->app->url($path, $query));
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = $request->header('Referer');
        if (\is_string($referer) && $referer !== '') {
            $path = \parse_url($referer, \PHP_URL_PATH);
            $host = \parse_url($referer, \PHP_URL_HOST);
            $ownHost = $request->server['HTTP_HOST'] ?? null;
            // Only ever bounce back inside this app: an open redirect is a
            // gift to a phisher.
            if (\is_string($path) && ($host === null || $host === $ownHost)) {
                return Response::redirect($path);
            }
        }
        return $this->redirect($fallback);
    }

    protected function flash(string $message, string $kind = 'ok'): void
    {
        $this->app->session()->flash($message, $kind);
    }

    /**
     * A user-entered date: defaults to the user's local today and accepts the
     * past, never the future (handoff Section 4).
     */
    protected function eventDate(Request $request, string $field = 'event_date'): string
    {
        $today = $this->today();
        $value = \Carl\Support\Clock::parseDate($request->input($field));
        if ($value === null) {
            return $today;
        }
        return $value > $today ? $today : $value;
    }

    /** @param list<string> $allowed */
    protected function choice(Request $request, string $field, array $allowed, string $default): string
    {
        $value = $request->input($field, $default) ?? $default;
        return \in_array($value, $allowed, true) ? $value : $default;
    }
}
