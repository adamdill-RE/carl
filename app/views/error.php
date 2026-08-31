<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var Carl\Auth\User|null $user
 * @var int $status
 * @var string $message
 * @var string $adminHint  shown only to an admin; never to a user
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

/*
 * The fallback has to match the status. It used to be "There is nothing at
 * that address." for every status with no message of its own -- so a 500
 * rendered a headline saying the server broke above a sentence saying the
 * URL was wrong. The two contradict, and the wrong one is the one people
 * act on: it sends whoever is diagnosing an outage looking for a routing
 * fault. That happened on the Phase 3 deploy.
 */
$fallback = match ($status) {
    404 => 'There is nothing at that address.',
    default => 'The server hit an error and could not finish that. It has been logged.',
};
$adminHint = $adminHint ?? '';
?>
<h1 class="page-title"><?= $e($headline) ?></h1>
<div class="card">
  <p><?= $e($message !== '' ? $message : $fallback) ?></p>
<?php if ($adminHint !== '' && $user !== null && $user->isAdmin()): ?>
  <div class="notice notice-warn">
    <p class="flush small"><?= $e($adminHint) ?></p>
  </div>
<?php endif; ?>
  <p><a class="btn btn-secondary" href="<?= $e($app->url()) ?>">Back to the main menu</a></p>
</div>
