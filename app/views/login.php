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
<?php /* The lockup carries the name here, so it IS the heading rather than a
       picture above one. Its <svg> is role="img" with a <title>, which is
       what gives the h1 its accessible name -- the wordmark is paths, so
       there is no text in it for a screen reader to find. */ ?>
<h1 class="lockup"><?= $view->partial('partials/logo_lockup') ?></h1>
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
