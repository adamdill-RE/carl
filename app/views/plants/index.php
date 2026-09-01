<?php
/**
 * The plant list, shared by View Plants and Log Plant Activity
 * (handoff Sections 4.4 and 4.5).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $plantings
 * @var array<int,int> $photoCounts
 * @var array<string,mixed> $filters
 * @var array{categories:list<string>,types:list<string>,states:list<string>} $options
 * @var list<array<string,mixed>> $gardens
 * @var string $title @var string $target
 */
$e = $view->e(...);
$pageTitle = $title;
$batch = $batch ?? false;
$S = Carl\Domain\PlantingState::class;
$U = Carl\Support\Units::class;
?>
<h1 class="page-title"><?= $e($title) ?></h1>
<p class="page-sub">
  <?= $e(\count($plantings)) ?> shown.
<?php if ($target === 'log'): ?>
  Tap one to log an action, or tick several and apply the same action to all of them.
<?php endif; ?>
</p>

<?= $view->partial('partials/filters', [
      'filters' => $filters, 'options' => $options, 'gardens' => $gardens, 'target' => $target]) ?>

<?php if ($plantings === []): ?>
  <div class="card">
    <p class="muted">Nothing matches. <a href="<?= $e($app->url('plants/new')) ?>">Start a new plant</a>
    <?php if ($filters['category'] !== '' || $filters['search'] !== ''): ?>
      or <a href="<?= $e($app->url($target)) ?>">clear the filters</a>
    <?php endif; ?>.</p>
<?php
    /* A well-formed code that matched nothing falls through to here rather
       than to a message, because a code that is not yours and a code that
       does not exist must look the same (QR-TAGS-SPEC Section 6.2) -- and
       because real words collide with the alphabet. The line is only offered
       when the query could BE a code, so an ordinary failed search is not
       told about a feature it was not using. */
    $looksLikeCode = Carl\Repo\TagRepository::isWellFormed(
        Carl\Repo\TagRepository::normalise((string) $filters['search'])
    );
?>
<?php if ($looksLikeCode): ?>
    <p class="tiny muted flush">If that was a tag code, check it on the
      <a href="<?= $e($app->url('tags')) ?>">plant tags</a> screen.</p>
<?php endif; ?>
  </div>
<?php else: ?>

<form method="post" action="<?= $e($app->url('log/batch')) ?>" class="card">
<?php if ($batch): ?>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php endif; ?>

  <ul class="list">
<?php foreach ($plantings as $planting):
    $id = (int) $planting['id'];
    $days = Carl\Support\Clock::daysBetween((string) $planting['start_date'], $today ?? (string) $planting['start_date']);
    $where = $planting['container_name']
        ?? \trim(((string) ($planting['garden_name'] ?? '')) . ' ' . ((string) ($planting['row_name'] ?? '')));
?>
    <li>
<?php if ($batch): ?>
      <input type="checkbox" name="planting_ids[]" value="<?= $e($id) ?>"
             class="tickbox" aria-label="Select this plant">
<?php endif; ?>
      <a class="grow" href="<?= $e($app->url(($target === 'log' ? 'log/' : 'plants/') . $id)) ?>">
        <span class="name">
          <?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?>
<?php if (!empty($planting['label'])): ?>
          <span class="muted">(<?= $e($planting['label']) ?>)</span>
<?php endif; ?>
        </span><br>
        <span class="meta">
          <span class="badge<?= (string) $planting['state'] === $S::ENDED ? ' badge-muted' : '' ?>">
            <?= $e($S::label((string) $planting['state'])) ?></span>
<?php if ($planting['split_from_id'] !== null): ?>
          <?php /* A split child looks exactly like a sowing of six in this
                 list, which is how somebody comes to wonder why they have two
                 tomato plantings started the same day. */ ?>
          <span class="badge badge-muted">moved out of another</span>
<?php endif; ?>
          <?= $e((int) $planting['quantity_live']) ?> of <?= $e((int) $planting['quantity_initial']) ?> living
<?php if ($where !== ''): ?>
          &middot; <?= $e($where) ?>
<?php endif; ?>
<?php if ($days !== null): ?>
          &middot; day <?= $e(\abs($days)) ?>
<?php endif; ?>
<?php if (($photoCounts[$id] ?? 0) > 0): ?>
          &middot; <?= $e($photoCounts[$id]) ?> photo<?= $photoCounts[$id] === 1 ? '' : 's' ?>
<?php endif; ?>
<?php if (!empty($planting['tag_code'])): ?>
          <?php /* The code on the stake, so the list and the thing in your
                 hand carry the same identifier -- and so that typing it into
                 the box above is a discoverable act rather than a secret. */ ?>
          &middot; <span class="mono tag-ref"><?= $e($planting['tag_code']) ?></span>
<?php endif; ?>
        </span>
      </a>
    </li>
<?php endforeach; ?>
  </ul>

<?php if ($batch): ?>
  <button type="submit" class="btn btn-secondary btn-block">Apply one action to the ticked plants</button>
  <p class="help">Only actions every ticked plant can take are offered.</p>
<?php endif; ?>
</form>
<?php endif; ?>
