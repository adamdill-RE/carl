<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var int $status
 * @var string $message
 */
$e = $view->e(...);
$pageTitle = 'Error ' . $status;

$headline = match ($status) {
    404 => 'Not found',
    403 => 'No access',
    405 => 'Wrong method',
    413 => 'That was too large',
    419 => 'Your session expired',
    429 => 'Too many attempts',
    default => 'Something went wrong',
};
?>
<h1 class="page-title"><?= $e($headline) ?></h1>
<div class="card">
  <p><?= $e($message !== '' ? $message : 'There is nothing at that address.') ?></p>
  <p><a class="btn btn-secondary" href="<?= $e($app->url()) ?>">Back to the main menu</a></p>
</div>
