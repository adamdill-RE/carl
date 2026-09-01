<?php
/**
 * The Calendar (Phase 9).
 *
 * A month grid of what happened and what is coming, and below it the table of
 * upcoming actions -- "17 October 2026, Transplant window opens for Cherokee
 * Purple" -- which is the half people actually act on. The grid is the shape
 * of the season; the table is the to-do list.
 *
 * A CELL COLLAPSES REPEATS. Watering nine plants on Tuesday is one line
 * reading "Watered x9", not nine lines, because a month of individually
 * listed waterings is a month nobody can read. The full set is still there:
 * filter to one plant, or open the plant's own timeline.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $month @var string $prev @var string $next @var string $thisMonth
 * @var list<list<array{date:string,in_month:bool}>> $weeks
 * @var array<string,list<array<string,mixed>>> $byDate
 * @var list<array<string,mixed>> $upcoming
 * @var bool $truncated @var string $horizon
 * @var list<array<string,mixed>> $plantings
 * @var array{plant_ids:list<int>,wide:bool} $filter
 * @var bool $hasRegion @var string $today
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$C = Carl\Planting\Calendar::class;
$pageTitle = 'Calendar';

$selected = \array_flip($filter['plant_ids']);
$monthName = \date('F Y', (int) \strtotime($month . '-01 00:00:00 UTC'));

/**
 * The query string that carries the current filter onto another month.
 * http_build_query() nests an array as plant_id[0]=..., which is the shape
 * Request::queryIntList() reads back -- so paging months keeps the filter.
 */
$carry = static function (string $toMonth) use ($filter): array {
    $query = ['month' => $toMonth, 'f' => '1'];
    if ($filter['plant_ids'] !== []) {
        $query['plant_id'] = $filter['plant_ids'];
    }
    if ($filter['wide']) {
        $query['wide'] = '1';
    }
    return $query;
};
?>
<h1 class="page-title">Calendar</h1>
<p class="page-sub">
  What you logged, and what your plants and your county's research say is coming.
  Everything dated ahead of today is worked out, not promised.
</p>

<form method="get" action="<?= $e($app->url('calendar')) ?>" class="card card-tight">
  <?php /* The marker that tells "garden-wide off" from "never submitted" --
         an unchecked box sends nothing, so without it the default inverts
         itself the first time anybody filters. */ ?>
  <input type="hidden" name="f" value="1">
  <input type="hidden" name="month" value="<?= $e($month) ?>">
  <div class="field">
    <label for="plant_id">Plants</label>
    <select id="plant_id" name="plant_id[]" multiple size="6">
<?php foreach ($plantings as $planting): ?>
      <option value="<?= $e($planting['id']) ?>"
              <?= isset($selected[(int) $planting['id']]) ? 'selected' : '' ?>>
        <?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?><?php
          if (!empty($planting['label'])): ?> (<?= $e($planting['label']) ?>)<?php endif; ?>
      </option>
<?php endforeach; ?>
    </select>
    <p class="help">Choose none for all of them. Hold Ctrl or Cmd to pick several.</p>
  </div>
  <div class="check">
    <input type="checkbox" id="wide" name="wide" value="1" <?= $filter['wide'] ? 'checked' : '' ?>>
    <label for="wide">Garden-wide dates: frost, sowing, pest windows, zone actions</label>
  </div>
  <div class="row gap-sm">
    <button type="submit" class="btn btn-small">Filter</button>
    <a class="btn btn-secondary btn-small"
       href="<?= $e($app->url('calendar', ['month' => $month])) ?>">Clear</a>
  </div>
</form>

<section class="card">
  <div class="cal-head">
    <a class="btn btn-secondary btn-small" rel="prev"
       href="<?= $e($app->url('calendar', $carry($prev))) ?>"
       aria-label="Previous month">&larr;</a>
    <h2 class="grow flush"><?= $e($monthName) ?></h2>
    <a class="btn btn-secondary btn-small" rel="next"
       href="<?= $e($app->url('calendar', $carry($next))) ?>"
       aria-label="Next month">&rarr;</a>
  </div>
