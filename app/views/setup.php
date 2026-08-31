<?php
/**
 * /setup?key= -- applies migrations and sets the first administrator's
 * credential, because before the migrations run there is no user table to log
 * in against (hosting Section 6.3).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $pending
 * @var string|null $error @var string $key
 * @var list<array<string,mixed>>|null $applied
 */
$e = $view->e(...);
$pageTitle = 'Setup';
$action = $app->url('setup', ['key' => $key]);
?>
<h1 class="page-title">Setup</h1>
<p class="page-sub">
  This page is reachable only with <code>setup_key</code> configured. Remove that line
  from <code>config/local.php</code> as soon as you are finished: whoever holds it can
  take the master admin account.
</p>

<?php if ($error !== null): ?>
  <div class="notice notice-error"><?= $e($error) ?></div>
<?php endif; ?>

<?php if (\is_array($applied) && $applied !== []): ?>
  <div class="notice notice-ok">
    <ul class="errors">
<?php foreach ($applied as $migration): ?>
      <li><?= $e($migration['filename']) ?> -- <?= $e($migration['statements']) ?> statements,
          <?= $e($migration['ms']) ?> ms</li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Migrations</h2>
<?php if ($pending === []): ?>
  <p>Nothing pending. The schema is up to date.</p>
<?php else: ?>
  <p><?= $e(\count($pending)) ?> pending:</p>
  <ul class="errors">
<?php foreach ($pending as $migration): ?>
    <li><?= $e($migration['filename']) ?> <span class="muted">(<?= $e($migration['kind']) ?>)</span></li>
<?php endforeach; ?>
  </ul>
  <form method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="migrate">
    <button type="submit" class="btn">Apply them</button>
  </form>
  <p class="help">Safe to retry: every migration is applied once and recorded with a checksum.</p>
<?php endif; ?>
</section>

<section class="card">
  <h2>Administrator credential</h2>
  <p class="small">
    Sets (or resets) the master admin password. Any existing sign-in for that
    account is revoked immediately.
  </p>
  <form method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="admin">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="admin" autocapitalize="none">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
      <p class="help">At least 10 characters.</p>
    </div>
    <button type="submit" class="btn">Set it</button>
  </form>
</section>

<section class="card">
  <h2>Next</h2>
  <ol class="small">
    <li>Remove <code>setup_key</code> from <code>config/local.php</code>.</li>
    <li>Check <a href="<?= $e($app->url('status')) ?>">/status?key=</a> -- it reports the
        session cookie, whether any code is web-reachable, and weather health.</li>
    <li>Sign in and import the first research dataset from Admin.</li>
  </ol>
</section>
