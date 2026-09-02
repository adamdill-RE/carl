<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\DripLine;
use Carl\Domain\EventType;
use Carl\Domain\ListType;
use Carl\Domain\SoilType;

/**
 * Build Garden, Garden Actions and View Garden
 * (handoff Sections 4.6, 4.7, 4.8).
 */
final class GardenController extends Controller
{
    public function index(Request $request): Response
    {
        $gardens = $this->gardens()->activeGardens();
        $ids = \array_map(static fn (array $g): int => (int) $g['id'], $gardens);

        return $this->render('gardens/index', [
            'gardens'    => $gardens,
            'rowsByGarden' => $this->gardens()->rowsForGardens($ids),
            'containers' => $this->gardens()->containers(),
        ]);
    }

    public function newForm(Request $request): Response
    {
        return $this->render('gardens/form', [
            'garden'    => null,
            'values'    => ['name' => '', 'ns_ft' => '', 'ew_ft' => '', 'row_count' => '3',
                            'row_orientation' => 'ns', 'soil_type' => '', 'notes' => ''],
            'soilTypes' => SoilType::options(),
            'errors'    => [],
        ]);
    }

    public function create(Request $request): Response
    {
        $values = $this->gardenValues($request);
        $errors = $this->validateGarden($values);

        if ($errors !== []) {
            return $this->render('gardens/form', [
                'garden' => null, 'values' => $values,
                'soilTypes' => SoilType::options(), 'errors' => $errors,
            ]);
        }

        $gardenId = $this->gardens()->insert($this->gardenData($values));
        $this->gardens()->syncRows($gardenId, (int) $values['row_count']);

        $this->flash('Garden created.');
        return $this->redirect('gardens/' . $gardenId);
    }

