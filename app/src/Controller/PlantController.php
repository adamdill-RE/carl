<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\EventType;
use Carl\Domain\ListType;
use Carl\Domain\PlantingState;
use Carl\Support\Clock;

/**
 * Start a New Plant (handoff Section 4.3) and View Plants (Section 4.5).
 *
 * Three entry forms; the choice sets the initial event and the initial state.
 * Every date defaults to the user's local today and accepts the past.
 */
final class PlantController extends Controller
{
    private const KINDS = [
        'indoor_seed' => [
            'title' => 'Indoor seed start',
            'blurb' => 'Seeds into trays. Goes into your Indoor Garden by default.',
            'event' => EventType::SEED_STARTED,
        ],
        'direct_sow' => [
            'title' => 'Direct sow',
            'blurb' => 'Seeds straight into a row or a container.',
            'event' => EventType::DIRECT_SOWN,
        ],
        'nursery_transplant' => [
            'title' => 'Transplant',
            'blurb' => 'Nursery-bought, or a plant whose origin you did not log.',
            'event' => EventType::TRANSPLANTED_IN,
        ],
    ];

    public function chooseKind(Request $request): Response
    {
        return $this->render('plants/choose', [
            'kinds' => self::KINDS,
            // "Start a new plant with this tag" from a scan lands here
            // (docs/QR-TAGS-SPEC.md Section 6.4 item 2). The code rides
            // through the kind choice and the form as a value, and binds
            // when the plant is saved.
            'tag'   => \Carl\Repo\TagRepository::normalise((string) ($request->query('tag', '') ?? '')),
        ]);
    }

    public function newForm(Request $request, array $params): Response
    {
        $kind = (string) $params['kind'];
        return $this->render('plants/form', $this->formData($kind, $request));
    }

