<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\LabelStock;
use Carl\Domain\PlantingState;
use Carl\Qr\Encoder;
use Carl\Qr\Svg;
use Carl\Qr\TagUrl;
use Carl\Reports\LabelSheet;
use Carl\Repo\TagRepository;
use Carl\Support\Clock;

/**
 * QR plant tags (docs/QR-TAGS-SPEC.md): the pool, the printing, the scan, and
 * the field screen the scan lands on.
 *
 * The whole feature exists for one sentence in Section 0: logging a watering
 * costs six interactions today -- sign in, View Plants, find the right one
 * among forty, Log, choose the type, submit -- to record a fact that took
 * three seconds to perform, done standing in a garden holding a hose. A scan
 * collapses it to two: point the camera, tap "Watered".
 *
 * Every decision below is made in favour of the person standing in the mud
 * rather than the person at the desk.
 */
final class TagController extends Controller
{
    // ==================================================================
    // The scan
    // ==================================================================

    /**
     * `GET /t/{code}` -- what a phone camera opens.
     *
     * THE TOKEN IS AN IDENTIFIER, NOT A CREDENTIAL, and this route is
     * Route::USER_ACCESS for that reason (Section 6.1). A tag on a stake in a
     * front garden is readable by anyone walking past and photographable from
     * the pavement; a bearer token there would let a stranger read the owner's
     * whole garden history or log a harvest that never happened.
     * Route::TOKEN_ACCESS exists for exactly one route and its docblock says
     * why the reasoning does not transfer: a forged unsubscribe achieves
     * precisely what the link it forged was for.
     *
     * It costs the gardener nothing. TokenStore issues a 30-day rotating
     * cookie (hosting Section 8.3), so their own phone is signed in
     * essentially always -- and a signed-out scan is not lost either: App's
     * guard stores the path it was going to and the login returns to it.
     *
     * STATEMENT BUDGET: TWO, against Section 6.3's three. The database is on
     * separate hardware and this is a page that gets hit forty times in one
     * walk around a garden. One statement resolves the tag, its live binding
     * and the planting; one reads the recent events the header shows. The
     * action list is computed from the state that came back on the row.
     */
    public function scan(Request $request, array $params): Response
    {
        $code = TagRepository::normalise((string) $params['code']);
        if (!TagRepository::isWellFormed($code)) {
            throw HttpException::notFound('No such tag.');
        }

        $tag = $this->tags()->scan($code);

        // A code that does not exist and a code belonging to somebody else get
        // the SAME 404, deliberately (Section 6.2). Anything that told the two
        // apart would let a stranger with a photographed tag learn which codes
        // are real.
        if ($tag === null) {
            throw HttpException::notFound('No such tag.');
        }

        if ($tag['planting_id'] === null) {
            // A tagging session makes THE SCAN THE CONFIRM (Section 6.5):
            // a free tag scanned mid-session goes straight on the plant the
            // session is filling, or the next plant with no stake, and lands
            // on the field screen with an undo. Phase 8 rendered the bind
            // screen here regardless, so the session named the next plant
            // and then asked for a tap anyway.
            return $this->sessionBind($tag) ?? $this->bindScreen($request, $tag);
        }

        return $this->fieldScreen($request, $tag);
    }