    public function edit(Request $request, array $params): Response
    {
        $garden = $this->gardens()->findOrFail((int) $params['id']);

        return $this->render('gardens/form', [
            'garden' => $garden,
            'values' => [
                'name'            => (string) $garden['name'],
                'ns_ft'           => (string) ($garden['ns_ft'] ?? ''),
                'ew_ft'           => (string) ($garden['ew_ft'] ?? ''),
                'row_count'       => (string) $garden['row_count'],
                'row_orientation' => (string) $garden['row_orientation'],
                'soil_type'       => (string) ($garden['soil_type'] ?? ''),
                'notes'           => (string) ($garden['notes'] ?? ''),
            ],
            'soilTypes' => SoilType::options(),
            'errors'    => [],
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        $values = $this->gardenValues($request);
        $errors = $this->validateGarden($values);

        if ($errors !== []) {
            return $this->render('gardens/form', [
                'garden' => $garden, 'values' => $values,
                'soilTypes' => SoilType::options(), 'errors' => $errors,
            ]);
        }

        $this->gardens()->update($gardenId, $this->gardenData($values));
        $this->gardens()->syncRows($gardenId, (int) $values['row_count']);

        $this->flash('Garden saved.');
        return $this->redirect('gardens/' . $gardenId);
    }

    /**
     * Rows are posted one screen at a time. Hosting Section 4: PHP truncates
     * silently past max_input_vars (1000), so a garden with many rows must
     * never post every row's every field in one form -- this posts four
     * fields per row and refuses politely if the count would exceed the cap.
     */
    public function updateRows(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $this->gardens()->findOrFail($gardenId);

        $rowIds = $request->intList('row_id');
        $names = $request->inputList('row_name');
        $sun = $request->inputList('row_sun');
        $notes = $request->inputList('row_notes');
        $shade = $request->inputList('row_shade');

        foreach ($rowIds as $index => $rowId) {
            if ($this->gardens()->findRow($rowId) === null) {
                continue;
            }
            $shadeId = $this->lists()->resolveChoice(
                ListType::SHADE_CLOTH,
                $shade[$index] ?? null,
                null
            );
            $this->gardens()->updateRow($rowId, [
                'name'           => \substr(\trim($names[$index] ?? ''), 0, 60) ?: 'Row ' . ($index + 1),
                'sun_exposure'   => \in_array($sun[$index] ?? '', ['high', 'medium', 'low'], true)
                    ? $sun[$index] : 'high',
                'shade_cloth_id' => $shadeId,
                'notes'          => ($notes[$index] ?? '') === '' ? null : $notes[$index],
            ]);
        }

        $this->flash(\count($rowIds) . ' rows saved.');
        return $this->redirect('gardens/' . $gardenId);
    }

    public function saveZone(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $this->gardens()->findOrFail($gardenId);

        if ($request->input('delete_zone_id') !== null) {
            $this->gardens()->deleteZone((int) $request->input('delete_zone_id'));
            $this->flash('Water zone removed.');
            return $this->redirect('gardens/' . $gardenId);
        }

        $name = \trim((string) $request->input('zone_name', ''));
        if ($name === '') {
            $this->flash('Give the water zone a name.', 'error');
            return $this->redirect('gardens/' . $gardenId);
        }

        $methodId = $this->lists()->resolveChoice(
            ListType::WATER_METHOD,
            $request->input('water_method_id'),
            $request->input('water_method_new')
        );

        // What the zone puts down (Phase 14). All optional; a zone with no
        // emitter figure keeps using the method's rate. Typed in the
        // gardener's units and stored in the packet's (gph, inches), which
        // is the one conversion DripLine owns.
        $emitter = $this->readEmitter($request);
        if (\is_string($emitter)) {
            $this->flash($emitter, 'error');
            return $this->redirect('gardens/' . $gardenId);
        }

        $zoneId = $this->gardens()->createZone($gardenId, $name, $methodId, $emitter);

        // Only rows that belong to this garden may join the zone.
        $wanted = $request->intList('zone_rows');
        $valid = [];
        foreach ($this->gardens()->rows($gardenId) as $row) {
            if (\in_array((int) $row['id'], $wanted, true)) {
                $valid[] = (int) $row['id'];
            }
        }
        $this->gardens()->setZoneRows($zoneId, $valid);

        $this->flash('Water zone saved.');
        return $this->redirect('gardens/' . $gardenId);
    }

    /**
     * The emitter fields off the zone form, validated, in gph and inches --
     * or a sentence saying what was wrong with them.
     *
     * The bounds are DripLine's: outside them a value is far more likely a
     * typo (or gph typed where litres were asked for) than a real system,
     * and a wrong rate does not fail loudly -- it quietly tells the model
     * the bed had a season's water in an afternoon.
     *
     * @return array{emitter_gph:?float,emitter_spacing_in:?float,line_spacing_in:?float,
     *               efficiency_pct:int}|string
     */
    private function readEmitter(Request $request): array|string
    {
        $us = $this->app->units()->isUs();

        $flow = $request->floatInput('emitter_flow');
        $emitterSpacing = $request->floatInput('emitter_spacing');
        $lineSpacing = $request->floatInput('line_spacing');
        $efficiency = $request->intInput('efficiency_pct');

        if ($flow !== null && !$us) {
            $flow = DripLine::litresPerHourToGph($flow);
        }
        if ($emitterSpacing !== null && !$us) {
            $emitterSpacing = DripLine::cmToInches($emitterSpacing);
        }
        if ($lineSpacing !== null && !$us) {
            $lineSpacing = DripLine::cmToInches($lineSpacing);
        }

        if ($flow !== null && ($flow < DripLine::GPH_MIN || $flow > DripLine::GPH_MAX)) {
            return \sprintf('Emitter flow should be between %s and %s %s per hour per emitter.',
                $us ? DripLine::trimNumber(DripLine::GPH_MIN) : DripLine::trimNumber(DripLine::gphToLitresPerHour(DripLine::GPH_MIN)),
                $us ? DripLine::trimNumber(DripLine::GPH_MAX) : DripLine::trimNumber(DripLine::gphToLitresPerHour(DripLine::GPH_MAX)),
                $us ? 'gallons' : 'litres');
        }
        foreach ([['Emitter spacing', $emitterSpacing], ['Line spacing', $lineSpacing]] as [$label, $value]) {
            if ($value !== null && ($value < DripLine::SPACING_MIN_IN || $value > DripLine::SPACING_MAX_IN)) {
                return $label . ' should be between ' . ($us ? '1 and 240 inches' : '2.5 and 610 cm') . '.';
            }
        }
        if ($efficiency === null) {
            $efficiency = DripLine::DEFAULT_EFFICIENCY_PCT;
        }
        if ($efficiency < DripLine::EFFICIENCY_MIN_PCT || $efficiency > DripLine::EFFICIENCY_MAX_PCT) {
            return 'Efficiency should be between ' . DripLine::EFFICIENCY_MIN_PCT . ' and '
                . DripLine::EFFICIENCY_MAX_PCT . ' per cent.';
        }
        if ($flow === null && ($emitterSpacing !== null || $lineSpacing !== null)) {
            return 'Give the emitter flow as well as the spacing, or leave all three blank to use'
                . ' the water method\'s rate.';
        }

        return [
            'emitter_gph'        => $flow === null ? null : \round($flow, 3),
            'emitter_spacing_in' => $emitterSpacing === null ? null : \round($emitterSpacing, 2),
            'line_spacing_in'    => $lineSpacing === null ? null : \round($lineSpacing, 2),
            'efficiency_pct'     => $efficiency,
        ];
    }

    /** View Garden: the garden report in list form (handoff Section 4.8). */
    public function show(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        $plantings = $this->plantings()->listWithDetail(['garden_id' => $gardenId]);
        $rows = $this->gardens()->rows($gardenId);

        // Per-row yield totals (handoff Section 4.8), one statement.
        $yields = $this->app->db()->all(
            'SELECT p.garden_row_id, COALESCE(SUM(e.weight_g), 0) AS weight_g,'
            . ' COALESCE(SUM(e.count_qty), 0) AS count_qty'
            . ' FROM `plant_event` e JOIN `planting` p ON p.id = e.planting_id'
            . ' WHERE e.user_id = :user_id AND p.garden_id = :garden_id AND e.event_type = :yielded'
            . ' GROUP BY p.garden_row_id',
            ['user_id' => $this->userId(), 'garden_id' => $gardenId, 'yielded' => EventType::YIELDED]
        );
        $yieldByRow = [];
        foreach ($yields as $row) {
            $yieldByRow[(int) ($row['garden_row_id'] ?? 0)] = $row;
        }

        // The Phase 4 charts, from the same builder the plant report and
        // /api/garden/<id>/series read: one weather statement and one for the
        // garden's own actions, whatever the size of the garden.
        $series = $this->series()->forGarden(
            $gardenId, $this->user()->weatherLocationId, $this->today()
        );

        return $this->render('gardens/show', [
            'garden'     => $garden,
            'rows'       => $rows,
            'zones'      => $this->gardens()->zones($gardenId),
            // The line spacing the model falls back to when a zone leaves
            // it blank (Phase 14), so the form can say what "blank" means.
            'rowSpacingIn' => DripLine::rowSpacingIn($garden),
            'plantings'  => $plantings,
            'events'     => $this->events()->gardenTimeline($gardenId),
            'photos'     => $this->photos()->forGarden($gardenId),
            'yieldByRow' => $yieldByRow,
            'occupancy'  => $this->gardens()->livingCountByRow($gardenId),
            'lists'      => $this->lists()->manyTypes([ListType::WATER_METHOD, ListType::SHADE_CLOTH]),
            'series'        => $series,
            'weatherModels' => $series['sources'],
            'seriesUrl'     => $this->app->url('api/garden/' . $gardenId . '/series'),
            'pdfUrl'        => $this->app->url('report/garden/' . $gardenId . '/pdf'),
        ]);
    }

    public function actions(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        return $this->render('gardens/actions', [
            'garden' => $garden,
            'rows'   => $this->gardens()->rows($gardenId),
            'zones'  => $this->gardens()->zones($gardenId),
            'lists'  => $this->lists()->manyTypes([
                ListType::WATER_METHOD, ListType::FERTILIZER_GARDEN, ListType::SOIL_AMENDMENT,
                ListType::MULCH_TYPE, ListType::PEST_DISEASE, ListType::PEST_TREATMENT,
            ]),
            'actions' => EventType::gardenTypes(),
        ]);
    }

    public function recordAction(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $this->gardens()->findOrFail($gardenId);

        $eventType = (string) $request->input('event_type', '');
        if (!\in_array($eventType, EventType::gardenTypes(), true)) {
            throw HttpException::badRequest('That is not a garden action.');
        }

        $eventDate = $this->eventDate($request);
        $zoneId = null;
        $rowIds = [];
        $data = [
            'narrative'    => $request->nullable('narrative'),
            'duration_min' => $request->intInput('duration_min'),
        ];

        switch ($eventType) {
            case EventType::WATERED:
                $zoneIdRaw = $request->intInput('water_zone_id');
                if ($zoneIdRaw !== null && $zoneIdRaw > 0) {
                    $zoneId = $zoneIdRaw;
                    $rowIds = $this->gardens()->zoneRowIds($zoneId);
                }
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::WATER_METHOD,
                    $request->input('water_method_id'),
                    $request->input('water_method_new')
                );
                break;

            case EventType::FERTILIZED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::FERTILIZER_GARDEN,
                    $request->input('fertilizer_id'),
                    $request->input('fertilizer_new')
                );
                break;

            case EventType::AMENDED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::SOIL_AMENDMENT,
                    $request->input('amendment_id'),
                    $request->input('amendment_new')
                );
                break;

            case EventType::MULCHED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::MULCH_TYPE,
                    $request->input('mulch_id'),
                    $request->input('mulch_new')
                );
                $rowIds = $this->validRows($gardenId, $request->intList('rows'));
                break;

