<?php
/**
 * Filters for the plant list: category, type, state, garden, row
 * (handoff Section 4.4). Only values the user actually has are offered.
 *
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var array<string,mixed> $filters
 * @var array{categories:list<string>,types:list<string>,states:list<string>} $options
 * @var list<array<string,mixed>> $gardens
 * @var string $target
 */
$e = $view->e(...);
?>
<form method="get" action="<?= $e($app->url($target)) ?>" class="card card-tight">
  <div class="filters">
    <div class="field">
      <label for="f-category">Category</label>
      <select id="f-category" name="category">
        <option value="">All</option>
<?php foreach ($options['categories'] as $category): ?>
        <option value="<?= $e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= $e($category) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-type">Type</label>
      <select id="f-type" name="type">
        <option value="">All</option>
<?php foreach ($options['types'] as $type): ?>
        <option value="<?= $e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= $e($type) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-state">State</label>
      <select id="f-state" name="state">
        <option value="">All</option>
<?php foreach ($options['states'] as $state): ?>
        <option value="<?= $e($state) ?>" <?= $filters['state'] === $state ? 'selected' : '' ?>>
          <?= $e(Carl\Domain\PlantingState::label($state)) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-garden">Garden</label>
      <select id="f-garden" name="garden_id">
        <option value="">All</option>
<?php foreach ($gardens as $garden): ?>
        <option value="<?= $e($garden['id']) ?>" <?= (int) $filters['garden_id'] === (int) $garden['id'] ? 'selected' : '' ?>>
          <?= $e($garden['name']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row gap-sm">
    <input type="search" name="q" value="<?= $e($filters['search']) ?>"
           placeholder="Search, or type a tag code" class="grow"
           autocapitalize="characters" autocorrect="off" spellcheck="false">
    <button type="submit" class="btn btn-small">Filter</button>
    <a class="btn btn-secondary btn-small" href="<?= $e($app->url($target)) ?>">Clear</a>
  </div>
  <?php /* The camera cannot type into this box -- it navigates to /t/{code}
         by itself (docs/QR-TAGS-SPEC.md Section 7, no in-app scanner). This
         is the other half: the six characters read off the stake in your
         hand, which land on the plant's page on THIS screen rather than on
         the field screen. Case, spaces and hyphens do not matter. */ ?>
  <p class="help flush">Six characters off a plant tag jump straight to that plant.
     Fewer than six narrows the list.</p>
</form>
