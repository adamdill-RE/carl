<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var string $type @var list<array<string,mixed>> $items
 *  @var list<array<string,mixed>> $gardens */
$e = $view->e(...);
$L = Carl\Domain\ListType::class;
$pageTitle = $L::label($type);
$attr1 = $L::attr1Label($type);
$attr2 = $L::attr2Label($type);
?>
<h1 class="page-title"><?= $e($L::label($type)) ?></h1>
<p class="page-sub"><a href="<?= $e($app->url('lists')) ?>">All lists</a></p>

<form method="post" action="<?= $e($app->url('lists')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="list_type" value="<?= $e($type) ?>">
  <div class="field">
    <label for="name">Add a <?= $e($L::singular($type)) ?></label>
    <input type="text" id="name" name="name" maxlength="120" required>
  </div>
<?php if ($attr1 !== null): ?>
  <div class="field">
    <label for="attr_1"><?= $e($attr1) ?></label>
    <input type="text" id="attr_1" name="attr_1" maxlength="120">
  </div>
<?php endif; ?>
<?php if ($attr2 !== null): ?>
  <div class="field">
    <label for="attr_2"><?= $e($attr2) ?></label>
    <input type="text" id="attr_2" name="attr_2" maxlength="255">
  </div>
<?php endif; ?>
<?php if ($gardens !== []): ?>
  <div class="field">
    <label for="garden_id">Tied to a garden (optional)</label>
    <select id="garden_id" name="garden_id">
      <option value="">-- any garden --</option>
<?php foreach ($gardens as $garden): ?>
      <option value="<?= $e($garden['id']) ?>"><?= $e($garden['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
<?php endif; ?>
  <button type="submit" class="btn">Add</button>
</form>

<div class="card">
<?php if ($items === []): ?>
  <p class="muted">Nothing here yet.</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($items as $item): $active = (int) $item['is_active'] === 1; ?>
    <li>
      <span class="grow<?= $active ? '' : ' muted' ?>">
        <?= $e($item['name']) ?>
<?php if (!empty($item['attr_1'])): ?><span class="muted small"> &middot; <?= $e($item['attr_1']) ?></span><?php endif; ?>
<?php if (!empty($item['attr_2'])): ?><br><span class="muted small"><?= $e($item['attr_2']) ?></span><?php endif; ?>
<?php if (!$active): ?><span class="badge badge-muted">archived</span><?php endif; ?>
      </span>
      <form method="post" action="<?= $e($app->url('lists/archive')) ?>" style="margin:0">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="list_type" value="<?= $e($type) ?>">
        <input type="hidden" name="id" value="<?= $e($item['id']) ?>">
<?php if (!$active): ?><input type="hidden" name="restore" value="1"><?php endif; ?>
        <button type="submit" class="btn-link tiny"><?= $active ? 'archive' : 'restore' ?></button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="tiny muted">Archiving hides an item from new forms. Past records keep it, so nothing you
     already logged changes.</p>
<?php endif; ?>
</div>
