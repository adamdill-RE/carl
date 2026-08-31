<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var string $username
 * @var list<string> $errors
 */
$e = $view->e(...);
$pageTitle = 'Sign in';
?>
<h1 class="page-title">Carl The Garden Helper</h1>
<p class="page-sub">Sign in to log what happened in the garden.</p>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('login')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= $e($username) ?>"
           autocomplete="username" autocapitalize="none" autocorrect="off" required>
  </div>

  <div class="field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
  </div>

  <button type="submit" class="btn btn-block">Sign in</button>
</form>