<?php if ($month !== $thisMonth): ?>
  <p class="tiny flush"><a href="<?= $e($app->url('calendar', $carry($thisMonth))) ?>">Back to this month</a></p>
<?php endif; ?>

  <div class="matrix-scroll">
  <table class="cal">
    <caption class="sr-only"><?= $e($monthName) ?> in the garden</caption>
    <thead>
      <tr>
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day): ?>
        <th scope="col"><abbr title="<?= $e($day) ?>"><?= $e(\substr($day, 0, 1)) ?></abbr>
          <span class="cal-dayname"><?= $e($day) ?></span></th>
<?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($weeks as $week): ?>
      <tr>
<?php foreach ($week as $cell):
        $entries = $byDate[$cell['date']] ?? [];
        // Collapse repeats: nine waterings on one day is one line.
        $rolled = [];
        foreach ($entries as $entry) {
            $key = $entry['kind'] . '|' . $entry['label'];
            $rolled[$key] ??= ['entry' => $entry, 'count' => 0];
            $rolled[$key]['count']++;
        }
        $classes = 'cal-cell';
        if (!$cell['in_month']) { $classes .= ' cal-outside'; }
        if ($cell['date'] === $today) { $classes .= ' cal-today'; }
?>
        <td class="<?= $e($classes) ?>">
          <span class="cal-num"><?= $e((int) \substr($cell['date'], 8, 2)) ?></span>
<?php foreach ($rolled as $group): $entry = $group['entry']; ?>
          <span class="cal-chip <?= $entry['projected'] ? 'cal-ahead' : 'cal-done' ?>"
                title="<?= $e($entry['title']) ?>">
            <?= $e($entry['label']) ?><?php if ($group['count'] > 1): ?>
              &times;<?= $e($group['count']) ?><?php endif; ?>
          </span>
<?php endforeach; ?>
        </td>
<?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="tiny muted flush">
    <span class="cal-chip cal-done">logged</span> happened.
    <span class="cal-chip cal-ahead">ahead</span> is worked out from days to maturity,
    a hardening duration, or your county's windows.
  </p>
</section>

<section class="card">
  <h2>Upcoming actions</h2>
<?php if ($upcoming === []): ?>
  <p class="muted flush">
    Nothing dated between now and <?= $e($U::longDate($horizon)) ?>.
<?php if (!$hasRegion): ?>
    Your county is not researched yet, so Carl has no frost dates, planting windows
    or pest seasons for your area -- only what your own plants imply.
<?php endif; ?>
  </p>
<?php else: ?>
  <table class="data">
    <thead>
      <tr><th scope="col">When</th><th scope="col">What</th><th scope="col">Plant</th></tr>
    </thead>
    <tbody>
<?php foreach ($upcoming as $entry):
      $away = Carl\Support\Clock::daysBetween($today, (string) $entry['date']);
?>
      <tr>
        <td class="nowrap">
          <?= $e($U::longDate((string) $entry['date'])) ?><br>
          <span class="tiny muted"><?= $e($U::relativeDays($away)) ?></span>
        </td>
        <td>
          <span class="topic kind-<?= $e($entry['kind']) ?>"><?= $e($entry['label']) ?></span>
          <strong><?= $e($entry['title']) ?></strong>
<?php if ((string) $entry['detail'] !== ''): ?>
          <div class="tiny muted"><?= $e($entry['detail']) ?></div>
<?php endif; ?>
        </td>
        <td class="nowrap">
<?php if ($entry['planting_id'] !== null): ?>
          <a href="<?= $e($app->url('log/' . (int) $entry['planting_id'])) ?>">Log it</a>
<?php else: ?>
          <span class="muted tiny">garden</span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if ($truncated): ?>
  <p class="tiny muted">Showing the first <?= $e($C::UPCOMING_LIMIT) ?>. Filter by plant to see the rest.</p>
<?php endif; ?>
  <p class="tiny muted flush">
    Dates ahead are worked out from your records and your county's research. Days to
    maturity is a guide, not a promise -- go and look.
  </p>
<?php endif; ?>
</section>
