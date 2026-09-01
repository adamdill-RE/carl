<?php
/**
 * The free-code picker: "here is a plant, which tag?" (docs/QR-TAGS-SPEC.md
 * Section 5.2, the desk direction). On the plant page and at the foot of
 * Start a New Plant.
 *
 * A SELECT, GROUPED BY SHEET, IN SHEET ORDER -- not a text box. The person
 * using this is at a desk with a sheet of labels in front of them, and the
 * thing they do after choosing is peel a label, so each option says where on
 * the sheet it is. A native select needs no script, type-to-jump finds a
 * code read off a stake already in hand, and a wheel of forty-eight codes on
 * a phone is a short scroll. The code is printed on every label in large
 * mono, so "row 3, column 1" is a shortcut and not the only way to find it.
 *
 * A code carried in from a scan is preselected, and is added as an option if
 * the list has not got it -- the POST checks it again, so a stale one is
 * refused with a reason rather than quietly dropped.
 *
 * @var Carl\Core\View $view
 * @var list<array{batch_id:int,stock_sku:string,sheet:int,tags:list<array{id:int,code:string,row:int,column:int}>}> $sheets
 * @var string $name      the field name
 * @var string $selected  a code, or ''
 * @var bool   $allowNone a leading "no tag" option
 * @var string $id        the control's id, for its label
 */
$e = $view->e(...);
$LS = Carl\Domain\LabelStock::class;
$selected = $selected ?? '';
$allowNone = $allowNone ?? true;
$id = $id ?? $name;

$present = false;
foreach ($sheets as $sheet) {
    foreach ($sheet['tags'] as $tag) {
        if ($tag['code'] === $selected) {
            $present = true;
        }
    }
}
?>
<select id="<?= $e($id) ?>" name="<?= $e($name) ?>" class="mono">
<?php if ($allowNone): ?>
  <option value=""<?= $selected === '' ? ' selected' : '' ?>>No tag</option>
<?php endif; ?>
<?php if ($selected !== '' && !$present): ?>
  <option value="<?= $e($selected) ?>" selected><?= $e($selected) ?> (scanned)</option>
<?php endif; ?>
<?php foreach ($sheets as $sheet): ?>
  <optgroup label="Sheet <?= $e($sheet['batch_id']) ?><?= $sheet['sheet'] > 1 ? ', page ' . $e($sheet['sheet']) : '' ?> &middot; <?= $e($LS::name($sheet['stock_sku'])) ?>">
<?php foreach ($sheet['tags'] as $tag): ?>
    <option value="<?= $e($tag['code']) ?>"<?= $tag['code'] === $selected ? ' selected' : '' ?>>
      <?= $e($tag['code']) ?> &middot; row <?= $e($tag['row']) ?>, column <?= $e($tag['column']) ?>
    </option>
<?php endforeach; ?>
  </optgroup>
<?php endforeach; ?>
</select>
