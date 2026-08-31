<?php
/**
 * The plant report (handoff Section 4.5). Charts are Phase 4; this is the
 * research card, the full timeline, the photos in chronological order, the
 * yield summary, and the weather that actually happened over the plant's
 * in-ground period.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $planting
 * @var array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $photos
 * @var array{weight_g:float,count_qty:int,events:int,first:?string,last:?string} $yield
 * @var list<array<string,mixed>> $weather
 * @var int $weatherGaps
 * @var list<array{label:string,date:string,days:?int}> $countdowns
 */
$e = $view->e(...);
$S = Carl\Domain\PlantingState::class;
$U = Carl\Support\Units::class;
$pageTitle = $planting['category'] . ' ' . $planting['type'];

$where = $planting['container_name']
    ?? \trim(((string) ($planting['garden_name'] ?? '')) . ' ' . ((string) ($planting['row_name'] ?? '')));
$initial = (int) $planting['quantity_initial'];
$live = (int) $planting['quantity_live'];
?>
<h1 class="page-title"><?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?></h1>
<p class="page-sub">
  <span class="badge<?= (string) $planting['state'] === $S::ENDED ? ' badge-muted' : '' ?>">
    <?= $e($S::label((string) $planting['state'])) ?></span>
<?php if (!empty($planting['label'])): ?>
  &middot; <?= $e($planting['label']) ?>
<?php endif; ?>
<?php if ($where !== ''): ?>
  &middot; <?= $e($where) ?>
<?php endif; ?>
  &middot; <a href="<?= $e($app->url('log/' . $planting['id'])) ?>">log an action</a>
</p>

<section class="card">
  <h2>Where it stands</h2>
  <table class="data">
    <tbody>
      <tr><th>Started</th><td><?= $e($U::longDate((string) $planting['start_date'])) ?>
        (<?= $e(\str_replace('_', ' ', (string) $planting['start_method'])) ?>)</td></tr>
<?php if ($planting['in_ground_date'] !== null): ?>
      <tr><th>In the ground</th><td><?= $e($U::longDate((string) $planting['in_ground_date'])) ?></td></tr>
<?php endif; ?>
      <tr><th>Living</th><td><?= $e($live) ?> of <?= $e($initial) ?>
<?php if ($initial > 0): ?>
        <span class="muted">(<?= $e(\round($live / $initial * 100)) ?>% survival)</span>
<?php endif; ?>
      </td></tr>
<?php if ($planting['germinated_at'] !== null): ?>
      <tr><th>Germinated</th><td><?= $e($U::longDate((string) $planting['germinated_at'])) ?></td></tr>
<?php endif; ?>
<?php if ($planting['ended_at'] !== null): ?>
      <tr><th>Ended</th><td><?= $e($U::longDate((string) $planting['ended_at'])) ?></td></tr>
<?php endif; ?>
<?php foreach ($countdowns as $countdown): ?>
      <tr><th><?= $e($countdown['label']) ?></th>
          <td><?= $e($U::longDate($countdown['date'])) ?>
              <span class="muted"><?= $e($U::relativeDays($countdown['days'])) ?></span></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($yield['events'] > 0): ?>
<section class="card">
  <h2>Yield</h2>
  <p>
<?php if ($yield['weight_g'] > 0): ?>
    <strong><?= $e($units->weight($yield['weight_g'])) ?></strong>
<?php endif; ?>
<?php if ($yield['count_qty'] > 0): ?>
    <strong><?= $e($yield['count_qty']) ?></strong> picked
<?php endif; ?>
    over <?= $e($yield['events']) ?> harvest<?= $yield['events'] === 1 ? '' : 's' ?>,
    <?= $e($U::longDate($yield['first'])) ?> to <?= $e($U::longDate($yield['last'])) ?>.
  </p>
</section>
<?php endif; ?>

<?= $view->partial('plants/research_card', ['card' => $card, 'hasRegion' => $user->hasRegion()]) ?>

<?php if ($weather !== [] || $weatherGaps > 0): ?>
<section class="card">
  <h2>Weather while it was in the ground</h2>
<?php if ($weatherGaps > 0): ?>
  <p class="notice notice-info small">
    <?= $e($weatherGaps) ?> day<?= $weatherGaps === 1 ? '' : 's' ?> in this range have not been
    fetched yet. The nightly sync fills gaps working backwards; they will appear here on their own.
  </p>
<?php endif; ?>
<?php if ($weather !== []): ?>
<?php
    $rain = 0.0; $et0 = 0.0; $hottest = null; $coldest = null;
    foreach ($weather as $day) {
        $rain += (float) ($day['precip_mm'] ?? 0);
        $et0 += (float) ($day['et0_mm'] ?? 0);
        if ($day['temp_max_c'] !== null && ($hottest === null || (float) $day['temp_max_c'] > $hottest)) {
            $hottest = (float) $day['temp_max_c'];
        }
        if ($day['temp_min_c'] !== null && ($coldest === null || (float) $day['temp_min_c'] < $coldest)) {
            $coldest = (float) $day['temp_min_c'];
        }
    }
?>
  <table class="data">
    <tbody>
      <tr><th>Days covered</th><td><?= $e(\count($weather)) ?></td></tr>
      <tr><th>Total rain</th><td><?= $e($units->rain($rain)) ?></td></tr>
      <tr><th>Total ET&#8320;</th><td><?= $e($units->rain($et0)) ?></td></tr>
      <tr><th>Water balance</th><td><?= $e($units->rain($rain - $et0)) ?>
        <span class="muted small">rain minus evapotranspiration</span></td></tr>
      <tr><th>Hottest / coldest</th><td><?= $e($units->temperatureRange($hottest, $coldest)) ?></td></tr>
    </tbody>
  </table>
  <p class="tiny muted">Charts arrive in a later phase; these are the totals behind them.</p>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Timeline</h2>
<?php if ($events === []): ?>
  <p class="muted">Nothing logged yet.</p>
<?php else: ?>
  <?= $view->partial('plants/timeline', ['events' => $events, 'photos' => $photos]) ?>
<?php endif; ?>
</section>

<?php if ($photos !== []): ?>
<section class="card">
  <h2>Photos</h2>
  <div class="photos">
<?php foreach ($photos as $photo): ?>
    <a href="<?= $e($app->url('photos/' . $photo['id'])) ?>"
       title="<?= $e($U::longDate((string) $photo['taken_on'])) ?>">
      <img src="<?= $e($app->url('photos/' . $photo['id'] . '/thumb')) ?>" alt="" loading="lazy">
    </a>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
