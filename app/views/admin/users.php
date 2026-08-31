<?php
/**
 * Admin: create a user (handoff Section 4.10). Email delivery is Phase 3, so
 * the temporary password is shown once, here, and the admin passes it on.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $users
 * @var array{username:string,password:string,email:string}|null $created
 * @var list<string> $errors @var array<string,string> $old
 */
$e = $view->e(...);
$pageTitle = 'Users';
?>
<h1 class="page-title">Users</h1>
<p class="page-sub"><a href="<?= $e($app->url('admin')) ?>">Admin</a></p>

<?php if ($created !== null): ?>
<div class="notice notice-ok">
  <p><strong><?= $e($created['username']) ?></strong> created.</p>
  <p>Temporary password: <code style="font-size:18px"><?= $e($created['password']) ?></code></p>
  <p class="small">
    This is shown once and is not stored anywhere readable. Write it down or send it now.
    They will be made to change it the first time they sign in.
    Emailing it automatically arrives in a later phase.
  </p>
</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('admin/users')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <h2>Create a user</h2>
  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" maxlength="64" required
           autocapitalize="none" value="<?= $e($old['username'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" maxlength="190" required
           value="<?= $e($old['email'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" maxlength="120" required
           value="<?= $e($old['name'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="user">User</option>
      <option value="admin">Admin</option>
    </select>
  </div>
  <button type="submit" class="btn">Create and show the password</button>
</form>

<section class="card">
  <h2>Accounts</h2>
  <table class="data">
    <thead><tr><th>User</th><th>Where</th><th>Last seen</th></tr></thead>
    <tbody>
<?php foreach ($users as $account): ?>
      <tr>
        <td><?= $e($account['username']) ?>
<?php if ($account['role'] === 'admin'): ?><span class="badge">admin</span><?php endif; ?>
<?php if ((int) $account['must_reset_password'] === 1): ?>
          <span class="badge badge-muted">must reset</span>
<?php endif; ?>
          <br><span class="muted small"><?= $e($account['name']) ?></span>
        </td>
        <td class="small">
          <?= $e($account['zip'] ?? '--') ?>
<?php if (!empty($account['region_label'])): ?>
          <br><span class="muted"><?= $e($account['region_label']) ?></span>
<?php if (($account['research_status'] ?? '') !== 'researched'): ?>
          <span class="badge badge-muted">unresearched</span>
<?php endif; ?>
<?php endif; ?>
        </td>
        <td class="small muted"><?= $e($account['last_login_at'] ?? 'never') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
