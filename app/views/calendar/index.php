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
 * EVERY CHIP IS A LINK TO ITS DAY (Phase 15). A chip says "Transplant" and a
 * title attribute is the only place it said for which plant, which on a phone
 * is nowhere. Paging three months ahead put the reader past the upcoming
 * table's ninety-day horizon with a grid of one-word chips and no way in. So
 * a chip opens its day in the panel under the grid -- every entry on that
 * date, with the title and the reason -- with no script, a URL that can be
 * bookmarked, and the back button as the way out.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $month @var string $monthName @var string $prev @var string $next @var string $thisMonth
 * @var list<list<array{date:string,in_month:bool}>> $weeks
 * @var array<string,list<array<string,mixed>>> $byDate
 * @var list<array<string,mixed>> $upcoming
 * @var bool $truncated @var string $horizon
 * @var list<array<string,mixed>> $plantings
 * @var array{plant_ids:list<int>,wide:bool} $filter
 * @var bool $hasRegion @var string $today
 * @var string|null $day @var list<array<string,mixed>> $onDay
 * @var array<string,int> $inWindow the grid dates inside a harvest window
 * @var list<array<string,mixed>> $onDayWindows the windows open on the picked day
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$C = Carl\Planting\Calendar::class;
$pageTitle = 'Calendar';

$selected = \array_flip($filter['plant_ids']);
$day = $day ?? null;
$onDay = $onDay ?? [];
$inWindow = $inWindow ?? [];
$onDayWindows = $onDayWindows ?? [];

/**
 * The query string that carries the current filter onto another month.
 * http_build_query() nests an array as plant_id[0]=..., which is the shape
 * Request::queryIntList() reads back -- so paging months keeps the filter.
 * The picked day is NOT carried: it belongs to the month it was picked in.
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
  <?php /* The way to paper (Phase 15): the same month, the same filter, as a
         PDF that follows the field sheet's rules. Beside "back to this
         month" because both are about WHICH month is in front of you. */ ?>
  <p class="tiny cal-tools">
<?php if ($month !== $thisMonth): ?>
    <a href="<?= $e($app->url('calendar', $carry($thisMonth))) ?>">Back to this month</a>
    &middot;
<?php endif; ?>
    <a href="<?= $e($app->url('calendar.pdf', $carry($month))) ?>">Print this month (PDF)</a>
  </p>

  <div class="matrix-scroll">
  <table class="cal">
    <caption class="sr-only"><?= $e($monthName) ?> in the garden</caption>
    <thead>
      <tr>
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName): ?>
        <th scope="col"><abbr title="<?= $e($dayName) ?>"><?= $e(\substr($dayName, 0, 1)) ?></abbr>
          <span class="cal-dayname"><?= $e($dayName) ?></span></th>
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
        if ($cell['date'] === $day) { $classes .= ' cal-picked'; }
        if (isset($inWindow[$cell['date']])) { $classes .= ' cal-window'; }
        $dayUrl = $app->url('calendar', $carry($month) + ['day' => $cell['date']]) . '#day';
?>
        <td class="<?= $e($classes) ?>">
<?php /* A harvest window is open on this day (Phase 17): a band along the
       top of the cell, between the "starts" and "ends" chips, because a
       window is a span and two chips with nothing between read as two
       events. Which plant, and how far along, is the day panel's. */ ?>
<?php if (isset($inWindow[$cell['date']])): ?>
          <span class="cal-band"><span class="sr-only">Harvest window open</span></span>
<?php endif; ?>
          <span class="cal-num"><?= $e((int) \substr($cell['date'], 8, 2)) ?></span>
<?php foreach ($rolled as $group): $entry = $group['entry']; ?>
          <a class="cal-chip <?= $entry['projected'] ? 'cal-ahead' : 'cal-done' ?>"
             href="<?= $e($dayUrl) ?>" title="<?= $e($entry['title']) ?>">
            <?= $e($entry['label']) ?><?php if ($group['count'] > 1): ?>
              &times;<?= $e($group['count']) ?><?php endif; ?>
          </a>
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
    <span class="cal-band-key" aria-hidden="true"></span> a harvest window is open: the days between
    the early and late ends of days to maturity. Tap any day for what it means.
  </p>
</section>

<?php if ($day !== null): ?>
<?php /* The picked day, in full. Every entry on it and not only the ones
       that were tapped: the chip was one line of a collapsed group, and the
       question a reader had was "what is on this day". The `list guidance`
       shape is the MOTD's, so a kind label and a sentence look the same
       here as they do on the menu. */ ?>
<section class="card" id="day">
  <h2 class="flush"><?= $e($U::longDate($day)) ?>
    <span class="muted small"><?= $e($U::relativeDays(Carl\Support\Clock::daysBetween($today, $day))) ?></span>
  </h2>
<?php if ($onDay === [] && $onDayWindows === []): ?>
  <p class="muted gap-sm flush">Nothing logged or worked out for this day.</p>
<?php elseif ($onDay === []): ?>
<?php else: ?>
  <ul class="list guidance gap-sm">
<?php foreach ($onDay as $entry): ?>
    <li>
      <div class="grow">
        <span class="topic kind-<?= $e($entry['kind']) ?>"><?= $e($entry['label']) ?></span>
        <span class="tiny muted"><?= $entry['projected'] ? 'worked out' : 'logged' ?></span><br>
        <strong><?= $e($entry['title']) ?></strong>
<?php if ((string) $entry['detail'] !== ''): ?>
        <div class="small muted"><?= $e($entry['detail']) ?></div>
<?php endif; ?>
<?php if ($entry['planting_id'] !== null): ?>
        <div class="tiny gap-xs">
          <a href="<?= $e($app->url('plants/' . (int) $entry['planting_id'])) ?>">Open the plant</a>
          &middot;
          <a href="<?= $e($app->url('log/' . (int) $entry['planting_id'])) ?>">Log it</a>
        </div>
<?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($onDayWindows !== []): ?>
<?php /* The windows this day sits inside (Phase 17): not dated entries but
       spans, so they are their own list -- with where in the window the
       day is, because "day 13 of 28" is the answer to "should I be
       picking yet". */ ?>
  <h3>In the harvest window</h3>
  <ul class="list guidance">
<?php foreach ($onDayWindows as $window): ?>
    <li>
      <div class="grow">
        <span class="topic kind-first_harvest_expected">Harvest</span>
        <span class="tiny muted">day <?= $e($window['day']) ?> of <?= $e($window['length']) ?></span><br>
        <strong><?= $e($window['name']) ?></strong>
        <div class="small muted">
          <?= $e($U::longDate((string) $window['from'])) ?> to <?= $e($U::longDate((string) $window['to'])) ?>:
          <?= $e($window['min']) ?> to <?= $e($window['max']) ?> days from <?= $e($window['counted']) ?>.
        </div>
        <div class="tiny gap-xs">
          <a href="<?= $e($app->url('plants/' . (int) $window['planting_id'])) ?>">Open the plant</a>
          &middot;
          <a href="<?= $e($app->url('log/' . (int) $window['planting_id'])) ?>">Log it</a>
        </div>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <p class="tiny muted flush gap-sm">
    <a href="<?= $e($app->url('calendar', $carry($month))) ?>">Close</a>
  </p>
</section>
<?php endif; ?>

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
