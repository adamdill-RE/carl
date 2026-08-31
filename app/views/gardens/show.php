<?php
/**
 * View Garden -- the garden report (handoff Section 4.8): the rows, the
 * zones, what is planted, the garden's own actions, and from Phase 4 the
 * weather over the dates it has been in use, as charts and as a PDF.
 *
 * The charts are drawn by assets/js/charts.js from /api/garden/<id>/series.
 * There is no JSON island: CSP is script-src 'self' with no nonce (hosting
 * Section 8.5).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $garden
 * @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $zones
 * @var list<array<string,mixed>> $plantings @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $photos
 * @var array<int,array<string,mixed>> $yieldByRow
 * @var array<int,array{living:int,plantings:int}> $occupancy
 * @var array<string,list<array<string,mixed>>> $lists
 * @var array<string,mixed> $series
 * @var string $seriesUrl @var string $pdfUrl
 */
$e = $view->e(...);
$S = Carl\Domain\PlantingState::class;
$E = Carl\Domain\EventType::class;
$U = Carl\Support\Units::class;
$L = Carl\Domain\ListType::class;
$Soil = Carl\Domain\SoilType::class;
$pageTitle = (string) $garden['name'];
?>
<h1 class="page-title"><?= $e($garden['name']) ?></h1>
<p class="page-sub">
<?php if ($garden['ns_ft'] !== null && $garden['ew_ft'] !== null): ?>
  <?= $e($garden['ns_ft']) ?> x <?= $e($garden['ew_ft']) ?> ft &middot;
<?php endif; ?>
  <?= $e(\count($rows)) ?> rows running <?= $e($garden['row_orientation'] === 'ns' ? 'north-south' : 'east-west') ?>
  &middot; <?= $e($Soil::label($garden['soil_type'])) ?>
  &middot; <a href="<?= $e($app->url('gardens/' . $garden['id'] . '/actions')) ?>">garden actions</a>
</p>

<section class="card">
  <h2>Rows</h2>
<?php if ($rows === []): ?>
  <p class="muted">No rows. <a href="<?= $e($app->url('gardens/' . $garden['id'] . '/edit')) ?>">Add some</a>.</p>
<?php else: ?>
  <form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/rows')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <table class="data">
      <thead><tr><th>Row</th><th>Sun</th><th>Living</th><th>Yield</th></tr></thead>
      <tbody>
<?php foreach ($rows as $row):
    $rowId = (int) $row['id'];
    $yield = $yieldByRow[$rowId] ?? null;
?>
        <tr>
          <td>
            <input type="hidden" name="row_id[]" value="<?= $e($rowId) ?>">
            <input type="text" name="row_name[]" value="<?= $e($row['name']) ?>" maxlength="60">
          </td>
          <td>
            <select name="row_sun[]">
