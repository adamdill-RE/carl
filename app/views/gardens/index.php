<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var list<array<string,mixed>> $gardens
 *  @var array<int,list<array<string,mixed>>> $rowsByGarden
 *  @var list<array<string,mixed>> $containers */
$e = $view->e(...);
$pageTitle = 'Gardens';
$Soil = Carl\Domain\SoilType::class;
?>
<h1 class="page-title">Gardens</h1>
<p class="page-sub">Open a garden to see its report, or go straight to its actions.</p>

<?php foreach ($gardens as $garden): $rows = $rowsByGarden[(int) $garden['id']] ?? []; ?>
<section class="card">
  <h2><a href="<?= $e($app->url('gardens/' . $garden['id'])) ?>"><?= $e($garden['name']) ?></a>
<?php if ((int) $garden['is_indoor'] === 1): ?>
    <span class="badge badge-muted">indoor</span>
<?php endif; ?>
  </h2>
  <p class="small muted">
<?php if ($garden['ns_ft'] !== null && $garden['ew_ft'] !== null): ?>
    <?= $e($garden['ns_ft']) ?> x <?= $e($garden['ew_ft']) ?> ft &middot;
<?php endif; ?>
    <?= $e(\count($rows)) ?> rows &middot; <?= $e($Soil::label($garden['soil_type'])) ?>
  </p>
  <div class="row">
    <a class="btn btn-small" href="<?= $e($app->url('gardens/' . $garden['id'] . '/actions')) ?>">Garden actions</a>
    <a class="btn btn-secondary btn-small" href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">View</a>
    <a class="btn btn-secondary btn-small" href="<?= $e($app->url('gardens/' . $garden['id'] . '/edit')) ?>">Edit</a>
  </div>
</section>
<?php endforeach; ?>

<?php if ($containers !== []): ?>
<section class="card">
  <h2>Containers</h2>
  <ul class="list">
<?php foreach ($containers as $container): ?>
    <li><span class="grow"><?= $e($container['name']) ?>
      <?php if (!empty($container['size'])): ?><span class="muted">(<?= $e($container['size']) ?>)</span><?php endif; ?>
    </span></li>
<?php endforeach; ?>
  </ul>
  <p class="small"><a href="<?= $e($app->url('lists/containers')) ?>">Manage containers</a></p>
</section>
<?php endif; ?>

<p><a class="btn" href="<?= $e($app->url('gardens/new')) ?>">Build another garden</a></p>
