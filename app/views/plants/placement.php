<?php
/**
 * Where a plant goes: a garden row, or a container. The occupancy hint and
 * the crop rotation warning on row selection are both nudges, never blocks
 * (handoff Section 4.3, Phase 5 handoff Section 3.4).
 *
 * The count rides in the option's own text rather than in a separate element,
 * because forms.js re-parents these options when the garden changes and a
 * sibling hint would be left behind. It also means the hint survives with
 * JavaScript off, which is the rule every enhancement here follows.
 *
 * The rotation history rides the same way, for the same two reasons, and then
 * a third: the warning depends on the plant TYPE as well as the row, so the
 * live version of it needs a script. With JavaScript off the option still
 * reads "Row 3 -- grew Solanaceae in 2025", which is the fact; with the
 * script the box below says what it means for the plant actually chosen.
 *
 * @var Carl\Core\View $view
 * @var list<array<string,mixed>> $gardens
 * @var array<int,list<array<string,mixed>>> $rowsByGarden
 * @var list<array<string,mixed>> $containers
 * @var array<int,array{living:int,plantings:int}> $occupancy
 * @var array<int,list<array{family:string,last_date:string,plantings:int}>> $rotation
 * @var int $rotationYears
 * @var array<string,mixed> $old
 */
$e = $view->e(...);
$old = $old ?? [];
$occupancy = $occupancy ?? [];
$rotation = $rotation ?? [];
$rotationYears = $rotationYears ?? 3;

/** "Row 3 already has 4 living plants." -- said once, in one place. */
$hint = static function (array $counts): string {
    $living = (int) ($counts['living'] ?? 0);
    if ($living <= 0) {
        return '';
    }
    return ' -- already has ' . $living . ' living plant' . ($living === 1 ? '' : 's');
};

/**
 * "grew Solanaceae in 2025, Fabaceae in 2024". The two most recent families
 * only: the option text is read on a 380 px phone inside a select, and a row
 * with six years of history would push the row's own name off the end.
 *
 * @param list<array{family:string,last_date:string,plantings:int}> $history
 */
$grew = static function (array $history): string {
    if ($history === []) {
        return '';
    }
    $parts = [];
    foreach (\array_slice($history, 0, 2) as $entry) {
        $parts[] = $entry['family'] . ' in ' . \substr($entry['last_date'], 0, 4);
    }
    return ' -- grew ' . \implode(', ', $parts);
};

/** The families a row has grown, for the script to match against. */
$families = static function (array $history): string {
    return \implode(',', \array_column($history, 'family'));
};
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
  <select id="garden_row_id" name="garden_row_id" data-rotation-years="<?= $e($rotationYears) ?>">
    <option value="">-- no particular row --</option>
<?php foreach ($rowsByGarden as $gardenId => $rows): ?>
<?php foreach ($rows as $row): $history = $rotation[(int) $row['id']] ?? []; ?>
    <option value="<?= $e($row['id']) ?>" data-garden="<?= $e($gardenId) ?>"
            data-families="<?= $e($families($history)) ?>"
            <?= (string) ($old['garden_row_id'] ?? '') === (string) $row['id'] ? 'selected' : '' ?>>
      <?= $e($row['name']) ?><?= $e($hint($occupancy[(int) $row['id']] ?? [])) ?><?= $e($grew($history)) ?>
    </option>
<?php endforeach; ?>
<?php endforeach; ?>
  </select>
  <p class="rotation-warn" id="rotation-warning" hidden></p>
  <p class="help">
    The count beside a row is what is already living there, and what it grew is
    the last <?= $e($rotationYears) ?> years. Both are hints, not limits.
  </p>
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
