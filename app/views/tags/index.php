<?php
/**
 * The tag pool (docs/QR-TAGS-SPEC.md Section 5.4): printed, bound, free,
 * retired -- and the two things you come here to start.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array{total:int,bound:int,free:int,retired:int} $pool
 * @var list<array<string,mixed>> $batches @var int $untagged
 * @var list<array<string,mixed>> $inUse
 * @var array{sheet:list<array<string,mixed>>,loose:list<array<string,mixed>>} $free
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
    <li><span class="grow">On plants</span><strong><?= $e($pool['bound']) ?></strong></li>
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

<?php /* THE DIRECTORY. Which stakes are on which plant, and which codes are
       still in the box. Before this, "what is this tag on?" was answerable
       only by scanning it or by opening sheets one at a time, and "which do
       I pull in October?" was not answerable at all. Grouped by plant,
       because a tray carries a stake per cell (Section 14.7) and twenty-four
       rows for one tray is a list nobody reads. Each plant goes two ways:
       its page for the desk, and each code to the field screen for the
       garden -- and its stakes come off from here, because clearing a bed
       is pulling six stakes off a list. */ ?>
<?php
$byPlant = [];
foreach ($inUse as $row) {
    $byPlant[(int) $row['planting_id']][] = $row;
}
?>
<?php if ($byPlant !== []): ?>
<section class="card">
  <h2>Stakes on plants</h2>
  <p class="muted small">
    <?= $e(\count($inUse)) ?> stake<?= \count($inUse) === 1 ? '' : 's' ?> on
    <?= $e(\count($byPlant)) ?> plant<?= \count($byPlant) === 1 ? '' : 's' ?>, most recently tagged first.
    A code opens the one-tap logging screen; "Take off" frees the stakes and leaves the plant alone.
  </p>
  <ul class="list">
<?php foreach ($byPlant as $plantingId => $rows):
    $first = $rows[0];
    $name = \trim((string) $first['label']) !== ''
        ? (string) $first['label']
        : \trim((string) $first['category'] . ' ' . (string) $first['type']);
    $place = $first['row_name'] !== null
        ? \trim((string) $first['garden_name'] . ' - ' . (string) $first['row_name'])
        : (string) ($first['garden_name'] ?? $first['container_name'] ?? '');
    $ended = (string) $first['state'] === Carl\Domain\PlantingState::ENDED;
    $codes = \array_map(static fn (array $r): string => (string) $r['code'], $rows);
    \sort($codes);
?>
    <li>
      <div class="grow">
        <a href="<?= $e($app->url('plants/' . $plantingId)) ?>#tag" class="name"><?= $e($name) ?></a>
        <span class="hint">
<?php if ($ended): ?>
          <span class="badge badge-muted">ended</span>
<?php endif; ?>
          <?= $e(\count($rows)) ?> stake<?= \count($rows) === 1 ? '' : 's' ?>
<?php if ($place !== ''): ?> &middot; <?= $e($place) ?><?php endif; ?>
        </span>
        <span class="tag-codes">
<?php foreach ($codes as $code): ?>
          <a class="mono tag-ref" href="<?= $e($app->url('t/' . $code)) ?>"><?= $e($code) ?></a>
<?php endforeach; ?>
        </span>
      </div>
      <form method="post" action="<?= $e($app->url('plants/' . $plantingId . '/tag/release')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="return" value="tags">
        <button type="submit" class="btn btn-secondary btn-small">Take off</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php $freeCount = Carl\Repo\TagRepository::countFree($free); ?>
<?php if ($freeCount > 0): ?>
<details class="card card-tight">
  <summary>Free codes (<?= $e($freeCount) ?>)</summary>
  <p class="muted small">
    In code order. Open a code to put it on a plant; retire one whose label tore or whose
    stake snapped, so it stops counting as free.
  </p>
<?php if ($free['sheet'] !== []): ?>
  <h3 class="small">Still on a sheet <span class="muted">&middot; <?= $e(\count($free['sheet'])) ?></span></h3>
  <ul class="list small">
<?php foreach ($free['sheet'] as $tag): ?>
    <li>
      <a class="grow" href="<?= $e($app->url('t/' . $tag['code'])) ?>">
        <span class="mono tag-ref"><?= $e($tag['code']) ?></span>
        <span class="muted">&middot; sheet <?= $e($tag['batch_id']) ?>,
          <?= $e($LS::placeText((string) $tag['stock_sku'], (int) $tag['ordinal'])) ?></span>
      </a>
      <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/retire')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn-link small">Retire</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($free['loose'] !== []): ?>
  <h3 class="small">Loose stakes, used before <span class="muted">&middot; <?= $e(\count($free['loose'])) ?></span></h3>
  <ul class="list small">
<?php foreach ($free['loose'] as $tag): ?>
    <li>
      <a class="grow mono tag-ref" href="<?= $e($app->url('t/' . $tag['code'])) ?>"><?= $e($tag['code']) ?></a>
      <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/retire')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn-link small">Retire</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
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
