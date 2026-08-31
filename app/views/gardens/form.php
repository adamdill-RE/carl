<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var array<string,mixed>|null $garden @var array<string,string> $values
 *  @var array<string,string> $soilTypes @var list<string> $errors */
$e = $view->e(...);
$editing = $garden !== null;
$pageTitle = $editing ? 'Edit garden' : 'Build Garden';
?>
<h1 class="page-title"><?= $e($pageTitle) ?></h1>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($editing ? $app->url('gardens/' . $garden['id']) : $app->url('gardens')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?= $view->partial('gardens/fields', ['values' => $values, 'soilTypes' => $soilTypes]) ?>
  <button type="submit" class="btn btn-block"><?= $editing ? 'Save garden' : 'Create garden' ?></button>
</form>
