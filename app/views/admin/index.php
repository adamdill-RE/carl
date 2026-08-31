<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var int $userCount @var array<string,int> $counts @var int $queue
 *  @var list<array<string,mixed>> $imports */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$pageTitle = 'Admin';
?>
<h1 class="page-title">Admin</h1>
<p class="page-sub">Three functions: create users, import research, and see which
   regions still need it.</p>

<nav class="menu">
  <a href="<?= $e($app->url('admin/users')) ?>">Users
    <span class="hint"><?= $e($userCount) ?> accounts</span></a>
  <a href="<?= $e($app->url('admin/research-import')) ?>">Research import
    <span class="hint"><?= $e($counts['plant_type']) ?> plants, <?= $e($counts['region']) ?> regions</span></a>
  <a href="<?= $e($app->url('admin/regions')) ?>">Regions needing research
    <span class="hint"><?= $e($queue) ?> in the queue</span></a>
</nav>

<section class="card">
  <h2>Reference data</h2>
  <table class="data">
    <tbody>
      <tr><th>Plant types</th><td><?= $e($counts['plant_type']) ?></td></tr>
      <tr><th>Plant windows by region</th><td><?= $e($counts['plant_region']) ?></td></tr>
      <tr><th>Pests and diseases</th><td><?= $e($counts['pest']) ?></td></tr>
      <tr><th>Pest windows by region</th><td><?= $e($counts['pest_region']) ?></td></tr>
      <tr><th>Regions</th><td><?= $e($counts['region']) ?></td></tr>
      <tr><th>Guidance lines</th><td><?= $e($counts['region_guidance']) ?></td></tr>
    </tbody>
  </table>
</section>

<?php if ($imports !== []): ?>
<section class="card">
  <h2>Recent imports</h2>
  <ul class="list">
<?php foreach ($imports as $import): ?>
    <li><span class="grow">
      <strong><?= $e($import['dataset_version']) ?></strong>
      <span class="muted small">&middot; <?= $e($import['region_keys']) ?>
        &middot; <?= $e($import['imported_at']) ?>
        <?php if (!empty($import['imported_by_name'])): ?>by <?= $e($import['imported_by_name']) ?><?php endif; ?>
      </span>
    </span></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
