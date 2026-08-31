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
        return $this->render('plants/choose', ['kinds' => self::KINDS]);
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

        if ($errors !== []) {
            return $this->render('plants/form',
                $this->formData($kind, $request) + ['errors' => $errors]);
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

        $this->flash('Plant recorded. Log what happens to it from Log Plant Activity.');
        return $this->redirect('plants/' . $plantingId);
    }

    /** View Plants: the list, living and culled (handoff Section 4.5). */
    public function index(Request $request): Response
    {
        $filters = $this->readFilters($request);
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
            'card'          => $card,
            'events'        => $events,
            'photos'        => $photos,
            'yield'         => $yield,
            'series'        => $series,
            'weatherModels' => $series['sources'],
            'seriesUrl'     => $this->app->url('api/plant/' . $plantingId . '/series'),
            'pdfUrl'        => $this->app->url('report/plant/' . $plantingId . '/pdf'),
            'countdowns'    => $this->countdowns($planting, $card),
        ]);
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

        return [
            'kind'       => $kind,
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
            'errors'     => [],
            'old'        => $request->isPost() ? $request->post : [],
        ];
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
