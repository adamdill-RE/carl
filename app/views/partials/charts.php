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
 * PHASE 12 TURNED THE SUBJECT ROUND. weather.md Section 7.3 is the authority
 * and it says weather is CONTEXT, NOT THE SUBJECT: "on a plant-performance
 * chart it belongs as a muted background band or a secondary axis, never
 * competing with the performance line for attention". Until Phase 12 this
 * block was three weather panels with the plant reduced to identical
 * triangles -- because until size (migration 024) the plant had almost no
 * number of its own to draw. It now has height, diameter, harvest weight,
 * harvest count and watering minutes, so `build` below is a plant series on
 * the left axis with a weather series muted behind it, and the tabs are
 * presets that set the two pickers rather than three fixed charts.
 *
 * THE THREE WEATHER PANELS ARE STILL HERE and are still drawn -- they are
 * what the PDF posts (Section 13.2, the three `chart_*` fields below). They
 * are laid out and hidden by CSS, which keeps their canvases sized so
 * toDataURL() returns a picture rather than a blank. They carry no tab: the
 * same three views are reachable from the pickers, and six tabs do not fit
 * across 380 px.
 *
 * THE TABS AND THE PICKERS ARE BUILT BY THE SCRIPT, not here. Which layers a
 * subject has depends on what has been logged against it -- a garden has no
 * height, an unmeasured plant has no growth curve -- and that is in the
 * fetched document, not in anything this template knows. One panel is still
 * marked active server-side, without which the whole stack would be
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

/** The three the PDF posts. Drawn always, tabbed never. */
$pdfPanels = [
    'temp' => 'Temperature',
    'rain' => 'Rainfall',
    'et0'  => "ET\u{2080}",
];
?>
<div class="charts" data-charts data-series-url="<?= $e($seriesUrl) ?>">
  <noscript>
    <p class="tiny muted">Charts need JavaScript. The totals above are the same data,
       and the full daily series is in <a href="<?= $e($app->url('export/weather.csv')) ?>">the weather CSV</a>.</p>
  </noscript>

  <p class="tiny muted" data-chart-status>Loading the chart&hellip;</p>

  <div class="chart-tabs" role="tablist" aria-label="Views" data-chart-tabs></div>

  <div class="chart-stack">
    <?php /* The one panel that is active before the script runs, so the
           container has a height from the first paint. */ ?>
    <div class="chart-panel is-active" data-chart-panel="build">
      <canvas data-chart="build" role="img"
              aria-label="The subject's own measurements, with weather behind them"></canvas>
    </div>
    <div class="chart-panel" data-chart-panel="compare">
      <canvas data-chart="compare" role="img"
              aria-label="One measurement against the weather in the days before it"></canvas>
    </div>
<?php foreach ($pdfPanels as $key => $label): ?>
    <div class="chart-panel" data-chart-panel="<?= $e($key) ?>">
      <canvas data-chart="<?= $e($key) ?>" aria-label="<?= $e($label) ?> over the covered dates"
              role="img"></canvas>
    </div>
<?php endforeach; ?>
  </div>

  <?php /* The pickers. Two selects and nothing else: weather.md Section 7.3's
         annotation records that even TWO weather series overlaid were
         unreadable at 380 px, so what is buildable here is one subject series
         and one context series -- which is the shape that section asks for,
         and not a free-for-all that would let anybody rebuild the chart it
         says does not work. */ ?>
  <div class="chart-layers" data-chart-layers hidden>
    <div class="field">
      <label for="chart-plant">Show</label>
      <select id="chart-plant" data-chart-pick="plant"></select>
    </div>
    <div class="field">
      <label for="chart-weather">Against</label>
      <select id="chart-weather" data-chart-pick="weather"></select>
    </div>
    <div class="field" data-chart-lag hidden>
      <label for="chart-lag">Weather over</label>
      <select id="chart-lag" data-chart-pick="lag">
        <option value="0">the same day</option>
        <option value="7" selected>the 7 days before</option>
        <option value="14">the 14 days before</option>
        <option value="30">the 30 days before</option>
      </select>
    </div>
  </div>

  <p class="tiny muted" data-chart-note></p>

<?php if (($range['provisional'] ?? 0) > 0): ?>
<?php /* "Drawn faded", not "marked with a hollow point": this note sits under
       whichever panel is showing, and a line marks a provisional day with a
       hollow point while the rainfall bars mark it with a paler fill. Saying
       the specific one was true of the temperature chart and wrong under the
       other two -- found by looking at them. */ ?>
  <p class="tiny muted" data-chart-provisional>
    <?= $e($range['provisional']) ?> of these days
    <?= (int) $range['provisional'] === 1 ? 'is' : 'are' ?> still provisional and drawn faded.
    The archive revises recent days for about two weeks, so those figures can still
    move (weather.md Section 6.2).
  </p>
<?php endif; ?>

  <form method="post" action="<?= $e($pdfUrl) ?>" class="gap-sm" data-chart-pdf>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php foreach (\array_keys($pdfPanels) as $key): ?>
    <input type="hidden" name="chart_<?= $e($key) ?>" value="">
<?php endforeach; ?>
    <button type="submit" class="btn btn-secondary btn-small">Download PDF</button>
  </form>
</div>
