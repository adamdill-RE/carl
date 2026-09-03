<?php
/**
 * One photograph, large, with the way back and the way along (Phase 17).
 *
 * THIS PAGE EXISTS BECAUSE THE HOME-SCREEN APP HAS NO BACK BUTTON. A
 * thumbnail used to link to the JPEG itself, and in Safari that is fine. Add
 * Carl to an iPhone's home screen and open it from there, and the same link
 * is a full-screen photograph with no address bar, no back, no forward and
 * no way out short of killing the app -- Safari strips its chrome from a
 * standalone web app, and a bare image response has nothing of its own.
 *
 * So a photo is a page of Carl's: the picture, the plant or garden it
 * belongs to as the way back, and previous and next through the same set
 * the plant page shows. gallery.js opens the same set in an in-page viewer
 * where it can; this is the version that works with no script at all, and
 * the one the viewer falls back to.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $photo
 * @var int $position @var int $count
 * @var array<string,mixed>|null $prev @var array<string,mixed>|null $next
 * @var array{url:string,label:string} $back
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$pageTitle = 'Photo';

$prevUrl = $prev === null ? '' : $app->url('photos/' . (int) $prev['id'] . '/view');
$nextUrl = $next === null ? '' : $app->url('photos/' . (int) $next['id'] . '/view');
?>
<h1 class="page-title">Photo <?= $e($position) ?> of <?= $e($count) ?></h1>
<p class="page-sub">
  <a href="<?= $e($back['url']) ?>">Back to <?= $e($back['label']) ?></a>
</p>

<figure class="photo-view" data-prev="<?= $e($prevUrl) ?>" data-next="<?= $e($nextUrl) ?>">
  <img src="<?= $e($app->url('photos/' . (int) $photo['id'])) ?>" alt=""
       width="<?= $e((int) $photo['width']) ?>" height="<?= $e((int) $photo['height']) ?>">
  <figcaption>
    <?= $e($U::longDate((string) $photo['taken_on'])) ?>
<?php if ((string) ($photo['caption'] ?? '') !== ''): ?>
    &middot; <?= $e($photo['caption']) ?>
<?php endif; ?>
  </figcaption>
</figure>

<?php /* Both controls always take up their room, so the one that is there
       does not jump across the page when the other is not. */ ?>
<p class="photo-nav">
<?php if ($prev !== null): ?>
  <a class="btn btn-secondary" rel="prev" href="<?= $e($prevUrl) ?>">&larr; Previous</a>
<?php else: ?>
  <span class="btn btn-secondary photo-nav-off" aria-hidden="true">&larr; Previous</span>
<?php endif; ?>
<?php if ($next !== null): ?>
  <a class="btn btn-secondary" rel="next" href="<?= $e($nextUrl) ?>">Next &rarr;</a>
<?php else: ?>
  <span class="btn btn-secondary photo-nav-off" aria-hidden="true">Next &rarr;</span>
<?php endif; ?>
</p>

<p><a class="btn btn-secondary" href="<?= $e($back['url']) ?>">Back to <?= $e($back['label']) ?></a></p>

<?php /* Swipe and the arrow keys move along the set; the links above are
       the same thing for everybody else. */ ?>
<script src="<?= $e($app->asset('assets/js/gallery.js')) ?>" defer></script>
