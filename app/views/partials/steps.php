<?php
/** @var Carl\Core\View $view @var string $step */
$e = $view->e(...);
$order = ['profile' => 'You', 'garden' => 'Garden', 'plant' => 'First plant'];
$seen = false;
?>
<div class="steps">
<?php foreach ($order as $key => $label):
    $class = $key === $step ? 'on' : ($seen ? '' : 'done');
    if ($key === $step) { $seen = true; }
?>
  <span class="<?= $e($class) ?>"><?= $e($label) ?></span>
<?php endforeach; ?>
</div>
