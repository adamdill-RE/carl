<?php
/**
 * The research card (handoff Section 9.1): shown on every plant form and on
 * the plant report, with the source and confidence behind each value so a
 * reader can tell a measured number from a regional estimate.
 *
 * @var Carl\Core\View $view
 * @var array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
 * @var bool $hasRegion
 */
$e = $view->e(...);
$plant = $card['plant'];
if ($plant === null) {
    return;
}
$U = Carl\Support\Units::class;
?>
<div class="card research">
  <h2><?= $e($plant['category']) ?> &middot; <?= $e($plant['type']) ?>
<?php if (!empty($plant['confidence'])): ?>
    <span class="confidence confidence-<?= $e($plant['confidence']) ?>"><?= $e($plant['confidence']) ?></span>
<?php endif; ?>
  </h2>
<?php if (!empty($plant['latin_name'])): ?>
  <p class="small muted"><em><?= $e($plant['latin_name']) ?></em> &middot; <?= $e($plant['plant_family']) ?></p>
<?php endif; ?>

  <dl>
<?php if ($plant['dtm_days_min'] !== null || $plant['dtm_days_max'] !== null): ?>
    <dt>Days to maturity</dt>
    <dd><?= $e(\trim(($plant['dtm_days_min'] ?? '') . ' - ' . ($plant['dtm_days_max'] ?? ''), ' -')) ?>
        <span class="muted">from <?= $e($plant['dtm_counted_from']) ?></span></dd>
<?php endif; ?>
<?php if ($plant['germ_days_min'] !== null): ?>
    <dt>Germination</dt>
    <dd><?= $e($plant['germ_days_min']) ?>-<?= $e($plant['germ_days_max']) ?> days
<?php if ($plant['germ_soil_temp_f_min'] !== null): ?>
        at <?= $e($plant['germ_soil_temp_f_min']) ?>-<?= $e($plant['germ_soil_temp_f_max']) ?>&deg;F soil
<?php endif; ?>
    </dd>
<?php endif; ?>
<?php if ($plant['spacing_in'] !== null): ?>
    <dt>Spacing</dt><dd><?= $e($U::length($plant['spacing_in'])) ?></dd>
<?php endif; ?>
<?php if ($plant['seed_depth_in'] !== null): ?>
    <dt>Seed depth</dt><dd><?= $e($U::length($plant['seed_depth_in'])) ?></dd>
<?php endif; ?>
<?php if (!empty($plant['sun'])): ?>
    <dt>Sun</dt><dd><?= $e(\ucfirst((string) $plant['sun'])) ?></dd>
<?php endif; ?>
<?php if ($plant['weeks_before_transplant_to_start'] !== null): ?>
    <dt>Start indoors</dt>
    <dd><?= $e($plant['weeks_before_transplant_to_start']) ?> weeks before transplanting</dd>
<?php endif; ?>
<?php if ($plant['hardening_days_default'] !== null): ?>
    <dt>Harden off</dt><dd><?= $e($plant['hardening_days_default']) ?> days</dd>
<?php endif; ?>
<?php if ((int) $plant['heat_tolerant'] === 1): ?>
    <dt>Heat</dt><dd>Tolerant</dd>
<?php endif; ?>
  </dl>

<?php if ($card['regions'] !== []): ?>
  <h3>In your area</h3>
  <table class="data">
    <thead><tr><th>Season</th><th>Window</th><th>Method</th><th></th></tr></thead>
    <tbody>
<?php foreach ($card['regions'] as $region): ?>
      <tr>
        <td><?= $e(\ucfirst((string) $region['season'])) ?></td>
        <td><?= $e($U::monthDayRange($region['window_start'], $region['window_end'])) ?></td>
        <td><?= $e($region['method'] ?? '') ?></td>
        <td>
<?php if ((int) $region['recommended'] === 1): ?>
          <span class="badge">recommended</span>
<?php endif; ?>
<?php if (!empty($region['confidence'])): ?>
          <span class="confidence confidence-<?= $e($region['confidence']) ?>"><?= $e($region['confidence']) ?></span>
<?php endif; ?>
        </td>
      </tr>
<?php if (!empty($region['regional_notes'])): ?>
      <tr><td colspan="4" class="small muted"><?= $e($region['regional_notes']) ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
    </tbody>
  </table>
<?php elseif (!$hasRegion): ?>
  <p class="small muted">
    Carl has no researched planting windows for your county yet, so these are the
    general values. Days to maturity still counts down normally.
  </p>
<?php endif; ?>

<?php if (!empty($plant['notes'])): ?>
  <p class="small"><?= $e($plant['notes']) ?></p>
<?php endif; ?>
<?php if (!empty($plant['source'])): ?>
  <p class="cite">Source: <?= $e($plant['source']) ?></p>
<?php endif; ?>
</div>
