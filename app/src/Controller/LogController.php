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
 * Log Plant Activity (handoff Section 4.4).
 *
 * One screen lists the plants with filters; tapping one -- or selecting
 * several for a batch -- opens the actions its state allows. Each action
 * writes exactly one plant_event, all backdatable, all able to carry a
 * narrative and photos.
 */
final class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'category'      => $request->query('category', '') ?? '',
            'type'          => $request->query('type', '') ?? '',
            'state'         => $request->query('state', '') ?? '',
            'garden_id'     => (int) ($request->query('garden_id', '0') ?? '0'),
            'garden_row_id' => (int) ($request->query('garden_row_id', '0') ?? '0'),
            'search'        => $request->query('q', '') ?? '',
            'living'        => $request->query('all') === null,
        ];

        $plantings = $this->plantings()->listWithDetail($filters);
        $ids = \array_map(static fn (array $p): int => (int) $p['id'], $plantings);

        return $this->render('plants/index', [
            'plantings'   => $plantings,
            'photoCounts' => $this->photos()->countsForPlantings($ids),
            'filters'     => $filters,
            'options'     => $this->plantings()->filterOptions(),
            'gardens'     => $this->gardens()->activeGardens(),
            'title'       => 'Log Plant Activity',
            'target'      => 'log',
            'batch'       => true,
        ]);
    }

    public function plant(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        return $this->render('plants/log', $this->logFormData([$planting]));
    }

    public function record(Request $request, array $params): Response
    {
        $plantingId = (int) $params['id'];
        $planting = $this->plantings()->findWithDetail($plantingId);
        if ($planting === null) {
            throw HttpException::notFound('That is not one of your plants.');
        }

        $eventType = (string) $request->input('event_type', '');
        $allowed = PlantingState::actionsFor((string) $planting['state']);
        if (!\in_array($eventType, $allowed, true)) {
            throw HttpException::badRequest(
                'A plant in the "' . PlantingState::label((string) $planting['state'])
                . '" state cannot record that.'
            );
        }

        $eventDate = $this->eventDate($request);
        $data = $this->eventData($request, $eventType, $planting);

        $eventId = $this->events()->record($plantingId, $eventType, $eventDate, $data);
        $this->afterRecord($request, $eventType, $plantingId, $eventId, $eventDate, $data);

        $this->flash(EventType::label($eventType) . ' recorded for '
            . $planting['category'] . ' ' . $planting['type'] . '.');

        return $this->redirect('log/' . $plantingId);
    }

    /** The batch action: the same event applied to each selected plant. */
    public function batch(Request $request): Response
    {
        $ids = $request->intList('planting_ids');
        if ($ids === []) {
            $this->flash('Select at least one plant first.', 'error');
            return $this->redirect('log');
        }

        $plantings = $this->plantings()->findManyWithDetail($ids);
        if ($plantings === []) {
            throw HttpException::notFound('None of those are your plants.');
        }

        // Step one: the form, showing only the actions every selected plant
        // can take, so a batch cannot half-apply.
        if ($request->input('event_type') === null) {
            return $this->render('plants/log', $this->logFormData($plantings));
        }

        $eventType = (string) $request->input('event_type');
        foreach ($plantings as $planting) {
            if (!\in_array($eventType, PlantingState::actionsFor((string) $planting['state']), true)) {
                throw HttpException::badRequest(
                    'Not every selected plant can record ' . EventType::label($eventType) . '.'
                );
            }
        }

        $eventDate = $this->eventDate($request);
        $data = $this->eventData($request, $eventType, $plantings[0]);

        // Quantities default to each planting's own live count, so "all of
        // them" means each plant's own remainder (handoff Section 4.4).
        if ($request->checkbox('quantity_all') && EventType::isAttrition($eventType)) {
            unset($data['quantity_delta']);
            $data['quantity_all'] = true;
        }

        $written = $this->events()->recordBatch(
            \array_map(static fn (array $p): int => (int) $p['id'], $plantings),
            $eventType,
            $eventDate,
            $data
        );

        $this->flash(EventType::label($eventType) . ' recorded for ' . $written . ' plants.');
        return $this->redirect('log');
    }

    // -- Helpers ---------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $plantings
     * @return array<string,mixed>
     */
    private function logFormData(array $plantings): array
    {
        $user = $this->user();

        // The offered actions are the intersection across a batch: an action
        // only some of them can take is not offered at all.
        $actions = null;
        foreach ($plantings as $planting) {
            $forState = PlantingState::actionsFor((string) $planting['state']);
            $actions = $actions === null ? $forState : \array_values(\array_intersect($actions, $forState));
        }

        $first = $plantings[0];

        return [
            'plantings' => $plantings,
            'single'    => \count($plantings) === 1 ? $first : null,
            'actions'   => $actions ?? [],
            'card'      => $this->reference()->researchCard((int) $first['plant_type_id'], $user->regionId),
            'hasRegion' => $user->hasRegion(),
            'today'     => $this->today(),
            'gardens'   => $this->gardens()->activeGardens(),
            'rowsByGarden' => $this->gardens()->rowsForGardens(
                \array_map(static fn (array $g): int => (int) $g['id'], $this->gardens()->activeGardens())
            ),
            'containers' => $this->gardens()->containers(),
            'schedules'  => $this->hardeningSchedules(),
            'lists'      => $this->lists()->manyTypes([
                ListType::WATER_METHOD, ListType::UP_POT_SOIL, ListType::UP_POT_CONTAINER,
                ListType::FERTILIZER_GARDEN, ListType::SOIL_AMENDMENT, ListType::MULCH_TYPE,
                ListType::PEST_DISEASE, ListType::PEST_TREATMENT, ListType::CULL_REASON,
            ]),
            'timeline'  => \count($plantings) === 1
                ? $this->events()->timeline((int) $first['id'])
                : [],
        ];
    }

    /**
     * Build the event row for one action.
     *
     * @param array<string,mixed> $planting
     * @return array<string,mixed>
     */
    private function eventData(Request $request, string $eventType, array $planting): array
    {
        $data = [
            'narrative'    => $request->nullable('narrative'),
            'duration_min' => $request->intInput('duration_min'),
        ];

        $live = (int) $planting['quantity_live'];
        $quantity = $request->intInput('quantity');

        switch ($eventType) {
            case EventType::WATERED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::WATER_METHOD, $request->input('water_method_id'), $request->input('water_method_new'));
                break;

            case EventType::GERMINATED:
                // germinated has delta 0 but sets a marker (handoff 5.3).
                $data['quantity_delta'] = 0;
                $data['count_qty'] = $quantity ?? $live;
                break;

            case EventType::GERMINATION_FAILED:
            case EventType::DIED:
            case EventType::CULLED:
                $count = $quantity === null ? $live : \max(0, \min($quantity, $live));
                $data['quantity_delta'] = -$count;
                $data['count_qty'] = $count;
                if ($eventType === EventType::CULLED) {
                    $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                        ListType::CULL_REASON, $request->input('cull_reason_id'), $request->input('cull_reason_new'));
                }
                break;

            case EventType::UP_POTTED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::UP_POT_SOIL, $request->input('soil_id'), $request->input('soil_new'));
                $data['ref_list_item_id_2'] = $this->lists()->resolveChoice(
                    ListType::UP_POT_CONTAINER, $request->input('container_type_id'), $request->input('container_type_new'));
                break;

            case EventType::HARDENING_STARTED:
                $scheduleId = $request->intInput('hardening_schedule_id');
                $days = $request->intInput('hardening_days');
                $data['payload'] = ['schedule_id' => $scheduleId, 'duration_days' => $days];
                break;

            case EventType::TRANSPLANTED:
                $gardenId = $request->intInput('garden_id');
                $rowId = $request->intInput('garden_row_id');
                $containerId = $request->intInput('container_id');
                if ($gardenId !== null && !$this->gardens()->exists($gardenId)) {
                    throw HttpException::badRequest('That garden is not one of yours.');
                }
                if ($rowId !== null && $this->gardens()->findRow($rowId) === null) {
                    throw HttpException::badRequest('That row is not one of yours.');
                }
                if ($containerId !== null && $this->gardens()->findContainer($containerId) === null) {
                    throw HttpException::badRequest('That container is not one of yours.');
                }
                $data['garden_id'] = $gardenId;
                $data['garden_row_id'] = $rowId;
                $data['container_id'] = $containerId;
                break;

            case EventType::YIELDED:
                // Weight OR count, whichever the gardener actually measured.
                $data['weight_g'] = $this->weightInGrams($request);
                $data['count_qty'] = $request->intInput('yield_count');
                $data['unit'] = $request->nullable('yield_unit');
                break;

            case EventType::PEST_OBSERVED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::PEST_DISEASE, $request->input('pest_id'), $request->input('pest_new'));
                break;

            case EventType::PEST_TREATED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::PEST_DISEASE, $request->input('pest_id'), $request->input('pest_new'));
                $data['ref_list_item_id_2'] = $this->lists()->resolveChoice(
                    ListType::PEST_TREATMENT, $request->input('treatment_id'), $request->input('treatment_new'));
                break;

            case EventType::FERTILIZED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::FERTILIZER_GARDEN, $request->input('fertilizer_id'), $request->input('fertilizer_new'));
                break;

            case EventType::AMENDED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::SOIL_AMENDMENT, $request->input('amendment_id'), $request->input('amendment_new'));
                break;

            case EventType::MULCHED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::MULCH_TYPE, $request->input('mulch_id'), $request->input('mulch_new'));
                break;
        }

        return $data;
    }

    /**
     * Side effects that belong with an event but are not the event itself.
     *
     * @param array<string,mixed> $data
     */
    private function afterRecord(
        Request $request,
        string $eventType,
        int $plantingId,
        int $eventId,
        string $eventDate,
        array $data,
    ): void {
        $photoIds = $request->intList('photo_ids');
        if ($photoIds !== []) {
            $this->photos()->attachToEvent($photoIds, $eventId);
            $this->app->db()->run(
                'UPDATE `photo` SET `planting_id` = :planting_id'
                . ' WHERE `user_id` = :user_id AND `plant_event_id` = :event_id',
                ['planting_id' => $plantingId, 'user_id' => $this->userId(), 'event_id' => $eventId]
            );
        }

        if ($eventType === EventType::HARDENING_STARTED) {
            $payload = $data['payload'] ?? [];
            $this->plantings()->update($plantingId, [
                'hardening_schedule_id' => $payload['schedule_id'] ?? null,
                'hardening_days'        => $payload['duration_days'] ?? null,
            ]);
        }

        if ($eventType === EventType::TRANSPLANTED) {
            $this->plantings()->update($plantingId, [
                'garden_id'     => $data['garden_id'] ?? null,
                'garden_row_id' => $data['garden_row_id'] ?? null,
                'container_id'  => $data['container_id'] ?? null,
            ]);
        }

        // Treating a pest with no observation on record offers to log the
        // observation too (handoff Section 4.4).
        if ($eventType === EventType::PEST_TREATED
            && $request->checkbox('also_observe')
            && !$this->events()->hasPestObservation($plantingId, $data['ref_list_item_id'] ?? null)) {
            $this->events()->record($plantingId, EventType::PEST_OBSERVED, $eventDate, [
                'ref_list_item_id' => $data['ref_list_item_id'] ?? null,
                'narrative'        => 'Recorded alongside the treatment.',
            ]);
        }
    }

    /** Gardeners weigh in ounces and pounds; the column is grams. */
    private function weightInGrams(Request $request): ?float
    {
        $value = $request->floatInput('yield_weight');
        if ($value === null) {
            return null;
        }
        return match ($request->input('yield_weight_unit', 'oz')) {
            'lb' => $value * 453.59237,
            'g'  => $value,
            'kg' => $value * 1000,
            default => $value * 28.349523125,
        };
    }

    /** @return list<array<string,mixed>> */
    private function hardeningSchedules(): array
    {
        return $this->app->db()->all(
            'SELECT * FROM `hardening_schedule` WHERE `user_id` = :user_id AND `is_active` = 1'
            . ' ORDER BY `is_default` DESC, `name`',
            ['user_id' => $this->userId()]
        );
    }
}
