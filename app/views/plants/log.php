<?php
/**
 * Log Plant Activity for one plant, or for a batch (handoff Section 4.4).
 *
 * The research card sits above the actions MOTD-style, dismissable for the
 * page. Which actions appear is decided by state; for a batch it is the
 * intersection, so a batch can never half-apply.
 *
 * Transplanted, Up-potted and Moved all ask HOW MANY and WHERE TO, and a
 * quantity short of the live count makes the plants that move a planting of
 * their own. That is the split, and the form never calls it one: the
 * gardener's sentence is "I transplanted six of them".
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $plantings
 * @var array<string,mixed>|null $single
 * @var list<string> $actions
 * @var array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
 * @var bool $hasRegion @var string $today
 * @var array<string,list<array<string,mixed>>> $lists
 * @var list<array<string,mixed>> $schedules
 * @var array<int,array{living:int,plantings:int}> $occupancy
 * @var array<int,list<array{family:string,last_date:string,plantings:int}>> $rotation
 * @var int $rotationYears
 * @var list<array<string,mixed>> $timeline
 */
$e = $view->e(...);
$E = Carl\Domain\EventType::class;
$L = Carl\Domain\ListType::class;
$S = Carl\Domain\PlantingState::class;
$U = Carl\Support\Units::class;

$isBatch = $single === null;
$pageTitle = $isBatch ? 'Log for ' . \count($plantings) . ' plants' : 'Log activity';
$action = $isBatch ? $app->url('log/batch') : $app->url('log/' . $single['id']);
?>
<h1 class="page-title">
<?php if ($isBatch): ?>
  Log the same action for <?= $e(\count($plantings)) ?> plants
<?php else: ?>
  <?= $e($single['category']) ?> &middot; <?= $e($single['type']) ?>
<?php endif; ?>
</h1>
<p class="page-sub">
<?php if ($isBatch): ?>
<?php foreach ($plantings as $planting): ?>
  <?= $e($planting['category']) ?> <?= $e($planting['type']) ?> (<?= $e($planting['quantity_live']) ?> living)<?= $planting === \end($plantings) ? '' : ' &middot;' ?>
<?php endforeach; ?>
<?php else: ?>
  <span class="badge"><?= $e($S::label((string) $single['state'])) ?></span>
  <?= $e((int) $single['quantity_live']) ?> of <?= $e((int) $single['quantity_initial']) ?> living
  &middot; started <?= $e($U::longDate((string) $single['start_date'])) ?>
  &middot; <a href="<?= $e($app->url('plants/' . $single['id'])) ?>">full report</a>
<?php if (!empty($single['tag_code'])): ?>
  <?php /* Free: the code rides in on the row the form was built from, so
         naming the stake costs no statement -- and it is what makes the
         search box on the list screen worth typing into. */ ?>
  &middot; tag <a class="mono tag-ref"
       href="<?= $e($app->url('t/' . $single['tag_code'])) ?>"><?= $e($single['tag_code']) ?></a>
<?php endif; ?>
<?php endif; ?>
</p>

<details class="card motd">
  <summary><strong>Research for this plant</strong></summary>
  <?= $view->partial('plants/research_card', ['card' => $card, 'hasRegion' => $hasRegion]) ?>
</details>

<?php if ($actions === []): ?>
  <div class="notice notice-info">
    There is no action these plants have in common right now. Log them one at a time.
  </div>
<?php else: ?>

<form method="post" action="<?= $e($action) ?>" class="card" id="log-form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php if ($isBatch): ?>
<?php foreach ($plantings as $planting): ?>
  <input type="hidden" name="planting_ids[]" value="<?= $e($planting['id']) ?>">
<?php endforeach; ?>
<?php endif; ?>

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
    <p class="help">Defaults to today; past dates are fine.</p>
  </div>

  <!-- Every action's own fields. forms.js shows only the chosen one; with no
       JavaScript they are all visible and the unused ones are simply ignored. -->

  <fieldset class="event-fields" data-for="<?= $e($E::WATERED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'water_method_id', 'newName' => 'water_method_new', 'label' => 'Water method',
          'listType' => $L::WATER_METHOD, 'items' => $lists[$L::WATER_METHOD] ?? []]) ?>
    <div class="field">
      <label for="duration_min">Duration (minutes)</label>
      <input type="number" id="duration_min" name="duration_min" min="0" max="1440">
    </div>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::GERMINATED) ?> <?= $e($E::GERMINATION_FAILED) ?> <?= $e($E::DIED) ?> <?= $e($E::CULLED) ?>">
    <div class="field">
      <label for="quantity">How many</label>
      <input type="number" id="quantity" name="quantity" min="0" max="100000"
             <?= $isBatch ? '' : 'placeholder="' . $e((int) $single['quantity_live']) . ' (all of them)"' ?>>
      <p class="help">Leave blank for all of them. Entering fewer records partial attrition.</p>
    </div>
