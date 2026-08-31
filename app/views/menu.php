<?php
/**
 * The main menu (handoff Section 4.2).
 *
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var Carl\Auth\User $user
 * @var Carl\Support\Units $units
 * @var array{recent:list<array<string,mixed>>,forecast:list<array<string,mixed>>} $weather
 * @var list<array<string,mixed>> $watering
 * @var list<array<string,mixed>> $items
 * @var list<array<string,mixed>> $guidance
 * @var list<array<string,mixed>> $pests
 * @var list<array<string,mixed>> $alerts
 * @var array<string,mixed>|null $region
 * @var bool $researched @var bool $dismissed @var string $forecastHash
 * @var array{living:int,plantings:int,gardens:int,events:int} $counts
 */
$e = $view->e(...);
$K = Carl\Domain\ReminderKind::class;
$pageTitle = null;
$hasWeather = $weather['recent'] !== [] || $weather['forecast'] !== [];
?>

<?php if (!$onboarded): ?>
  <div class="notice notice-info">
    Your setup is not finished. <a href="<?= $e($app->url('onboarding')) ?>">Pick up where you left off</a>.
  </div>
<?php endif; ?>

<?php foreach ($alerts as $alert): ?>
  <div class="notice notice-warn">
    <strong><?= $e($alert['event']) ?></strong>
    <?php if (!empty($alert['headline'])): ?><br><?= $e($alert['headline']) ?><?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if (!$dismissed): ?>
<section class="card motd">
  <form method="post" action="<?= $e($app->url('motd/dismiss')) ?>" class="flush">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="forecast_hash" value="<?= $e($forecastHash) ?>">
    <button type="submit" class="btn btn-secondary btn-small dismiss"
            aria-label="Dismiss for today">&times;</button>
  </form>

  <h2>Weather</h2>

<?php if (!$hasWeather): ?>
  <p class="muted small">
    Weather arrives nightly. Once the sync has run you will see the last three days
    and the next three here.
  </p>
<?php else: ?>
  <div class="matrix-scroll">
  <table class="matrix">
    <thead>
      <tr>
        <th>Day</th><th>High / low</th><th>Rain</th><th>ET&#8320;</th>
        <th>Soil</th><th>Humidity</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($weather['recent'] as $day): ?>
      <tr class="<?= ((int) $day['is_provisional']) === 1 ? 'provisional' : '' ?>">
        <td><?= $e(Carl\Support\Units::shortDate((string) $day['obs_date'])) ?>
<?php if (((int) $day['is_provisional']) === 1): ?>
          <span class="tiny" title="Not yet settled; the reanalysis can still revise it">*</span>
<?php endif; ?>
        </td>
        <td><?= $e($units->temperatureRange($day['temp_max_c'], $day['temp_min_c'])) ?></td>
        <td><?= $e($units->rain($day['precip_mm'])) ?></td>
        <td><?= $e($units->et0($day['et0_mm'])) ?></td>
        <td><?= $e($units->soilMoisture($day['soil_moist_0_7'])) ?></td>
        <td><?= $e($units->percent($day['rh_mean_pct'])) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($weather['forecast'] !== []): ?>
      <tr><th colspan="6" class="tiny">Forecast</th></tr>
<?php foreach ($weather['forecast'] as $day): ?>
      <tr>
        <td><?= $e(Carl\Support\Units::shortDate((string) $day['forecast_date'])) ?></td>
        <td><?= $e($units->temperatureRange($day['temp_max_c'], $day['temp_min_c'])) ?></td>
        <td><?= $e($units->rain($day['precip_mm'])) ?>
            <?php if ($day['precip_prob_pct'] !== null): ?>
              <span class="muted tiny">(<?= $e($units->percent($day['precip_prob_pct'])) ?>)</span>
            <?php endif; ?>
        </td>
        <td><?= $e($units->et0($day['et0_mm'])) ?></td>
        <td><?= $e($units->soilMoisture($day['soil_moist_0_7'])) ?></td>
        <td><?= $e($units->percent($day['rh_mean_pct'])) ?></td>
      </tr>