    /**
     * Bind a scanned free tag without a tap, if a session is running and
     * there is a plant to put it on. Null means "show the bind screen".
     *
     * WHERE IT GOES: the plant the session is FILLING if it still wants
     * stakes -- the tray you are working along, cell by cell -- and
     * otherwise the next plant with no stake at all. The fill target is the
     * plant the last scan went on, kept in the PHP session (the scan is a
     * full page load, and the camera opens it in the browser that holds the
     * login, so the session is there); it is re-checked on every read
     * against the database, so it cannot go stale, and "Next plant" on the
     * strip clears it. A row of a hundred carrots gets one stake and a tap
     * on Next, not a hundred scans.
     *
     * @param array<string,mixed> $tag
     */
    private function sessionBind(array $tag): ?Response
    {
        if (!$this->sessionActive() || $tag['tag_retired_at'] !== null) {
            return null;
        }

        $target = null;
        $fillId = $this->app->session()->get('tagging_planting_id');
        if (\is_int($fillId)) {
            $target = $this->tags()->fillTarget($fillId);
        }
        if ($target === null) {
            $target = $this->tags()->nextUntagged();
            if ($target !== null) {
                $target['tag_count'] = 0;
            }
        }
        if ($target === null) {
            return null;
        }

        $bindingId = $this->tags()->bindTo((int) $tag['tag_id'], (int) $target['id']);
        $this->app->session()->set('tagging_planting_id', (int) $target['id']);

        $have = (int) $target['tag_count'] + 1;
        $this->flash('Tag ' . $tag['code'] . ' is on ' . self::plantName($target)
            . ' (' . $have . ' of ' . (int) $target['quantity_live'] . ' stakes). Scan the next one.');

        return $this->redirect('t/' . $tag['code'], ['bound' => $bindingId]);
    }

    /**
     * The bind screen: "Tag AB7K4M isn't assigned yet."
     *
     * @param array<string,mixed> $tag
     */
    private function bindScreen(Request $request, array $tag): Response
    {
        $search = (string) ($request->query('q', '') ?? '');
        $session = $this->sessionState();

        return $this->render('tags/bind', [
            'tag'        => $tag,
            'qr'         => $this->svgFor((string) $tag['code']),
            // Every living plant, split into the ones that still want stakes
            // and the ones that have one per plant (Section 14.7). One
            // statement for both.
            'candidates' => $this->tags()->bindCandidates($search),
            'search'     => $search,
            'session'    => $session,
            'pageTitle'  => 'Tag ' . $tag['code'],
        ]);
    }

    /**
     * The field screen (Section 7). Everything on it assumes one hand,
     * sunlight on the screen, mud, and no patience.
     *
     * NOT `/plants/{id}`. That is the report page with charts -- the right
     * page at a desk and the wrong page in a garden.
     *
     * @param array<string,mixed> $tag
     */
    private function fieldScreen(Request $request, array $tag): Response
    {
        $state = (string) $tag['state'];
        $ended = $state === PlantingState::ENDED;

        return $this->render('tags/field', [
            'tag'      => $tag,
            'qr'       => $this->svgFor((string) $tag['code']),
            'actions'  => self::fieldActions($state),
            'ended'    => $ended,
            'recent'   => $this->events()->recentForPlanting((int) $tag['planting_id'], 6),
            'days'     => Clock::daysBetween((string) $tag['start_date'], $this->today()),
            'session'  => $this->sessionState(),
            'today'    => $this->today(),
            // Set by a bind that just happened, so the field screen can offer
            // the one-tap undo beside it (Section 6.5).
            'justBound' => (int) ($request->query('bound', '0') ?? '0'),
            'pageTitle' => self::plantName($tag),
        ]);
    }

    /**
     * `POST /t/{code}/log` -- one tap records one event, dated today, and
     * comes back here.
     *
     * No date picker and no dropdown: the defaults are "today, this plant",
     * which is right about 95% of the time because you are standing next to
     * it. Backdating and narrative live on /log/{id}, which is already built
     * and is linked below the fold.
     *
     * The action is checked against what the plant's state allows, the same
     * way LogController does, because a form is a suggestion and a POST is
     * not.
     */
    public function log(Request $request, array $params): Response
    {
        $tag = $this->requireBoundTag((string) $params['code']);
        $plantingId = (int) $tag['planting_id'];

        $eventType = (string) $request->input('event_type', '');
        if (!\in_array($eventType, self::fieldActions((string) $tag['state']), true)) {
            throw HttpException::badRequest(
                'A plant in the "' . PlantingState::label((string) $tag['state'])
                . '" state cannot record that here.'
            );
        }

        $this->events()->record($plantingId, $eventType, $this->today());

        $this->flash(\Carl\Domain\EventType::label($eventType) . ' recorded for '
            . self::plantName($tag) . '.');

        return $this->redirect('t/' . $tag['code']);
    }

    // ==================================================================
    // Binding
    // ==================================================================

