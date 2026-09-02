<?php
/**
 * The Reports menu (Phase 5 handoff Section 3.2).
 *
 * Links and nothing else. Every destination here already existed; what did
 * not exist was a place that named them all, so a person who had never
 * opened a plant page had no way of knowing there was a PDF at the bottom of
 * one.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $gardens
 * @var int $plantCount @var int $livingCount
 * @var bool $hasWeather @var bool $analysisReady @var bool $hasRegion
 */
$e = $view->e(...);
$pageTitle = 'Reports';
?>
<h1 class="page-title">Reports</h1>
<p class="page-sub">
  <?= $e($livingCount) ?> living plants across <?= $e($plantCount) ?> plantings.
  Everything Carl can tell you about them, and everything you can take away.
</p>

<section class="card">
  <h2>Read</h2>
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('advice')) ?>">Recommendations</a>
      <div class="small muted">
        What your records say about your season, read against the weather that
        actually happened.
<?php if (!$analysisReady): ?>
        No analysis key is configured yet, so a request waits in the queue until
        one is.
<?php endif; ?>
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('plants')) ?>">Plant reports</a>
      <div class="small muted">
        One per planting: the research card for it, its whole timeline, its
        photographs, and the temperature, rain and ET&#8320; over the days it has
        been in the ground. &ldquo;Download PDF&rdquo; is at the bottom of each.
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('gardens')) ?>">Garden reports</a>
      <div class="small muted">
<?php if ($gardens === []): ?>
        You have no gardens yet.
        <a href="<?= $e($app->url('gardens/new')) ?>">Build one</a>.
<?php else: ?>
        The same for a whole bed: what is in each row, what it yielded, the
        garden's own actions, and the weather over the dates it has been in use.
<?php endif; ?>
      </div>
    </li>
  </ul>
<?php if ($gardens !== []): ?>
  <ul class="list">
<?php foreach ($gardens as $garden): ?>
    <li>
      <div class="grow">
        <a href="<?= $e($app->url('gardens/' . $garden['id'])) ?>"><?= $e($garden['name']) ?></a>
<?php if ((int) $garden['is_indoor'] === 1): ?>
        <span class="badge badge-muted">indoor</span>
<?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section class="card">
  <h2>Plan</h2>
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('succession')) ?>">Succession planting</a>
      <div class="small muted">
<?php if ($hasRegion): ?>
        Every sowing your area&rsquo;s research still allows this season, a
        fortnight apart, with the date each round should start coming in. Each
        one is a link straight into Start a New Plant.
<?php else: ?>
        The sowing windows come from the research for your county, and none is
        loaded yet &mdash; so there is nothing to plan from.
<?php endif; ?>
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('companions')) ?>">Companion planting</a>
      <div class="small muted">
        Which crops are said to suit each other and which to keep apart, with
        the mechanism behind each and how well established it is. A reference:
        nothing here changes a reminder or a countdown.
      </div>
    </li>
  </ul>
</section>

<section class="card">
  <h2>Take away</h2>
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('export/plants.csv')) ?>">plants.csv</a>
      <div class="small muted">One row per planting, with its yield to date.</div>
    </li>
    <li>
      <a href="<?= $e($app->url('export/events.csv')) ?>">events.csv</a>
      <div class="small muted">Every logged action, plant and garden alike.</div>
    </li>
    <li>
      <a href="<?= $e($app->url('export/weather.csv')) ?>">weather.csv</a>
      <div class="small muted">
<?php if ($hasWeather): ?>
        The daily series for your location, in SI as it is stored.
<?php else: ?>
        Nothing yet &mdash; finish onboarding so Carl knows where your garden is.
<?php endif; ?>
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('export/claude.json')) ?>">for-claude.json</a>
      <div class="small muted">
        The whole record as one document, for pasting into a conversation with
        Claude yourself. Recommendations above does this for you, on a smaller
        summary of the same data.
      </div>
    </li>
  </ul>
  <p class="small">
    <a href="<?= $e($app->url('export')) ?>">More about these files</a> &mdash; what is in
    each column, and why a cell can start with an apostrophe.
  </p>
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('connect')) ?>">Connect Claude Code</a>
      <div class="small muted">
        Skip the pasting: a token that lets Claude Code read your garden directly,
        one question at a time. Read-only; revoke it here whenever you like.
      </div>
    </li>
  </ul>
</section>

<section class="card">
  <h2>Print</h2>
  <ul class="list">
    <li>
      <a href="<?= $e($app->url('reports/field-sheet.pdf')) ?>">Field sheet (blank)</a>
      <div class="small muted">
        A page to take out to the beds and write on: a line per plant for the
        round, and blocks underneath for anything with a quantity, a product
        name or a sentence behind it. Every box on it is a field on Log Plant
        Activity.
      </div>
    </li>
    <li>
      <a href="<?= $e($app->url('reports/field-sheet.pdf', ['kind' => 'garden'])) ?>">Garden actions sheet (blank)</a>
      <div class="small muted">
        The same for what you do to a whole bed or one watering zone.
      </div>
    </li>
<?php foreach ($gardens as $garden): ?>
    <li>
      <a href="<?= $e($app->url('reports/garden/' . (int) $garden['id'] . '/field-sheet.pdf')) ?>">
        Field sheet &mdash; <?= $e($garden['name']) ?></a>
      <div class="small muted">
        The same sheet with this garden&rsquo;s rows and living plants already
        printed on it, so you only write what changed.
      </div>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="small muted">
    A4 and US Letter both, from one page: drawn to fit inside whichever of the
    two is smaller in each direction, so nothing needs shrinking to fit.
  </p>
</section>

<p><a class="btn btn-secondary" href="<?= $e($app->url('')) ?>">Back to the menu</a></p>
