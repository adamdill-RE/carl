<?php
/**
 * End Growing Season (Phase 5 handoff Section 3.3).
 *
 * The one destructive action in Carl, and the only screen in it that asks
 * someone to type something to go ahead. Everything on this page exists to
 * make sure the person pressing the button knows exactly what they are
 * ending: every planting is named, with what is living in it and where it is,
 * because a count alone reads the same whether or not the fourteenth row is
 * the one that matters to them.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $garden
 * @var list<array<string,mixed>> $plantings @var int $tagged
 * @var list<string> $errors
 */
$e = $view->e(...);
$S = Carl\Domain\PlantingState::class;
$U = Carl\Support\Units::class;
$pageTitle = 'End growing season';

$livingTotal = 0;
foreach ($plantings as $planting) {
    $livingTotal += (int) $planting['quantity_live'];
}
?>
<h1 class="page-title">End the growing season</h1>
<p class="page-sub"><?= $e($garden['name']) ?>
  &middot; <a href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">back to the garden</a></p>

<?php if ($errors !== []): ?>
<div class="notice notice-error">
  <ul class="errors">
<?php foreach ($errors as $error): ?>
    <li><?= $e($error) ?></li>
<?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($plantings === []): ?>
<section class="card">
  <p>Nothing is living in <?= $e($garden['name']) ?>, so there is no season to end.</p>
  <p><a class="btn btn-secondary" href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">Back to the garden</a></p>
</section>
<?php else: ?>

<div class="notice notice-warn">
  This ends <strong><?= $e(\count($plantings)) ?> planting<?= \count($plantings) === 1 ? '' : 's' ?></strong>
  &mdash; <strong><?= $e($livingTotal) ?> living plant<?= $livingTotal === 1 ? '' : 's' ?></strong> &mdash;
  in one go. Each one gets a &ldquo;culled&rdquo; entry on its timeline for the date below,
  and each moves to Ended.
  <br><br>
  Carl's log is append-only, so nothing is deleted and every timeline stays
  readable. But there is no single undo: putting one back means logging a new
  event against it.
</div>

<form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/end-season')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <h2>What will be ended</h2>
  <ul class="list">
<?php foreach ($plantings as $planting): ?>
    <li>
      <div class="grow">
        <strong><?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?></strong>
<?php if (!empty($planting['label'])): ?>
        &mdash; <?= $e($planting['label']) ?>
<?php endif; ?>
        <div class="small muted">
          <?= $e($planting['quantity_live']) ?> living
          &middot; <?= $e($S::label((string) $planting['state'])) ?>
<?php if (!empty($planting['row_name'])): ?>
          &middot; <?= $e($planting['row_name']) ?>
<?php elseif (!empty($planting['container_name'])): ?>
          &middot; <?= $e($planting['container_name']) ?>
<?php endif; ?>
          &middot; started <?= $e($U::shortDate((string) $planting['start_date'])) ?>
        </div>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="tiny muted">
    This list is re-read when you submit, so anything you end or start in
    another tab in the meantime is counted correctly.
  </p>

  <h2>When</h2>
  <div class="field">
    <label for="event_date">Date the season ended</label>
    <input type="date" id="event_date" name="event_date"
           value="<?= $e($today) ?>" max="<?= $e($today) ?>" required>
    <p class="help">
      Backdate it to the frost, or to the day you cleared the bed. It goes on
      every timeline as that date.
    </p>
  </div>

  <div class="field">
    <label for="narrative">Note (optional)</label>
    <textarea id="narrative" name="narrative"
              placeholder="First hard freeze; cleared everything but the garlic."></textarea>
    <p class="help">Written onto every one of these entries.</p>
  </div>

<?php if (($tagged ?? 0) > 0): ?>
  <h2>Tags</h2>
  <div class="field">
    <label class="check">
      <input type="checkbox" name="release_tags" value="1">
      <span>Put the <?= $e($tagged) ?> tag<?= $tagged === 1 ? '' : 's' ?> on these plants back
        in the pool</span>
    </label>
    <p class="help">
      Tick this once you have actually pulled the stakes. Each tag still remembers what it
      was on, so an old photo of a stake does not lie about it; the code is simply free to
      go on something else next season.
    </p>
  </div>
<?php endif; ?>

  <h2>Confirm</h2>
  <div class="field">
    <label for="confirm">Type <strong>end season</strong> to go ahead</label>
    <input type="text" id="confirm" name="confirm" autocomplete="off"
           autocapitalize="none" spellcheck="false" required>
    <p class="help">
      A box to tick is one mis-tap on a phone. This is the action with no undo,
      so it asks for the words.
    </p>
  </div>

  <button type="submit" class="btn btn-danger btn-block">
    End the season for <?= $e(\count($plantings)) ?> planting<?= \count($plantings) === 1 ? '' : 's' ?>
  </button>
</form>

<p><a class="btn btn-secondary" href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">No, take me back</a></p>
<?php endif; ?>
