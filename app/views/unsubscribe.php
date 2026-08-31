<?php
/**
 * The tokenised opt-out (handoff Section 12).
 *
 * Reachable without signing in, because someone clicking a link in an email
 * is not signed in and making them sign in to stop the mail is exactly the
 * pattern that gets a sender marked as spam.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $name @var string $email @var string $token
 * @var bool $enabled @var bool $done
 */
$e = $view->e(...);
$pageTitle = 'Daily digest';
?>
<h1 class="page-title">Carl's daily digest</h1>

<?php if ($done && !$enabled): ?>
  <div class="notice notice-ok">
    <p class="flush">Stopped. No more daily emails to <?= $e($email) ?>.</p>
  </div>
  <p class="small">
    Everything you have recorded is untouched, and today's items are still on your
    main menu when you sign in. Changed your mind?
  </p>
  <form method="post" action="<?= $e($app->url('unsubscribe/' . $token . '/resume')) ?>">
    <button type="submit" class="btn btn-secondary">Start them again</button>
  </form>

<?php elseif ($done && $enabled): ?>
  <div class="notice notice-ok">
    <p class="flush">Back on. <?= $e($email) ?> will get the digest each morning.</p>
  </div>

<?php elseif (!$enabled): ?>
  <div class="notice notice-info">
    <p class="flush">You are already unsubscribed. Nothing is being sent to <?= $e($email) ?>.</p>
  </div>
  <form method="post" action="<?= $e($app->url('unsubscribe/' . $token . '/resume')) ?>">
    <button type="submit" class="btn btn-secondary">Start them again</button>
  </form>

<?php else: ?>
  <p class="page-sub">
    <?= $e($name !== '' ? $name . ', this' : 'This') ?> will stop the daily email to
    <strong><?= $e($email) ?></strong>.
  </p>
  <section class="card">
    <p class="small">
      It only stops the email. Your plants, your logs and your photos are untouched, and
      the same items still appear on your main menu when you sign in.
    </p>
    <form method="post" action="<?= $e($app->url('unsubscribe/' . $token)) ?>">
      <button type="submit" class="btn btn-danger">Stop the daily email</button>
    </form>
  </section>
<?php endif; ?>
