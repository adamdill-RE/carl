<?php
/**
 * The pest and disease reference (Phase 9).
 *
 * THE ORDER OF THE SECTIONS IN A CARD IS THE ARGUMENT. What it looks like,
 * what it costs, what it is confused with, when to look, how to stop it
 * happening, what to do without a spray, and only then what the spray is.
 * That is the order an IPM programme asks the questions in, and putting the
 * chemistry last is the point rather than a layout preference.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $pests
 * @var string $kind @var string $search @var string $category
 * @var array<string,string> $grown
 * @var bool $mineOnly @var int $ownCount
 */
$e = $view->e(...);
$pageTitle = 'Pests and diseases';

$kinds = ['pest' => 'Pests', 'disease' => 'Diseases', 'disorder' => 'Disorders'];
$severities = [
    'cosmetic'   => 'you will still eat the crop',
    'manageable' => 'a smaller crop if left alone',
    'serious'    => 'most of that crop in a bad year',
    'fatal'      => 'the plant does not recover',
];

/** A card section, drawn only when there is something in it. */
$section = static function (string $heading, mixed $body) use ($e): string {
    $text = \trim((string) $body);
    if ($text === '') {
        return '';
    }
    return '<dt>' . $e($heading) . '</dt><dd>' . $e($text) . '</dd>';
};
?>
<h1 class="page-title">Pests and diseases</h1>
<p class="page-sub">
  <?= $e(\count($pests)) ?> shown.
  Carl comes with this list so the dropdown on the log form is never empty and
  so two records of the same pest are the same record.
  <a href="<?= $e($app->url('lists/pest_disease')) ?>">Your own list</a>
<?php if ($ownCount > 0): ?>
  has <?= $e($ownCount) ?> entr<?= $ownCount === 1 ? 'y' : 'ies' ?> you added
<?php endif; ?>
  &mdash; add anything this does not cover.
</p>

<div class="notice notice-warn">
  <strong>Read the label before you spray anything.</strong>
  The chemicals named here are <em>active ingredients</em>, never products, and no
  amounts are given. Which products are legal on which crop differs by state, and the
  label on the bottle in your hand is the legal authority on the crop, the amount and
  how long before you can pick. Where a control is dangerous to bees it says so &mdash;
  and the answer there is almost always to spray at dusk, on nothing that is in flower.
</div>

<form method="get" action="<?= $e($app->url('pests')) ?>" class="card card-tight">
  <div class="filters">
    <div class="field">
      <label for="f-kind">Kind</label>
      <select id="f-kind" name="kind">
        <option value="">All</option>
<?php foreach ($kinds as $value => $label): ?>
        <option value="<?= $e($value) ?>" <?= $kind === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-category">Affecting</label>
      <select id="f-category" name="category">
        <option value="">Anything I grow</option>
<?php foreach ($grown as $key => $name): ?>
        <option value="<?= $e($name) ?>" <?= \strcasecmp($category, $name) === 0 ? 'selected' : '' ?>>
          <?= $e($name) ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row gap-sm">
    <input type="search" name="q" value="<?= $e($search) ?>" class="grow"
           placeholder="Name, latin name, or what you can see">
    <button type="submit" class="btn btn-small">Filter</button>
    <a class="btn btn-secondary btn-small" href="<?= $e($app->url('pests')) ?>">Clear</a>
  </div>
<?php if ($mineOnly): ?>
  <p class="help flush">
    Showing what can affect the plants you have grown, plus the ones that affect
    anything. <a href="<?= $e($app->url('pests', ['all' => '1'])) ?>">Show the whole
    catalogue</a> &mdash; useful before you plant something new.
  </p>
<?php else: ?>
  <p class="help flush">
    Showing the whole catalogue.
    <a href="<?= $e($app->url('pests')) ?>">Narrow it to the plants you grow</a>.
  </p>
<?php endif; ?>
</form>

<?php if ($pests === []): ?>
<div class="card">
  <p class="muted flush">Nothing matches.
    <a href="<?= $e($app->url('pests', ['all' => '1'])) ?>">Search the whole catalogue</a>,
    or <a href="<?= $e($app->url('lists/pest_disease')) ?>">add it to your own list</a>
    if Carl does not know about it.</p>
</div>
<?php endif; ?>

<?php foreach ($pests as $pest):
    $key = (string) $pest['pest_key'];
    $severity = (string) ($pest['severity'] ?? '');
    $affects = \array_filter(\array_map('trim', \explode(';', (string) ($pest['affects_categories'] ?? ''))));
?>
<article class="card pest" id="pest-<?= $e($key) ?>">
  <h2 class="pest-name">
    <?= $e($pest['name']) ?>
    <span class="badge badge-muted"><?= $e($pest['kind']) ?></span>
<?php if ($severity !== ''): ?>
    <span class="badge sev-<?= $e($severity) ?>"
          title="<?= $e($severities[$severity] ?? '') ?>"><?= $e($severity) ?></span>
<?php endif; ?>
<?php if ((int) $pest['pollinator_risk'] === 1): ?>
    <span class="badge sev-bees"
          title="A control for this one kills bees if it is applied wrongly">bee care</span>
<?php endif; ?>
  </h2>

<?php if (!empty($pest['also_called']) || !empty($pest['latin_name'])): ?>
  <p class="tiny muted flush">
<?php if (!empty($pest['latin_name'])): ?><em><?= $e($pest['latin_name']) ?></em><?php endif; ?>
<?php if (!empty($pest['also_called'])): ?>
    <?= empty($pest['latin_name']) ? '' : '&middot;' ?> also called <?= $e($pest['also_called']) ?>
<?php endif; ?>
  </p>
<?php endif; ?>

  <p class="small gap-sm"><?= $e($pest['description']) ?></p>

<?php if ($affects !== []): ?>
  <p class="tiny muted">
    Affects: <?= $e(\implode(', ', $affects)) ?>
  </p>
<?php else: ?>
  <p class="tiny muted">Affects anything.</p>
<?php endif; ?>

  <dl class="pest-facts">
    <?= $section('What you will see', $pest['signs']) ?>
    <?= $section('What it costs to ignore', $pest['consequence']) ?>
    <?= $section('Confused with', $pest['look_alikes']) ?>
    <?= $section('When to look', $pest['monitoring']) ?>
    <?= $section('Stopping it happening', $pest['prevention']) ?>
    <?= $section('Without a spray', $pest['organic_controls']) ?>
    <?= $section('If you do spray', $pest['chemical_controls']) ?>
    <?= $section('On your side', $pest['beneficials']) ?>
<?php if (empty($pest['organic_controls']) && !empty($pest['treatments'])): ?>
    <?php /* An entry that came from a county research import rather than the
           built-in catalogue has only the older single `treatments` cell.
           Show it rather than a blank card. */ ?>
    <?= $section('What to do', $pest['treatments']) ?>
<?php endif; ?>
  </dl>

  <p class="tiny muted flush">
    <a href="<?= $e($app->url('log')) ?>">Log this on a plant</a>
<?php if (!empty($pest['source'])): ?>
    &middot; <?= $e($pest['source']) ?>
<?php endif; ?>
  </p>
</article>
<?php endforeach; ?>

<?php if ($pests !== []): ?>
<p class="tiny muted">
  Summarised for a home garden. Your county extension office knows your area and this
  page does not; anything unusual, expensive or about to be sprayed is worth asking them.
</p>
<?php endif; ?>
