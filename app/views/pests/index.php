<?php
/**
 * The pest and disease reference (Phase 9).
 *
 * A LIST THAT EXPANDS ONE ENTRY. Drawn as seventy-six full cards this page is
 * 202 KB of HTML -- ten times the whole client shell, on the connection
 * somebody standing in a garden actually has. So the list carries the name,
 * what it attacks and the line that says what you would see, which is what a
 * browser search needs, and `?key=` opens the eight paragraphs behind one of
 * them.
 *
 * THE ORDER OF THE SECTIONS IN A CARD IS THE ARGUMENT. What it looks like,
 * what it costs, what it is confused with, when to look, how to stop it
 * happening, what to do without a spray, and only then what the spray is.
 * That is the order an IPM programme asks the questions in, and putting the
 * chemistry last is the point rather than a layout preference.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $pests
 * @var array<string,mixed>|null $selected
 * @var string $kind @var string $search @var string $category
 * @var array<string,string> $grown
 * @var bool $mineOnly @var int $ownCount
 */
$e = $view->e(...);
$pageTitle = $selected === null ? 'Pests and diseases' : (string) $selected['name'];

$kinds = ['pest' => 'Pests', 'disease' => 'Diseases', 'disorder' => 'Disorders'];
$severities = [
    'cosmetic'   => 'you will still eat the crop',
    'manageable' => 'a smaller crop if left alone',
    'serious'    => 'most of that crop in a bad year',
    'fatal'      => 'the plant does not recover',
];

/** The filters as they stand, so a card can link back to the list you were on. */
$listQuery = \array_filter([
    'kind' => $kind, 'q' => $search, 'category' => $category,
    'all' => $mineOnly ? '' : '1',
], static fn (string $v): bool => $v !== '');

/** A card section, drawn only when there is something in it. */
$section = static function (string $heading, mixed $body) use ($e): string {
    $text = \trim((string) $body);
    if ($text === '') {
        return '';
    }
    return '<dt>' . $e($heading) . '</dt><dd>' . $e($text) . '</dd>';
};

/** The badges that go beside a name, in both the list and the card. */
$badges = static function (array $pest) use ($e, $severities): string {
    $out = '<span class="badge badge-muted">' . $e($pest['kind']) . '</span>';
    $severity = (string) ($pest['severity'] ?? '');
    if ($severity !== '') {
        $out .= ' <span class="badge sev-' . $e($severity) . '" title="'
            . $e($severities[$severity] ?? '') . '">' . $e($severity) . '</span>';
    }
    if ((int) ($pest['pollinator_risk'] ?? 0) === 1) {
        $out .= ' <span class="badge sev-bees"'
            . ' title="A control for this one kills bees if it is applied wrongly">bee care</span>';
    }
    return $out;
};
?>
<h1 class="page-title">Pests and diseases</h1>
<p class="page-sub">
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

<?php if ($selected !== null):
    $key = (string) $selected['pest_key'];
    $affects = \array_filter(\array_map('trim',
        \explode(';', (string) ($selected['affects_categories'] ?? ''))));
?>
<article class="card pest" id="pest-<?= $e($key) ?>">
  <h2 class="pest-name"><?= $e($selected['name']) ?> <?= $badges($selected) ?></h2>

<?php if (!empty($selected['also_called']) || !empty($selected['latin_name'])): ?>
  <p class="tiny muted flush">
<?php if (!empty($selected['latin_name'])): ?><em><?= $e($selected['latin_name']) ?></em><?php endif; ?>
<?php if (!empty($selected['also_called'])): ?>
    <?= empty($selected['latin_name']) ? '' : '&middot;' ?> also called <?= $e($selected['also_called']) ?>
<?php endif; ?>
  </p>
<?php endif; ?>

  <p class="small gap-sm"><?= $e($selected['description']) ?></p>
  <p class="tiny muted">
    Affects: <?= $affects === [] ? 'anything' : $e(\implode(', ', $affects)) ?>
  </p>

  <dl class="pest-facts">
    <?= $section('What you will see', $selected['signs']) ?>
    <?= $section('What it costs to ignore', $selected['consequence']) ?>
    <?= $section('Confused with', $selected['look_alikes']) ?>
    <?= $section('When to look', $selected['monitoring']) ?>
    <?= $section('Stopping it happening', $selected['prevention']) ?>
    <?= $section('Without a spray', $selected['organic_controls']) ?>
    <?= $section('If you do spray', $selected['chemical_controls']) ?>
    <?= $section('On your side', $selected['beneficials']) ?>
<?php if (empty($selected['organic_controls']) && !empty($selected['treatments'])): ?>
    <?php /* An entry that came from a county research import rather than the
           built-in catalogue has only the older single `treatments` cell.
           Show it rather than drawing an empty card. */ ?>
    <?= $section('What to do', $selected['treatments']) ?>
<?php endif; ?>
  </dl>

  <p class="tiny muted flush">
    <a href="<?= $e($app->url('log')) ?>">Log this on a plant</a>
    &middot; <a href="<?= $e($app->url('pests', $listQuery)) ?>">back to the list</a>
<?php if (!empty($selected['source'])): ?>
    <br><?= $e($selected['source']) ?>
<?php endif; ?>
  </p>
</article>
<?php endif; ?>

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
<?php foreach ($grown as $name): ?>
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
<?php if (!$mineOnly): ?>
  <input type="hidden" name="all" value="1">
<?php endif; ?>
<?php if ($mineOnly): ?>
  <p class="help flush">
    <?= $e(\count($pests)) ?> shown: what can affect the plants you have grown, plus the
    ones that affect anything.
    <a href="<?= $e($app->url('pests', ['all' => '1'])) ?>">Show the whole catalogue</a>
    &mdash; useful before you plant something new.
  </p>
<?php else: ?>
  <p class="help flush">
    <?= $e(\count($pests)) ?> shown, from the whole catalogue.
    <a href="<?= $e($app->url('pests')) ?>">Narrow it to the plants you grow</a>.
  </p>
<?php endif; ?>
</form>

<div class="card">
<?php if ($pests === []): ?>
  <p class="muted flush">Nothing matches.
    <a href="<?= $e($app->url('pests', ['all' => '1'])) ?>">Search the whole catalogue</a>,
    or <a href="<?= $e($app->url('lists/pest_disease')) ?>">add it to your own list</a>
    if Carl does not know about it.</p>
<?php else: ?>
  <ul class="list pest-list">
<?php foreach ($pests as $pest):
    $key = (string) $pest['pest_key'];
    $affects = \array_filter(\array_map('trim',
        \explode(';', (string) ($pest['affects_categories'] ?? ''))));
?>
    <li>
      <a class="grow" href="<?= $e($app->url('pests', $listQuery + ['key' => $key])) ?>#pest-<?= $e($key) ?>">
        <span class="name"><?= $e($pest['name']) ?></span> <?= $badges($pest) ?><br>
        <span class="meta"><?= $e($affects === [] ? 'Anything' : \implode(', ', $affects)) ?></span>
<?php if (!empty($pest['signs'])): ?>
        <?php /* The one line worth carrying into a list: it is the sentence
               people search this page by, and it is what turns a name they do
               not recognise into one they do. */ ?>
        <br><span class="meta"><?= $e($pest['signs']) ?></span>
<?php endif; ?>
      </a>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>

<p class="tiny muted">
  Summarised for a home garden. Your county extension office knows your area and this
  page does not; anything unusual, expensive or about to be sprayed is worth asking them.
</p>