<?php if ($isBatch): ?>
    <div class="check">
      <input type="checkbox" id="quantity_all" name="quantity_all" value="1">
      <label for="quantity_all">All remaining, per plant</label>
    </div>
<?php endif; ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::CULLED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'cull_reason_id', 'newName' => 'cull_reason_new', 'label' => 'Reason',
          'listType' => $L::CULL_REASON, 'items' => $lists[$L::CULL_REASON] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::UP_POTTED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'soil_id', 'newName' => 'soil_new', 'label' => 'Soil',
          'listType' => $L::UP_POT_SOIL, 'items' => $lists[$L::UP_POT_SOIL] ?? []]) ?>
    <?= $view->partial('partials/select_add', [
          'name' => 'container_type_id', 'newName' => 'container_type_new', 'label' => 'Container',
          'listType' => $L::UP_POT_CONTAINER, 'items' => $lists[$L::UP_POT_CONTAINER] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::HARDENING_STARTED) ?>">
    <div class="field">
      <label for="hardening_schedule_id">Schedule</label>
      <select id="hardening_schedule_id" name="hardening_schedule_id">
        <option value="">-- none --</option>
<?php foreach ($schedules as $schedule): ?>
        <option value="<?= $e($schedule['id']) ?>" data-days="<?= $e($schedule['duration_days']) ?>"
                <?= (int) $schedule['is_default'] === 1 ? 'selected' : '' ?>>
          <?= $e($schedule['name']) ?> (<?= $e($schedule['duration_days']) ?> days)
        </option>
<?php endforeach; ?>
      </select>
      <p class="help">Make schedules under <a href="<?= $e($app->url('lists/hardening')) ?>">Lists</a>.</p>
    </div>
    <div class="field">
      <label for="hardening_days">Projected duration (days)</label>
      <input type="number" id="hardening_days" name="hardening_days" min="1" max="60" value="10">
      <p class="help">Carl counts down from the start date to the day the transplant is due.</p>
    </div>
  </fieldset>

  <?php /* The three actions that move plants somewhere else share one block:
         how many, and where to. A quantity smaller than the live count makes
         the plants that move a planting of their own, because a planting has
         exactly one location (docs/PLANTING-SPLIT-SPEC.md Section 4.1). The
         user is never shown the word "split". */ ?>
  <fieldset class="event-fields" data-for="<?= $e($E::TRANSPLANTED) ?> <?= $e($E::UP_POTTED) ?> <?= $e($E::MOVED) ?>">
    <div class="field">
      <label for="move_quantity">How many are moving</label>
      <input type="number" id="move_quantity" name="move_quantity" min="1" max="100000"
             <?= $isBatch ? '' : 'placeholder="' . $e((int) $single['quantity_live']) . ' (all of them)"' ?>>
      <p class="help">
        Leave blank for all of them. Move fewer and the ones that move are
        tracked on their own from here on, with a link back to
        <?= $isBatch ? 'the planting they came from' : 'this planting' ?>.
      </p>
    </div>
    <?= $view->partial('plants/placement', [
          'gardens' => $gardens, 'rowsByGarden' => $rowsByGarden,
          'containers' => $containers, 'occupancy' => $occupancy,
          'rotation' => $rotation, 'rotationYears' => $rotationYears, 'old' => []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::YIELDED) ?>">
    <div class="row">
      <div class="field grow">
        <label for="yield_weight">Weight</label>
        <input type="number" step="0.01" min="0" id="yield_weight" name="yield_weight">
      </div>
      <div class="field">
        <label for="yield_weight_unit">Unit</label>
        <select id="yield_weight_unit" name="yield_weight_unit">
          <option value="oz">oz</option><option value="lb">lb</option>
          <option value="g">g</option><option value="kg">kg</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="yield_count">or a count</label>
      <input type="number" min="0" id="yield_count" name="yield_count">
      <p class="help">Weight or count -- whichever you actually measured.</p>
    </div>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_OBSERVED) ?> <?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'pest_id', 'newName' => 'pest_new', 'label' => 'Pest or disease',
          'listType' => $L::PEST_DISEASE, 'items' => $lists[$L::PEST_DISEASE] ?? []]) ?>
    <?php /* Phase 9: the dropdown names it and the reference says what it
           does. The link is here rather than a panel because this form is
           already long, and because the question -- "which of these is it,
           and does it matter?" -- is asked once and then remembered. */ ?>
    <p class="help">
      Not sure which, or what it does?
      <a href="<?= $e($app->url('pests')) ?>" target="_blank" rel="noopener">Open the pest
      and disease reference</a> -- signs, what it costs to ignore, and what to do.
    </p>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'treatment_id', 'newName' => 'treatment_new', 'label' => 'Treatment',
          'listType' => $L::PEST_TREATMENT, 'items' => $lists[$L::PEST_TREATMENT] ?? []]) ?>
    <div class="check">
      <input type="checkbox" id="also_observe" name="also_observe" value="1" checked>
      <label for="also_observe">Also record the observation, if there is not one already</label>
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
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::MEASURED) ?>">
    <p class="help flush">
      Fill in the size below &mdash; a height, a diameter, or both. A measurement
      with neither is not recorded.
    </p>
  </fieldset>

