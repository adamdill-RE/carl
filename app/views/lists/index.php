<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var list<string> $types @var array<string,int> $counts
 *  @var list<array<string,mixed>> $containers @var list<array<string,mixed>> $schedules */
$e = $view->e(...);
$L = Carl\Domain\ListType::class;
$pageTitle = 'Lists';
?>
<h1 class="page-title">Lists</h1>
<p class="page-sub">
  Your own seed sources, soils, fertilisers and the rest. Every dropdown in Carl
  reads from these, and every dropdown can add to them without leaving the form.
</p>

<div class="card">
  <ul class="list">
<?php foreach ($types as $type): ?>
    <li><a class="grow" href="<?= $e($app->url('lists/' . $type)) ?>">
      <?= $e($L::label($type)) ?>
      <span class="muted small">&middot; <?= $e($counts[$type] ?? 0) ?></span>
    </a></li>
<?php endforeach; ?>
    <li><a class="grow" href="<?= $e($app->url('lists/containers')) ?>">
      Containers <span class="muted small">&middot; <?= $e(\count($containers)) ?></span></a></li>
    <li><a class="grow" href="<?= $e($app->url('lists/hardening')) ?>">
      Hardening schedules <span class="muted small">&middot; <?= $e(\count($schedules)) ?></span></a></li>
  </ul>
</div>