<?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label): ?>
              <option value="<?= $e($key) ?>" <?= $row['sun_exposure'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
            </select>
            <input type="hidden" name="row_shade[]" value="<?= $e($row['shade_cloth_id'] ?? '') ?>">
            <input type="hidden" name="row_notes[]" value="<?= $e($row['notes'] ?? '') ?>">
          </td>
          <td class="nowrap"><?= $e($occupancy[$rowId]['living'] ?? 0) ?></td>
          <td class="nowrap">
<?php if ($yield !== null && (float) $yield['weight_g'] > 0): ?>
            <?= $e($units->weight($yield['weight_g'])) ?>
<?php elseif ($yield !== null && (int) $yield['count_qty'] > 0): ?>
            <?= $e($yield['count_qty']) ?>
<?php else: ?>
            <span class="muted">--</span>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" class="btn btn-secondary btn-small">Save row names and sun</button>
  </form>
<?php endif; ?>
</section>

<section class="card">
  <h2>Water zones</h2>
<?php if ($zones === []): ?>
  <p class="muted small">No zones yet. A zone groups rows under one watering method, so
     watering the zone logs it against every living plant in those rows at once.</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($zones as $zone): ?>
    <li>
      <span class="grow"><strong><?= $e($zone['name']) ?></strong>
        <?php if (!empty($zone['method_name'])): ?><span class="muted">-- <?= $e($zone['method_name']) ?></span><?php endif; ?>
      </span>
      <form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/zones')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="delete_zone_id" value="<?= $e($zone['id']) ?>">
        <button type="submit" class="btn-link tiny">remove</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($rows !== []): ?>
  <form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/zones')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <h3>Add a zone</h3>
    <div class="field">
      <label for="zone_name">Zone name</label>
      <input type="text" id="zone_name" name="zone_name" maxlength="80" placeholder="Drip line east">
    </div>
    <?= $view->partial('partials/select_add', [
          'name' => 'water_method_id', 'newName' => 'water_method_new', 'label' => 'Water method',
          'listType' => $L::WATER_METHOD, 'items' => $lists[$L::WATER_METHOD] ?? []]) ?>
    <div class="field">
      <label>Rows covered</label>
<?php foreach ($rows as $row): ?>
      <span class="check">
        <input type="checkbox" id="zr-<?= $e($row['id']) ?>" name="zone_rows[]" value="<?= $e($row['id']) ?>">
        <label for="zr-<?= $e($row['id']) ?>"><?= $e($row['name']) ?></label>
      </span>
<?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-secondary">Save zone</button>
  </form>
<?php endif; ?>
</section>

<section class="card">
  <h2>Plants here</h2>
<?php if ($plantings === []): ?>
  <p class="muted">Nothing planted here yet.</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($plantings as $planting): ?>
    <li><a class="grow" href="<?= $e($app->url('plants/' . $planting['id'])) ?>">
      <span class="name"><?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?></span><br>
      <span class="meta">
        <span class="badge<?= (string) $planting['state'] === $S::ENDED ? ' badge-muted' : '' ?>">
          <?= $e($S::label((string) $planting['state'])) ?></span>
        <?= $e($planting['quantity_live']) ?> living
        <?php if (!empty($planting['row_name'])): ?>&middot; <?= $e($planting['row_name']) ?><?php endif; ?>
      </span>
    </a></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<?php
$range = $series['range'];
$hasWeather = $series['days'] !== [];
?>
<?php if ($hasWeather): ?>
<section class="card">
  <h2>Weather over this garden</h2>
  <table class="data">
    <tbody>
      <tr><th>Days covered</th><td><?= $e($range['days_held']) ?></td></tr>
      <tr><th>Total rain</th><td><?= $e($series['totals']['rain']) ?></td></tr>
      <tr><th>Total ET&#8320;</th><td><?= $e($series['totals']['et0']) ?></td></tr>
      <tr><th>Water balance</th><td><?= $e($series['totals']['balance']) ?>
        <span class="muted small">rain minus evapotranspiration</span></td></tr>
      <tr><th>Hottest / coldest</th><td><?= $e($series['totals']['temp_range']) ?></td></tr>
    </tbody>
  </table>
  <?= $view->partial('partials/charts', [
        'seriesUrl' => $seriesUrl,
        'pdfUrl'    => $pdfUrl,
        'range'     => $range,
        'csrf'      => $csrf,
      ]) ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Garden events</h2>
<?php if ($events === []): ?>
  <p class="muted">Nothing logged for the garden itself yet.</p>
<?php else: ?>
  <ul class="timeline">
<?php foreach ($events as $event): ?>
    <li>
      <div class="when"><?= $e($U::longDate((string) $event['event_date'])) ?></div>
      <div class="what"><?= $e($E::label((string) $event['event_type'])) ?>
<?php if ((int) $event['fanout_count'] > 0): ?>
        <span class="badge badge-muted">logged to <?= $e($event['fanout_count']) ?> plants</span>
<?php endif; ?>
      </div>
      <div class="small">
<?php
    $bits = [];
    foreach (['zone_name', 'ref_name', 'ref_name_2'] as $key) {
        if (!empty($event[$key])) {
            $bits[] = (string) $event[$key];
        }
    }
    if ($event['duration_min'] !== null) {
        $bits[] = $event['duration_min'] . ' min';
    }
    // Each part is escaped on its own and the separator is joined in raw --
    // escaping the joined string would turn the entity into &amp;middot;.
    echo \implode(' &middot; ', \array_map($e, $bits));
?>
      </div>
<?php if (!empty($event['narrative'])): ?>
      <div class="small"><?= $e($event['narrative']) ?></div>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<?php if ($photos !== []): ?>
<section class="card">
  <h2>Photos</h2>
  <div class="photos">
<?php foreach ($photos as $photo): ?>
    <a href="<?= $e($app->url('photos/' . $photo['id'])) ?>">
      <img src="<?= $e($app->url('photos/' . $photo['id'] . '/thumb')) ?>" alt="" loading="lazy">
    </a>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* Scripts last, as everywhere else; the vendored library before the
       file that uses it. Both are deferred, so this is the run order. */ ?>
<?php if ($series['days'] !== []): ?>
<script src="<?= $e($app->asset('assets/vendor/chart.umd.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/charts.js')) ?>" defer></script>
<?php endif; ?>
