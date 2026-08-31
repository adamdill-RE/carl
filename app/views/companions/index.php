<?php
/**
 * The companion planting reference (handoff Section 14 v2, Phase 6).
 *
 * A reference, not a recommendation. Nothing on this page fires a reminder or
 * moves a countdown, and the confidence marker on every line is the point of
 * the screen rather than a decoration: this is the subject where the gap
 * between what is repeated and what has been tested is widest, and a page
 * that flattened the two would be teaching folklore in Carl's voice.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,list<array<string,mixed>>> $byCategory
 * @var array<string,bool> $mine
 * @var int $pairCount
 * @var Carl\Auth\User|null $user
 */
$e = $view->e(...);
$pageTitle = 'Companion planting';

// The crops in this garden first. Somebody looking up their own bed should
// not have to scroll past a catalogue to find it.
$grown = [];
$rest = [];
foreach ($byCategory as $category => $pairs) {
    if (isset($mine[\strtolower((string) $category)])) {
        $grown[$category] = $pairs;
    } else {
        $rest[$category] = $pairs;
    }
}
?>
<h1 class="page-title">Companion planting</h1>
<p class="page-sub">
  <?= $e($pairCount) ?> pairings from the research, listed under both crops.
  Each one says what it is supposed to do and how well established that is.
</p>

<?php if ($pairCount === 0): ?>
  <div class="card">
    <p class="small muted">
      No companion pairings are loaded. They arrive with a research dataset
      &mdash; <code>companions.csv</code>, from template version 2 on &mdash; so an
      older dataset simply has none.
    </p>
  </div>
<?php else: ?>

<div class="notice notice-info">
  <p class="small">
    Read the confidence markers. <span class="confidence confidence-verified">verified</span>
    means a trial or an extension publication naming a mechanism;
    <span class="confidence confidence-approx">approx</span> means the mechanism is
    plausible and partly supported; <span class="confidence confidence-generic">generic</span>
    means traditional and widely printed, with nothing measured behind it. Most
    familiar companion advice is the last kind, including the famous ones.
  </p>
</div>

<?php
$section = static function (string $heading, array $groups, callable $e): void {
    if ($groups === []) {
        return;
    }
    echo '<h2 class="page-title">' . $e($heading) . "</h2>\n";
    foreach ($groups as $category => $pairs) {
        echo '<section class="card">' . "\n";
        echo '  <h2>' . $e($category) . "</h2>\n";
        echo '  <ul class="items small">' . "\n";
        foreach ($pairs as $pair) {
            $bad = $pair['relationship'] === 'bad';
            echo '    <li>' . "\n";
            echo '      <div class="grow">' . "\n";
            echo '        <span class="topic">' . ($bad ? 'Keep apart from' : 'Grows well with')
                . ' <strong>' . $e($pair['other']) . "</strong></span>\n";
            if ($pair['reason'] !== null && $pair['reason'] !== '') {
                echo '        <div class="muted">' . $e($pair['reason']) . "</div>\n";
            }
            if ($pair['source'] !== null && $pair['source'] !== '') {
                echo '        <div class="tiny muted">Source: ' . $e($pair['source']) . "</div>\n";
            }
            echo "      </div>\n";
            if ($pair['confidence'] !== null) {
                echo '      <span class="confidence confidence-' . $e($pair['confidence']) . '">'
                    . $e($pair['confidence']) . "</span>\n";
            }
            echo "    </li>\n";
        }
        echo "  </ul>\n";
        echo "</section>\n";
    }
};
$section('In your garden', $grown, $e);
$section($grown === [] ? 'Every crop' : 'Everything else', $rest, $e);
?>

<?php endif; ?>

<div class="card">
  <h2>Where these come from</h2>
  <p class="small muted">
    They arrive with a research dataset, like the planting windows and the pest
    windows, and an admin imports them at
    <?php if ($user !== null && $user->isAdmin()): ?>
      <a href="<?= $e($app->url('admin/research-import')) ?>">Research import</a>.
    <?php else: ?>
      Research import.
    <?php endif; ?>
    They are global rather than per-county: whether basil sits well beside a
    tomato is a fact about the two plants, not about where you garden.
  </p>
  <p class="small muted">
    Carl deliberately does nothing with them beyond showing them. Crop rotation
    &mdash; which family last grew in a bed &mdash; is a separate and much
    better-evidenced thing, and that one does warn you, beside the row picker
    on Start a New Plant.
  </p>
</div>

<p><a class="btn btn-secondary" href="<?= $e($app->url('reports')) ?>">Back to reports</a></p>
