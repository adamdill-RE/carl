<?php
/**
 * The succession planner (handoff Section 15, built in Phase 6).
 *
 * Every date on this page is a link into Start a New Plant with the crop and
 * the date already filled in, because a planner whose output has to be
 * copied by hand into another screen is a planner nobody uses twice. There is
 * no "accept" button and no saved plan: accepting a round is sowing it, and
 * sowing it writes the `planting` row that the rest of Carl already believes.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $today @var bool $hasRegion
 * @var string|null $firstFrost @var int $interval @var int $horizon
 * @var list<array<string,mixed>> $crops
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$pageTitle = 'Succession planting';
$regionLabel = $regionLabel ?? '';
?>
<h1 class="page-title">Succession planting</h1>
<p class="page-sub">
  A short row every <?= $e($interval) ?> days beats one long one in spring.
  These are the sowings your area&rsquo;s research still allows this season.
</p>

<?php if (!$hasRegion): ?>
  <div class="notice notice-info">
    <p class="small">
      Carl has no research loaded for your county yet, and the sowing windows
      are exactly what that research carries &mdash; so there is nothing to plan
      from. Everything else keeps working: days to maturity, the watering model
      and the countdowns do not need it.
    </p>
  </div>
  <p><a class="btn btn-secondary" href="<?= $e($app->url('reports')) ?>">Back to reports</a></p>
  <?php return; ?>
<?php endif; ?>

<form method="get" action="<?= $e($app->url('succession')) ?>" class="card card-tight">
  <div class="row">
    <label for="every" class="grow small">Days between rounds</label>
    <input type="number" id="every" name="every" min="7" max="35" step="1"
           value="<?= $e($interval) ?>" class="narrow">
    <button class="btn btn-small" type="submit">Redraw</button>
  </div>
</form>

<?php if ($crops === []): ?>
  <div class="card">
    <p class="small muted">
      Nothing to sow in the next <?= $e($horizon) ?> days for
      <?= $regionLabel !== '' ? $e($regionLabel) : 'your area' ?>. Every sowing
      window in the research is either closed or further off than that.
    </p>
  </div>
<?php else: ?>

<?php foreach ($crops as $crop): ?>
  <section class="card">
    <h2>
      <?= $e($crop['type']) ?>
      <span class="small muted"><?= $e($crop['category']) ?></span>
<?php if ($crop['recommended']): ?>
      <span class="badge">recommended here</span>
<?php endif; ?>
    </h2>

    <p class="small muted">
<?php if ($crop['last_sown'] !== null): ?>
      You have sown this <?= $e($crop['rounds_so_far']) ?>
      time<?= (int) $crop['rounds_so_far'] === 1 ? '' : 's' ?>, most recently
      <?= $e($U::longDate($crop['last_sown'])) ?>.
<?php else: ?>
      You have not sown this yet.
<?php endif; ?>
    </p>

<?php foreach ($crop['seasons'] as $season): ?>
    <h3>
      <?= $e(\ucfirst($season['season'])) ?>
      <span class="small muted">
        sows <?= $e($U::monthDayRange($season['window_start'], $season['window_end'])) ?>
      </span>
<?php if ($season['confidence'] !== null): ?>
      <span class="confidence confidence-<?= $e($season['confidence']) ?>"><?= $e($season['confidence']) ?></span>
<?php endif; ?>
    </h3>

    <div class="matrix-scroll">
      <table class="matrix">
        <thead>
          <tr><th>Sow</th><th>Ready from</th><th>Through</th><th></th></tr>
        </thead>
        <tbody>
<?php foreach ($season['rounds'] as $round): ?>
          <tr<?= $round['after_frost'] ? ' class="provisional"' : '' ?>>
            <td class="nowrap"><?= $e($U::shortDate($round['sow_on'])) ?></td>
            <td class="nowrap"><?= $e($U::shortDate($round['harvest_from'])) ?></td>
            <td class="nowrap"><?= $e($U::shortDate($round['harvest_to'])) ?></td>
            <td class="right nowrap">
<?php if ($round['after_frost']): ?>
              <span class="small muted" title="Ripens after your average first frost">after frost</span>
<?php else: ?>
              <a class="btn btn-small btn-secondary"
                 href="<?= $e($app->url('plants/new/direct_sow', [
                     'plant_type_id' => (string) $crop['plant_type_id'],
                     'category'      => (string) $crop['category'],
                     'start_date'    => (string) $round['sow_on'],
                 ])) ?>">Sow</a>
<?php endif; ?>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

<?php if ($season['notes'] !== null && $season['notes'] !== ''): ?>
    <p class="small muted"><?= $e($season['notes']) ?></p>
<?php endif; ?>
<?php if ($season['source'] !== null && $season['source'] !== ''): ?>
    <p class="tiny muted">Source: <?= $e($season['source']) ?></p>
<?php endif; ?>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>

<?php endif; ?>

<div class="card">
  <h2>How these dates are worked out</h2>
  <p class="small muted">
    The sowing window comes from the research for your county, and it is the
    authority on when it is too late &mdash; Carl does not second-guess it.
    &ldquo;Ready from&rdquo; is the sowing date plus the days to maturity for
    that crop, using your region&rsquo;s override where the research gives one.
<?php if ($firstFrost !== null): ?>
    A round whose first pick would fall after your average first frost
    (<?= $e($U::monthDay($firstFrost)) ?>) is greyed out and marked rather than
    hidden: a row cover and a mild autumn beat an average more often than not,
    and you can see for yourself which way it went.
<?php else: ?>
    No average first frost is recorded for your area, so nothing here is
    checked against one.
<?php endif; ?>
  </p>
  <p class="small muted">
    Only windows opening within the next <?= $e($horizon) ?> days are drawn. A
    crop whose season is half a year off is not a plan, and twenty of them
    would bury the two that are open now.
  </p>
  <p class="small muted">
    Nothing on this page is saved. Sowing a round is what records it, and the
    digest picks the chain up from there &mdash; a fortnight after each sowing
    it will say another round is due, for as long as the window stays open.
  </p>
</div>

<p><a class="btn btn-secondary" href="<?= $e($app->url('reports')) ?>">Back to reports</a></p>
