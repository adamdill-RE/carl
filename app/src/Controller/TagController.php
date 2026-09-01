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
            return $this->bindScreen($request, $tag);
        }

        return $this->fieldScreen($request, $tag);
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
            'tag'       => $tag,
            'qr'        => $this->svgFor((string) $tag['code']),
            'untagged'  => $this->tags()->untagged($search),
            'search'    => $search,
            'session'   => $session,
            'pageTitle' => 'Tag ' . $tag['code'],
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
     * Reached from the bind screen's list, from "assign a tag" on a plant
     * page, and from a tagging session. Both directions of Section 5.2 end
     * here: tag-first (the seed-starting case, which is why the pre-printed
     * pool exists) and planting-first (the desk case).
     */
    public function bind(Request $request, array $params): Response
    {
        $code = TagRepository::normalise((string) $params['code']);
        $tag = $this->tags()->scan($code);
        if ($tag === null) {
            throw HttpException::notFound('No such tag.');
        }
        if ($tag['tag_retired_at'] !== null) {
            $this->flash('That tag is retired. Un-retire its sheet first.', 'error');
            return $this->redirect('tags');
        }

        $plantingId = (int) $request->input('planting_id', '0');
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $existing = $this->tags()->forPlanting($plantingId);
        // Section 6.4: assigning to a plant that already has a tag silently
        // unbinds the old one, so it is behind a confirmation. This is the
        // replacement-for-a-destroyed-tag path, not an everyday one.
        if ($existing !== null && !$request->checkbox('replace')) {
            $this->flash(
                self::plantName($planting) . ' already has tag ' . $existing['code']
                . '. Tick "replace the existing tag" if that one is lost or ruined.',
                'error'
            );
            return $this->redirect('t/' . $code);
        }

        $bindingId = $this->tags()->bindTo((int) $tag['tag_id'], $plantingId);

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

        return $this->redirect('t/' . $tag['code']);
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
        $starting = $request->input('action', 'start') === 'start';

        $this->app->db()->run(
            'UPDATE `user` SET `tagging_started_at` = :started, `updated_at` = UTC_TIMESTAMP()'
            . ' WHERE `id` = :id',
            ['started' => $starting ? \gmdate('Y-m-d H:i:s') : null, 'id' => $this->userId()]
        );

        $this->flash($starting
            ? 'Tagging. Scan a tag and it goes on the plant named at the top.'
            : 'Tagging stopped.');

        return $this->back($request, 'tags');
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
        $startedAt = $this->user()->taggingStartedAt;
        if ($startedAt === null) {
            return null;
        }

        // An expiry, an explicit stop and a visible banner (Section 6.5). A
        // silent binding mode that outlives the potting session is a way to
        // attach a tag to the wrong plant a week later and never find out.
        $expires = \strtotime($startedAt . ' UTC') + TagRepository::SESSION_HOURS * 3600;
        if ($expires < \time()) {
            return null;
        }

        $next = $this->tags()->nextUntagged();
        if ($next === null) {
            // The list emptied. The session ends itself and says so.
            return ['next' => null, 'bound' => $this->tags()->boundSince($startedAt),
                    'remaining' => 0, 'started_at' => $startedAt];
        }

        return [
            'next'       => $next,
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