<?php endforeach; ?>
<?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="tiny muted">* still provisional -- the reanalysis revises the last few days.</p>
<?php endif; ?>

<?php if ($watering !== []): ?>
  <h3>Watering</h3>
  <ul class="list guidance">
<?php foreach ($watering as $place): ?>
    <li>
      <div class="grow">
        <span class="topic tier-<?= $e($place['tier']) ?>"><?= $e($place['tier']) ?></span><br>
        <strong><?= $e($place['place_name']) ?></strong>
        <div class="small"><?= $e($place['reason_text']) ?></div>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($guidance !== [] || $pests !== []): ?>
  <h3>For your area today</h3>
  <ul class="list guidance">
<?php foreach ($guidance as $line): ?>
    <li>
      <div class="grow">
        <span class="topic"><?= $e($line['topic']) ?></span><br>
        <?= $e($line['guidance']) ?>
        <?php if (!empty($line['confidence'])): ?>
          <span class="confidence confidence-<?= $e($line['confidence']) ?>"><?= $e($line['confidence']) ?></span>
        <?php endif; ?>
        <?php if (!empty($line['source'])): ?>
          <div class="tiny muted"><?= $e($line['source']) ?></div>
        <?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
<?php foreach ($pests as $pest): ?>
    <li>
      <div class="grow">
        <span class="topic">watch for</span><br>
        <strong><?= $e($pest['name']) ?></strong> is active in your area now.
        <?php if (!empty($pest['signs'])): ?><br><span class="small"><?= $e($pest['signs']) ?></span><?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php elseif (!$researched): ?>
  <p class="small muted">
    Your county is not researched yet, so Carl has no local planting windows or advice
    to show. Everything you record still works, and the advice appears once the research
    for your area is loaded.
  </p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($items !== []): ?>
<section class="card">
  <h2>Today</h2>
  <p class="tiny muted flush">
    The same items as your morning email. Computed overnight, not while you waited.
  </p>
  <ul class="list items">
<?php foreach ($items as $item): ?>
    <li>
      <div class="grow">
        <span class="topic kind-<?= $e($item['kind']) ?>"><?= $e($K::label((string) $item['kind'])) ?></span><br>
        <strong><?= $e($item['title']) ?></strong>
<?php if ((string) $item['body'] !== ''): ?>
        <div class="small muted"><?= $e($item['body']) ?></div>
<?php endif; ?>
      </div>
      <form method="post" action="<?= $e($app->url('reminders/dismiss')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="reminder_id" value="<?= $e($item['id']) ?>">
        <button type="submit" class="btn btn-secondary btn-small"
                aria-label="Dismiss this item">&times;</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<h1 class="page-title">What would you like to do?</h1>
<p class="page-sub">
  <?= $e($counts['living']) ?> living plants across <?= $e($counts['gardens']) ?> gardens
  &middot; <?= $e($counts['events']) ?> logged events
</p>

<nav class="menu">
  <a href="<?= $e($app->url('plants/new')) ?>">Start a New Plant
    <span class="hint">Seed start, direct sow or transplant</span></a>
  <a href="<?= $e($app->url('log')) ?>">Log Plant Activity
    <span class="hint">Water, yield, pests, cull</span></a>
  <a href="<?= $e($app->url('plants')) ?>">View Plants
    <span class="hint">Timeline and photos</span></a>
  <a href="<?= $e($app->url('gardens/new')) ?>">Build Garden
    <span class="hint">Rows, zones and soil</span></a>
  <a href="<?= $e($app->url('gardens')) ?>">Garden Actions
    <span class="hint">Water a zone, mulch, fertilise</span></a>
  <a href="<?= $e($app->url('lists')) ?>">Lists
    <span class="hint">Your seeds, soils, fertilisers</span></a>
  <a href="<?= $e($app->url('reports')) ?>">Reports
    <span class="hint">Charts, PDFs, recommendations and exports</span></a>
</nav>