    /**
     * `POST /t/{code}/bind` -- put this tag on a plant.
     *
     * Reached from the bind screen's list. Both directions of Section 5.2
     * end in TagRepository::bindTo(): tag-first here (the seed-starting
     * case, which is why the pre-printed pool exists) and planting-first in
     * attach() (the desk case).
     *
     * A plant that already has stakes simply gets one more (Section 14.7).
     * Phase 8 put "replace the existing tag" behind a tick here, because
     * one plant could carry one tag; that rule is gone and so is the tick.
     */
    public function bind(Request $request, array $params): Response
    {
        $code = TagRepository::normalise((string) $params['code']);
        $tag = $this->tags()->scan($code);
        if ($tag === null) {
            throw HttpException::notFound('No such tag.');
        }
        if ($tag['tag_retired_at'] !== null) {
            $this->flash('That tag is retired. Put it back in the pool first.', 'error');
            return $this->redirect('tags');
        }
        if ($tag['planting_id'] !== null) {
            $this->flash('Tag ' . $code . ' is already on ' . self::plantName($tag)
                . '. Take it off there first.', 'error');
            return $this->redirect('t/' . $code);
        }

        $plantingId = (int) $request->input('planting_id', '0');
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $bindingId = $this->tags()->bindTo((int) $tag['tag_id'], $plantingId);

        // A tap on the list mid-session is also an answer to "which plant
        // next": the following scans go on this one until it is full.
        if ($this->sessionActive()) {
            $this->app->session()->set('tagging_planting_id', $plantingId);
        }

        // Optimistic bind with undo, not confirm-then-bind (Section 6.5). For
        // a repetitive physical task, confirming every scan is the whole cost
        // you were removing; undo is one tap and only wanted when something
        // went wrong.
        $this->flash('Tag ' . $code . ' is now on ' . self::plantName($planting) . '.');

        return $this->redirect('t/' . $code, ['bound' => $bindingId]);
    }

    /** `POST /t/{code}/undo` -- the one-tap undo beside a fresh binding. */
    public function undo(Request $request, array $params): Response
    {
        $code = TagRepository::normalise((string) $params['code']);
        $bindingId = (int) $request->input('binding_id', '0');

        if ($this->tags()->undoBinding($bindingId)) {
            $this->flash('Undone. Tag ' . $code . ' is free again.');
        }

        return $this->redirect('t/' . $code);
    }

    /**
     * `POST /t/{code}/release` -- take the tag off, leaving the plant alone.
     *
     * Offered on an ended planting's summary, and on the field screen under
     * "change tag". The tag goes back to the free pool and the closed binding
     * stays, so the tag's history still says what it was.
     */
    public function release(Request $request, array $params): Response
    {
        $tag = $this->requireBoundTag((string) $params['code']);
        $this->tags()->unbind((int) $tag['tag_id']);
        $this->flash('Tag ' . $tag['code'] . ' is free. The plant is untouched.');

        // From the directory on the Plant tags screen the person is pulling
        // stakes off a list, and the list is where they want to be again --
        // not the bind screen of the tag they just freed. A named
        // destination and not the Referer, because the Client in the tests
        // sends none and a header is a suggestion.
        if ($request->input('return') === 'tags') {
            return $this->redirect('tags');
        }
        return $this->redirect('t/' . $tag['code']);
    }

    /**
     * `POST /t/{code}/retire` -- one code out of the pool, or back in.
     *
     * The sheet is the thing that usually goes missing (Section 5.4), and
     * retiring a sheet is built. This is the other case: one label torn
     * coming off the sheet, one stake snapped in a border. Without it the
     * pool count says a code is printed and free when it is in the bin.
     * Refused while the code is on a plant, so a plant page never claims a
     * stake that does not exist.
     */
    public function retireTag(Request $request, array $params): Response
    {
        $code = TagRepository::normalise((string) $params['code']);
        $tag = $this->tags()->scan($code);
        if ($tag === null) {
            throw HttpException::notFound('No such tag.');
        }

        $return = $request->input('return') === 'batch' && $tag['batch_id'] !== null
            ? 'tags/batches/' . (int) $tag['batch_id']
            : 'tags';

        if ($tag['planting_id'] !== null) {
            $this->flash('Tag ' . $code . ' is on ' . self::plantName($tag)
                . '. Take it off that plant before retiring it.', 'error');
            return $this->redirect($return);
        }

        $retiring = $tag['tag_retired_at'] === null;
        $this->tags()->retireTag((int) $tag['tag_id'], $retiring);

        $this->flash($retiring
            ? 'Tag ' . $code . ' retired. Nothing is deleted; put it back if it turns up.'
            : 'Tag ' . $code . ' is back in the pool.');

        return $this->redirect($return);
    }

