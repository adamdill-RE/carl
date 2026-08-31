<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var array<string,string> $soilTypes
 * @var array<string,string> $values
 * @var list<string> $errors
 */
$e = $view->e(...);
$pageTitle = 'Your first garden';
?>
<?= $view->partial('partials/steps', ['step' => $step]) ?>

<h1 class="page-title">Build your first garden</h1>
<p class="page-sub">
  An <strong>Indoor Garden</strong> has already been created for you -- that is where
  indoor seed starts go. This one is the outdoor bed or plot.
</p>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('onboarding/garden')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?= $view->partial('gardens/fields', ['values' => $values, 'soilTypes' => $soilTypes]) ?>
  <button type="submit" class="btn btn-block">Create garden</button>
</form>

<form method="post" action="<?= $e($app->url('onboarding/finish')) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button type="submit" class="btn-link">Skip to main menu</button>
</form>
