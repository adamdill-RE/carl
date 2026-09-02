<?php
/**
 * The free-code grid: "here is a plant, which tags?" (docs/QR-TAGS-SPEC.md
 * Section 5.2, the desk direction; Section 14.7, as many as the tray has
 * cells). On the plant page and at the foot of Start a New Plant.
 *
 * CHECKBOXES, BY CODE, IN TWO GROUPS -- not a dropdown. A planting can carry
 * a stake per cell, so a form has to take twenty-four at once, and the two
 * groups are the two things a gardener has in front of them over a season:
 *
 *   Still on a sheet   never been on a plant; listed by code, with the
 *                      sheet and the row and column beside it, so the one
 *                      you tick is the one you peel. With script, "tick the
 *                      next N on this sheet" takes them in peeling order.
 *   Loose stakes       been on a plant before; pulled at the end of a
 *                      season and in a box. Only the code identifies one
 *                      now, so it is listed by code and nothing else.
 *
 * Both ascend by code. A code carried in from a scan is pre-ticked, and is
 * added if the list has not got it -- the POST checks it again, so a stale
 * one is refused with a reason rather than quietly dropped.
 *
 * @var Carl\Core\View $view
 * @var array{sheet:list<array<string,mixed>>,loose:list<array<string,mixed>>} $free
 * @var string $name        the field name, e.g. tags
 * @var list<string> $checked  codes to pre-tick
 * @var int|null $wanted    how many the form is likely to want, for the counter
 */
$e = $view->e(...);
$LS = Carl\Domain\LabelStock::class;
$checked = $checked ?? [];
$wanted = $wanted ?? null;
$id = \preg_replace('/[^a-z0-9]+/i', '-', $name);

$present = [];
foreach (\array_merge($free['sheet'], $free['loose']) as $tag) {
    $present[(string) $tag['code']] = true;
}

$bySheet = [];
foreach ($free['sheet'] as $tag) {
    $bySheet[(int) $tag['batch_id'] . ':' . (int) $tag['sheet']][] = $tag;
}
?>
<div class="tag-picker" data-tag-picker data-wanted="<?= $e($wanted ?? '') ?>">
<?php foreach ($checked as $code): if (!isset($present[$code])): ?>
  <label class="tag-cell">
    <input type="checkbox" name="<?= $e($name) ?>[]" value="<?= $e($code) ?>" checked>
    <span class="mono"><?= $e($code) ?></span> <span class="muted small">scanned</span>
  </label>
<?php endif; endforeach; ?>

<?php if ($free['sheet'] !== []): ?>
  <p class="small flush"><strong>Still on a sheet</strong>
    <span class="muted">&mdash; tick, then peel that label</span></p>
<?php foreach ($bySheet as $key => $tags): $first = $tags[0]; ?>
  <div class="tag-sheet" data-sheet="<?= $e($key) ?>">
    <p class="tiny muted flush">
      Sheet <?= $e($first['batch_id']) ?><?= (int) $first['sheet'] > 1 ? ', page ' . $e($first['sheet']) : '' ?>
      &middot; <?= $e($LS::name((string) $first['stock_sku'])) ?> &middot; <?= $e(\count($tags)) ?> free
      <span class="tag-sheet-tools" hidden>
        &middot; tick the next
        <input type="number" min="1" max="<?= $e(\count($tags)) ?>" value="<?= $e(\min(\count($tags), \max(1, (int) ($wanted ?? 1)))) ?>"
               class="tag-next-count" inputmode="numeric" aria-label="How many to tick on this sheet">
        <button type="button" class="btn-link tag-next">in peeling order</button>
      </span>
    </p>
    <div class="tag-grid">
<?php foreach ($tags as $tag): ?>
      <label class="tag-cell" data-ordinal="<?= $e($tag['ordinal']) ?>">
        <input type="checkbox" name="<?= $e($name) ?>[]" value="<?= $e($tag['code']) ?>"
               <?= \in_array((string) $tag['code'], $checked, true) ? 'checked' : '' ?>>
        <span class="mono"><?= $e($tag['code']) ?></span>
        <span class="muted tiny">r<?= $e($tag['row']) ?><?= $LS::columns((string) $tag['stock_sku']) > 1 ? ' c' . $e($tag['column']) : '' ?></span>
      </label>
<?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($free['loose'] !== []): ?>
  <p class="small flush"><strong>Loose stakes</strong>
    <span class="muted">&mdash; used before; read the code off the stake</span></p>
  <div class="tag-grid">
<?php foreach ($free['loose'] as $tag): ?>
    <label class="tag-cell">
      <input type="checkbox" name="<?= $e($name) ?>[]" value="<?= $e($tag['code']) ?>"
             <?= \in_array((string) $tag['code'], $checked, true) ? 'checked' : '' ?>>
      <span class="mono"><?= $e($tag['code']) ?></span>
    </label>
<?php endforeach; ?>
  </div>
<?php endif; ?>

  <p class="tiny muted tag-picker-count" hidden></p>
</div>
