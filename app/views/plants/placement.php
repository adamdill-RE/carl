<?php
/**
 * Where a plant goes: a garden row, or a container. The occupancy hint on row
 * selection is a nudge, never a block (handoff Section 4.3).
 *
 * @var Carl\Core\View $view
 * @var list<array<string,mixed>> $gardens
 * @var array<int,list<array<string,mixed>>> $rowsByGarden
 * @var list<array<string,mixed>> $containers
 * @var array<string,mixed> $old
 */
$e = $view->e(...);
$old = $old ?? [];
?>
<div class="field">
  <label for="garden_id">Garden</label>
  <select id="garden_id" name="garden_id">
    <option value="">-- none (in a container) --</option>
<?php foreach ($gardens as $garden): ?>
    <option value="<?= $e($garden['id']) ?>"
            <?= (string) ($old['garden_id'] ?? '') === (string) $garden['id'] ? 'selected' : '' ?>>
      <?= $e($garden['name']) ?><?= (int) $garden['is_indoor'] === 1 ? ' (indoor)' : '' ?>
    </option>
<?php endforeach; ?>
  </select>
</div>

<div class="field">
  <label for="garden_row_id">Row</label>
  <select id="garden_row_id" name="garden_row_id">
    <option value="">-- no particular row --</option>
<?php foreach ($rowsByGarden as $gardenId => $rows): ?>
<?php foreach ($rows as $row): ?>
    <option value="<?= $e($row['id']) ?>" data-garden="<?= $e($gardenId) ?>"
            <?= (string) ($old['garden_row_id'] ?? '') === (string) $row['id'] ? 'selected' : '' ?>>
      <?= $e($row['name']) ?>
    </option>
<?php endforeach; ?>
<?php endforeach; ?>
  </select>
</div>

<div class="field">
  <label for="container_id">Container</label>
  <select id="container_id" name="container_id">
    <option value="">-- not a container --</option>
<?php foreach ($containers as $container): ?>
    <option value="<?= $e($container['id']) ?>"
            <?= (string) ($old['container_id'] ?? '') === (string) $container['id'] ? 'selected' : '' ?>>
      <?= $e($container['name']) ?><?php if (!empty($container['size'])): ?> (<?= $e($container['size']) ?>)<?php endif; ?>
    </option>
<?php endforeach; ?>
  </select>
  <p class="help">Add containers under Lists. A container behaves as a garden of one spot.</p>
</div>
