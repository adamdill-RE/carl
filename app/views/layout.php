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
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#265c37">
<title><?= $e($pageTitle !== null ? $pageTitle . ' - ' . $title : $title) ?></title>
<?php /* Handoff 13.5, delivered. The SVG is the icon everywhere that takes
       one; the 32 is for browsers that do not, and the 180 is the iOS home
       screen, which only comes as PNG. */ ?>
<link rel="icon" href="<?= $e($app->asset('assets/img/carl-favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= $e($app->asset('assets/img/carl-favicon-32.png')) ?>" sizes="32x32">
<link rel="apple-touch-icon" href="<?= $e($app->asset('assets/img/carl-favicon-180.png')) ?>">
<?php /* Every URL inside the manifest is relative to the manifest itself, so
       it needs no knowledge of base_path (hosting Section 5.1) and works
       unchanged under /carl/ or a domain root. */ ?>
<link rel="manifest" href="<?= $e($app->asset('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= $e($app->asset('assets/css/tokens.css')) ?>">
<?php /* After tokens.css, never before: it is a prefers-color-scheme block
       over the same names and it has to win. The PDF and the digest email
       stay light whatever this does -- Carl\Support\Tokens reads tokens.css
       and never this file, which is correct, because paper is white. */ ?>
<link rel="stylesheet" href="<?= $e($app->asset('assets/css/tokens-dark.css')) ?>">
<link rel="stylesheet" href="<?= $e($app->asset('assets/css/carl.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
  <a class="brand" href="<?= $e($app->url()) ?>"><span
     class="brand-mark"><?= $view->partial('partials/logo') ?></span>Carl</a>
  <span class="spacer"></span>
<?php if ($user !== null): ?>
  <span class="who"><?= $e($user->displayName()) ?></span>
  <form method="post" action="<?= $e($app->url('logout')) ?>" class="flush">
    <input type="hidden" name="_csrf" value="<?= $e($csrf ?? '') ?>">
    <button type="submit" class="btn-link inherit-colour">Sign out</button>
  </form>
<?php endif; ?>
</header>

<main id="main" class="wrap">
<?php if (!empty($flash)): ?>
  <p class="notice notice-<?= $e($flash['kind']) ?>"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<?php /* A running tagging session is visible on EVERY page, not just the tag
       screens (docs/QR-TAGS-SPEC.md Section 6.5). The partial reads it off the
       user row Auth has already loaded, so this costs no statement. */ ?>
<?= $view->partial('partials/tagging_strip', [
    'session' => $session ?? null, 'user' => $user, 'csrf' => $csrf ?? '',
]) ?>

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