<?php if (!$isBatch): ?>
  <?php /* Outside the per-action fieldsets, with the notes and the photos,
         because a size is not an action: it is something true of the plant
         while you were there doing something else. Somebody watering a bed
         records ONE thing -- "watered it, it is fourteen inches now" -- and a
         box that costs a second pass through this form is a box that stops
         being filled in. Measured (above) is the same two fields for the
         visit where measuring was the whole errand.

         Not offered for a BATCH: the same number written against twenty
         plants is nineteen measurements nobody took. EventType's
         SINGLE_PLANT_ONLY is the rule, and LogController::batch() refuses
         one, so this `if` and that refusal say the same thing twice on
         purpose -- a form that offers what the handler rejects is worse than
         either. */ ?>
  <fieldset class="size-fields">
    <legend>Size <span class="muted">(optional)</span></legend>
    <div class="row">
      <div class="field grow">
        <label for="size_height">Height</label>
        <input type="number" step="0.1" min="0" id="size_height" name="size_height"
               inputmode="decimal">
      </div>
      <div class="field grow">
        <label for="size_diameter">Diameter</label>
        <input type="number" step="0.1" min="0" id="size_diameter" name="size_diameter"
               inputmode="decimal">
      </div>
      <div class="field">
        <label for="size_unit">Unit</label>
        <select id="size_unit" name="size_unit">
<?php foreach ($U::SIZE_UNITS as $sizeUnit): ?>
          <option value="<?= $e($sizeUnit) ?>"<?= $sizeUnit === $units->sizeUnit() ? ' selected' : '' ?>><?= $e($sizeUnit) ?></option>
<?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="help">
      Whichever you measured &mdash; height for a tomato, across for a squash,
      both for a shrub. Kept in <?= $e($units->sizeUnit()) ?>, whichever unit you
      type it in, and charted on the plant's report.
    </p>
  </fieldset>
<?php endif; ?>

  <div class="field">
    <label for="narrative">Notes</label>
    <textarea id="narrative" name="narrative" placeholder="Anything worth remembering"></textarea>
  </div>

<?php if (!$isBatch): ?>
  <?= $view->partial('partials/photo_uploader', ['plantingId' => (int) $single['id']]) ?>
<?php endif; ?>

  <button type="submit" class="btn btn-block">Record it</button>
</form>
<?php endif; ?>

<?php if (!$isBatch && $timeline !== []): ?>
<section class="card">
  <h2>Recent activity</h2>
  <?= $view->partial('plants/timeline', ['events' => \array_slice($timeline, 0, 10), 'photos' => []]) ?>
  <p><a href="<?= $e($app->url('plants/' . $single['id'])) ?>">See the whole timeline</a></p>
</section>
<?php endif; ?>

<script src="<?= $e($app->asset('assets/js/forms.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/photos.js')) ?>" defer></script>
