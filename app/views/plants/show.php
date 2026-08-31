<?php
/**
 * The plant report (handoff Section 4.5): the research card, the full
 * timeline, the photos in chronological order, the yield summary, and the
 * weather that actually happened over the plant's in-ground period -- as
 * totals, and from Phase 4 as charts beside them.
 *
 * The charts are drawn by assets/js/charts.js from /api/plant/<id>/series,
 * NOT from a JSON island in this page: CSP is script-src 'self' with no
 * inline script and no nonce (hosting Section 8.5), so the data arrives over
 * fetch and the element below carries only its URL in a data- attribute.
 * With JavaScript off the totals table is the whole report and still true.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $planting
 * @var array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $photos
 * @var array{weight_g:float,count_qty:int,events:int,first:?string,last:?string} $yield
 * @var array<string,mixed> $series
 * @var string $seriesUrl @var string $pdfUrl
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

<?php
$range = $series['range'];
$totals = $series['totals'];
$hasWeather = $series['days'] !== [];
?>
<?php if ($hasWeather || $range['days_missing'] > 0): ?>
<section class="card">
  <h2>Weather while it was in the ground</h2>
<?php if ($range['days_missing'] > 0): ?>
  <p class="notice notice-info small">
    <?= $e($range['days_missing']) ?> day<?= $range['days_missing'] === 1 ? '' : 's' ?> in this range have not been
    fetched yet. The nightly sync fills gaps working backwards; they will appear here on their own.
  </p>
<?php endif; ?>
<?php if ($range['clamped']): ?>
  <p class="notice notice-info small">
    This plant has been in the ground longer than a chart can usefully show, so the
    last <?= $e($range['max_days']) ?> days are drawn:
    <?= $e($U::longDate($range['from'])) ?> onwards.
  </p>
<?php endif; ?>
<?php if ($hasWeather): ?>
  <table class="data">
    <tbody>
      <tr><th>Days covered</th><td><?= $e($range['days_held']) ?></td></tr>
      <tr><th>Total rain</th><td><?= $e($totals['rain']) ?></td></tr>
      <tr><th>Total ET&#8320;</th><td><?= $e($totals['et0']) ?></td></tr>
      <tr><th>Water balance</th><td><?= $e($totals['balance']) ?>
        <span class="muted small">rain minus evapotranspiration</span></td></tr>
      <tr><th>Hottest / coldest</th><td><?= $e($totals['temp_range']) ?></td></tr>
    </tbody>
  </table>

  <?= $view->partial('partials/charts', [
        'seriesUrl' => $seriesUrl,
        'pdfUrl'    => $pdfUrl,
        'range'     => $range,
        'csrf'      => $csrf,
      ]) ?>
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

<?php /* Scripts last, as everywhere else. The vendored library first:
       charts.js checks for window.Chart and does nothing without it, and
       both are deferred so the order here is the execution order. */ ?>
<?php if ($series['days'] !== []): ?>
<script src="<?= $e($app->asset('assets/vendor/chart.umd.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/charts.js')) ?>" defer></script>
<?php endif; ?>
