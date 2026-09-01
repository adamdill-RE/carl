<?php
/**
 * The three entry forms (handoff Section 4.3). Which fields appear is set by
 * $kind; everything else is shared.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $kind @var array<string,string> $meta
 * @var list<array<string,mixed>> $plantTypes
 * @var bool $hasRegion
 * @var list<array<string,mixed>> $gardens
 * @var array<int,list<array<string,mixed>>> $rowsByGarden
 * @var list<array<string,mixed>> $containers
 * @var array<int,array{living:int,plantings:int}> $occupancy
 * @var array<int,list<array{family:string,last_date:string,plantings:int}>> $rotation
 * @var int $rotationYears
 * @var array<string,list<array<string,mixed>>> $lists
 * @var string $today @var int|null $indoorGardenId @var string $tag
 * @var list<array{batch_id:int,stock_sku:string,sheet:int,tags:list<array{id:int,code:string,row:int,column:int}>}> $freeTags
 * @var list<string> $errors @var array<string,mixed> $old
 */
$e = $view->e(...);
$pageTitle = $meta['title'];
$L = Carl\Domain\ListType::class;
$old = $old ?? [];
$val = static fn (string $k, string $default = ''): string
    => (string) ($old[$k] ?? $default);

// Categories, in the order the region overlay put them: in-region and
// recommended first (handoff Section 4.3).
$byCategory = [];
foreach ($plantTypes as $type) {
    $byCategory[(string) $type['category']][] = $type;
}
?>
<h1 class="page-title"><?= $e($meta['title']) ?></h1>
<p class="page-sub"><?= $e($meta['blurb']) ?></p>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if (($tag ?? '') !== ''): ?>
<div class="notice notice-info">
  Tag <strong class="mono"><?= $e($tag) ?></strong> goes on this plant when you save it.
  Change it, or pick none, at the foot of the form.
</div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('plants')) ?>" class="card"
      data-research-url="<?= $e($app->url('research/')) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="start_method" value="<?= $e($kind) ?>">

  <div class="field">
    <label for="category">Category</label>
    <select id="category" name="category" required>
      <option value="">-- choose --</option>
<?php foreach (\array_keys($byCategory) as $category): ?>
      <option value="<?= $e($category) ?>" <?= $val('category') === $category ? 'selected' : '' ?>><?= $e($category) ?></option>
<?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="plant_type_id">Type</label>
    <select id="plant_type_id" name="plant_type_id" required>
      <option value="">-- choose a category first --</option>
<?php foreach ($plantTypes as $type): ?>
<?php /* data-family drives the crop rotation warning beside the row picker
       (Phase 5 handoff Section 3.4). It is here rather than fetched because
       the warning depends on BOTH selects, and a round trip on every change
       of either is a request per keystroke for a fact the page already has. */ ?>
      <option value="<?= $e($type['id']) ?>" data-category="<?= $e($type['category']) ?>"
              data-in-region="<?= (int) ($type['in_region'] ?? 0) ?>"
              data-family="<?= $e($type['plant_family'] ?? '') ?>"
              <?= $val('plant_type_id') === (string) $type['id'] ? 'selected' : '' ?>>
        <?= $e($type['type']) ?><?= ((int) ($type['recommended'] ?? 0) === 1) ? ' *' : '' ?>
      </option>
<?php endforeach; ?>
    </select>
<?php if ($hasRegion): ?>
    <p class="help">
      <label class="check inline">
        <input type="checkbox" id="show-all-types"> <span>Show every plant, not only those researched for your area</span>
      </label><br>
      * recommended for your area.
    </p>
<?php else: ?>
    <p class="help">
      Your county has no research loaded yet, so this is the full catalog with nothing
      marked. Everything still records normally.
    </p>
<?php endif; ?>
  </div>

  <div id="research-card"></div>

  <div class="field">
    <label for="start_date">Date</label>
    <input type="date" id="start_date" name="start_date" value="<?= $e($val('start_date', $today)) ?>"
           max="<?= $e($today) ?>" required>
    <p class="help">Defaults to today. Backdating is fine -- put in the date it actually happened.</p>
  </div>

  <div class="field">
    <label for="quantity_initial"><?= $kind === 'nursery_transplant' ? 'How many plants' : 'Quantity sown' ?></label>
    <input type="number" id="quantity_initial" name="quantity_initial" min="1" max="100000"
           value="<?= $e($val('quantity_initial', $kind === 'nursery_transplant' ? '1' : '12')) ?>" required>
  </div>

<?php if ($kind === 'indoor_seed'): ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'seed_source_id', 'newName' => 'seed_source_new', 'label' => 'Seed source',
        'listType' => $L::SEED_SOURCE, 'items' => $lists[$L::SEED_SOURCE] ?? [],
        'selected' => $val('seed_source_id')]) ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'soil_id', 'newName' => 'soil_new', 'label' => 'Seed-starting soil',
        'listType' => $L::SEED_STARTING_SOIL, 'items' => $lists[$L::SEED_STARTING_SOIL] ?? [],
        'selected' => $val('soil_id')]) ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'vessel_id', 'newName' => 'vessel_new', 'label' => 'Seed-starting vessel',
        'listType' => $L::SEED_STARTING_VESSEL, 'items' => $lists[$L::SEED_STARTING_VESSEL] ?? [],
        'selected' => $val('vessel_id')]) ?>
  <input type="hidden" name="garden_id" value="<?= $e($indoorGardenId ?? '') ?>">
  <p class="help">Goes into your Indoor Garden. Move it outdoors with Transplant when it is ready.</p>

