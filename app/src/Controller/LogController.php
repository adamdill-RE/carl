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
 *
 * ONE action does not write exactly one event: a relocation of PART of a
 * planting (docs/PLANTING-SPLIT-SPEC.md). The user's sentence is "I
 * transplanted six of them", never "I split the planting and then
 * transplanted the child", so the form asks how many and where to and Carl
 * splits behind it -- a `split_out` on the parent and the physical event on
 * the child it just made. See relocate() below for the whole truth table;
 * everything else on this screen still writes one row and must keep to it.
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

        // A tag code in the search box goes straight to that plant's LOG
        // form -- not to the field screen the camera opens, because somebody
        // typing a code into this box is at a desk with a date to backdate.
        // Controller::tagCodeJump() carries the reasoning.
        $jump = $this->tagCodeJump((string) $filters['search'], 'log');
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

        if (EventType::isRelocation($eventType)) {
            $moved = $this->relocate($planting, $eventType, $eventDate, $data, $request);
            if ($moved !== null) {
                // A split: the gardener's photos and notes belong to the
                // event they thought they were logging, which is the child's.
                $this->afterRecord($request, $eventType, $moved['child_id'],
                    $moved['event_id'], $eventDate, $data);
                $this->flash($moved['quantity'] . ' of the ' . $planting['category'] . ' '
                    . $planting['type'] . ' moved out and are now tracked on their own.');
                return $this->redirect('plants/' . $moved['child_id']);
            }
        }

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

        // A relocation cannot go through recordBatch(): each planting decides
        // for itself whether it is moving whole or splitting, because the
        // quantity asked for is measured against ITS live count and not the
        // first one's.
        if (EventType::isRelocation($eventType)) {
            return $this->relocateBatch($plantings, $eventType, $eventDate, $data, $request);
        }

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
        // Read once: hosting Section 9 counts statements, and this list was
        // being fetched twice to build the same two values.
        $gardens = $this->gardens()->activeGardens();

        $rotationYears = \max(1, $this->app->config()->int('rotation.years', 3));
        $rotationSince = (string) Clock::addDays($this->today(), -($rotationYears * 365));

        return [
            'plantings' => $plantings,
            'single'    => \count($plantings) === 1 ? $first : null,
            'actions'   => $actions ?? [],
            'card'      => $this->reference()->researchCard((int) $first['plant_type_id'], $user->regionId),
            'hasRegion' => $user->hasRegion(),
            'today'     => $this->today(),
            'gardens'   => $gardens,
            'rowsByGarden' => $this->gardens()->rowsForGardens(
                \array_map(static fn (array $g): int => (int) $g['id'], $gardens)
            ),
            'containers' => $this->gardens()->containers(),
            // The occupancy hint follows the Transplant action's row select
            // too, not only the new-plant forms (handoff Section 4.3), and so
            // does the crop rotation history (Phase 5 handoff Section 3.4).
            // Transplanting into a bed is the moment the warning is about;
            // before the split this screen was the one place it did not
            // appear, because it was the one place a plant chose a row
            // without going through Start a New Plant.
            'occupancy'  => $this->gardens()->livingCountByRow(),
            'rotation'      => $this->plantings()->familyHistoryByRow($rotationSince),
            'rotationYears' => $rotationYears,
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
     * A relocation: transplanted, up-potted or moved.
     *
     * The whole truth table, in one place, because getting it wrong is the
     * kind of mistake that shows as a plausible number:
     *
     * | how many        | destination            | what happens              |
     * | --------------- | ---------------------- | ------------------------- |
     * | all, or blank   | somewhere new          | the planting moves, as before |
     * | all, or blank   | none, or where it is   | one event, nothing moves  |
     * | some of them    | somewhere new          | **a split**               |
     * | some of them    | none, or where it is   | refused, with a reason    |
     *
     * The last row is the one worth arguing about. "Six of them, no
     * destination" could be read as recording an event against six plants,
     * but a transplant that does not say where to would then move all
     * hundred, and the gardener has just told us it was six -- a silent
     * hundred-for-six is exactly the failure this codebase writes tests
     * against. So it asks.
     *
     * Returns null when nothing was split, and the caller records the plain
     * event it would have recorded anyway.
     *
     * @param array<string,mixed> $planting
     * @param array<string,mixed> $data
     * @return array{child_id:int,event_id:int,quantity:int}|null
     */
    private function relocate(
        array $planting,
        string $eventType,
        string $eventDate,
        array $data,
        Request $request,
    ): ?array {
        if (!$this->splits($planting, $data, $request)) {
            return null;
        }

        $destination = [
            'garden_id'     => $data['garden_id'] ?? null,
            'garden_row_id' => $data['garden_row_id'] ?? null,
            'container_id'  => $data['container_id'] ?? null,
        ];

        // The narrative, the reference lists and the duration go on the
        // child's event; only the placement is shared.
        $eventData = $data;
        unset($eventData['garden_id'], $eventData['garden_row_id'], $eventData['container_id']);

        return $this->plantings()->split(
            $this->events(), (int) $planting['id'], (int) $request->intInput('move_quantity'),
            $eventType, $eventDate, $destination, $eventData
        );
    }

    /**
     * Does this planting split, given what was asked for? Rows three and four
     * of the table above, and the refusal is row four.
     *
     * Separated from the doing so that a BATCH can decide about every planting
     * before it writes anything about any of them: a batch that half-applies
     * is the failure `batch()` already goes out of its way to avoid.
     *
     * @param array<string,mixed> $planting
     * @param array<string,mixed> $data
     */
    private function splits(array $planting, array $data, Request $request): bool
    {
        $live = (int) $planting['quantity_live'];
        $quantity = $request->intInput('move_quantity');

        if ($quantity === null || $quantity <= 0 || $quantity >= $live) {
            return false;
        }

        $destination = [
            'garden_id'     => $data['garden_id'] ?? null,
            'garden_row_id' => $data['garden_row_id'] ?? null,
            'container_id'  => $data['container_id'] ?? null,
        ];
        if (!self::isElsewhere($planting, $destination)) {
            throw HttpException::badRequest(
                'Moving ' . $quantity . ' of ' . $live . ' needs somewhere for them to go'
                . ' that is not where they already are. Say which garden row or container'
                . ' the ' . $quantity . ' are moving to, or move all of them.'
            );
        }
        return true;
    }

    /**
     * The same, for a batch. Each planting is measured against its own live
     * count: "six of them" applied to a tray of 100 and a pot of 4 splits the
     * tray and moves the pot whole.
     *
     * @param list<array<string,mixed>> $plantings
     * @param array<string,mixed> $data
     */
    private function relocateBatch(
        array $plantings,
        string $eventType,
        string $eventDate,
        array $data,
        Request $request,
    ): Response {
        // Decide about all of them first. splits() throws on a partial move
        // with nowhere to go, and it must throw before the first write, not
        // after the third.
        foreach ($plantings as $planting) {
            $this->splits($planting, $data, $request);
        }

        $split = 0;
        $movedWhole = 0;

        foreach ($plantings as $planting) {
            $moved = $this->relocate($planting, $eventType, $eventDate, $data, $request);
            if ($moved !== null) {
                $split++;
                continue;
            }
            $plantingId = (int) $planting['id'];
            $eventId = $this->events()->record($plantingId, $eventType, $eventDate, $data);
            $this->afterRecord($request, $eventType, $plantingId, $eventId, $eventDate, $data);
            $movedWhole++;
        }

        $said = [];
        if ($movedWhole > 0) {
            $said[] = $movedWhole . ' moved whole';
        }
        if ($split > 0) {
            $said[] = $split . ' split, and the plants that moved are now tracked on their own';
        }
        $this->flash(EventType::label($eventType) . ': ' . \implode('; ', $said) . '.');
        return $this->redirect('log');
    }

    /**
     * Is this destination somewhere other than where the planting already is?
     *
     * A destination with nothing in it is not somewhere: an up-pot logged
     * without a container has not moved the plant, and must not blank the
     * placement it had.
     *
     * @param array<string,mixed> $planting
     * @param array{garden_id:?int,garden_row_id:?int,container_id:?int} $destination
     */
    private static function isElsewhere(array $planting, array $destination): bool
    {
        if ($destination['garden_id'] === null
            && $destination['garden_row_id'] === null
            && $destination['container_id'] === null) {
            return false;
        }
        foreach (['garden_id', 'garden_row_id', 'container_id'] as $column) {
            $now = $planting[$column] === null ? null : (int) $planting[$column];
            if ($now !== $destination[$column]) {
                return true;
            }
        }
        return false;
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
                $data += $this->destination($request);
                break;

            case EventType::HARDENING_STARTED:
                $scheduleId = $request->intInput('hardening_schedule_id');
                $days = $request->intInput('hardening_days');
                $data['payload'] = ['schedule_id' => $scheduleId, 'duration_days' => $days];
                break;

            case EventType::TRANSPLANTED:
            case EventType::MOVED:
                $data += $this->destination($request);
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
     * Where a relocation is going, checked against what this account owns.
     *
     * The three relocations read the same three fields, and they are read in
     * one place so that a fourth cannot arrive with two of the three checks.
     * A posted id is not evidence of anything (handoff Section 5).
     *
     * @return array{garden_id:?int,garden_row_id:?int,container_id:?int}
     */
    private function destination(Request $request): array
    {
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

        return [
            'garden_id'     => $gardenId,
            'garden_row_id' => $rowId,
            'container_id'  => $containerId,
        ];
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

        // A relocation moves the planting it was logged against -- but only
        // when it was actually given somewhere to go. An up-pot recorded with
        // the soil and no container has not moved the plant, and blanking its
        // placement would take it out of its bed on the strength of a field
        // nobody filled in.
        $destination = [
            'garden_id'     => $data['garden_id'] ?? null,
            'garden_row_id' => $data['garden_row_id'] ?? null,
            'container_id'  => $data['container_id'] ?? null,
        ];
        $somewhere = \array_filter($destination, static fn (mixed $v): bool => $v !== null) !== [];
        if (EventType::isRelocation($eventType) && $somewhere) {
            $this->plantings()->update($plantingId, $destination);
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
