<?php
/**
 * Garden Actions (handoff Section 4.7). Each writes one garden_event;
 * watering a zone also fans out a derived water record to every living plant
 * in the zone's rows.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $garden
 * @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $zones
 * @var array<string,list<array<string,mixed>>> $lists
 * @var list<string> $actions
 * @var list<array<string,mixed>> $timers @var list<array<string,mixed>> $finishedTimers
 * @var string|null $pushKey @var int $pushCount @var int $timerMinutes @var int $timerZone @var int $timerMax
 */
$e = $view->e(...);
$E = Carl\Domain\EventType::class;
$L = Carl\Domain\ListType::class;
$pageTitle = 'Garden actions';
?>
<h1 class="page-title">Garden actions</h1>
<p class="page-sub"><?= $e($garden['name']) ?>
  &middot; <a href="<?= $e($app->url('gardens/' . $garden['id'])) ?>">view the garden</a></p>

<form method="post" action="<?= $e($app->url('gardens/' . $garden['id'] . '/actions')) ?>" class="card" id="log-form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="event_type">What did you do?</label>
    <select id="event_type" name="event_type" required>
      <option value="">-- choose an action --</option>
<?php foreach ($actions as $type): ?>
      <option value="<?= $e($type) ?>"><?= $e($E::label($type)) ?></option>
<?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="event_date">Date</label>
    <input type="date" id="event_date" name="event_date" value="<?= $e($today) ?>" max="<?= $e($today) ?>" required>
  </div>

  <fieldset class="event-fields" data-for="<?= $e($E::WATERED) ?>">
    <div class="field">
      <label for="water_zone_id">Zone</label>
      <select id="water_zone_id" name="water_zone_id">
        <option value="">-- the whole garden --</option>
<?php foreach ($zones as $zone): ?>
        <option value="<?= $e($zone['id']) ?>"><?= $e($zone['name']) ?></option>
<?php endforeach; ?>
      </select>
      <p class="help">
        Watering a zone also logs the watering against every living plant in that
        zone's rows, marked as coming from here so it is not counted twice.
      </p>
    </div>
    <?= $view->partial('partials/select_add', [
          'name' => 'water_method_id', 'newName' => 'water_method_new', 'label' => 'Water method',
          'listType' => $L::WATER_METHOD, 'items' => $lists[$L::WATER_METHOD] ?? []]) ?>
    <div class="field">
      <label for="duration_min">Duration (minutes)</label>
      <input type="number" id="duration_min" name="duration_min" min="0" max="1440">
    </div>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::FERTILIZED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'fertilizer_id', 'newName' => 'fertilizer_new', 'label' => 'Fertiliser',
          'listType' => $L::FERTILIZER_GARDEN, 'items' => $lists[$L::FERTILIZER_GARDEN] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::AMENDED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'amendment_id', 'newName' => 'amendment_new', 'label' => 'Amendment',
          'listType' => $L::SOIL_AMENDMENT, 'items' => $lists[$L::SOIL_AMENDMENT] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::MULCHED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'mulch_id', 'newName' => 'mulch_new', 'label' => 'Mulch type',
          'listType' => $L::MULCH_TYPE, 'items' => $lists[$L::MULCH_TYPE] ?? []]) ?>
<?php if ($rows !== []): ?>
    <div class="field">
      <label>Rows mulched</label>
<?php foreach ($rows as $row): ?>
      <span class="check">
        <input type="checkbox" id="mr-<?= $e($row['id']) ?>" name="rows[]" value="<?= $e($row['id']) ?>">
        <label for="mr-<?= $e($row['id']) ?>"><?= $e($row['name']) ?></label>
      </span>
<?php endforeach; ?>
      <p class="help">Leave all unticked for the whole garden.</p>
    </div>
<?php endif; ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_OBSERVED) ?> <?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'pest_id', 'newName' => 'pest_new', 'label' => 'Pest or disease',
          'listType' => $L::PEST_DISEASE, 'items' => $lists[$L::PEST_DISEASE] ?? []]) ?>
  </fieldset>

  <fieldset class="event-fields" data-for="<?= $e($E::PEST_TREATED) ?>">
    <?= $view->partial('partials/select_add', [
          'name' => 'treatment_id', 'newName' => 'treatment_new', 'label' => 'Treatment',
          'listType' => $L::PEST_TREATMENT, 'items' => $lists[$L::PEST_TREATMENT] ?? []]) ?>
  </fieldset>

  <div class="field">
    <label for="narrative">Notes</label>
    <textarea id="narrative" name="narrative"></textarea>
  </div>

  <?= $view->partial('partials/photo_uploader', ['gardenId' => (int) $garden['id']]) ?>

  <button type="submit" class="btn btn-block">Record it</button>
</form>

