<?php
/**
 * Garden Actions (handoff Section 4.7). Each writes one garden_event;
 * watering a zone also fans out a derived water record to every living plant
 * in the zone's rows.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $garden
 * @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $zones
 * @var array<string,list<array<string,mixed>>> $lists
 * @var list<string> $actions
 */
$e = $view->e(...);
$E = Carl\Domain\EventType::class;
$L = Carl\Domain\ListType::class;
$pageTitle = 'Garden actions';
?>
<h1 class="page-title">Garden actions</h1>
<p class="page-sub"><?= $e($garden['name']) ?>
  &middot; <a href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">view the garden</a></p>

<form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/actions')) ?>" class="card" id="log-form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="event_type">What did you do?</label>
    <select id="event_type" name="event_type" required>
      <option value="">-- choose an action --</option>
<?php foreach ($actions as $type): ?>
      <option value="<?= $e($type) ?>"><?= $e($E::label($type)) ?></option>
<?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="event_date">Date</label>
    <input type="date" id="event_date" name="event_date" value="<?= $e($today) ?>" max="<?= $e($today) ?>" required>
  </div>

  <fieldset class="event-fields" data-for="<?= $e($E::WATERED) ?>">
    <div class="field">
      <label for="water_zone_id">Zone</label>
      <select id="water_zone_id" name="water_zone_id">
        <option value="">-- the whole garden --</option>
<?php foreach ($zones as $zone): ?>
        <option value="<?= $e($zone['id']) ?>"><?= $e($zone['name']) ?></option>
<?php endforeach; ?>
      </select>
      <p class="help">
        Watering a zone also logs the watering against every living plant in that
        zone's rows, marked as coming from here so it is not counted twice.
      </p>
    </div>
    <?= $view->partial('partials/select_add', [
          'name' => 'water_method_id', 'newName' => 'water_method_new', 'label' => 'Water method',
          'listType' => $L::WATER_METHOD, 'items' => $lists[$L::WATER_METHOD] ?? []]) ?>
    <div class="field">
      <label for="duration_min">Duration (minutes)</label>
      <input type="number" id="duration_min" name="duration_min" min="0" max="1440">
    </div>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::FERTILIZED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'fertilizer_id', 'newName' => 'fertilizer_new', 'label' => 'Fertiliser',
          'listType' => $L::FERTILIZER_GARDEN, 'items' => $lists[$L::FERTILIZER_GARDEN] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::AMENDED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'amendment_id', 'newName' => 'amendment_new', 'label' => 'Amendment',
          'listType' => $L::SOIL_AMENDMENT, 'items' => $lists[$L::SOIL_AMENDMENT] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::MULCHED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'mulch_id', 'newName' => 'mulch_new', 'label' => 'Mulch type',
          'listType' => $L::MULCH_TYPE, 'items' => $lists[$L::MULCH_TYPE] ?? []]) ?>
<?php if ($rows !== []): ?>
    <div class="field">
      <label>Rows mulched</label>
<?php foreach ($rows as $row): ?>
      <span class="check">
        <input type="checkbox" id="mr-<?= $e($row['id']) ?>" name="rows[]" value="<?= $e($row['id']) ?>">
        <label for="mr-<?= $e($row['id']) ?>"><?= $e($row['name']) ?></label>
      </span>
<?php endforeach; ?>
      <p class="help">Leave all unticked for the whole garden.</p>
    </div>
<?php endif; ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_OBSERVED) ?> <?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'pest_id', 'newName' => 'pest_new', 'label' => 'Pest or disease',
          'listType' => $L::PEST_DISEASE, 'items' => $lists[$L::PEST_DISEASE] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'treatment_id', 'newName' => 'treatment_new', 'label' => 'Treatment',
          'listType' => $L::PEST_TREATMENT, 'items' => $lists[$L::PEST_TREATMENT] ?? []]) ?>
  </fieldset>

  <div class="field">
    <label for="narrative">Notes</label>
    <textarea id="narrative" name="narrative"></textarea>
  </div>

  <?= $view->partial('partials/photo_uploader', ['gardenId' => (int) $garden['id']]) ?>

  <button type="submit" class="btn btn-block">Record it</button>
</form>

<?php /* End Growing Season (Phase 5 handoff Section 3.3). Below the form and
       in its own card rather than as another option in the action select:
       every other action here adds one row, and this one ends every living
       plant in the garden. It should not be one scroll away from "Watered". */ ?>
<section class="card">
  <h2>End the growing season</h2>
  <p class="small muted">
    Ends every living planting in <?= $e($garden['name']) ?> on one date, in one go.
    The next screen names each one before anything is written.
  </p>
  <p><a class="btn btn-secondary"
        href="<?= $e($app->url('gardens/' . $garden['id'] . '/end-season')) ?>">End growing season&hellip;</a></p>
</section>

<script src="<?= $e($app->asset('assets/js/forms.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/photos.js')) ?>" defer></script>
