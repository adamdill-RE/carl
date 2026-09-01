<?php

declare(strict_types=1);

namespace Carl\Controller;

use Carl\Core\HttpException;
use Carl\Core\Request;
use Carl\Core\Response;
use Carl\Domain\ListType;
use Carl\Domain\SoilType;

/**
 * Lists -- the user-set variables of handoff Section 4.9.
 *
 * One screen over one generic table, plus the two things that need their own
 * tables because other rows hold foreign keys to them: containers and
 * hardening schedules.
 */
final class ListController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('lists/index', [
            'types'      => ListType::all(),
            'counts'     => $this->countsByType(),
            'containers' => $this->gardens()->containers(false),
            'schedules'  => $this->schedules(),
        ]);
    }

    public function ofType(Request $request, array $params): Response
    {
        $type = (string) $params['type'];

        if ($type === 'containers') {
            return $this->render('lists/containers', [
                'containers' => $this->gardens()->containers(false),
                'soilTypes'  => SoilType::options(),
            ]);
        }
        if ($type === 'hardening') {
            return $this->render('lists/hardening', ['schedules' => $this->schedules()]);
        }
        if (!ListType::isValid($type)) {
            throw HttpException::notFound('There is no such list.');
        }

        // The pests list is read with its catalogue entry alongside, so the
        // screen can show which rows came with Carl and which this account
        // added -- one statement either way, and it is the same statement
        // count as every other list.
        $items = $type === ListType::PEST_DISEASE
            ? $this->lists()->pestListWithReference()
            : $this->lists()->ofType($type, false);

        return $this->render('lists/type', [
            'type'    => $type,
            'items'   => $items,
            'gardens' => $type === ListType::WATER_METHOD ? $this->gardens()->activeGardens() : [],
        ]);
    }

    public function save(Request $request): Response
    {
        $type = (string) $request->input('list_type', '');

        if ($type === 'containers') {
            return $this->saveContainer($request);
        }
        if ($type === 'hardening') {
            return $this->saveSchedule($request);
        }
        if (!ListType::isValid($type)) {
            throw HttpException::badRequest('There is no such list.');
        }

        $name = \trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->flash('Give it a name.', 'error');
            return $this->redirect('lists/' . $type);
        }

        $id = $request->intInput('id');
        if ($id !== null && $id > 0) {
            if ($this->lists()->findInType($id, $type) === null) {
                throw HttpException::notFound('That is not one of your list items.');
            }
            $this->lists()->update($id, [
                'name'   => $name,
                'attr_1' => $request->nullable('attr_1'),
                'attr_2' => $request->nullable('attr_2'),
                'garden_id' => $request->intInput('garden_id'),
            ]);
            $this->flash('Saved.');
        } else {
            $newId = $this->lists()->ensure(
                $type, $name, $request->nullable('attr_1'), $request->nullable('attr_2')
            );
            $gardenId = $request->intInput('garden_id');
            if ($gardenId !== null && $newId > 0 && $this->gardens()->exists($gardenId)) {
                $this->lists()->update($newId, ['garden_id' => $gardenId]);
            }
            $this->flash('Added ' . $name . '.');
        }

        return $this->redirect('lists/' . $type);
    }

    /**
     * List items are archived, never deleted: events reference them, and a
     * deleted fertiliser would take the reason a plant grew with it.
     */
    public function archive(Request $request): Response
    {
        $type = (string) $request->input('list_type', '');
        $id = $request->intInput('id');

        if ($type === 'containers' && $id !== null) {
            if ($this->gardens()->findContainer($id) === null) {
                throw HttpException::notFound();
            }
            $this->app->db()->run(
                'UPDATE `container` SET `is_active` = :active, `updated_at` = UTC_TIMESTAMP()'
                . ' WHERE `user_id` = :user_id AND `id` = :id',
                ['active' => $request->checkbox('restore') ? 1 : 0,
                 'user_id' => $this->userId(), 'id' => $id]
            );
            $this->flash('Container updated.');
            return $this->redirect('lists/containers');
        }

        if (!ListType::isValid($type) || $id === null) {
            throw HttpException::badRequest();
        }
        if ($this->lists()->findInType($id, $type) === null) {
            throw HttpException::notFound('That is not one of your list items.');
        }

        $this->lists()->update($id, ['is_active' => $request->checkbox('restore') ? 1 : 0]);
        $this->flash($request->checkbox('restore') ? 'Restored.' : 'Archived. Past records keep it.');

        return $this->redirect('lists/' . $type);
    }

    /**
     * The inline "+ Add new ..." on every dropdown: creates the item and
     * returns it as JSON without leaving the form (handoff Section 4).
     */
    public function inlineAdd(Request $request): Response
    {
        $type = (string) $request->input('list_type', '');
        $name = \trim((string) $request->input('name', ''));

        if (!ListType::isValid($type)) {
            return Response::json(['ok' => false, 'message' => 'Unknown list.'], 400);
        }
        if ($name === '') {
            return Response::json(['ok' => false, 'message' => 'Give it a name.'], 400);
        }

        $id = $this->lists()->ensure($type, $name);
        if ($id === 0) {
            return Response::json(['ok' => false, 'message' => 'Could not add that.'], 400);
        }

        return Response::json(['ok' => true, 'id' => $id, 'name' => $name]);
    }

    // -- Containers and hardening schedules -------------------------------

    private function saveContainer(Request $request): Response
    {
        $name = \trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->flash('Give the container a name.', 'error');
            return $this->redirect('lists/containers');
        }

        $soil = (string) $request->input('soil_type', '');
        $this->gardens()->ensureContainer(
            $name,
            $request->nullable('size'),
            $request->nullable('description'),
            SoilType::isValid($soil) ? $soil : null,
        );

        $this->flash('Container saved.');
        return $this->redirect('lists/containers');
    }

    private function saveSchedule(Request $request): Response
    {
        $name = \trim((string) $request->input('name', ''));
        $days = $request->intInput('duration_days', 10) ?? 10;

        if ($name === '') {
            $this->flash('Give the schedule a name.', 'error');
            return $this->redirect('lists/hardening');
        }
        if ($days < 1 || $days > 60) {
            $this->flash('A hardening schedule runs between 1 and 60 days.', 'error');
            return $this->redirect('lists/hardening');
        }

        $now = \gmdate('Y-m-d H:i:s');
        $this->app->db()->run(
            'INSERT INTO `hardening_schedule` (user_id, name, duration_days, is_default, is_active,'
            . ' created_at, updated_at)'
            . ' VALUES (:user_id, :name, :days, :is_default, 1, :created_at, :updated_at)'
            . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `duration_days` = VALUES(`duration_days`),'
            . ' `is_default` = VALUES(`is_default`), `is_active` = 1, `updated_at` = VALUES(`updated_at`)',
            [
                'user_id'    => $this->userId(),
                'name'       => \substr($name, 0, 120),
                'days'       => $days,
                'is_default' => $request->checkbox('is_default') ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $scheduleId = $this->app->db()->insertId();

        if ($request->checkbox('is_default')) {
            $this->app->db()->run(
                'UPDATE `hardening_schedule` SET `is_default` = 0'
                . ' WHERE `user_id` = :user_id AND `id` <> :id',
                ['user_id' => $this->userId(), 'id' => $scheduleId]
            );
        }

        // Days of the week, each with a time range (handoff Section 5.5).
        $this->app->db()->run(
            'DELETE FROM `hardening_schedule_day` WHERE `schedule_id` = :id', ['id' => $scheduleId]
        );

        $rows = [];
        foreach ($request->intList('weekday') as $weekday) {
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }
            $from = $request->post['time_from'][$weekday] ?? '09:00';
            $to = $request->post['time_to'][$weekday] ?? '15:00';
            $rows[] = [
                $scheduleId,
                $weekday,
                self::time(\is_string($from) ? $from : '09:00'),
                self::time(\is_string($to) ? $to : '15:00'),
            ];
        }
        if ($rows !== []) {
            $this->app->db()->upsertChunk(
                'hardening_schedule_day',
                ['schedule_id', 'weekday', 'time_from', 'time_to'],
                $rows,
                ['time_from', 'time_to']
            );
        }

        $this->flash('Hardening schedule saved.');
        return $this->redirect('lists/hardening');
    }

    private static function time(string $value): string
    {
        return \preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value . ':00' : '09:00:00';
    }

    /** @return array<string,int> */
    private function countsByType(): array
    {
        $rows = $this->app->db()->all(
            'SELECT `list_type`, COUNT(*) AS n FROM `user_list_item`'
            . ' WHERE `user_id` = :user_id AND `is_active` = 1 GROUP BY `list_type`',
            ['user_id' => $this->userId()]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['list_type']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function schedules(): array
    {
        $schedules = $this->app->db()->all(
            'SELECT * FROM `hardening_schedule` WHERE `user_id` = :user_id ORDER BY `is_default` DESC, `name`',
            ['user_id' => $this->userId()]
        );
        if ($schedules === []) {
            return [];
        }

        $ids = \array_map(static fn (array $s): int => (int) $s['id'], $schedules);
        $names = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $names[] = ':s' . $i;
            $params['s' . $i] = $id;
        }

        $days = $this->app->db()->all(
            'SELECT * FROM `hardening_schedule_day` WHERE `schedule_id` IN (' . \implode(', ', $names) . ')'
            . ' ORDER BY `weekday`',
            $params
        );

        $bySchedule = [];
        foreach ($days as $day) {
            $bySchedule[(int) $day['schedule_id']][] = $day;
        }
        foreach ($schedules as $index => $schedule) {
            $schedules[$index]['days'] = $bySchedule[(int) $schedule['id']] ?? [];
        }

        return $schedules;
    }
}