<?php /* The watering timer (Phase 16; Phase 15 handoff Section 3.2). A row
       with an end time; the per-minute cron fires it, logs the watering if
       asked, and reaches the phone -- push to this browser if it asked to be
       told, otherwise email. Nothing counts down in the page: iOS suspends a
       backgrounded tab within seconds and a countdown with it, which is why
       there is no JavaScript timer anywhere here. */ ?>
<section class="card" id="timers">
  <h2>Timer</h2>
<?php if ($timers !== []): ?>
  <ul class="list">
<?php foreach ($timers as $timer): ?>
    <li>
      <div class="grow">
        <strong><?= $e(Carl\Timers\TimerService::placeName($timer)) ?></strong>
        <span class="small muted">&middot; <?= $e($timer['minutes']) ?> min, done at
          <?= $e((new DateTimeImmutable((string) $timer['ends_at'], new DateTimeZone('UTC')))
                ->setTimezone($app->clock()->zone($user->tz()))->format('H:i')) ?>
          <?= (int) $timer['log_when_done'] === 1 ? '&middot; logs itself' : '' ?></span>
      </div>
      <form method="post" action="<?= $e($app->url('timers/' . $timer['id'] . '/cancel')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-secondary btn-small">Cancel</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php foreach ($finishedTimers as $timer): if ($timer['logged_event_id'] !== null || $timer['cancelled_at'] !== null) { continue; } ?>
  <p class="small">
    <a href="<?= $e($app->url('timers/' . $timer['id'])) ?>">
      <?= $e($timer['minutes']) ?> min on <?= $e(Carl\Timers\TimerService::placeName($timer)) ?> finished
      and is not logged yet</a>.
  </p>
<?php endforeach; ?>

  <form method="post" action="<?= $e($app->url('timers')) ?>" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="garden_id" value="<?= $e($garden['id']) ?>">
    <div class="field">
      <label for="timer_zone">Zone</label>
      <select id="timer_zone" name="water_zone_id">
        <option value="">-- the whole garden --</option>
<?php foreach ($zones as $zone): ?>
        <option value="<?= $e($zone['id']) ?>"<?= (int) $zone['id'] === $timerZone ? ' selected' : '' ?>><?= $e($zone['name']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="timer_minutes">Minutes</label>
      <input type="number" id="timer_minutes" name="minutes" min="1" max="<?= $e($timerMax) ?>"
             value="<?= $e($timerMinutes) ?>" inputmode="numeric" required>
      <p class="help">
        The main menu's watering line says how long a zone needs when it knows the zone's
        emitters, and offers this with the number filled in.
      </p>
    </div>
    <label class="check">
      <input type="checkbox" name="log_when_done" value="1" checked>
      <span>Log the watering when it finishes</span>
    </label>
    <button type="submit" class="btn">Start the timer</button>
  </form>

  <div class="push-setup" data-key="<?= $e($pushKey ?? '') ?>"
       data-subscribe="<?= $e($app->url('push/subscribe')) ?>"
       data-unsubscribe="<?= $e($app->url('push/unsubscribe')) ?>"
       data-sw="<?= $e($app->url('sw.js')) ?>" data-csrf="<?= $e($csrf) ?>">
    <h3>When it finishes</h3>
<?php if ($pushKey === null): ?>
    <p class="small muted">
      Carl will email <?= $e($user->email) ?>. Push notifications are not set up on this
      install yet: an administrator opens <code>/setup</code> once and they will be.
    </p>
<?php else: ?>
    <p class="small push-status" data-count="<?= $e($pushCount) ?>">
<?php if ($pushCount > 0): ?>
      <?= $e($pushCount) ?> phone<?= $pushCount === 1 ? '' : 's' ?> will be told.
<?php else: ?>
      Carl will email <?= $e($user->email) ?>.
<?php endif; ?>
    </p>
    <p class="push-controls" hidden>
      <button type="button" class="btn btn-secondary btn-small push-enable">Notify this phone</button>
      <button type="button" class="btn btn-secondary btn-small push-disable" hidden>Stop notifying this phone</button>
    </p>
    <p class="small muted push-note">
      On an iPhone, add Carl to the Home Screen first (Share, then Add to Home Screen) and
      open it from there: Safari only allows notifications from a home-screen app. Without
      one, the email still goes.
    </p>
<?php endif; ?>
  </div>
</section>

<?php /* End Growing Season (Phase 5 handoff Section 3.3). Below the form and
       in its own card rather than as another option in the action select:
       every other action here adds one row, and this one ends every living
       plant in the garden. It should not be one scroll away from "Watered". */ ?>
<section class="card">
  <h2>End the growing season</h2>
  <p class="small muted">
    Ends every living planting in <?= $e($garden['name']) ?> on one date, in one go.
    The next screen names each one before anything is written.
  </p>
  <p><a class="btn btn-secondary"
        href="<?= $e($app->url('gardens/' . $garden['id'] . '/end-season')) ?>">End growing season&hellip;</a></p>
</section>

<script src="<?= $e($app->asset('assets/js/forms.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/photos.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/push.js')) ?>" defer></script>
