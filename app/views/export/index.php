<?php
/**
 * Export your own data (handoff Section 13.3).
 *
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var bool $hasWeather
 */
$e = $view->e(...);
$pageTitle = 'Export your data';
?>
<h1 class="page-title">Export your data</h1>
<p class="page-sub">Three CSV files, covering everything you have recorded. Yours only.</p>

<section class="card">
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('export/plants.csv')) ?>">plants.csv</a>
      <div class="small muted">
        One row per planting: what it is, where it went, when it started, what state it
        is in now, and its yield to date.
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('export/events.csv')) ?>">events.csv</a>
      <div class="small muted">
        Every logged action, plant and garden alike, told apart by the first column.
        A watering that came from a garden zone appears once as the garden event and
        once per plant it reached, with the garden event's id on each plant row so a
        total does not count it twice.
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('export/weather.csv')) ?>">weather.csv</a>
      <div class="small muted">
<?php if ($hasWeather): ?>
        The daily series for your location, in SI as it is stored: millimetres,
        degrees Celsius, kilometres per hour. Provisional days are marked.
<?php else: ?>
        Nothing yet -- finish onboarding so Carl knows where your garden is, and the
        nightly sync will start filling this in.
<?php endif; ?>
      </div>
    </li>
  </ul>
</section>

<section class="card">
  <h2>Reading these</h2>
  <p class="small">
    Files are UTF-8 with a byte-order mark, so Excel opens accented names correctly.
    A cell that begins <code>=</code>, <code>+</code>, <code>-</code> or <code>@</code>
    and is not a plain number is prefixed with an apostrophe: a spreadsheet would
    otherwise read it as a formula and offer to run it. Numbers are untouched.
  </p>
  <p class="small muted">
    Weather is stored in SI and converted only for display, so a column named
    <code>_mm</code> or <code>_c</code> is exactly that (weather.md &sect;6.3).
  </p>
  <p><a class="btn btn-secondary" href="<?= $e($app->url('')) ?>">Back to the menu</a></p>
</section>
