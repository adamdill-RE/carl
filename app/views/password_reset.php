<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var bool $forced
 * @var list<string> $errors
 */
$e = $view->e(...);
$pageTitle = 'Choose a password';
?>
<h1 class="page-title">Choose a password</h1>
<?php if ($forced): ?>
  <p class="page-sub">This account is still on its starting password. Pick your own before going on.</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('password/reset')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="password">New password</label>
    <input type="password" id="password" name="password" autocomplete="new-password" required>
    <p class="help">At least 10 characters. A short phrase you will remember beats a clever short one.</p>
  </div>

  <div class="field">
    <label for="password_confirm">Type it again</label>
    <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
  </div>

  <button type="submit" class="btn btn-block">Save password</button>
  <p class="help">Saving signs you out everywhere else.</p>
</form>