    // ==================================================================
    // The desk half: from the plant's end (Section 5.2)
    // ==================================================================

    /**
     * `POST /plants/{id}/tag` -- put free codes on this plant, as many as
     * were ticked.
     *
     * The other direction from the scan. The scan says "here is a tag,
     * which plant?" and lists the plants; this says "here is a plant, which
     * tags?" and the plant page lists the free codes. Section 5.2 asked for
     * exactly this -- "on any plant's page: assign a tag" -- and until Phase
     * 13 the plant page offered a link to the pool.
     *
     * ALL OR NOTHING. Twenty-four codes ticked for a tray and one of them
     * stale is twenty-four stakes to check against the screen if
     * twenty-three went on; it is one line to fix if none did. A code that
     * is already on THIS plant is not an error -- it is what it should be.
     *
     * A code that is not yours reads the same as one that does not exist
     * (Section 6.2).
     */
    public function attach(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $codes = [];
        foreach (\array_merge($request->inputList('tags'), [(string) ($request->input('code', '') ?? '')]) as $raw) {
            $code = TagRepository::normalise($raw);
            if ($code !== '' && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            $this->flash('Tick the codes you are putting on, then press the button.', 'error');
            return $this->redirect('plants/' . $plantingId, [], 'tag');
        }

        $toBind = [];
        $problems = [];
        foreach ($codes as $code) {
            $tag = TagRepository::isWellFormed($code) ? $this->tags()->scan($code) : null;
            if ($tag === null) {
                $problems[] = 'no tag of yours has the code ' . $code;
            } elseif ($tag['tag_retired_at'] !== null) {
                $problems[] = $code . ' is retired';
            } elseif ($tag['planting_id'] !== null && (int) $tag['planting_id'] !== $plantingId) {
                $problems[] = $code . ' is on ' . self::plantName($tag) . ' -- take it off there first';
            } elseif ($tag['planting_id'] === null) {
                $toBind[] = (int) $tag['tag_id'];
            }
        }

        if ($problems !== []) {
            $this->flash('Nothing was put on: ' . \implode('; ', $problems) . '.', 'error');
            return $this->redirect('plants/' . $plantingId, [], 'tag');
        }

        foreach ($toBind as $tagId) {
            $this->tags()->bindTo($tagId, $plantingId);
        }

        $n = \count($toBind);
        $this->flash($n === 0
            ? 'Those stakes are already on ' . self::plantName($planting) . '.'
            : ($n === 1
                ? 'Tag ' . $codes[0] . ' is on ' . self::plantName($planting) . '. Scan it to log anything.'
                : $n . ' stakes are on ' . self::plantName($planting) . '. Scan any of them to log anything.'));

        return $this->redirect('plants/' . $plantingId, [], 'tag');
    }

    /**
     * `POST /plants/{id}/tag/release` -- take one stake off, or all of them,
     * from the plant page; and optionally retire the code because the stake
     * itself is gone.
     *
     * The same act as release() from the tag's end. It comes back to the
     * plant page because that is where the person is, and it carries the
     * one tick the field screen cannot sensibly offer: on the field screen
     * you are holding the stake, so it is not lost.
     */
    public function detach(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        if (!$this->plantings()->exists($plantingId)) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $on = $this->tags()->tagsOn($plantingId);
        if ($on === []) {
            $this->flash('There is no tag on this plant.');
            return $this->redirect('plants/' . $plantingId, [], 'tag');
        }

        // One stake by id, or every stake. The id is checked against what is
        // actually on this plant, so a stale form cannot pull a stake off
        // some other plant.
        $wanted = $request->intInput('tag_id');
        $taking = [];
        foreach ($on as $tag) {
            if ($wanted === null || (int) $tag['id'] === $wanted) {
                $taking[] = $tag;
            }
        }
        if ($taking === []) {
            $this->flash('That stake is not on this plant.', 'error');
            return $this->redirect('plants/' . $plantingId, [], 'tag');
        }

        $retire = $request->checkbox('retire');
        foreach ($taking as $tag) {
            $this->tags()->unbind((int) $tag['id']);
            if ($retire) {
                $this->tags()->retireTag((int) $tag['id'], true);
            }
        }

        $n = \count($taking);
        $what = $n === 1 ? 'Tag ' . $taking[0]['code'] : $n . ' stakes';
        $this->flash($what . ($retire
            ? ($n === 1 ? ' is' : ' are') . ' off and retired. The plant is untouched.'
            : ($n === 1 ? ' is' : ' are') . ' free again. The plant is untouched.'));

        return $this->redirect('plants/' . $plantingId, [], 'tag');
    }

    // ==================================================================
    // The tagging session (Section 6.5)
    // ==================================================================

    /**
     * `POST /tags/session` -- start or stop tagging a stack of tags in one
     * pass.
     *
     * Twelve plants and twelve tags is the seed-starting reality, and
     * scan-pick-scan-pick is twelve list-picks on a phone with wet hands. A
     * session inverts it: Carl holds the cursor, names the next untagged
     * plant, and THE SCAN IS THE CONFIRM. Twelve scans, zero taps.
     *
     * It lives on the user row because it cannot live in the page -- the scan
     * is a full page load, since the camera navigates to /t/{code} -- and it
     * costs no statement to read, because Auth::user() already selects that
     * row on every request.
     */
    public function session(Request $request): Response
    {
        $action = (string) ($request->input('action', 'start') ?? 'start');

        // "Next plant": leave the plant the session was filling and let the
        // next scan go on the next plant with no stake. This is the whole of
        // the skip Section 6.5 declined to build -- it needs no table,
        // because the only thing to forget is the one plant being filled.
        if ($action === 'next') {
            $this->app->session()->forget('tagging_planting_id');
            $next = $this->tags()->nextUntagged();
            $this->flash($next === null
                ? 'Every plant has a stake. Tap a plant on a scanned tag\'s list to add more to it.'
                : 'Moving on. The next scan goes on ' . self::plantName($next) . '.');
            return $this->back($request, 'tags');
        }

        $starting = $action === 'start';

        $this->app->db()->run(
            'UPDATE `user` SET `tagging_started_at` = :started, `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            ['started' => $starting ? \gmdate('Y-m-d H:i:s') : null, 'id' => $this->userId()]
        );
        $this->app->session()->forget('tagging_planting_id');

        $this->flash($starting
            ? 'Tagging. Scan a tag and it goes on the plant named at the top; keep scanning to fill a tray.'
            : 'Tagging stopped.');

        return $this->back($request, 'tags');
    }

    /** Whether a tagging session is running and unexpired. Costs no statement. */
    private function sessionActive(): bool
    {
        $startedAt = $this->user()->taggingStartedAt;
        if ($startedAt === null) {
            return false;
        }
        // An expiry, an explicit stop and a visible banner (Section 6.5). A
        // silent binding mode that outlives the potting session is a way to
        // attach a tag to the wrong plant a week later and never find out.
        return \strtotime($startedAt . ' UTC') + TagRepository::SESSION_HOURS * 3600 >= \time();
    }

    /**
     * The session as the views need it, or null when none is running.
     *
     * THE CURSOR IS COMPUTED, NEVER STORED. "Next untagged plant" is the
     * untagged query with LIMIT 1. A stored pointer would go stale the moment
     * a plant was tagged by another route or ended; a computed one cannot, and
     * it survives the phone being locked, the browser being closed and the
     * page being reloaded -- none of which is true of anything held
     * client-side.
     *
     * @return array{next:?array<string,mixed>,bound:list<array<string,mixed>>,
     *               remaining:int,started_at:string}|null
     */
    private function sessionState(): ?array
    {
        if (!$this->sessionActive()) {
            return null;
        }
        $startedAt = (string) $this->user()->taggingStartedAt;

        // The plant being filled, re-read from the database rather than
        // trusted: if it ended or got its last stake by another route, it is
        // simply not the target any more.
        $filling = null;
        $fillId = $this->app->session()->get('tagging_planting_id');
        if (\is_int($fillId)) {
            $filling = $this->tags()->fillTarget($fillId);
            if ($filling === null) {
                $this->app->session()->forget('tagging_planting_id');
            }
        }

        $next = $this->tags()->nextUntagged();
        if ($next === null && $filling === null) {
            // The list emptied. The session ends itself and says so.
            return ['next' => null, 'filling' => null,
                    'bound' => $this->tags()->boundSince($startedAt),
                    'remaining' => 0, 'started_at' => $startedAt];
        }

        return [
            'next'       => $next,
            'filling'    => $filling,
            'bound'      => $this->tags()->boundSince($startedAt),
            'remaining'  => $this->tags()->untaggedCount(),
            'started_at' => $startedAt,
        ];
    }

    // ==================================================================
    // The pool and printing
    // ==================================================================

    /** `GET /tags` -- printed, bound, free, retired. */
    public function index(Request $request): Response
    {
        return $this->render('tags/index', [
            'pool'       => $this->tags()->pool(),
            'batches'    => $this->tags()->batches(),
            'untagged'   => $this->tags()->untaggedCount(),
            // The directory: which stake is on which plant, and which codes
            // are still in the box. Two statements, on the one screen whose
            // job is to answer those questions.
            'inUse'      => $this->tags()->inUse(),
            'free'       => $this->tags()->free(),
            'session'    => $this->sessionState(),
            'encoding'   => $this->encoding(),
            'stock'      => $this->userStock(),
            'pageTitle'  => 'Plant tags',
        ]);
    }

    /**
     * `POST /tags/find` -- the recovery path when a symbol is caked in soil.
     *
     * Six unambiguous characters read off the tag and typed in. This is why
     * the alphabet excludes I, L, O and U and why the code is short
     * (Section 2.4), and normalise() is deliberately forgiving about case,
     * spaces and hyphens while refusing to guess at a character.
     */
    public function find(Request $request): Response
    {
        $code = TagRepository::normalise((string) $request->input('code', ''));

        if (!TagRepository::isWellFormed($code)) {
            $this->flash('A tag code is six characters, using the digits and the letters '
                . 'except I, L, O and U.', 'error');
            return $this->redirect('tags');
        }

        return $this->redirect('t/' . $code);
    }

    /** `GET /tags/print` -- how many sheets, which stock. */
    public function printForm(Request $request): Response
    {
        return $this->render('tags/print', [
            'stock'     => $this->userStock(),
            'stocks'    => LabelStock::options(),
            'encoding'  => $this->encoding(),
            'pool'      => $this->tags()->pool(),
            'pageTitle' => 'Print tags',
        ]);
    }

    /**
     * `POST /tags/batches` -- mint whole sheets.
     *
     * The form asks how many SHEETS and there is no start-position control on
     * it at all (Section 5.1). Blank tags are never printed to demand: you
     * print a sheet, the codes go in a box, and you take one out whenever a
     * plant needs a tag. A useful property falls out -- the physical sheet is
     * its own state, because you can see which positions are empty.
     */
    public function mint(Request $request): Response
    {
        $sheets = \max(1, \min(10, (int) $request->input('sheets', '1')));
        $stock = LabelStock::orFallback((string) $request->input('stock_sku', $this->userStock()));

        $minted = $this->tags()->mint($sheets, $stock);

        // A per-print override, not a settings change (Section 5.3): trying
        // the other stock for one sheet should not mean changing a preference
        // and changing it back. So the batch remembers the stock and the user
        // row is only updated when they ask for it.
        if ($request->checkbox('remember_stock')) {
            $this->app->db()->run(
                'UPDATE `user` SET `label_stock` = :stock, `updated_at` = UTC_TIMESTAMP()'
                . ' WHERE `id` = :id',
                ['stock' => $stock, 'id' => $this->userId()]
            );
        }

        $this->flash(\count($minted['codes']) . ' tag codes minted. Print at 100% scale -- '
            . 'check the 100 mm rule at the foot of the sheet before you peel anything.');

        return $this->redirect('tags/batches/' . $minted['batch_id']);
    }

    /** `GET /tags/batches/{id}` -- the sheet's own page: download, test, retire. */
    public function batch(Request $request, array $params): Response
    {
        $batch = $this->requireBatch((int) $params['id']);

        return $this->render('tags/batch', [
            'batch'     => $batch,
            'tags'      => $this->tags()->batchTags((int) $batch['id']),
            'encoding'  => $this->encoding(),
            'pageTitle' => 'Tag sheet ' . $batch['id'],
        ]);
    }

    /**
     * `GET /tags/batches/{id}.pdf` -- render that sheet.
     *
     * THE MINT IS A POST AND THIS IS A GET, so a paper jam does not cost you
     * thirty codes: re-open the URL and print it again (Section 5.4). That
     * only works because `stock_sku` is on the batch row, which makes the
     * render a pure function of it -- including after the user has changed
     * their stock preference, which is exactly when a re-render would
     * otherwise come back subtly wrong against the half-used sheet in their
     * hand.
     */
    public function batchPdf(Request $request, array $params): Response
    {
        $batch = $this->requireBatch((int) $params['id']);
        $tags = $this->tags()->batchTags((int) $batch['id']);

        $sheet = new LabelSheet((string) $batch['stock_sku'], $this->tagBase());
        $sheet->blankSheets($tags);

        return $this->pdf($sheet->render(), 'carl-tags-' . $batch['id'] . '.pdf');
    }

    /**
     * `GET /tags/batches/{id}/registration.pdf` -- position outlines on plain
     * paper.
     *
     * Section 5.6 calls this the acceptance test for the layout constants and
     * says it is not optional the first time a stock is used. Half of those
     * constants are derived rather than published -- Carl\Domain\LabelStock
     * marks which -- so this is the thing that turns a derivation into a
     * measurement, and it costs a sheet of plain paper.
     */
    public function registrationPdf(Request $request, array $params): Response
    {
        $batch = $this->requireBatch((int) $params['id']);

        $sheet = new LabelSheet((string) $batch['stock_sku'], $this->tagBase());
        $sheet->registrationSheet();

        return $this->pdf($sheet->render(), 'carl-registration-' . $batch['stock_sku'] . '.pdf');
    }

    /**
     * `GET /tags/labels.pdf` -- named labels for tags already on a plant
     * (Section 5b).
     *
     * The same code -- this is a reprint, not a new tag -- plus the plant
     * name, the variety and the start date, applied over or beside the blank
     * one. THE ONLY PLACE A PARTIAL SHEET OCCURS, which is why the
     * start-at-position control is here and not on the blank-sheet path.
     */
    public function labelsPdf(Request $request): Response
    {
        $stock = LabelStock::orFallback((string) $request->query('stock', $this->userStock()));
        $startAt = \max(0, (int) ($request->query('start', '0') ?? '0'));

        $tags = $this->namedQueue();
        if ($tags === []) {
            $this->flash('Nothing to print: every tag you have is either free or already '
                . 'has a named label.', 'error');
            return $this->redirect('tags');
        }

        $sheet = new LabelSheet($stock, $this->tagBase());
        $sheet->namedLabels($tags, $startAt);

        return $this->pdf($sheet->render(), 'carl-named-labels.pdf');
    }

    /** `POST /tags/batches/{id}/retire` -- the sheet you lost, and the undo. */
    public function retire(Request $request, array $params): Response
    {
        $batch = $this->requireBatch((int) $params['id']);
        $retiring = $batch['retired_at'] === null;

        $count = $this->tags()->retireBatch((int) $batch['id'], $retiring);

        $this->flash($retiring
            ? $count . ' codes retired. Nothing is deleted -- if the sheet turns up in a '
              . 'drawer next spring, un-retire it and the codes still work.'
            : $count . ' codes are back in the pool.');

        return $this->redirect('tags/batches/' . $batch['id']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * The actions the field screen offers: what the state allows, minus the
     * ones a single tap cannot honestly record.
     *
     * PlantingState::actionsFor() is the authority and this narrows it. The
     * ones dropped need a second answer that a one-tap screen does not ask
     * for -- how many died, where it was transplanted to, which pest, how
     * heavy the harvest -- and guessing a default for those writes a number
     * nobody said. They are all one tap away on /log/{id}, which the screen
     * links to under the fold.
     *
     * @return list<string>
     */
    private static function fieldActions(string $state): array
    {
        $needsAnAnswer = [
            \Carl\Domain\EventType::DIED,
            \Carl\Domain\EventType::CULLED,
            \Carl\Domain\EventType::GERMINATION_FAILED,
            \Carl\Domain\EventType::TRANSPLANTED,
            \Carl\Domain\EventType::UP_POTTED,
            \Carl\Domain\EventType::MOVED,
            \Carl\Domain\EventType::HARDENING_SCHEDULE_SET,
            // A measurement IS a number, so a one-tap "Measured" would record
            // that somebody looked at the plant and nothing about its size.
            \Carl\Domain\EventType::MEASURED,
            \Carl\Domain\EventType::PHOTO_ADDED,
        ];

        return \array_values(\array_diff(PlantingState::actionsFor($state), $needsAnAnswer));
    }

    /** @return array<string,mixed> @throws HttpException */
    private function requireBoundTag(string $rawCode): array
    {
        $code = TagRepository::normalise($rawCode);
        $tag = $this->tags()->scan($code);
        if ($tag === null || $tag['planting_id'] === null) {
            throw HttpException::notFound('No such tag.');
        }
        return $tag;
    }

    /** @return array<string,mixed> @throws HttpException */
    private function requireBatch(int $id): array
    {
        $batch = $this->tags()->findBatch($id);
        if ($batch === null) {
            throw HttpException::notFound('That is not one of your tag sheets.');
        }
        return $batch;
    }

    /**
     * Tags that are on a plant and worth a named label.
     *
     * @return list<array<string,mixed>>
     */
    private function namedQueue(): array
    {
        $out = [];
        foreach ($this->tags()->batches() as $batch) {
            foreach ($this->tags()->batchTags((int) $batch['id']) as $tag) {
                if ($tag['label'] !== null || $tag['category'] !== null) {
                    $out[] = $tag;
                }
            }
        }
        return $out;
    }

    private function userStock(): string
    {
        return LabelStock::orFallback($this->user()->labelStock);
    }

    /** The tag URL up to the code, in the case it will be encoded in. */
    private function tagBase(): string
    {
        return TagUrl::base(
            $this->app->config()->string('tags.origin'),
            $this->app->basePath(),
            $this->uppercase()
        );
    }

    private function uppercase(): bool
    {
        /** @var bool|null $configured */
        $configured = $this->app->config()->get('tags.uppercase_url');
        return TagUrl::uppercaseIsSafe(
            \is_bool($configured) ? $configured : null,
            $this->app->basePath()
        );
    }

    /** @return array{uppercase:bool,sample:string,mode:string,version:int,size:int,module_mm:float,headroom:int} */
    private function encoding(): array
    {
        return TagUrl::describe(
            $this->app->config()->string('tags.origin'),
            $this->app->basePath(),
            $this->uppercase()
        );
    }

    private function svgFor(string $code): string
    {
        return Svg::render(
            Encoder::encode($this->tagBase() . $code, TagUrl::EC_LEVEL),
            5,
            'Tag ' . $code
        );
    }

    /** @param array<string,mixed> $row */
    private static function plantName(array $row): string
    {
        $label = \trim((string) ($row['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        return \trim(((string) ($row['category'] ?? '')) . ' ' . ((string) ($row['type'] ?? '')));
    }

    private function pdf(string $document, string $filename): Response
    {
        return Response::binary($document, 'application/pdf', $filename)
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
