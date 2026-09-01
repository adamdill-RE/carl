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
use Carl\Repo\ReminderRepository;
use Carl\Repo\TagRepository;
use Carl\Repo\UserRepository;
use Carl\Repo\WateringRepository;
use Carl\Repo\WeatherRepository;
use Carl\Repo\ZctaRepository;
use Carl\Reports\Series;
use Carl\Support\Photos;

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
    private ?ReminderRepository $reminders = null;
    private ?TagRepository $tags = null;
    private ?UserRepository $accounts = null;
    private ?WeatherRepository $weather = null;
    private ?WateringRepository $watering = null;
    private ?ZctaRepository $zcta = null;
    private ?Series $series = null;
    private ?Photos $photoService = null;

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

    /**
     * Today's items, read only: the hourly digest job computes and stores
     * them, and the menu shows what is stored (handoff Section 4.2).
     */
    protected function reminders(): ReminderRepository
    {
        return $this->reminders ??= new ReminderRepository($this->app->db(), $this->userId());
    }

    /**
     * The watering recommendation, read only: it is computed nightly and
     * never at render (handoff Section 11).
     */
    protected function watering(): WateringRepository
    {
        return $this->watering ??= new WateringRepository($this->app->db(), $this->userId());
    }

    /**
     * QR plant tags (docs/QR-TAGS-SPEC.md).
     *
     * Four controllers reach for this now -- the tag screens, the plant page,
     * End Growing Season, and both plant lists since a tag code is something
     * you can type into their search box -- so it belongs here beside the
     * others rather than as a private copy in each. It was three copies
     * before the fourth wanted one.
     */
    protected function tags(): TagRepository
    {
        return $this->tags ??= new TagRepository($this->app->db(), $this->userId());
    }

    /**
     * The data behind a report (handoff Section 13.1): the weather over a
     * subject's covered dates and the subject's own events, in one statement
     * each. The plant page, the JSON endpoint and the PDF all read it, which
     * is what stops the three disagreeing about which days a plant covers.
     */
    protected function series(): Series
    {
        return $this->series ??= new Series(
            $this->plantings(),
            $this->events(),
            $this->gardens(),
            $this->weather(),
            $this->app->units(),
        );
    }

    /**
     * Server-side photo handling (handoff Section 10). Not a repository: it
     * owns the files under var/photos, which is the half of a photograph the
     * database does not hold.
     */
    protected function photoService(): Photos
    {
        if ($this->photoService !== null) {
            return $this->photoService;
        }
        $config = $this->app->config();
        return $this->photoService = new Photos(
            $this->app->varPath('photos'),
            $config->int('photos.max_bytes', 2097152),
            $config->int('photos.max_megapixels', 40),
            $config->int('photos.long_edge', 1920),
            $config->int('photos.thumb_edge', 320),
            $config->int('photos.jpeg_quality', 85),
        );
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

    // -- Shared screen behaviour -----------------------------------------

    /**
     * A tag code typed into the plant list's search box.
     *
     * docs/QR-TAGS-SPEC.md Section 7 rules out an in-app scanner and is right
     * to: a phone camera reads a QR symbol from the lock screen better than
     * anything that would fit in the 150 KB budget, and it lands on
     * `/t/{code}` by itself. What a camera cannot do is put six characters
     * into a box on a page you are already looking at -- so on these two
     * screens the code arrives the way it does when the symbol is caked in
     * soil: read off the stake and typed. `normalise()` is deliberately
     * forgiving about case, spaces and hyphens, which is why the alphabet has
     * no I, L, O or U.
     *
     * IT LANDS ON THE SCREEN YOU WERE ALREADY ON, and that is the whole
     * reason this exists rather than a link to /tags/find. `/t/{code}` is the
     * field screen: one hand, mud, six large buttons, today's date. It is the
     * right page in a garden and the wrong one at a desk, where you came to
     * this list to backdate a yield or read a timeline. From View Plants a
     * code opens the report page; from Log Plant Activity it opens the log
     * form. A code with no plant on it opens the bind screen, because typing
     * a free tag's code is how you say "put this one on something".
     *
     * COSTS NOTHING WHEN IT IS NOT A CODE. The lookup only runs for a query
     * that normalises to six characters of the tag alphabet, so an ordinary
     * search pays no statement for this feature.
     *
     * A MISS IS SILENT AND FALLS THROUGH TO THE TEXT SEARCH. Two reasons, and
     * the second is a rule: real words collide -- "pepper" and "garden" are
     * both six characters of the tag alphabet -- so a code that matches no
     * tag of yours has to keep behaving like a search. And a code that does
     * not exist must be indistinguishable from one that is somebody else's
     * (Section 6.2): saying nothing about either is the strongest form of
     * that, and the list this falls through to is user-scoped anyway.
     *
     * @param string $target 'plants' or 'log' -- the list this was typed into
     */
    protected function tagCodeJump(string $search, string $target): ?Response
    {
        $code = TagRepository::normalise($search);
        if (!TagRepository::isWellFormed($code)) {
            return null;
        }

        $tag = $this->tags()->scan($code);
        if ($tag === null) {
            return null;
        }

        if ($tag['planting_id'] === null) {
            return $this->redirect('t/' . $code);
        }

        return $this->redirect(
            ($target === 'log' ? 'log/' : 'plants/') . (int) $tag['planting_id']
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
