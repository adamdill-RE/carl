<?php
/**
 * Recommendations (handoff Section 14 v2; Phase 5 handoff Section 3.1).
 *
 * The answer is rendered from typed blocks, not from HTML: `Prose::blocks()`
 * returns headings, paragraphs and lists, and every string in them goes
 * through $e() like every other value on every other page. Nothing the API
 * returns can become markup (hosting Section 8.5).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed>|null $latest
 * @var list<array{type:string,text?:string,items?:list<string>}> $blocks
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $pending
 * @var bool $configured @var string $describe
 * @var int $askedToday @var int $perDay @var bool $canAsk @var int $days
 * @var array<string,mixed>|null $lastRun
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$P = Carl\Analysis\Prose::class;
$pageTitle = 'Recommendations';
?>
<h1 class="page-title">Recommendations</h1>
<p class="page-sub">
  What your own records say about your season, read against the weather that
  actually happened.
</p>

<?php foreach ($pending as $row): ?>
<div class="notice notice-info">
  Asked <?= $e($U::shortDate((string) $row['requested_on'])) ?><?php
    if (!empty($row['question'])): ?>: &ldquo;<?= $e($row['question']) ?>&rdquo;<?php endif; ?>.
  Carl works this out on its next run, not while you wait &mdash; nothing here calls
  an API while a page is loading. Come back in an hour or so.
</div>
<?php endforeach; ?>

<?php if (!$configured): ?>
<div class="notice notice-warn">
  No analysis key is configured yet, so anything you ask for waits in the queue
  rather than being answered. Nothing is lost: the day a key is added, the
  backlog goes out.
</div>
<?php endif; ?>

<?php if ($latest !== null): ?>
<section class="card advice">
  <h2>
<?php if (!empty($latest['question'])): ?>
    <?= $e($latest['question']) ?>
<?php else: ?>
    Your season so far
<?php endif; ?>
  </h2>
  <p class="tiny muted flush">
    <?= $e($U::shortDate((string) $latest['requested_on'])) ?>
    &middot; the <?= $e($days) ?> days up to then
<?php if (!empty($latest['model'])): ?>
    &middot; <?= $e($latest['model']) ?>
<?php endif; ?>
  </p>

<?php if (!empty($latest['last_error'])): ?>
  <p class="notice notice-warn small"><?= $e($latest['last_error']) ?></p>
<?php endif; ?>

<?php foreach ($blocks as $block): ?>
<?php if ($block['type'] === 'heading'): ?>
  <h3><?= $e($block['text'] ?? '') ?></h3>
<?php elseif ($block['type'] === 'list'): ?>
  <ul class="advice-list">
<?php foreach ($block['items'] ?? [] as $item): ?>
    <li><?= $e($item) ?></li>
<?php endforeach; ?>
  </ul>
<?php else: ?>
  <p><?= $e($block['text'] ?? '') ?></p>
<?php endif; ?>
<?php endforeach; ?>

  <p class="tiny muted">
    Written by a language model from your records. It is a reading of what you
    logged, not a measurement &mdash; check anything it says that would cost you a
    crop to get wrong.
  </p>
</section>
<?php endif; ?>

<section class="card">
  <h2><?= $latest === null ? 'Ask for one' : 'Ask for another' ?></h2>
<?php if ($latest === null && $pending === []): ?>
  <p class="small muted">
    Carl reads the last <?= $e($days) ?> days of your log &mdash; what you planted, what
    you did to it, and the weather over those dates &mdash; and writes back what it
    makes of them. Leave the box empty for a review of the season, or ask
    something specific.
  </p>
<?php endif; ?>

<?php if ($canAsk): ?>
  <form method="post" action="<?= $e($app->url('advice')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="field">
      <label for="question">Your question (optional)</label>
      <textarea id="question" name="question" maxlength="500"
                placeholder="Why did the second sowing of beans do so much worse than the first?"></textarea>
      <p class="help">
        Up to 500 characters. Leave it blank and Carl reviews the season instead.
      </p>
    </div>
    <button type="submit" class="btn btn-block">Ask Carl</button>
  </form>
  <p class="tiny muted gap-sm">
    <?= $e($askedToday) ?> of <?= $e($perDay) ?> today. The answer arrives on the next
    run, not on this page load.
  </p>
<?php else: ?>
  <p class="notice notice-info small">
    That is <?= $e($perDay) ?> analyses today, which is the daily limit. It resets
    tomorrow, on your own calendar day.
  </p>
<?php endif; ?>
</section>

<?php if (\count($history) > 1 || ($history !== [] && $latest === null)): ?>
<section class="card">
  <h2>Earlier</h2>
  <ul class="list">
<?php foreach ($history as $row): ?>
<?php if ($latest !== null && (int) $row['id'] === (int) $latest['id']) { continue; } ?>
    <li>
      <div class="grow">
<?php if ((string) $row['status'] === 'done'): ?>
        <a href="<?= $e($app->url('advice', ['id' => (int) $row['id']])) ?>">
          <?= $e($U::shortDate((string) $row['requested_on'])) ?>
          &mdash; <?= $e($P::excerpt((string) $row['answer'], 90)) ?>
        </a>
<?php else: ?>
        <span class="badge badge-muted"><?= $e($row['status']) ?></span>
        <?= $e($U::shortDate((string) $row['requested_on'])) ?>
<?php if (!empty($row['question'])): ?>
        &mdash; <?= $e($row['question']) ?>
<?php endif; ?>
<?php if (!empty($row['last_error'])): ?>
        <div class="tiny muted"><?= $e($row['last_error']) ?></div>
<?php endif; ?>
<?php endif; ?>
      </div>
<?php if ((string) $row['status'] === 'failed'): ?>
      <form method="post" action="<?= $e($app->url('advice/' . (int) $row['id'] . '/retry')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-secondary btn-small">Ask again</button>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<p><a class="btn btn-secondary" href="<?= $e($app->url('reports')) ?>">Back to reports</a></p>