    public function create(Request $request): Response
    {
        $user = $this->user();
        $kind = $this->choice($request, 'start_method', \array_keys(self::KINDS), 'indoor_seed');

        $plantTypeId = $request->intInput('plant_type_id');
        $errors = [];

        if ($plantTypeId === null || $this->reference()->findPlantType($plantTypeId) === null) {
            $errors[] = 'Choose a plant category and type.';
        }

        $startDate = $this->eventDate($request, 'start_date');
        $quantity = $request->intInput('quantity_initial', 1) ?? 1;
        if ($quantity < 1 || $quantity > 100000) {
            $errors[] = 'Quantity must be between 1 and 100,000.';
        }

        // Where it goes. Indoor seed starts default to the Indoor Garden;
        // everything else needs an explicit garden row or container.
        $gardenId = $request->intInput('garden_id');
        $rowId = $request->intInput('garden_row_id');
        $containerId = $request->intInput('container_id');

        if ($kind === 'indoor_seed' && $gardenId === null) {
            $gardenId = $this->gardens()->ensureIndoorGarden();
        }
        if ($gardenId !== null && !$this->gardens()->exists($gardenId)) {
            $errors[] = 'That garden is not one of yours.';
            $gardenId = null;
        }
        if ($rowId !== null && $this->gardens()->findRow($rowId) === null) {
            $errors[] = 'That row is not one of yours.';
            $rowId = null;
        }
        if ($containerId !== null && $this->gardens()->findContainer($containerId) === null) {
            $errors[] = 'That container is not one of yours.';
            $containerId = null;
        }
        if ($kind !== 'indoor_seed' && $gardenId === null && $containerId === null) {
            $errors[] = 'Say where it went in: a garden row, or a container.';
        }

        // The stakes, if any were ticked -- off the grid at the foot of the
        // form, or one carried in from a scan (QR-TAGS-SPEC Section 5.2,
        // both ends). A tray of twenty-four cells takes twenty-four
        // (Section 14.7). Checked HERE, before anything is written: a
        // choice that is quietly dropped is the field that "stays null on
        // every plant" (PHASE-13-HANDOFF Section 8); a choice refused with
        // the reason is a form that can be corrected.
        $tagRows = [];
        foreach ($this->chosenTagCodes($request) as $tagCode) {
            $tagRow = \Carl\Repo\TagRepository::isWellFormed($tagCode) ? $this->tags()->scan($tagCode) : null;
            if ($tagRow === null) {
                $errors[] = 'No free tag of yours has the code ' . $tagCode . '.';
            } elseif ($tagRow['tag_retired_at'] !== null) {
                $errors[] = 'Tag ' . $tagCode . ' is retired. Untick it, or put it back first.';
            } elseif ($tagRow['planting_id'] !== null) {
                $onName = \trim((string) $tagRow['label']) !== ''
                    ? \trim((string) $tagRow['label'])
                    : \trim((string) $tagRow['category'] . ' ' . (string) $tagRow['type']);
                $errors[] = 'Tag ' . $tagCode . ' is already on ' . $onName . '. Untick it.';
            } else {
                $tagRows[] = $tagRow;
            }
        }

        if ($errors !== []) {
            // The errors on the LEFT of the union. formData() carries an
            // empty 'errors' key of its own and PHP's + keeps the left-hand
            // value for a key both sides have, so the other way round -- which
            // is how this read from Phase 1 to Phase 13 -- rendered the form
            // with every error silently dropped. Nobody found out because
            // the browser's own `required` caught the common cases first;
            // the tag picker is the first check the browser cannot make.
            return $this->render('plants/form',
                ['errors' => $errors] + $this->formData($kind, $request));
        }

        $collarUsed = $request->checkbox('collar_used');
        $seedsPerCollar = $request->intInput('seeds_per_collar');

        $data = [
            'plant_type_id'    => $plantTypeId,
            'garden_id'        => $gardenId,
            'garden_row_id'    => $rowId,
            'container_id'     => $containerId,
            'label'            => $request->nullable('label'),
            'start_method'     => $kind,
            'start_date'       => $startDate,
            'quantity_initial' => $quantity,
            'quantity_live'    => $quantity,
            'state'            => $kind === 'indoor_seed'
                ? PlantingState::SEED_STARTED : PlantingState::PLANTED,
            'state_changed_at' => \gmdate('Y-m-d H:i:s'),
            'in_ground_date'   => $kind === 'indoor_seed' ? null : $startDate,
            'trellis_used'     => $request->checkbox('trellis_used') ? 1 : 0,
            'collar_used'      => $collarUsed ? 1 : 0,
            'seeds_per_collar' => $collarUsed ? $seedsPerCollar : null,
            'initial_height_in' => $request->floatInput('initial_height_in'),
            'initial_width_in'  => $request->floatInput('initial_width_in'),
            'notes'            => $request->nullable('notes'),
            'seed_source_id'   => $this->lists()->resolveChoice(
                ListType::SEED_SOURCE, $request->input('seed_source_id'), $request->input('seed_source_new')),
            'nursery_id'       => $this->lists()->resolveChoice(
                ListType::NURSERY, $request->input('nursery_id'), $request->input('nursery_new')),
            'default_water_method_id' => $this->lists()->resolveChoice(
                ListType::WATER_METHOD, $request->input('water_method_id'), $request->input('water_method_new')),
        ];

        $plantingId = $this->plantings()->insert($data);

        // The initial event. Everything a planting knows about itself beyond
        // the asset row is an event, including the one that created it.
        $eventData = [
            'quantity_delta' => null,
            'garden_id'      => $gardenId,
            'garden_row_id'  => $rowId,
            'container_id'   => $containerId,
            'narrative'      => $request->nullable('notes'),
        ];

        if ($kind === 'indoor_seed') {
            $eventData['ref_list_item_id'] = $this->lists()->resolveChoice(
                ListType::SEED_STARTING_SOIL, $request->input('soil_id'), $request->input('soil_new'));
            $eventData['ref_list_item_id_2'] = $this->lists()->resolveChoice(
                ListType::SEED_STARTING_VESSEL, $request->input('vessel_id'), $request->input('vessel_new'));
        } elseif ($kind === 'direct_sow') {
            $eventData['ref_list_item_id'] = $this->lists()->resolveChoice(
                ListType::FERTILIZER_SOW, $request->input('fertilizer_id'), $request->input('fertilizer_new'));
            $eventData['payload'] = [
                'collar_used'      => $collarUsed,
                'seeds_per_collar' => $seedsPerCollar,
            ];
        } else {
            $eventData['ref_list_item_id'] = $this->lists()->resolveChoice(
                ListType::FERTILIZER_SOW, $request->input('fertilizer_id'), $request->input('fertilizer_new'));
        }

        $eventId = $this->events()->record(
            $plantingId, self::KINDS[$kind]['event'], $startDate, $eventData
        );

        $photoIds = $request->intList('photo_ids');
        if ($photoIds !== []) {
            $this->photos()->attachToEvent($photoIds, $eventId);
            $this->app->db()->run(
                'UPDATE `photo` SET `planting_id` = :planting_id'
                . ' WHERE `user_id` = :user_id AND `plant_event_id` = :event_id',
                ['planting_id' => $plantingId, 'user_id' => $this->userId(), 'event_id' => $eventId]
            );
        }

        // A backdated plant pulls the weather window back with it, and the
        // nightly run fetches the gap (handoff Section 8.1).
        $this->extendWeatherBackfill($startDate);

        // Binding happens HERE, on submit, because until now there was no
        // planting to bind to -- whether the codes came from a scan ("start a
        // new plant with this tag", QR-TAGS-SPEC Section 6.4) or off the
        // grid. Every one was checked free above, before the plant was
        // written.
        foreach ($tagRows as $tagRow) {
            $this->tags()->bindTo((int) $tagRow['tag_id'], $plantingId);
        }

        $stakes = \count($tagRows);
        $this->flash($stakes === 0
            ? 'Plant recorded. Log what happens to it from Log Plant Activity.'
            : ($stakes === 1
                ? 'Plant recorded, and tag ' . $tagRows[0]['code'] . ' is on it. Scan it to log anything.'
                : 'Plant recorded with ' . $stakes . ' stakes on it. Scan any of them to log anything.'));

        // `started` is what makes the plant page offer "Start another" at the
        // top (Phase 15). A query flag rather than a second flash, because a
        // flash is spent on the next render and this one has to survive the
        // reader pressing back from the form to look at what they just
        // recorded. Nothing reads it but show(), and a stale one costs a
        // button that is true anyway.
        return $this->redirect('plants/' . $plantingId, ['started' => '1']);
    }

