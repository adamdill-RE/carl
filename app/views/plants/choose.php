<?php
/** @var Carl\Core\App $app @var Carl\Core\View $view
 *  @var array<string,array<string,string>> $kinds @var string $tag */
$e = $view->e(...);
$pageTitle = 'Start a New Plant';
$tag = $tag ?? '';
$carry = $tag === '' ? [] : ['tag' => $tag];
?>
<h1 class="page-title">Start a New Plant</h1>
<p class="page-sub">How did this plant come to be? The answer sets its first event.</p>

<?php if ($tag !== ''): ?>
<div class="notice notice-info">
  Tag <strong class="mono"><?= $e($tag) ?></strong> goes on whatever you start here.
</div>
<?php endif; ?>

<nav class="menu">
<?php foreach ($kinds as $key => $kind): ?>
  <a href="<?= $e($app->url('plants/new/' . $key, $carry)) ?>"><?= $e($kind['title']) ?>
    <span class="hint"><?= $e($kind['blurb']) ?></span></a>
<?php endforeach; ?>
</nav>