<?php elseif ($kind === 'direct_sow'): ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'seed_source_id', 'newName' => 'seed_source_new', 'label' => 'Seed source',
        'listType' => $L::SEED_SOURCE, 'items' => $lists[$L::SEED_SOURCE] ?? [],
        'selected' => $val('seed_source_id')]) ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'fertilizer_id', 'newName' => 'fertilizer_new', 'label' => 'Fertiliser used at sowing',
        'listType' => $L::FERTILIZER_SOW, 'items' => $lists[$L::FERTILIZER_SOW] ?? [],
        'selected' => $val('fertilizer_id')]) ?>

  <div class="check">
    <input type="checkbox" id="collar_used" name="collar_used" value="1" <?= $val('collar_used') !== '' ? 'checked' : '' ?>>
    <label for="collar_used">Collars used</label>
  </div>
  <div class="field">
    <label for="seeds_per_collar">Seeds per collar</label>
    <input type="number" id="seeds_per_collar" name="seeds_per_collar" min="1" max="100"
           value="<?= $e($val('seeds_per_collar')) ?>">
    <p class="help">Quantity above defaults to collars x seeds; edit it if you counted differently.</p>
  </div>
  <div class="check">
    <input type="checkbox" id="trellis_used" name="trellis_used" value="1" <?= $val('trellis_used') !== '' ? 'checked' : '' ?>>
    <label for="trellis_used">Trellis or cage</label>
  </div>
  <?= $view->partial('plants/placement', [
        'gardens' => $gardens, 'rowsByGarden' => $rowsByGarden,
        'containers' => $containers, 'occupancy' => $occupancy,
        'rotation' => $rotation, 'rotationYears' => $rotationYears, 'old' => $old]) ?>

<?php else: ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'nursery_id', 'newName' => 'nursery_new', 'label' => 'Nursery',
        'listType' => $L::NURSERY, 'items' => $lists[$L::NURSERY] ?? [],
        'selected' => $val('nursery_id')]) ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'fertilizer_id', 'newName' => 'fertilizer_new', 'label' => 'Fertiliser used',
        'listType' => $L::FERTILIZER_SOW, 'items' => $lists[$L::FERTILIZER_SOW] ?? [],
        'selected' => $val('fertilizer_id')]) ?>
  <?= $view->partial('partials/select_add', [
        'name' => 'water_method_id', 'newName' => 'water_method_new', 'label' => 'Default water method',
        'listType' => $L::WATER_METHOD, 'items' => $lists[$L::WATER_METHOD] ?? [],
        'selected' => $val('water_method_id')]) ?>
  <div class="check">
    <input type="checkbox" id="trellis_used" name="trellis_used" value="1" <?= $val('trellis_used') !== '' ? 'checked' : '' ?>>
    <label for="trellis_used">Trellis or cage</label>
  </div>
  <div class="row">
    <div class="field grow">
      <label for="initial_height_in">Height (in)</label>
      <input type="number" step="0.1" min="0" id="initial_height_in" name="initial_height_in"
             value="<?= $e($val('initial_height_in')) ?>">
    </div>
    <div class="field grow">
      <label for="initial_width_in">Width (in)</label>
      <input type="number" step="0.1" min="0" id="initial_width_in" name="initial_width_in"
             value="<?= $e($val('initial_width_in')) ?>">
    </div>
  </div>
  <?= $view->partial('plants/placement', [
        'gardens' => $gardens, 'rowsByGarden' => $rowsByGarden,
        'containers' => $containers, 'occupancy' => $occupancy,
        'rotation' => $rotation, 'rotationYears' => $rotationYears, 'old' => $old]) ?>
<?php endif; ?>

  <div class="field">
    <label for="label">Nickname (optional)</label>
    <input type="text" id="label" name="label" maxlength="120" value="<?= $e($val('label')) ?>"
           placeholder="The ones by the fence">
  </div>

  <div class="field">
    <label for="notes">Notes</label>
    <textarea id="notes" name="notes"><?= $e($val('notes')) ?></textarea>
  </div>

<?php /* "At the foot of Start a New Plant: assign a tag" (QR-TAGS-SPEC
       Section 5.2). The picker is the codes still in the box, by sheet; a
       code carried in from a scan ("start a new plant with this tag") is
       preselected. Shown only when there is something to pick or something
       was carried, so an account that has never printed a sheet is not asked
       about a feature it does not use. The code is validated on submit and a
       stale one is a form error, never a silent drop. */ ?>
<?php $chosenTag = $val('tag', $tag ?? ''); ?>
<?php if ($freeTags !== [] || $chosenTag !== ''): ?>
  <div class="field">
    <label for="tag">Tag (optional)</label>
    <?= $view->partial('partials/tag_picker', [
          'sheets' => $freeTags, 'name' => 'tag', 'selected' => $chosenTag,
          'allowNone' => true, 'id' => 'tag']) ?>
    <p class="help">
      A stake with a code on it, going into the cell or the ground with this plant. Pick the
      label off the sheet here, or leave it and put one on later from the plant's page
      &mdash; or by scanning any free tag.
    </p>
  </div>
<?php endif; ?>

  <?= $view->partial('partials/photo_uploader', ['plantingId' => null, 'gardenId' => null]) ?>

  <button type="submit" class="btn btn-block">Record it</button>
</form>

<script src="<?= $e($app->asset('assets/js/forms.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/photos.js')) ?>" defer></script>
