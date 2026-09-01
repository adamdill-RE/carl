<?php
/**
 * The tag pool (docs/QR-TAGS-SPEC.md Section 5.4): printed, bound, free,
 * retired -- and the two things you come here to start.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array{total:int,bound:int,free:int,retired:int} $pool
 * @var list<array<string,mixed>> $batches @var int $untagged
 * @var list<array<string,mixed>> $inUse
 * @var list<array{batch_id:int,stock_sku:string,sheet:int,tags:list<array{id:int,code:string,row:int,column:int}>}> $free
 * @var array{next:?array<string,mixed>,bound:list<array<string,mixed>>,remaining:int}|null $session
 * @var array{uppercase:bool,sample:string,mode:string,version:int,size:int,module_mm:float,headroom:int} $encoding
 * @var string $stock
 */
$e = $view->e(...);
$LS = Carl\Domain\LabelStock::class;
$pageTitle = 'Plant tags';
?>

<h1 class="page-title">Plant tags</h1>
<p class="page-sub">
  A stake in the soil with a code on it. Point a phone camera at one and you get that
  plant's logging screen: two taps to record a watering instead of six.
</p>

<section class="card">
  <ul class="list">
    <li><span class="grow">On a plant</span><strong><?= $e($pool['bound']) ?></strong></li>
    <li><span class="grow">Printed and free</span><strong><?= $e($pool['free']) ?></strong></li>
    <li><span class="grow">Living plants with no tag</span><strong><?= $e($untagged) ?></strong></li>
<?php if ($pool['retired'] > 0): ?>
    <li><span class="grow muted">Retired</span><span class="muted"><?= $e($pool['retired']) ?></span></li>
<?php endif; ?>
  </ul>
</section>

<?php if ($pool['free'] === 0 && $pool['total'] === 0): ?>
<div class="notice notice-info">
  No tags yet. Print a sheet in January at a desk, put the codes in a box, and take one
  out whenever a plant needs a stake &mdash; a tag does not have to know which plant it is
  for until you scan it.
</div>
<?php endif; ?>

<nav class="menu">
  <a href="<?= $e($app->url('tags/print')) ?>">Print a sheet of tags
    <span class="hint">Blank codes, whole sheets, <?= $e($LS::name($stock)) ?></span></a>
<?php if ($untagged > 0 && $pool['free'] > 0 && $session === null): ?>
  <?php /* Section 6.5: Start tagging is offered only when there is something
          to tag. With an empty untagged list the answer is "start a new plant
          with this tag", not a session with no cursor. */ ?>
  <form method="post" action="<?= $e($app->url('tags/session')) ?>" class="flush">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="start">
    <button type="submit" class="menu-action">Start tagging
      <span class="hint">Carl names the next plant; every scan binds and moves on.
        <?= $e($untagged) ?> to do</span></button>
  </form>
<?php endif; ?>
<?php if ($pool['bound'] > 0): ?>
  <a href="<?= $e($app->url('tags/labels.pdf')) ?>">Print named labels
    <span class="hint">The same codes with the plant's name on them, for a tag already in use</span></a>
<?php endif; ?>
</nav>

<section class="card">
  <h2>Find a tag by its code</h2>
  <p class="muted small">
    When the symbol is caked in soil &mdash; and one will be &mdash; read the six characters
    off the stake and type them here. Case, spaces and hyphens do not matter.
  </p>
  <form method="post" action="<?= $e($app->url('tags/find')) ?>" class="filters">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field grow">
      <span>Tag code</span>
      <input type="text" name="code" maxlength="12" autocapitalize="characters"
             autocomplete="off" spellcheck="false" placeholder="AB7K4M" class="mono">
    </label>
    <button type="submit" class="btn btn-secondary">Go</button>
  </form>
</section>

