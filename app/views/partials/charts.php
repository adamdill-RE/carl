<?php
/**
 * The chart block on a report (handoff Section 13.1), and the "Download PDF"
 * button under it (Section 13.2).
 *
 * There is no data in this markup. CSP is script-src 'self' with no nonce
 * (hosting Section 8.5), so the series cannot be written into an inline
 * script tag; the host element carries the endpoint's URL and
 * assets/js/charts.js fetches it. Both halves of that -- no inline script and
 * no off-site script -- are asserted in 01_core_test.php, and so is the
 * matching rule about inline style attributes.
 *
 * One panel is marked active server-side. Without it the whole stack would be
 * absolutely positioned until the script ran, the container would have no
 * height, and the block would jump on every load.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $seriesUrl the JSON endpoint
 * @var string $pdfUrl    where the canvases are posted
 * @var array<string,mixed> $range
 * @var string $csrf
 */
$e = $view->e(...);
$panels = [
    'temp' => 'Temperature',
    'rain' => 'Rainfall',
    'et0'  => "ET\u{2080}",
];
$first = \array_key_first($panels);
?>
<div class="charts" data-charts data-series-url="<?= $e($seriesUrl) ?>">
  <noscript>
    <p class="tiny muted">Charts need JavaScript. The totals above are the same data,
       and the full daily series is in <a href="<?= $e($app->url('export/weather.csv')) ?>">the weather CSV</a>.</p>
  </noscript>

  <p class="tiny muted" data-chart-status>Loading the chart&hellip;</p>

  <div class="chart-tabs" role="tablist" aria-label="Weather series">
<?php foreach ($panels as $key => $label): ?>
    <button type="button" class="chart-tab<?= $key === $first ? ' is-active' : '' ?>"
            role="tab" aria-selected="<?= $key === $first ? 'true' : 'false' ?>"
            data-chart-tab="<?= $e($key) ?>"><?= $e($label) ?></button>
<?php endforeach; ?>
  </div>

  <div class="chart-stack">
<?php foreach ($panels as $key => $label): ?>
    <div class="chart-panel<?= $key === $first ? ' is-active' : '' ?>" data-chart-panel="<?= $e($key) ?>">
      <canvas data-chart="<?= $e($key) ?>" aria-label="<?= $e($label) ?> over the covered dates"
              role="img"></canvas>
    </div>
<?php endforeach; ?>
  </div>

<?php if (($range['provisional'] ?? 0) > 0): ?>
<?php /* "Drawn faded", not "marked with a hollow point": this note sits under
       whichever panel is showing, and a line marks a provisional day with a
       hollow point while the rainfall bars mark it with a paler fill. Saying
       the specific one was true of the temperature chart and wrong under the
       other two -- found by looking at them. */ ?>
  <p class="tiny muted">
    <?= $e($range['provisional']) ?> of these days
    <?= (int) $range['provisional'] === 1 ? 'is' : 'are' ?> still provisional and drawn faded.
    The archive revises recent days for about two weeks, so those figures can still
    move (weather.md Section 6.2).
  </p>
<?php endif; ?>

  <form method="post" action="<?= $e($pdfUrl) ?>" class="gap-sm" data-chart-pdf>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php foreach (\array_keys($panels) as $key): ?>
    <input type="hidden" name="chart_<?= $e($key) ?>" value="">
<?php endforeach; ?>
    <button type="submit" class="btn btn-secondary btn-small">Download PDF</button>
  </form>
</div>
