<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var list<array<string,mixed>> $gardens
 */
$e = $view->e(...);
$pageTitle = 'Start your first plant';
?>
<?= $view->partial('partials/steps', ['step' => $step]) ?>

<h1 class="page-title">Start your first plant</h1>
<p class="page-sub">
  Optional, and you can do it any time. Dates default to today and accept the past,
  so a plant you started six weeks ago goes in with the date it actually went in.
</p>

<div class="menu">
  <a href="<?= $e($app->url('plants/new/indoor_seed')) ?>">Indoor seed start
    <span class="hint">Seeds in trays under lights</span></a>
  <a href="<?= $e($app->url('plants/new/direct_sow')) ?>">Direct sow
    <span class="hint">Seeds straight into a row or container</span></a>
  <a href="<?= $e($app->url('plants/new/nursery_transplant')) ?>">Transplant
    <span class="hint">Nursery-bought or unknown origin</span></a>
</div>

<form method="post" action="<?= $e($app->url('onboarding/finish')) ?>" class="card card-tight">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button type="submit" class="btn btn-secondary btn-block">I am done -- take me to the main menu</button>
</form>