            case EventType::PEST_OBSERVED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::PEST_DISEASE,
                    $request->input('pest_id'),
                    $request->input('pest_new')
                );
                break;

            case EventType::PEST_TREATED:
                $data['ref_list_item_id'] = $this->lists()->resolveChoice(
                    ListType::PEST_DISEASE,
                    $request->input('pest_id'),
                    $request->input('pest_new')
                );
                $data['ref_list_item_id_2'] = $this->lists()->resolveChoice(
                    ListType::PEST_TREATMENT,
                    $request->input('treatment_id'),
                    $request->input('treatment_new')
                );
                break;
        }

        // Watering a zone fans out a derived water record to every living
        // plant in the zone's rows, carrying source_garden_event_id so it is
        // not double-counted (handoff Section 4.7).
        $fanOut = $eventType === EventType::WATERED && $zoneId !== null;

        $result = $this->events()->recordGardenEvent(
            $gardenId, $eventType, $eventDate, $data, $rowIds, $zoneId, $fanOut
        );

        $photoIds = $request->intList('photo_ids');
        if ($photoIds !== []) {
            $this->photos()->attachToGardenEvent($photoIds, $result['event_id']);
        }

        $message = EventType::label($eventType) . ' recorded';
        if ($result['fanout'] > 0) {
            $message .= ', and logged against ' . $result['fanout'] . ' living plant'
                . ($result['fanout'] === 1 ? '' : 's') . ' in that zone';
        }
        $this->flash($message . '.');

        return $this->redirect('gardens/' . $gardenId . '/actions');
    }

    // -- End Growing Season (Phase 5 handoff Section 3.3) -------------------

    /**
     * The confirmation screen.
     *
     * Section 3.3 is explicit that "the care needed is in the confirmation
     * screen, not the code", and it is right: the write itself is one call to
     * `recordBatch()`, which already exists and already re-derives state per
     * planting. What is new is that this is **the one destructive action in
     * the application** -- everything else in Carl adds a row to an
     * append-only log; this adds a row to twenty of them at once and takes
     * twenty plants out of the garden.
     *
     * So the screen names every planting it will end, and how many living
     * plants each stands for, before there is a button to press. A count is
     * not enough: "this will end 14 plantings" reads the same whether or not
     * the fourteenth is the one you have been nursing since February.
     */
    public function endSeasonForm(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        $living = $this->plantings()->listWithDetail(
            ['garden_id' => $gardenId, 'living' => true]
        );

        return $this->render('gardens/end_season', [
            'garden'    => $garden,
            'plantings' => $living,
            // One statement, and only to decide whether to offer the tick at
            // all: an account with no tags should not be asked about them.
            'tagged'    => self::stakeCount($this->tags()->codesForPlantings(
                \array_map(static fn (array $p): int => (int) $p['id'], $living)
            )),
            'errors'    => [],
        ]);
    }

    /**
     * Do it.
     *
     * Three guards, in order of how much they would cost to be missing:
     *
     *  1. **A typed confirmation.** Not a checkbox: a checkbox next to a
     *     submit button is one mis-tap on a phone, and this is the action
     *     with no undo.
     *  2. **The date is a real event date** -- the user's own today by
     *     default, and backdatable like every other event (handoff Section
     *     4), because a season ends on the day of the frost, not on the day
     *     someone gets round to recording it.
     *  3. **The planting ids come from the database, not the form.** The
     *     screen lists them; the write re-reads them. A stale tab whose list
     *     is a week old must not resurrect a planting that has since been
     *     ended, nor miss one started since.
     *
     * `culled` is the event type rather than a new "season ended" one. It is
     * already in the vocabulary (handoff Section 5.3), it is already
     * attrition, and `PlantingState::derive()` already ends a planting the
     * moment its last living plant is gone by any route. A new type would
     * have meant a new column value, a new label, a new marker colour and a
     * new case in the state machine, to record the same fact.
     */
    public function endSeason(Request $request, array $params): Response
    {
        $gardenId = (int) $params['id'];
        $garden = $this->gardens()->findOrFail($gardenId);

        $living = $this->plantings()->listWithDetail(['garden_id' => $gardenId, 'living' => true]);
        $eventDate = $this->eventDate($request);

        $errors = [];
        if (\strtolower(\trim((string) $request->input('confirm', ''))) !== 'end season') {
            $errors[] = 'Type "end season" in the box to confirm. Nothing has been changed.';
        }
        if ($living === []) {
            $errors[] = 'There is nothing living in this garden to end.';
        }

        if ($errors !== []) {
            return $this->render('gardens/end_season', [
                'garden'    => $garden,
                'plantings' => $living,
                'tagged'    => self::stakeCount($this->tags()->codesForPlantings(
                    \array_map(static fn (array $p): int => (int) $p['id'], $living)
                )),
                'errors'    => $errors,
            ]);
        }

        $written = $this->events()->recordBatch(
            \array_map(static fn (array $p): int => (int) $p['id'], $living),
            EventType::CULLED,
            $eventDate,
            [
                // Each planting's own remainder, not a single number across
                // all of them -- which is exactly what recordBatch()'s
                // quantity_all already means (handoff Section 4.4).
                'quantity_all' => true,
                'garden_id'    => $gardenId,
                'narrative'    => $request->nullable('narrative')
                    ?? 'End of season for ' . $garden['name'] . '.',
            ]
        );

        // Return the stakes to the pool (docs/QR-TAGS-SPEC.md Section 8).
        // Opt-in, because it is a physical claim -- it says you walked the bed
        // and pulled the tags -- and a gardener who leaves them in over winter
        // to know what was where is doing a reasonable thing.
        //
        // The bindings are CLOSED, never deleted, so each tag still remembers
        // what it was on: "this stake was Cherokee Purple in 2026" is a fact
        // about a real object and it is the reason the binding is a period of
        // time rather than a pointer.
        $released = 0;
        if ($request->checkbox('release_tags')) {
            $released = $this->tags()->releaseForPlantings(
                \array_map(static fn (array $p): int => (int) $p['id'], $living)
            );
        }

        $this->flash(
            'Season ended for ' . $written . ' planting' . ($written === 1 ? '' : 's')
            . ' in ' . $garden['name'] . ', dated ' . $eventDate . '. '
            . ($released > 0
                ? $released . ' tag' . ($released === 1 ? '' : 's') . ' back in the pool. '
                : '')
            . 'The log is append-only: every one of them is still on its timeline, and a '
            . 'later event brings a planting back if you ended one by mistake.'
        );

        return $this->redirect('gardens/' . $gardenId);
    }

    /** @param list<int> $wanted @return list<int> */
    private function validRows(int $gardenId, array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }
        $valid = [];
        foreach ($this->gardens()->rows($gardenId) as $row) {
            if (\in_array((int) $row['id'], $wanted, true)) {
                $valid[] = (int) $row['id'];
            }
        }
        return $valid;
    }

    /** @return array<string,string> */
    private function gardenValues(Request $request): array
    {
        return [
            'name'            => \trim((string) $request->input('name', '')),
            'ns_ft'           => (string) $request->input('ns_ft', ''),
            'ew_ft'           => (string) $request->input('ew_ft', ''),
            'row_count'       => (string) $request->input('row_count', '0'),
            'row_orientation' => $this->choice($request, 'row_orientation', ['ns', 'ew'], 'ns'),
            'soil_type'       => (string) $request->input('soil_type', ''),
            'notes'           => (string) $request->input('notes', ''),
        ];
    }

    /** @param array<string,string> $values @return list<string> */
    private function validateGarden(array $values): array
    {
        $errors = [];
        if ($values['name'] === '') {
            $errors[] = 'Give the garden a name.';
        }
        $rowCount = (int) $values['row_count'];
        if ($rowCount < 0 || $rowCount > 200) {
            $errors[] = 'Rows must be between 0 and 200.';
        }
        // Hosting Section 4: max_input_vars is 1000 and PHP truncates past it
        // silently. The row editor posts five fields per row.
        if ($rowCount > 150) {
            $errors[] = 'More than 150 rows cannot be edited in one form on this server. '
                . 'Split the plot into two gardens.';
        }
        if ($values['soil_type'] !== '' && !SoilType::isValid($values['soil_type'])) {
            $errors[] = 'Pick a soil type from the list.';
        }
        foreach (['ns_ft' => 'North-south', 'ew_ft' => 'East-west'] as $key => $label) {
            if ($values[$key] !== '' && (!\is_numeric($values[$key]) || (float) $values[$key] < 0)) {
                $errors[] = $label . ' size must be a number of feet.';
            }
        }
        return $errors;
    }

    /** @param array<string,string> $values @return array<string,mixed> */
    private function gardenData(array $values): array
    {
        return [
            'name'            => $values['name'],
            'ns_ft'           => $values['ns_ft'] === '' ? null : (float) $values['ns_ft'],
            'ew_ft'           => $values['ew_ft'] === '' ? null : (float) $values['ew_ft'],
            'row_count'       => (int) $values['row_count'],
            'row_orientation' => $values['row_orientation'],
            'soil_type'       => $values['soil_type'] === '' ? null : $values['soil_type'],
            'notes'           => $values['notes'] === '' ? null : $values['notes'],
        ];
    }

    /**
     * How many stakes are on a set of plantings: a planting carries a list
     * of codes (a tray has one per cell), so this is the length of every
     * list, not the number of plantings that have one.
     *
     * @param array<int,list<string>> $codesByPlanting
     */
    private static function stakeCount(array $codesByPlanting): int
    {
        $n = 0;
        foreach ($codesByPlanting as $codes) {
            $n += \count($codes);
        }
        return $n;
    }
}
