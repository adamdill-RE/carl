<?php
/**
 * Hardening schedules (handoff Section 5.5): a name, days of the week each
 * with a time range, and a projected duration the countdown runs from.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $schedules
 */
$e = $view->e(...);
$pageTitle = 'Hardening schedules';
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<h1 class="page-title">Hardening schedules</h1>
<p class="page-sub">Carl counts down from the day hardening starts to the day the
  transplant is due. <a href="<?= $e($app->url('lists')) ?>">All lists</a></p>

<form method="post" action="<?= $e($app->url('lists')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="list_type" value="hardening">

  <div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" maxlength="120" required placeholder="Standard 10 day">
  </div>
  <div class="field">
    <label for="duration_days">Projected duration (days)</label>
    <input type="number" id="duration_days" name="duration_days" min="1" max="60" value="10" required>
  </div>
  <div class="check">
    <input type="checkbox" id="is_default" name="is_default" value="1">
    <label for="is_default">Use this one by default</label>
  </div>

  <h3>Hours outside</h3>
<?php foreach ($weekdays as $index => $day): ?>
  <div class="weekday-row">
    <span class="check">
      <input type="checkbox" id="wd-<?= $e($index) ?>" name="weekday[]" value="<?= $e($index) ?>">
      <label for="wd-<?= $e($index) ?>"><?= $e($day) ?></label>
    </span>
    <div class="times">
      <input type="time" name="time_from[<?= $e($index) ?>]" value="09:00"
             aria-label="<?= $e($day) ?> from">
      <input type="time" name="time_to[<?= $e($index) ?>]" value="15:00"
             aria-label="<?= $e($day) ?> to">
    </div>
  </div>
<?php endforeach; ?>

  <button type="submit" class="btn">Save schedule</button>
</form>

<div class="card">
<?php if ($schedules === []): ?>
  <p class="muted">No schedules yet.</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($schedules as $schedule): ?>
    <li><span class="grow">
      <strong><?= $e($schedule['name']) ?></strong>
      <?= $e($schedule['duration_days']) ?> days
<?php if ((int) $schedule['is_default'] === 1): ?><span class="badge">default</span><?php endif; ?>
<?php if (!empty($schedule['days'])): ?>
      <br><span class="muted small">
<?php foreach ($schedule['days'] as $day): ?>
        <?= $e(\substr($weekdays[(int) $day['weekday']], 0, 3)) ?>
        <?= $e(\substr((string) $day['time_from'], 0, 5)) ?>-<?= $e(\substr((string) $day['time_to'], 0, 5)) ?>
<?php endforeach; ?>
      </span>
<?php endif; ?>
    </span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
