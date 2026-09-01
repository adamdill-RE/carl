<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var string $name @var string $zip @var string $county
 * @var array<string,mixed>|null $resolved
 * @var list<string> $errors
 */
$e = $view->e(...);
$pageTitle = 'Set up your account';
?>
<?= $view->partial('partials/steps', ['step' => $step]) ?>

<?php /* Step one of the wizard is the other place the lockup belongs
       (Claude Design). Decorative here, because unlike login this page has a
       real heading of its own and the mark would otherwise be announced
       twice. */ ?>
<span class="lockup" aria-hidden="true"><?= $view->partial('partials/logo_lockup') ?></span>

<h1 class="page-title">Welcome to Carl</h1>
<p class="page-sub">
  Two things to start: what to call you, and where you garden. Your ZIP code sets
  the weather Carl records against your plants, and the regional advice it can offer.
</p>

<?php if ($errors !== []): ?>
  <div class="notice notice-error">
    <ul class="errors"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('onboarding/profile')) ?>" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="name">Your name</label>
    <input type="text" id="name" name="name" value="<?= $e($name) ?>" autocomplete="name" required>
  </div>

  <div class="field">
    <label for="county">Garden county</label>
    <input type="text" id="county" name="county" value="<?= $e($county) ?>"
           placeholder="Hill" autocomplete="off">
    <p class="help">Optional. Carl works the county out from the ZIP code; this is only
       used if the ZIP is one Carl has not seen before.</p>
  </div>

  <div class="field">
    <label for="zip">ZIP code</label>
    <input type="text" id="zip" name="zip" value="<?= $e($zip) ?>"
           inputmode="numeric" pattern="[0-9]{5}(-[0-9]{4})?" maxlength="10"
           autocomplete="postal-code" required>
    <p class="help" id="zip-help">
<?php if ($resolved !== null): ?>
      Found: <?= $e(($resolved['county_name'] ?? '') . ' ' . ($resolved['state'] ?? '')) ?>
<?php else: ?>
      One location per account. If you garden at two ZIP codes, make two accounts.
<?php endif; ?>
    </p>
  </div>

  <button type="submit" class="btn btn-block">Continue</button>
</form>

<script src="<?= $e($app->asset('assets/js/zip.js')) ?>" defer></script>