    /** View Plants: the list, living and culled (handoff Section 4.5). */
    public function index(Request $request): Response
    {
        $filters = $this->readFilters($request);

        // A tag code in the search box goes straight to that plant's report
        // page. Controller::tagCodeJump() is the whole argument; it returns
        // null for anything that is not one of this account's codes, and the
        // search below then behaves exactly as it always did.
        $jump = $this->tagCodeJump((string) $filters['search'], 'plants');
        if ($jump !== null) {
            return $jump;
        }

        $plantings = $this->plantings()->listWithDetail($filters);
        $ids = \array_map(static fn (array $p): int => (int) $p['id'], $plantings);

        return $this->render('plants/index', [
            'plantings'   => $plantings,
            'photoCounts' => $this->photos()->countsForPlantings($ids),
            'filters'     => $filters,
            'options'     => $this->plantings()->filterOptions(),
            'gardens'     => $this->gardens()->activeGardens(),
            'title'       => 'View Plants',
            'target'      => 'plants',
        ]);
    }

    /**
     * The plant report (handoff Section 4.5), now with the Phase 4 charts.
     *
     * The weather half comes from the Series builder, which is also what
     * `/api/plant/<id>/series` returns -- so the totals printed here and the
     * chart drawn beside them cannot disagree about which days a plant
     * covers. It also costs one weather statement where this page used to
     * spend three (series, gapCount, sourceModels), because the gap count and
     * the source models are both answerable from the rows already in hand.
     */
    public function show(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $user = $this->user();
        $card = $this->reference()->researchCard((int) $planting['plant_type_id'], $user->regionId);
        $events = $this->events()->timeline($plantingId);
        $photos = $this->photos()->forPlanting($plantingId);
        $yield = $this->plantings()->yieldSummary($plantingId);

        // The row is already loaded and already user-scoped, so the builder
        // is handed it rather than looking it up again.
        $series = $this->series()->forPlantingRow($planting, $user->weatherLocationId, $this->today());

        return $this->render('plants/show', [
            'planting'      => $planting,
            // ONE statement, and only for a page that can show something: a
            // planting that was never split off anything and never sent
            // anything out has no lineage to draw, and asking costs a
            // statement on every plant page in the app to answer "no" for
            // almost all of them (docs/PLANTING-SPLIT-SPEC.md Section 4.6).
            'lineage'       => $this->lineageFor($planting),
            'card'          => $card,
            'events'        => $events,
            'photos'        => $photos,
            'yield'         => $yield,
            'series'        => $series,
            'weatherModels' => $series['sources'],
            'seriesUrl'     => $this->app->url('api/plant/' . $plantingId . '/series'),
            'pdfUrl'        => $this->app->url('report/plant/' . $plantingId . '/pdf'),
            'countdowns'    => $this->countdowns($planting, $card),
            // ONE statement, over (planting_id, unbound_at): every stake on
            // the plant. The plant page is where stakes get assigned from
            // the desk -- the other half of QR-TAGS-SPEC Section 5.2, whose
            // first half is the scan.
            'tags'          => $this->tags()->tagsOn($plantingId),
            // And one more for the codes still in the box, so "assign a tag"
            // here is a picker and not a link to another screen. The
            // statement-count tests on this page compare a big plant with a
            // small one and a split with an unsplit; a constant on both
            // sides is what they allow.
            'free'          => $this->tags()->free(),
            'startAnother'  => $request->query('started') !== null
                ? $this->startAnother($planting) : null,
        ]);
    }

