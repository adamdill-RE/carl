<?php
/**
 * The set-password link's landing page (Phase 5 handoff Section 3.5).
 *
 * Four states, and each says which one it is. A link that has expired and a
 * link that was never real look identical to the person holding them, and
 * telling them apart is the difference between "ask for another" and "check
 * you copied the whole address".
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $status  one of Carl\Auth\InviteStore's constants
 * @var string $token @var string $username
 * @var list<string> $errors
 */
$e = $view->e(...);
$I = Carl\Auth\InviteStore::class;
$pageTitle = 'Set your password';
?>
<h1 class="page-title">Set your password</h1>

<?php if ($status === $I::VALID): ?>
<?php if ($username !== ''): ?>
<p class="page-sub">
  You are setting the password for <strong><?= $e($username) ?></strong>. That is the
  name you will sign in with.
</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('password/setup/' . $token)) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf ?? '') ?>">

  <div class="field">
    <label for="password">Your password</label>
    <input type="password" id="password" name="password" autocomplete="new-password" required>
    <p class="help">At least 10 characters. A short phrase you will remember beats a clever short one.</p>
  </div>

  <div class="field">
    <label for="password_confirm">Type it again</label>
    <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
  </div>

  <button type="submit" class="btn btn-block">Set it and sign in</button>
  <p class="help">
    This link works once. After that it stops working, whether or not you used it.
  </p>
</form>

<?php elseif ($status === $I::USED): ?>
<div class="notice notice-info">
  That link has already been used, which means the password on the account is
  already set. It only ever works once.
</div>
<p>
  <a class="btn" href="<?= $e($app->url('login')) ?>">Go to sign in</a>
</p>
<p class="small muted">
  If it was not you who used it, tell whoever set the account up straight away
  and ask them to send a new invitation &mdash; issuing one cancels any link that
  is still out there.
</p>

<?php elseif ($status === $I::EXPIRED): ?>
<div class="notice notice-warn">
  That link has expired. They are deliberately short-lived, so that an old
  message sitting in a mailbox is not a way into the account.
</div>
<p class="small">
  Ask whoever set the account up to send another. It takes them one click, and
  the new one cancels this one.
</p>
<p><a class="btn btn-secondary" href="<?= $e($app->url('login')) ?>">Sign in instead</a></p>

<?php else: ?>
<div class="notice notice-error">
  That link is not one Carl recognises.
</div>
<p class="small">
  The most common cause is a mail client breaking the address across two lines
  &mdash; check you copied the whole thing, right to the end. Otherwise, ask for a
  new invitation.
</p>
<p><a class="btn btn-secondary" href="<?= $e($app->url('login')) ?>">Sign in instead</a></p>
<?php endif; ?>