<?php /* THE DIRECTORY. Which stake is on which plant, and which codes are still
       in the box. Before this, "what is this tag on?" was answerable only by
       scanning it or by opening sheets one at a time, and "which do I pull
       in October?" was not answerable at all. Each row goes two ways: the
       plant's page for the desk, the field screen for the garden -- and a
       stake can come off from here, because pulling six stakes off a list is
       how a bed gets cleared. */ ?>
<?php if ($inUse !== []): ?>
<section class="card">
  <h2>Tags on plants</h2>
  <p class="muted small">
    Most recently attached first. "Take off" frees the stake and leaves the plant alone.
  </p>
  <ul class="list">
<?php foreach ($inUse as $row):
    $name = \trim((string) $row['label']) !== ''
        ? (string) $row['label']
        : \trim((string) $row['category'] . ' ' . (string) $row['type']);
    $place = $row['row_name'] !== null
        ? \trim((string) $row['garden_name'] . ' - ' . (string) $row['row_name'])
        : (string) ($row['garden_name'] ?? $row['container_name'] ?? '');
    $ended = (string) $row['state'] === Carl\Domain\PlantingState::ENDED;
?>
    <li>
      <a class="grow" href="<?= $e($app->url('plants/' . $row['planting_id'])) ?>#tag">
        <span class="name"><span class="mono tag-ref"><?= $e($row['code']) ?></span>
          &middot; <?= $e($name) ?></span>
        <span class="hint">
<?php if ($ended): ?>
          <span class="badge badge-muted">ended</span>
<?php endif; ?>
          since <?= $e(Carl\Support\Units::shortDate((string) $row['bound_at'])) ?>
<?php if ($place !== ''): ?> &middot; <?= $e($place) ?><?php endif; ?>
        </span>
      </a>
      <a class="btn btn-secondary btn-small" href="<?= $e($app->url('t/' . $row['code'])) ?>"
         title="The one-tap logging screen">Scan view</a>
      <form method="post" action="<?= $e($app->url('t/' . $row['code'] . '/release')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="return" value="tags">
        <button type="submit" class="btn btn-secondary btn-small">Take off</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($free !== []): ?>
<details class="card card-tight">
  <summary>Free codes, by sheet (<?= $e(Carl\Repo\TagRepository::countFree($free)) ?>)</summary>
  <p class="muted small">
    Still in the box. Open a code to put it on a plant, or retire one whose label tore or
    whose stake snapped, so it stops counting as free.
  </p>
<?php foreach ($free as $sheet): ?>
  <h3 class="small">Sheet <?= $e($sheet['batch_id']) ?><?= $sheet['sheet'] > 1 ? ', page ' . $e($sheet['sheet']) : '' ?>
    <span class="muted">&middot; <?= $e($LS::name($sheet['stock_sku'])) ?></span></h3>
  <ul class="list small">
<?php foreach ($sheet['tags'] as $tag): ?>
    <li>
      <a class="grow" href="<?= $e($app->url('t/' . $tag['code'])) ?>">
        <span class="mono tag-ref"><?= $e($tag['code']) ?></span>
        <span class="muted">&middot; row <?= $e($tag['row']) ?>, column <?= $e($tag['column']) ?></span>
      </a>
      <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/retire')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn-link small">Retire</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endforeach; ?>
</details>
<?php endif; ?>

<?php if ($batches !== []): ?>
<section class="card">
  <h2>Sheets you have printed</h2>
  <ul class="list">
<?php foreach ($batches as $batch): ?>
    <li><a class="grow" href="<?= $e($app->url('tags/batches/' . $batch['id'])) ?>">
      Sheet <?= $e($batch['id']) ?>
      <span class="hint">
        <?= $e($batch['tag_count']) ?> codes &middot; <?= $e($LS::name((string) $batch['stock_sku'])) ?>
        &middot; <?= $e($batch['bound_count']) ?> in use
<?php if ($batch['retired_at'] !== null): ?> &middot; retired<?php endif; ?>
      </span></a>
<?php if ($batch['retired_at'] !== null): ?>
      <span class="badge badge-muted">retired</span>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?= $view->partial('tags/encoding', ['encoding' => $encoding]) ?>