    /**
     * "Start another" -- the same kind of start again, offered at the top of
     * the page a new plant lands on (Phase 15).
     *
     * A tray of starts is entered one variety at a time, and until this
     * existed each one was three taps back through the menu and the kind
     * chooser. The kind is the plant's own start_method, so the button says
     * "Start another indoor seed start" and not "start another plant"; the
     * date rides along because a batch sown last Saturday is backdated once,
     * not once per variety. Nothing else is carried: the next variety is a
     * different packet, and prefilling the last one is how a tray of six
     * gets recorded as six of the same thing.
     *
     * @param array<string,mixed> $planting
     * @return array{title:string,url:string}|null
     */
    private function startAnother(array $planting): ?array
    {
        $kind = (string) $planting['start_method'];
        if (!isset(self::KINDS[$kind])) {
            return null;
        }
        return [
            'title' => self::KINDS[$kind]['title'],
            'url'   => $this->app->url('plants/new/' . $kind, [
                'start_date' => (string) $planting['start_date'],
            ]),
        ];
    }

    /**
     * The lineage panel's data, or an empty one without asking.
     *
     * A planting knows from its own row whether it came out of something:
     * split_from_id is right there. What it cannot know without asking is
     * whether anything came out of IT -- but a planting that has never been
     * split has quantity_live + quantity_lost = quantity_initial, because
     * dispersal is the only other thing that takes plants off a row. So the
     * question is answered from the row in hand, and the statement is spent
     * only by the pages that have something to show for it.
     *
     * @param array<string,mixed> $planting
     * @return array{parent:?array<string,mixed>,children:list<array<string,mixed>>}
     */
    private function lineageFor(array $planting): array
    {
        $splitFrom = $planting['split_from_id'] === null ? null : (int) $planting['split_from_id'];
        $dispersed = (int) $planting['quantity_initial']
            - (int) $planting['quantity_lost'] - (int) $planting['quantity_live'];

        if ($splitFrom === null && $dispersed <= 0) {
            return ['parent' => null, 'children' => []];
        }
        return $this->plantings()->lineage((int) $planting['id'], $splitFrom);
    }

