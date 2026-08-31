<?php
/**
 * One printed sheet (docs/QR-TAGS-SPEC.md Section 5.4).
 *
 * The render is a GET and re-printable forever, because `stock_sku` is on the
 * batch row: the sheet is a pure function of it, and comes back identical even
 * after the user has changed their stock preference -- which is exactly when a
 * reprint would otherwise be subtly wrong against the half-used sheet in their
 * hand.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array<string,mixed> $batch @var list<array<string,mixed>> $tags
 * @var array{uppercase:bool,sample:string,mode:string,version:int,size:int,module_mm:float,headroom:int} $encoding
 */
$e = $view->e(...);
$LS = Carl\Domain\LabelStock::class;
$retired = $batch['retired_at'] !== null;
$inUse = \count(\array_filter($tags, static fn (array $t): bool => $t['category'] !== null));
?>
<h1 class="page-title">Tag sheet <?= $e($batch['id']) ?></h1>
<p class="page-sub">
  <?= $e($batch['tag_count']) ?> codes on <?= $e($LS::name((string) $batch['stock_sku'])) ?>
  &middot; <?= $e($inUse) ?> on a plant
  &middot; printed <?= $e(Carl\Support\Units::shortDate((string) $batch['created_at'])) ?>
</p>

<?php if ($retired): ?>
<div class="notice notice-warn">
  This sheet is retired, so its codes are out of the pool count. Nothing was deleted:
  if it turns up in a drawer next spring, un-retire it and every code still works.
</div>
<?php endif; ?>

<nav class="menu">
  <a href="<?= $e($app->url('tags/batches/' . $batch['id'] . '.pdf')) ?>">Download the sheet
    <span class="hint">Print at 100% scale &mdash; check the 100 mm rule at the foot</span></a>
  <a href="<?= $e($app->url('tags/batches/' . $batch['id'] . '/registration.pdf')) ?>">Registration test
    <span class="hint">Outlines on plain paper, to hold against a real label sheet</span></a>
</nav>

<section class="card">
  <h2>The codes</h2>
  <ul class="list small">
<?php foreach ($tags as $tag): ?>
    <li>
      <a class="grow mono" href="<?= $e($app->url('t/' . $tag['code'])) ?>"><?= $e($tag['code']) ?></a>
<?php if ($tag['category'] !== null): ?>
      <span class="muted"><?= $e(\trim((string) $tag['label']) !== ''
          ? (string) $tag['label']
          : \trim((string) $tag['category'] . ' ' . (string) $tag['type'])) ?></span>
<?php else: ?>
      <span class="muted">free</span>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>

<section class="card card-tight">
  <form method="post" action="<?= $e($app->url('tags/batches/' . $batch['id'] . '/retire')) ?>"
        class="flush">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn btn-secondary btn-small">
      <?= $retired ? 'Put this sheet back in the pool' : 'Retire this sheet' ?>
    </button>
  </form>
  <p class="help small">
    Retire the sheet you lost or ruined, so its codes stop counting as free. It is not a
    delete &mdash; the codes still resolve, and this button puts them back.
  </p>
</section>

<?= $view->partial('tags/encoding', ['encoding' => $encoding]) ?>
