<?php
/**
 * One timer: the page the notification opens (Phase 16).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array<string,mixed> $timer @var string $place
 * @var string|null $endsLocal @var string|null $firedLocal
 * @var int $minutesLeft @var bool $running
 */
$e = $view->e(...);
$pageTitle = 'Timer';
$cancelled = $timer['cancelled_at'] !== null;
$logged = $timer['logged_event_id'] !== null;
?>
<h1 class="page-title"><?= $e($timer['minutes']) ?> min on <?= $e($place) ?></h1>
<p class="page-sub"><?= $e($timer['garden_name']) ?>
  &middot; <a href="<?= $e($app->url('gardens/' . $timer['garden_id'] . '/actions')) ?>">garden actions</a></p>

<section class="card">
<?php if ($cancelled): ?>
  <p>Cancelled before it finished. Nothing was logged.</p>
<?php elseif ($running): ?>
  <p>
    <strong>Still running.</strong> Done at <?= $e($endsLocal) ?>,
    <?= $minutesLeft <= 0 ? 'any minute now' : 'about ' . $e($minutesLeft) . ' min from now' ?>.
    <?= (int) $timer['log_when_done'] === 1 ? 'Carl will log the watering when it finishes.' : '' ?>
  </p>
  <form method="post" action="<?= $e($app->url('timers/' . $timer['id'] . '/cancel')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn btn-secondary">Cancel the timer</button>
  </form>
<?php else: ?>
  <p><strong>Finished at <?= $e($firedLocal) ?>.</strong>
<?php if ($logged): ?>
    Logged as a watering<?= $timer['water_zone_id'] !== null ? ', and against every living plant in the zone' : '' ?>.
<?php else: ?>
    Not logged yet.
<?php endif; ?>
  </p>
<?php if (!$logged): ?>
  <form method="post" action="<?= $e($app->url('timers/' . $timer['id'] . '/log')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn btn-block">Log it as a watering</button>
  </form>
  <p class="help">
    Today's date, <?= $e($timer['minutes']) ?> minutes, on <?= $e($place) ?>.
    For a different date or method, use
    <a href="<?= $e($app->url('gardens/' . $timer['garden_id'] . '/actions')) ?>">garden actions</a> instead.
  </p>
<?php endif; ?>
<?php if ($timer['notified_via'] !== null): ?>
  <p class="tiny muted">
    You were told by <?= $e($timer['notified_via'] === 'none' ? 'nothing -- no phone and no email address' : $timer['notified_via']) ?>.
<?php if ($timer['fire_error'] !== null): ?>
    Something went wrong on the way: <?= $e($timer['fire_error']) ?>
<?php endif; ?>
  </p>
<?php endif; ?>
<?php endif; ?>
</section>

<p><a class="btn btn-secondary" href="<?= $e($app->url('')) ?>">Back to the menu</a></p>
