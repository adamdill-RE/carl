<?php
/**
 * The bind screen: "Tag AB7K4M isn't assigned yet."
 *
 * THE LIST IS EVERY LIVING PLANT THAT STILL WANTS A STAKE, MOST RECENT FIRST
 * -- not recent plants (docs/QR-TAGS-SPEC.md Section 6.4). A tomato that went
 * in the ground in May has no tag and is not recent, so a recency filter
 * would hide the plant you are standing in front of. Recency is the sort;
 * nothing is filtered out; the search box finds the May tomato by name. And
 * since a tray carries a stake per cell (Section 14.7), a plant part-way
 * through its stakes is on the list too, after the ones with none.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array<string,mixed> $tag @var string $qr
 * @var array{wants:list<array<string,mixed>>,full:list<array<string,mixed>>} $candidates @var string $search
 * @var array{next:?array<string,mixed>,bound:list<array<string,mixed>>,remaining:int}|null $session
 */
$e = $view->e(...);
$nameOf = static fn (array $p): string => \trim((string) $p['label']) !== ''
    ? (string) $p['label']
    : \trim((string) $p['category'] . ' ' . (string) $p['type']);
$placeOf = static function (array $p): string {
    if ($p['row_name'] !== null) {
        return \trim((string) $p['garden_name'] . ' - ' . (string) $p['row_name']);
    }
    return (string) ($p['garden_name'] ?? $p['container_name'] ?? '');
};
?>

<h1 class="page-title">Tag <span class="mono"><?= $e($tag['code']) ?></span> isn't assigned yet</h1>
<p class="page-sub">Pick the plant this stake is going into, or start a new one with it.</p>

<?php if ($tag['tag_retired_at'] !== null): ?>
<div class="notice notice-warn">
  This tag is on a sheet you retired. Un-retire the sheet from
  <a href="<?= $e($app->url('tags')) ?>">Plant tags</a> before using it.
</div>
<?php endif; ?>

<section class="card card-tight qr-panel">
  <div class="qr-holder"><?= $qr ?></div>
  <p class="muted small">
    A tag is a reusable stake, not a plant. Bind it now, release it at the end of the
    season, and use the same stake next year for something else.
  </p>
</section>

<?php
$wants = $candidates['wants'];
$full = $candidates['full'];
$rowFor = static function (array $planting) use ($e, $app, $csrf, $tag, $nameOf, $placeOf): string {
    $place = $placeOf($planting);
    $count = (int) $planting['tag_count'];
    $live = (int) $planting['quantity_live'];
    $stakes = $count === 0
        ? 'no stake yet'
        : $count . ' of ' . $live . ' stake' . ($live === 1 ? '' : 's');
    return '<li><form method="post" action="' . $e($app->url('t/' . $tag['code'] . '/bind')) . '"'
        . ' class="flush grow row-tight">'
        . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
        . '<input type="hidden" name="planting_id" value="' . $e($planting['id']) . '">'
        . '<button type="submit" class="btn-link grow">'
        . '<span class="name">' . $e($nameOf($planting)) . '</span>'
        . '<span class="hint">' . $e($stakes)
        . ' &middot; started ' . $e(Carl\Support\Units::shortDate((string) $planting['start_date']))
        . ($place !== '' ? ' &middot; ' . $e($place) : '')
        . '</span></button></form></li>';
};
?>
<?php if ($wants === [] && $full === [] && $search === ''): ?>
<div class="notice notice-info">
  Nothing living to put it on. Start a new plant and this tag goes on it.
</div>
<?php else: ?>
<section class="card">
  <form method="get" action="<?= $e($app->url('t/' . $tag['code'])) ?>" class="filters">
    <label class="field grow">
      <span>Find a plant</span>
      <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Cherokee Purple">
    </label>
    <button type="submit" class="btn btn-secondary">Search</button>
  </form>

<?php if ($wants === [] && $full === []): ?>
  <p class="muted">Nothing matches "<?= $e($search) ?>".</p>
<?php elseif ($wants === []): ?>
  <p class="muted small">Every plant has a stake for each of its plants. Any of them can still take another.</p>
<?php else: ?>
  <?php /* Plants with no stake first, then the ones part-way through a tray,
         most recently started first within each (Section 14.7): the tray
         you are working along with the fourth stake in your hand is on this
         list, and so is the May tomato with none, four screens down. */ ?>
  <ul class="list">
<?php foreach ($wants as $planting): ?>
    <?= $rowFor($planting) ?>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($full !== []): ?>
  <details class="gap-sm">
    <summary class="small">Plants with a stake for every plant (<?= $e(\count($full)) ?>)</summary>
    <p class="help small">
      The count is a guide, not a rule: a plant whose stake broke, or one you want a second
      stake on, is here.
    </p>
    <ul class="list">
<?php foreach ($full as $planting): ?>
      <?= $rowFor($planting) ?>
<?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="card card-tight">
  <ul class="list">
    <li><a class="grow" href="<?= $e($app->url('plants/new', ['tag' => $tag['code']])) ?>">
      Start a new plant with this tag
      <span class="hint">The sow-as-you-go case: the tag binds when you save</span></a></li>
  </ul>
</section>

