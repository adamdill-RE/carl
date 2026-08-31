<?php
/**
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var string $content
 * @var Carl\Auth\User|null $user
 * @var array{kind:string,message:string}|null $flash
 * @var string $title
 */
$e = $view->e(...);
$pageTitle = $pageTitle ?? null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<title><?= $e($pageTitle !== null ? $pageTitle . ' - ' . $title : $title) ?></title>
<link rel="stylesheet" href="<?= $e($app->asset('assets/css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= $e($app->asset('assets/css/carl.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
  <a class="brand" href="<?= $e($app->url()) ?>">Carl</a>
  <span class="spacer"></span>
<?php if ($user !== null): ?>
  <span class="who"><?= $e($user->displayName()) ?></span>
  <form method="post" action="<?= $e($app->url('logout')) ?>" style="margin:0">
    <input type="hidden" name="_csrf" value="<?= $e($csrf ?? '') ?>">
    <button type="submit" class="btn-link" style="color:inherit">Sign out</button>
  </form>
<?php endif; ?>
</header>

<main id="main" class="wrap">
<?php if (!empty($flash)): ?>
  <p class="notice notice-<?= $e($flash['kind']) ?>"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<?= $content ?>

  <footer class="foot">
<?php if ($user !== null): ?>
    <p><a href="<?= $e($app->url()) ?>">Main menu</a>
<?php if ($user->isAdmin()): ?>
       &middot; <a href="<?= $e($app->url('admin')) ?>">Admin</a>
<?php endif; ?>
    </p>
<?php endif; ?>
    <?= $view->partial('partials/attribution', ['models' => $weatherModels ?? []]) ?>
  </footer>
</main>
</body>
</html>
