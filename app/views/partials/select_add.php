<?php
/**
 * A dropdown with an inline "+ Add new ..." (handoff Section 4).
 *
 * Works with no JavaScript: the extra text input is always in the DOM and the
 * controller prefers it when it is filled. forms.js only hides it until the
 * "+ Add new" option is chosen.
 *
 * @var Carl\Core\View $view
 * @var string $name      field name for the id, e.g. "water_method_id"
 * @var string $newName   field name for the typed name, e.g. "water_method_new"
 * @var string $label
 * @var string $listType  a Carl\Domain\ListType constant, for the async add
 * @var list<array<string,mixed>> $items
 * @var string|int|null $selected
 * @var bool $required
 */
$e = $view->e(...);
$required = $required ?? false;
$selected = $selected ?? null;
$help = $help ?? null;
?>
<div class="field select-add" data-list-type="<?= $e($listType ?? '') ?>">
  <label for="<?= $e($name) ?>"><?= $e($label) ?></label>
  <select id="<?= $e($name) ?>" name="<?= $e($name) ?>" data-new-target="<?= $e($newName) ?>"
          <?= $required ? 'data-required="1"' : '' ?>>
    <option value="">-- choose --</option>
<?php foreach ($items as $item): ?>
    <option value="<?= $e($item['id']) ?>" <?= (string) $selected === (string) $item['id'] ? 'selected' : '' ?>>
      <?= $e($item['name']) ?><?php if (!empty($item['attr_1'])): ?> (<?= $e($item['attr_1']) ?>)<?php endif; ?>
    </option>
<?php endforeach; ?>
    <option value="__new">+ Add new...</option>
  </select>
  <input type="text" name="<?= $e($newName) ?>" id="<?= $e($newName) ?>"
         class="new-item gap-xs" placeholder="Name the new <?= $e(\strtolower($label)) ?>">
<?php if ($help !== null): ?>
  <p class="help"><?= $e($help) ?></p>
<?php endif; ?>
</div>
