<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var list<array<string,mixed>> $containers @var array<string,string> $soilTypes */
$e = $view->e(...);
$Soil = Carl\Domain\SoilType::class;
$pageTitle = 'Containers';
?>
<h1 class="page-title">Containers</h1>
<p class="page-sub">A container behaves as a garden of one spot.
  <a href="<?= $e($app->url('lists')) ?>">All lists</a></p>

<form method="post" action="<?= $e($app->url('lists')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="list_type" value="containers">
  <div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" maxlength="120" required placeholder="Half barrel by the door">
  </div>
  <div class="field">
    <label for="size">Size</label>
    <input type="text" id="size" name="size" maxlength="60" placeholder="15 gallon">
  </div>
  <div class="field">
    <label for="description">Description</label>
    <input type="text" id="description" name="description" maxlength="255">
  </div>
  <div class="field">
    <label for="soil_type">Soil</label>
    <select id="soil_type" name="soil_type">
      <option value="">-- not sure --</option>
<?php foreach ($soilTypes as $key => $label): ?>
      <option value="<?= $e($key) ?>" <?= $key === 'container' ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn">Add container</button>
</form>

<div class="card">
<?php if ($containers === []): ?>
  <p class="muted">No containers yet.</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($containers as $container): $active = (int) $container['is_active'] === 1; ?>
    <li>
      <span class="grow<?= $active ? '' : ' muted' ?>"><?= $e($container['name']) ?>
<?php if (!empty($container['size'])): ?><span class="muted small"> &middot; <?= $e($container['size']) ?></span><?php endif; ?>
<?php if (!empty($container['soil_type'])): ?><span class="muted small"> &middot; <?= $e($Soil::label($container['soil_type'])) ?></span><?php endif; ?>
      </span>
      <form method="post" action="<?= $e($app->url('lists/archive')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="list_type" value="containers">
        <input type="hidden" name="id" value="<?= $e($container['id']) ?>">
<?php if (!$active): ?><input type="hidden" name="restore" value="1"><?php endif; ?>
        <button type="submit" class="btn-link tiny"><?= $active ? 'archive' : 'restore' ?></button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
