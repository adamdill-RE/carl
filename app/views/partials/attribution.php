<?php
/**
 * Attribution is required and non-optional (weather.md Section 10), and is
 * generated from source_model on the rows actually shown rather than
 * hard-coded, which keeps it honest.
 *
 * The sentences themselves live in Carl\Support\Attribution, because the JSON
 * series endpoint and the PDF report have to print the same ones.
 *
 * @var Carl\Core\View $view
 * @var list<string> $models
 */
$e = $view->e(...);
$credits = Carl\Support\Attribution::of($models);
if ($credits === []) {
    return;
}
?>
<p class="tiny">
<?php foreach ($credits as $credit): ?>
  <?= $e($credit['before']) ?>
<?php if ($credit['link_text'] !== null && $credit['url'] !== null): ?>
<a href="<?= $e($credit['url']) ?>" rel="noopener"><?= $e($credit['link_text']) ?></a>
<?php endif; ?>
<?= $e($credit['after']) ?>
<?php endforeach; ?>
</p>
