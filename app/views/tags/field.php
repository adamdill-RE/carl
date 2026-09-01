<?php
/**
 * The field screen (docs/QR-TAGS-SPEC.md Section 7) -- what a scan lands on.
 *
 * Everything here assumes one hand, sunlight on the screen, mud, and no
 * patience. One row of large tap targets, one tap records one event dated
 * today, and it comes straight back here. No date picker. No dropdown.
 *
 * Deliberately NOT /plants/{id}: that is the report page with charts, which
 * is the right page at a desk and the wrong one in a garden. The link to it
 * is under the fold, where somebody who wants it will look.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array<string,mixed> $tag @var string $qr
 * @var list<string> $actions @var bool $ended
 * @var list<array<string,mixed>> $recent @var ?int $days
 * @var array{next:?array<string,mixed>,bound:list<array<string,mixed>>,remaining:int}|null $session
 * @var string $today @var int $justBound
 */
$e = $view->e(...);
$E = Carl\Domain\EventType::class;
$S = Carl\Domain\PlantingState::class;

$name = \trim((string) $tag['label']) !== ''
    ? (string) $tag['label']
    : \trim((string) $tag['category'] . ' ' . (string) $tag['type']);

$place = $tag['garden_name'] ?? $tag['container_name'];
if ($tag['row_name'] !== null) {
    $place = ($tag['garden_name'] ?? '') . ' - ' . $tag['row_name'];
}
?>

<?php if ($justBound > 0): ?>
<?php /* Optimistic bind with undo, not confirm-then-bind (Section 6.5). For a
        repetitive physical task, confirming every scan is the whole cost you
        were removing; undo is one tap and only wanted when something went
        wrong. It DELETES the binding rather than closing it, so an undone
        scan leaves no trace -- a closed binding would read forever after as
        "this tag was on that plant for four seconds", which is a lie about a
        physical object. */ ?>
<div class="notice notice-ok tagging-strip">
  <strong>Bound to <?= $e($name) ?>.</strong>
  <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/undo')) ?>" class="flush inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="binding_id" value="<?= $e($justBound) ?>">
    <button type="submit" class="btn-link">Undo</button>
  </form>
  &middot;
  <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/release')) ?>" class="flush inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn-link">Assign to a different plant</button>
  </form>
</div>
<?php endif; ?>

<h1 class="page-title"><?= $e($name) ?></h1>
<p class="page-sub">
  <?php if (\trim((string) $tag['label']) !== ''): ?>
    <?= $e(\trim((string) $tag['category'] . ' ' . (string) $tag['type'])) ?> &middot;
  <?php endif; ?>
  <?= $e($S::label((string) $tag['state'])) ?>
<?php if ($days !== null): ?>
  &middot; day <?= $e($days) ?>
<?php endif; ?>
<?php if ((int) $tag['quantity_live'] > 0): ?>
  &middot; <?= $e($tag['quantity_live']) ?> alive
<?php endif; ?>
<?php if ($place !== null && $place !== ''): ?>
  &middot; <?= $e($place) ?>
<?php endif; ?>
</p>

<?php if ($ended): ?>
<?php /* An ended planting gets a read-only summary and a way to free the tag,
        so the stake can go on something else next season (Section 6.2). */ ?>
<div class="notice notice-info">
  <strong><?= $e($S::endedLabel($tag['ended_reason'] === null ? null : (string) $tag['ended_reason'])) ?></strong>
<?php if ($tag['ended_at'] !== null): ?>
  on <?= $e(Carl\Support\Units::shortDate((string) $tag['ended_at'])) ?>.
<?php endif; ?>
  <?php $survival = $S::survivalPercent((int) $tag['quantity_initial'], (int) $tag['quantity_lost']); ?>
<?php if ($survival !== null): ?>
  Started <?= $e($tag['quantity_initial']) ?>, <?= $e($survival) ?>% survived.
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($actions !== []): ?>
<section class="card">
  <div class="row tag-actions">
<?php foreach ($actions as $action): ?>
    <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/log')) ?>" class="flush grow">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="event_type" value="<?= $e($action) ?>">
      <button type="submit" class="btn btn-block tag-action"><?= $e($E::label($action)) ?></button>
    </form>
<?php endforeach; ?>
  </div>
  <p class="help small">
    One tap records it against today, <?= $e(Carl\Support\Units::shortDate($today)) ?>, and comes
    back here. Anything needing a number, a place or a sentence -- how many died, where it
    went, how heavy the pick -- is on the full log form below.
  </p>
</section>
<?php endif; ?>

<?php if ($recent !== []): ?>
<section class="card card-tight">
  <h2 class="small">Lately</h2>
  <ul class="list small">
<?php foreach ($recent as $event): ?>
    <li><span class="grow"><?= $e($E::label((string) $event['event_type'])) ?></span>
      <span class="muted"><?= $e(Carl\Support\Units::shortDate((string) $event['event_date'])) ?></span></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php /* Secondary, below the fold (Section 7). */ ?>
<section class="card card-tight">
  <ul class="list">
    <li><a class="grow" href="<?= $e($app->url('log/' . $tag['planting_id'])) ?>">
      Full log form <span class="hint">Backdate, add a photo, write a sentence</span></a></li>
    <li><a class="grow" href="<?= $e($app->url('plants/' . $tag['planting_id'])) ?>">
      The plant's own page <span class="hint">Timeline, charts, photos, PDF</span></a></li>
  </ul>
</section>

<section class="card card-tight qr-panel">
  <div class="qr-holder"><?= $qr ?></div>
  <div>
    <p class="mono qr-code"><?= $e($tag['code']) ?></p>
    <p class="muted small">
      This tag. Bound <?= $e(Carl\Support\Units::shortDate((string) $tag['bound_at'])) ?>.
    </p>
    <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/release')) ?>" class="flush">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="btn btn-secondary btn-small">Take this tag off</button>
    </form>
    <p class="help small">
      Frees the stake for another plant. The plant keeps its whole history, and the tag
      remembers it was here.
    </p>
  </div>
</section>
