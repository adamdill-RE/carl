<?php
/**
 * The bind screen: "Tag AB7K4M isn't assigned yet."
 *
 * THE LIST IS EVERY UNTAGGED LIVING PLANT, MOST RECENT FIRST -- not recent
 * plants (docs/QR-TAGS-SPEC.md Section 6.4). A tomato that went in the ground
 * in May has no tag and is not recent, so a recency filter would hide the
 * plant you are standing in front of. Recency is the sort; nothing is
 * filtered out; the search box finds the May tomato by name.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var array<string,mixed> $tag @var string $qr
 * @var list<array<string,mixed>> $untagged @var string $search
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

<?php if ($untagged === [] && $search === ''): ?>
<div class="notice notice-info">
  Every living plant you have already has a tag. Start a new plant and this tag goes
  on it.
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

<?php if ($untagged === []): ?>
  <p class="muted">Nothing untagged matches "<?= $e($search) ?>".</p>
<?php else: ?>
  <ul class="list">
<?php foreach ($untagged as $planting): $place = $placeOf($planting); ?>
    <li>
      <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/bind')) ?>"
            class="flush grow row-tight">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="planting_id" value="<?= $e($planting['id']) ?>">
        <button type="submit" class="btn-link grow">
          <span class="name"><?= $e($nameOf($planting)) ?></span>
          <span class="hint">
            started <?= $e(Carl\Support\Units::shortDate((string) $planting['start_date'])) ?>
<?php if ($place !== ''): ?> &middot; <?= $e($place) ?><?php endif; ?>
          </span>
        </button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
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

<?php /* Section 6.4 item 3: reassigning a tag to a plant that already has one
        silently unbinds the old one, so it is behind a tick rather than in
        the list above. This is the replacement-for-a-destroyed-tag path. */ ?>
<details class="card card-tight">
  <summary>Replace a tag that was lost or ruined</summary>
  <form method="post" action="<?= $e($app->url('t/' . $tag['code'] . '/bind')) ?>" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field">
      <span>Plant id</span>
      <input type="number" name="planting_id" min="1" required inputmode="numeric">
      <span class="help small">From the plant's own page address, or its list entry.</span>
    </label>
    <label class="check">
      <input type="checkbox" name="replace" value="1">
      <span>Replace the existing tag &mdash; the old one comes off and goes back in the pool</span>
    </label>
    <button type="submit" class="btn btn-secondary">Move this tag onto that plant</button>
  </form>
</details>