    /** The research card, fetched when a plant type is chosen on a form. */
    public function researchCard(Request $request, array $params): Response
    {
        $card = $this->reference()->researchCard((int) $params['id'], $this->user()->regionId);
        if ($card['plant'] === null) {
            throw HttpException::notFound();
        }
        return Response::html($this->app->view()->partial('plants/research_card', [
            'card'     => $card,
            'hasRegion' => $this->user()->hasRegion(),
        ]));
    }

    // -- Helpers ---------------------------------------------------------

    /**
     * The three fields the succession planner may fill in for the reader.
     *
     * Deliberately not "whatever is in the query string": every value here is
     * re-validated by create() anyway, but a form that will echo any
     * parameter it is handed is a form somebody can build a misleading link
     * to. Three named fields cannot be.
     *
     * @return array<string,string>
     */
    private static function prefillFrom(Request $request): array
    {
        $out = [];
        foreach (['plant_type_id', 'category', 'start_date'] as $field) {
            $value = $request->query($field);
            if ($value !== null && $value !== '') {
                $out[$field] = $value;
            }
        }
        return $out;
    }


    /** @return array<string,mixed> */
    private function formData(string $kind, Request $request): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw HttpException::notFound();
        }
        $user = $this->user();

        $listTypes = [
            ListType::SEED_SOURCE, ListType::SEED_STARTING_SOIL, ListType::SEED_STARTING_VESSEL,
            ListType::FERTILIZER_SOW, ListType::NURSERY, ListType::WATER_METHOD,
        ];

        $gardens = $this->gardens()->activeGardens();
        $gardenIds = \array_map(static fn (array $g): int => (int) $g['id'], $gardens);

        // How far back a bed's history still counts against planting the same
        // family in it again (Phase 5 handoff Section 3.4). Three years is
        // the conventional rotation for the families that need one.
        $rotationYears = \max(1, $this->app->config()->int('rotation.years', 3));
        $rotationSince = (string) Clock::addDays($this->today(), -($rotationYears * 365));

        return [
            'kind'       => $kind,
            // A code carried in from a scan ("start a new plant with this
            // tag"), and on a POST the codes that were ticked, so the form
            // comes back with them still ticked.
            'tag'        => \Carl\Repo\TagRepository::normalise((string) ($request->query('tag', '') ?? '')),
            'chosenTags' => $request->isPost()
                ? $this->chosenTagCodes($request)
                : \array_values(\array_filter([\Carl\Repo\TagRepository::normalise((string) ($request->query('tag', '') ?? ''))])),
            // "At the foot of Start a New Plant: assign a tag" (QR-TAGS-SPEC
            // Section 5.2). The grid shows the codes still in the box.
            'free'       => $this->tags()->free(),
            'meta'       => self::KINDS[$kind],
            'plantTypes' => $this->reference()->plantTypesForRegion($user->regionId, $this->today()),
            'hasRegion'  => $user->hasRegion(),
            'gardens'    => $gardens,
            'rowsByGarden' => $this->gardens()->rowsForGardens($gardenIds),
            'containers' => $this->gardens()->containers(),
            'lists'      => $this->lists()->manyTypes($listTypes),
            'today'      => $this->today(),
            'indoorGardenId' => $this->indoorGardenId($gardens),
            // The hint beside each row option (handoff Section 4.3). One
            // statement for every garden, because the row select carries the
            // rows of all of them and filters in the browser.
            'occupancy'  => $this->gardens()->livingCountByRow(),
            // The crop rotation history (Phase 5 handoff Section 3.4), which
            // is the same shape and the same trick: one statement for every
            // row this user has, because the select carries all of them.
            'rotation'      => $this->plantings()->familyHistoryByRow($rotationSince),
            'rotationYears' => $rotationYears,
            'errors'     => [],
            // On a POST this is what the person just typed, so the form can
            // come back with their work in it. On a GET it is the succession
            // planner handing over a crop and a date (Phase 6): the planner's
            // "Sow" link is only useful if it arrives filled in, and a
            // whitelist is what keeps that from being an open prefill of
            // every field in the form from the query string.
            'old'        => $request->isPost()
                ? $request->post
                : self::prefillFrom($request),
        ];
    }

    /**
     * The tag codes ticked on the form, normalised and de-duplicated, in the
     * order they were ticked. `tags[]` from the grid; `tag` is the single
     * hidden value an older link may still carry.
     *
     * @return list<string>
     */
    private function chosenTagCodes(Request $request): array
    {
        $raw = $request->inputList('tags');
        $single = (string) ($request->input('tag', '') ?? '');
        if ($single !== '') {
            $raw[] = $single;
        }
        $out = [];
        foreach ($raw as $code) {
            $code = \Carl\Repo\TagRepository::normalise($code);
            if ($code !== '' && !\in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $gardens */
    private function indoorGardenId(array $gardens): ?int
    {
        foreach ($gardens as $garden) {
            if ((int) $garden['is_indoor'] === 1) {
                return (int) $garden['id'];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readFilters(Request $request): array
    {
        return [
            'category'      => $request->query('category', '') ?? '',
            'type'          => $request->query('type', '') ?? '',
            'state'         => $request->query('state', '') ?? '',
            'garden_id'     => (int) ($request->query('garden_id', '0') ?? '0'),
            'garden_row_id' => (int) ($request->query('garden_row_id', '0') ?? '0'),
            'search'        => $request->query('q', '') ?? '',
        ];
    }

    /**
     * DTM countdowns (handoff Section 9.1): dtm_counted_from decides the
     * anchor event, and a region override replaces the global value.
     *
     * @param array<string,mixed> $planting
     * @param array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
     * @return list<array{label:string,date:string,days:?int}>
     */
    private function countdowns(array $planting, array $card): array
    {
        $today = $this->today();
        $out = [];

        $anchor = (string) $planting['dtm_counted_from'] === 'transplant'
            ? ($planting['in_ground_date'] ?? null)
            : (string) $planting['start_date'];

        $min = $planting['dtm_days_min'];
        $max = $planting['dtm_days_max'];
        foreach ($card['regions'] as $region) {
            if ($region['dtm_days_min_override'] !== null) {
                $min = $region['dtm_days_min_override'];
            }
            if ($region['dtm_days_max_override'] !== null) {
                $max = $region['dtm_days_max_override'];
            }
        }

        if (\is_string($anchor) && $min !== null) {
            $date = Clock::addDays($anchor, (int) $min);
            if ($date !== null) {
                $out[] = [
                    'label' => 'First harvest expected',
                    'date'  => $date,
                    'days'  => Clock::daysBetween($today, $date),
                ];
            }
        }
        if (\is_string($anchor) && $max !== null) {
            $date = Clock::addDays($anchor, (int) $max);
            if ($date !== null) {
                $out[] = [
                    'label' => 'Harvest window closes around',
                    'date'  => $date,
                    'days'  => Clock::daysBetween($today, $date),
                ];
            }
        }

        // Hardening countdown: start date + duration - today (handoff 5.5).
        if ($planting['hardening_started_at'] !== null && $planting['hardening_days'] !== null) {
            $due = Clock::addDays((string) $planting['hardening_started_at'], (int) $planting['hardening_days']);
            if ($due !== null) {
                $out[] = [
                    'label' => 'Transplant due',
                    'date'  => $due,
                    'days'  => Clock::daysBetween($today, $due),
                ];
            }
        }

        return $out;
    }

    /**
     * Backdating a plant past the weather window pulls backfill_from back.
     * Nothing is fetched here -- the nightly run picks the gap up
     * (weather.md Section 3, rule 2).
     */
    private function extendWeatherBackfill(string $startDate): void
    {
        $locationId = $this->user()->weatherLocationId;
        if ($locationId === null) {
            return;
        }
        $earliest = $this->plantings()->earliestStartDate() ?? $startDate;
        $this->weather()->extendBackfill($locationId, $earliest);
    }
}
