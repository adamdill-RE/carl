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
 * @var float|null $rowSpacingIn
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
$Drip = Carl\Domain\DripLine::class;
$rowSpacingIn = $rowSpacingIn ?? null;
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
<?php foreach ($zones as $zone): $drip = $Drip::resolve($zone, $rowSpacingIn); ?>
    <li>
      <span class="grow"><strong><?= $e($zone['name']) ?></strong>
        <?php if (!empty($zone['method_name'])): ?><span class="muted">-- <?= $e($zone['method_name']) ?></span><?php endif; ?>
<?php if ($drip !== null): ?>
        <br><span class="muted small">
<?php /* What the zone puts down, in the gardener's units, with every
       assumption the model will make said out loud (Phase 14). */ ?>
<?php if ($units->isUs()): ?>
          <?= $e($Drip::trimNumber((float) $zone['emitter_gph'])) ?> gph every
          <?= $e($Drip::trimNumber($drip['emitter_spacing_in'])) ?> in<?= empty($zone['emitter_spacing_in']) ? ' (assumed)' : '' ?>,
          lines <?= $e($Drip::trimNumber($drip['line_spacing_in'])) ?> in apart<?= empty($zone['line_spacing_in']) ? ($rowSpacingIn !== null ? ' (this garden\'s row spacing)' : ' (assumed)') : '' ?>,
          <?= $e($drip['efficiency_pct']) ?>% reaching the roots:
          about <?= $e($Drip::mmPerHourToInchesPerHour($drip['rate_mm_h'])) ?> in/h
<?php else: ?>
          <?= $e($Drip::trimNumber($Drip::gphToLitresPerHour((float) $zone['emitter_gph']))) ?> L/h every
          <?= $e($Drip::trimNumber($Drip::inchesToCm($drip['emitter_spacing_in']))) ?> cm<?= empty($zone['emitter_spacing_in']) ? ' (assumed)' : '' ?>,
          lines <?= $e($Drip::trimNumber($Drip::inchesToCm($drip['line_spacing_in']))) ?> cm apart<?= empty($zone['line_spacing_in']) ? ($rowSpacingIn !== null ? ' (this garden\'s row spacing)' : ' (assumed)') : '' ?>,
          <?= $e($drip['efficiency_pct']) ?>% reaching the roots:
          about <?= $e(\round($drip['rate_mm_h'], 1)) ?> mm/h
<?php endif; ?>
        </span>
<?php endif; ?>
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
<?php /* What the zone puts down (Phase 14). Every field optional: with none
       of them the zone keeps using the water method's rate, exactly as
       before. Typed in the gardener's units; stored as gph and inches, the
       units on the emitter packet. */ ?>
<?php $usUnits = $units->isUs(); ?>
    <h3>What it puts down <span class="muted small">(optional)</span></h3>
    <p class="help">
      From the emitter packet. With these, Carl works out how deep each watering
      went and how long to run the zone to refill the soil, instead of guessing
      from the method's name.
    </p>
    <div class="field">
      <label for="emitter_flow">Emitter flow (<?= $usUnits ? 'gallons per hour per emitter' : 'litres per hour per emitter' ?>)</label>
      <input type="number" id="emitter_flow" name="emitter_flow" inputmode="decimal" step="any"
             min="<?= $usUnits ? '0.01' : '0.04' ?>" max="<?= $usUnits ? '60' : '227' ?>" placeholder="<?= $usUnits ? '0.5' : '1.9' ?>">
    </div>
    <div class="field">
      <label for="emitter_spacing">Emitter spacing (<?= $usUnits ? 'inches' : 'cm' ?> between emitters)</label>
      <input type="number" id="emitter_spacing" name="emitter_spacing" inputmode="decimal" step="any"
             min="<?= $usUnits ? '1' : '2.5' ?>" max="<?= $usUnits ? '240' : '610' ?>" placeholder="<?= $usUnits ? '12' : '30' ?>">
      <p class="help">Blank means <?= $usUnits ? '12 inches' : '30 cm' ?>, the common inline spacing.</p>
    </div>
    <div class="field">
      <label for="line_spacing">Line spacing (<?= $usUnits ? 'inches' : 'cm' ?> between lines)</label>
      <input type="number" id="line_spacing" name="line_spacing" inputmode="decimal" step="any"
             min="<?= $usUnits ? '1' : '2.5' ?>" max="<?= $usUnits ? '240' : '610' ?>" placeholder="<?= $usUnits ? '24' : '60' ?>">
      <p class="help">
<?php if ($rowSpacingIn !== null): ?>
        Blank means this garden's row spacing, about
        <?= $usUnits ? $e($Drip::trimNumber($rowSpacingIn)) . ' inches' : $e($Drip::trimNumber($Drip::inchesToCm($rowSpacingIn))) . ' cm' ?>
        from its size and row count.
<?php else: ?>
        Blank means the same as the emitter spacing. Give the garden a size and a
        row count and Carl will use its row spacing instead.
<?php endif; ?>
      </p>
    </div>
    <div class="field">
      <label for="efficiency_pct">Efficiency (% of that water reaching the roots)</label>
      <input type="number" id="efficiency_pct" name="efficiency_pct" inputmode="numeric"
             min="<?= $e($Drip::EFFICIENCY_MIN_PCT) ?>" max="<?= $e($Drip::EFFICIENCY_MAX_PCT) ?>" value="<?= $e($Drip::DEFAULT_EFFICIENCY_PCT) ?>">
      <p class="help">
        Not all drip line is perfect: a clogged emitter, a slope, a run past the end
        of the bed. 80% is a fair figure for a home system; a new, level, flushed
        line can be 90 or better.
      </p>
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
/* A garden has no height and no harvest of its own, so its only subject
   series is watering minutes -- but the pickers are built from what the
   fetched document says the subject HAS, so this needs no branch of its own
   (charts.js, PLANT_LAYERS `need`). What it needs is a spine. */
$hasChart = ($series['plant']['dates'] ?? []) !== [];
?>
<?php if ($hasChart): ?>
<section class="card">
  <h2>How this garden did, and the weather over it</h2>
  <?= $view->partial('partials/charts', [
        'seriesUrl' => $seriesUrl,
        'pdfUrl'    => $pdfUrl,
        'range'     => $range,
        'csrf'      => $csrf,
      ]) ?>
<?php if ($hasWeather): ?>
  <h3>Weather over this garden</h3>
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
<?php endif; ?>
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
    <a href="<?= $e($app->url('photos/' . $photo['id'] . '/view')) ?>"
       data-full="<?= $e($app->url('photos/' . $photo['id'])) ?>"
       data-caption="<?= $e(\Carl\Support\Units::longDate((string) $photo['taken_on'])
           . ((string) ($photo['caption'] ?? '') !== '' ? ' -- ' . $photo['caption'] : '')) ?>">
      <img src="<?= $e($app->url('photos/' . $photo['id'] . '/thumb')) ?>" alt="" loading="lazy">
    </a>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* Scripts last, as everywhere else; the vendored library before the
       file that uses it. Both are deferred, so this is the run order. */ ?>
<?php if ($hasChart): ?>
<script src="<?= $e($app->asset('assets/vendor/chart.umd.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/charts.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($photos !== []): ?>
<script src="<?= $e($app->asset('assets/js/gallery.js')) ?>" defer></script>
<?php endif; ?>
