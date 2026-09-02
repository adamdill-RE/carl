<?php
/**
 * The plant report (handoff Section 4.5): the research card, the full
 * timeline, the photos in chronological order, the yield summary, the latest
 * measurement of the plant, and the weather that actually happened over the
 * plant's in-ground period -- as totals, and from Phase 4 as charts beside
 * them.
 *
 * The charts are drawn by assets/js/charts.js from /api/plant/<id>/series,
 * NOT from a JSON island in this page: CSP is script-src 'self' with no
 * inline script and no nonce (hosting Section 8.5), so the data arrives over
 * fetch and the element below carries only its URL in a data- attribute.
 * With JavaScript off the totals table is the whole report and still true.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var array<string,mixed> $planting
 * @var array{plant:?array<string,mixed>,regions:list<array<string,mixed>>} $card
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $photos
 * @var array{weight_g:float,count_qty:int,events:int,first:?string,last:?string} $yield
 * @var array<string,mixed> $series
 * @var string $seriesUrl @var string $pdfUrl
 * @var list<array{label:string,date:string,days:?int}> $countdowns
 * @var array{parent:?array<string,mixed>,children:list<array<string,mixed>>} $lineage
 * @var list<array<string,mixed>> $tags
 * @var array{sheet:list<array<string,mixed>>,loose:list<array<string,mixed>>} $free
 * @var array{title:string,url:string}|null $startAnother
 */
$e = $view->e(...);
$S = Carl\Domain\PlantingState::class;
$U = Carl\Support\Units::class;
$pageTitle = $planting['category'] . ' ' . $planting['type'];

$where = $planting['container_name']
    ?? \trim(((string) ($planting['garden_name'] ?? '')) . ' ' . ((string) ($planting['row_name'] ?? '')));
$initial = (int) $planting['quantity_initial'];
$live = (int) $planting['quantity_live'];
$lost = (int) $planting['quantity_lost'];
$survival = $S::survivalPercent($initial, $lost);
$endedReason = $planting['ended_reason'] ?? null;

/*
 * The most recent measurement, taken off the timeline this page has already
 * loaded rather than asked for.
 *
 * A statement, not saved: 11_reports_test.php counts them, and a SELECT for
 * "the last row with a height" would answer a question the rows in hand
 * already answer. timeline() comes back newest first, so the first row
 * carrying each column is the latest one -- and height and diameter are
 * looked for INDEPENDENTLY, because either may stand alone (migration 024)
 * and a plant measured for height in July and across in June has both, from
 * two different days.
 */
$lastHeight = null;
$lastDiameter = null;
foreach ($events as $event) {
    if ($lastHeight === null && ($event['height_mm'] ?? null) !== null) {
        $lastHeight = $event;
    }
    if ($lastDiameter === null && ($event['diameter_mm'] ?? null) !== null) {
        $lastDiameter = $event;
    }
    if ($lastHeight !== null && $lastDiameter !== null) {
        break;
    }
}

/** Where a lineage row is, in the words the rest of the page uses. */
$placeOf = static function (array $row): string {
    return (string) ($row['container_name']
        ?? \trim(((string) ($row['garden_name'] ?? '')) . ' ' . ((string) ($row['row_name'] ?? ''))));
};
$startAnother = $startAnother ?? null;
?>
<?php if ($startAnother !== null): ?>
<?php /* The page a new plant lands on, and the first thing on it is the way
       to the next one (Phase 15). A tray is entered a variety at a time, so
       "start another" of the SAME KIND -- seed start, direct sow, transplant
       -- with the date already filled in is what turns six trips through
       the menu into six presses of one button. The rest of the page is the
       record of what was just saved, which is still worth a glance. */ ?>
<section class="card card-tight start-another" id="start-another">
  <a class="btn btn-block" href="<?= $e($startAnother['url']) ?>"
     >Start another: <?= $e(\strtolower($startAnother['title'])) ?></a>
  <p class="help flush gap-xs">
    The same kind of start, on the same date, for the next variety.
    Everything below is what you just recorded.
  </p>
</section>
<?php endif; ?>
<h1 class="page-title"><?= $e($planting['category']) ?> &middot; <?= $e($planting['type']) ?></h1>
<p class="page-sub">
  <span class="badge<?= (string) $planting['state'] === $S::ENDED ? ' badge-muted' : '' ?>">
    <?= $e($S::label((string) $planting['state'])) ?></span>
<?php if (!empty($planting['label'])): ?>
  &middot; <?= $e($planting['label']) ?>
<?php endif; ?>
<?php if ($where !== ''): ?>
  &middot; <?= $e($where) ?>
<?php endif; ?>
  &middot; <a href="<?= $e($app->url('log/' . $planting['id'])) ?>">log an action</a>
</p>

<section class="card">
  <h2>Where it stands</h2>
  <table class="data">
    <tbody>
      <tr><th>Started</th><td><?= $e($U::longDate((string) $planting['start_date'])) ?>
        (<?= $e(\str_replace('_', ' ', (string) $planting['start_method'])) ?>)</td></tr>
<?php if ($planting['in_ground_date'] !== null): ?>
      <tr><th>In the ground</th><td><?= $e($U::longDate((string) $planting['in_ground_date'])) ?></td></tr>
<?php endif; ?>
      <tr><th>Living</th><td><?= $e($live) ?> of <?= $e($initial) ?>
<?php if ($survival !== null): ?>
        <?php /* Survival counts what DIED, not what left: plants moved out to
               another bed are alive somewhere else, and reading them as
               losses is the bug PLANTING-SPLIT-SPEC Section 4.5 is about. */ ?>
        <span class="muted">(<?= $e($survival) ?>% survival<?= $lost > 0 ? ', ' . $e($lost) . ' lost' : '' ?>)</span>
<?php endif; ?>
<?php if ($live < $initial - $lost): ?>
        <span class="muted">&middot; <?= $e($initial - $lost - $live) ?> moved out</span>
<?php endif; ?>
      </td></tr>
<?php if ($planting['germinated_at'] !== null): ?>
      <tr><th>Germinated</th><td><?= $e($U::longDate((string) $planting['germinated_at'])) ?></td></tr>
<?php endif; ?>
<?php if ($planting['ended_at'] !== null): ?>
      <tr><th><?= $e($S::endedLabel($endedReason)) ?></th>
          <td><?= $e($U::longDate((string) $planting['ended_at'])) ?>
<?php if ($endedReason === $S::ENDED_BY_DISPERSAL): ?>
        <span class="muted">every plant was moved on to somewhere else, not lost</span>
<?php endif; ?>
      </td></tr>
<?php endif; ?>
<?php if ($lastHeight !== null || $lastDiameter !== null): ?>
      <?php /* One row, because "how big is it" is one question.
             The date is printed ONCE when both figures came off the same
             visit, which is the ordinary case -- a row that says "(23 Aug) .
             (23 Aug)" is asking the reader to compare two dates that are the
             same. When they differ it is printed against each, because a
             single date would have to pick one and be wrong about the
             other. */ ?>
<?php
    $sameVisit = $lastHeight !== null && $lastDiameter !== null
        && (string) $lastHeight['event_date'] === (string) $lastDiameter['event_date'];
    $stamp = static fn (?array $row): string => $row === null ? ''
        : ' <span class="muted small">(' . $e($U::shortDate((string) $row['event_date'])) . ')</span>';
?>
      <tr><th>Size</th><td>
<?php if ($lastHeight !== null): ?>
        <?= $e($units->size($lastHeight['height_mm'])) ?> tall<?= $sameVisit ? '' : $stamp($lastHeight) ?>
<?php endif; ?>
<?php if ($lastHeight !== null && $lastDiameter !== null): ?>
        &middot;
<?php endif; ?>
<?php if ($lastDiameter !== null): ?>
        <?= $e($units->size($lastDiameter['diameter_mm'])) ?> across<?= $sameVisit ? '' : $stamp($lastDiameter) ?>
<?php endif; ?>
<?= $sameVisit ? $stamp($lastHeight) : '' ?>
      </td></tr>
<?php endif; ?>
<?php foreach ($countdowns as $countdown): ?>
      <tr><th><?= $e($countdown['label']) ?></th>
          <td><?= $e($U::longDate($countdown['date'])) ?>
              <span class="muted"><?= $e($U::relativeDays($countdown['days'])) ?></span></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php /* The tag panel: the DESK half of QR-TAGS-SPEC Section 5.2, whose other
       half is the scan. You planned the season in Carl in February; here is
       where stakes get attached to what you planned -- ticked off the
       sheet in front of you, as many as the tray has cells (Section 14.7)
       -- and where they come off again in October. Every form here posts
       back to #tag, so the page comes back to this panel and not to the
       top of the report. */ ?>
<section class="card card-tight" id="tag">
<?php $stakes = \count($tags); $hasFree = $free['sheet'] !== [] || $free['loose'] !== []; ?>
<?php if ($stakes > 0): ?>
  <h2><?= $stakes === 1 ? 'Tag <span class="mono">' . $e($tags[0]['code']) . '</span>' : $e($stakes) . ' stakes on this plant' ?></h2>
  <p class="muted small">
    Scanning <?= $stakes === 1 ? 'it' : 'any of them' ?> opens a logging screen for this plant.
<?php if ($stakes < (int) $planting['quantity_live']): ?>
    <?= $e($live) ?> living, so there is room for more.
<?php endif; ?>
  </p>
  <ul class="list small">
<?php foreach ($tags as $tag): ?>
    <li>
      <a class="grow" href="<?= $e($app->url('t/' . $tag['code'])) ?>" title="The one-tap logging screen">
        <span class="mono tag-ref"><?= $e($tag['code']) ?></span>
        <span class="muted">&middot; since <?= $e($U::shortDate((string) $tag['bound_at'])) ?></span>
<?php if ($tag['retired_at'] !== null): ?>
        <span class="badge badge-muted">sheet retired</span>
<?php endif; ?>
      </a>
      <form method="post" action="<?= $e($app->url('plants/' . $planting['id'] . '/tag/release')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="tag_id" value="<?= $e($tag['id']) ?>">
        <button type="submit" class="btn-link small" title="Frees the stake; the plant is untouched">Take off</button>
      </form>
      <form method="post" action="<?= $e($app->url('plants/' . $planting['id'] . '/tag/release')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="tag_id" value="<?= $e($tag['id']) ?>">
        <input type="hidden" name="retire" value="1">
        <button type="submit" class="btn-link small muted"
                title="Off and retired, so a stake that is in the bin stops counting as free">Lost</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="help small">
    <strong>Take off</strong> frees the stake for another plant; <strong>Lost</strong> also retires
    the code, for a stake that snapped or a label that tore. The plant keeps its whole history
    either way, and each tag remembers it was here.
  </p>
<?php if ($stakes > 1): ?>
  <form method="post" action="<?= $e($app->url('plants/' . $planting['id'] . '/tag/release')) ?>" class="flush">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn btn-secondary btn-small">Take all <?= $e($stakes) ?> off</button>
  </form>
<?php endif; ?>
<?php else: ?>
  <h2>No stake on this plant</h2>
<?php endif; ?>

<?php if ($hasFree): ?>
  <details<?= $stakes === 0 ? ' open' : '' ?>>
    <summary><?= $stakes === 0 ? 'Put stakes on it' : 'Add more stakes' ?></summary>
    <form method="post" action="<?= $e($app->url('plants/' . $planting['id'] . '/tag')) ?>"
          class="stack">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <p class="help small flush">
        <?= $e(Carl\Repo\TagRepository::countFree($free)) ?> printed and free. Tick the ones you are
        putting in &mdash; one for the whole planting, or one a plant &mdash; and each one you scan
        will open this plant.
      </p>
      <?= $view->partial('partials/tag_picker', [
            'free' => $free, 'name' => 'tags', 'checked' => [],
            'wanted' => \max(1, $live - $stakes)]) ?>
      <button type="submit" class="btn btn-secondary">Put them on this plant</button>
    </form>
  </details>
  <p class="help small">
    Or, standing at the plant: scan any free tag and pick this plant from the list.
  </p>
<?php elseif ($stakes === 0): ?>
  <p class="muted small">
    A stake is a tag with a code on it; scan it and you get this plant's logging screen.
    There are no free codes to put on one &mdash;
    <a href="<?= $e($app->url('tags/print')) ?>">print a sheet</a> and come back here.
  </p>
<?php endif; ?>
</section>

<?php /* Lineage: a LINK, never a merged timeline. The child's history before
       the split is the parent's, and walking the ancestor chain on every
       plant page would cost a statement per generation and break the
       statement-count assertions in 11_reports_test.php. It is also more
       honest: those events happened to the tray, not to these six
       (PLANTING-SPLIT-SPEC Section 4.6). */ ?>
<?php if ($lineage['parent'] !== null || $lineage['children'] !== []): ?>
<section class="card">
  <h2>Where these came from, and where they went</h2>
<?php if ($lineage['parent'] !== null): $parent = $lineage['parent']; ?>
  <p>
    Split from
    <a href="<?= $e($app->url('plants/' . $parent['id'])) ?>"><?= $e($parent['category']) ?>
      <?= $e($parent['type']) ?></a><?php if (!empty($parent['label'])): ?>
    (<?= $e($parent['label']) ?>)<?php endif; ?>
<?php if ($parent['moved_on'] !== null): ?>
    on <?= $e($U::longDate((string) $parent['moved_on'])) ?><?php endif; ?>.
    Everything before then is on that page.
  </p>
<?php endif; ?>
<?php if ($lineage['children'] !== []): ?>
  <p class="muted small">Plants moved out of this one. Their history up to the move is on this page.</p>
  <ul class="list">
<?php foreach ($lineage['children'] as $child): $place = $placeOf($child); ?>
    <li>
      <a class="grow" href="<?= $e($app->url('plants/' . $child['id'])) ?>">
        <span class="name"><?= $e($child['quantity_initial']) ?> moved out<?php
          if ($child['moved_on'] !== null): ?>
          on <?= $e($U::longDate((string) $child['moved_on'])) ?><?php endif; ?></span><br>
        <span class="meta">
          <span class="badge<?= (string) $child['state'] === $S::ENDED ? ' badge-muted' : '' ?>">
            <?= $e($S::label((string) $child['state'])) ?></span>
          <?= $e((int) $child['quantity_live']) ?> living<?php if ($place !== ''): ?>
          &middot; <?= $e($place) ?><?php endif; ?>
        </span>
      </a>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($yield['events'] > 0): ?>
<section class="card">
  <h2>Yield</h2>
  <p>
<?php if ($yield['weight_g'] > 0): ?>
    <strong><?= $e($units->weight($yield['weight_g'])) ?></strong>
<?php endif; ?>
<?php if ($yield['count_qty'] > 0): ?>
    <strong><?= $e($yield['count_qty']) ?></strong> picked
<?php endif; ?>
    over <?= $e($yield['events']) ?> harvest<?= $yield['events'] === 1 ? '' : 's' ?>,
    <?= $e($U::longDate($yield['first'])) ?> to <?= $e($U::longDate($yield['last'])) ?>.
  </p>
</section>
<?php endif; ?>

<?= $view->partial('plants/research_card', ['card' => $card, 'hasRegion' => $user->hasRegion()]) ?>

<?php
$range = $series['range'];
$totals = $series['totals'];
$hasWeather = $series['days'] !== [];
/* Something to chart is not the same as weather to chart, from Phase 13. A
   plant measured this morning has numbers of its own and no weather row yet
   -- the archive's last day is yesterday (Series::coveredRange) -- and its
   growth curve is a chart. The weather TOTALS below still need weather, and
   still say so. */
$hasChart = ($series['plant']['dates'] ?? []) !== [];
?>
<?php if ($hasChart || $range['days_missing'] > 0): ?>
<section class="card">
  <h2>How it did, and the weather it did it in</h2>
<?php if ($range['days_missing'] > 0): ?>
  <p class="notice notice-info small">
    <?= $e($range['days_missing']) ?> day<?= $range['days_missing'] === 1 ? '' : 's' ?> in this range have not been
    fetched yet. The nightly sync fills gaps working backwards; they will appear here on their own.
  </p>
<?php endif; ?>
<?php if ($range['clamped']): ?>
  <p class="notice notice-info small">
    This plant has been in the ground longer than a chart can usefully show, so the
    last <?= $e($range['max_days']) ?> days are drawn:
    <?= $e($U::longDate($range['from'])) ?> onwards.
  </p>
<?php endif; ?>
<?php if ($hasChart): ?>
  <?= $view->partial('partials/charts', [
        'seriesUrl' => $seriesUrl,
        'pdfUrl'    => $pdfUrl,
        'range'     => $range,
        'csrf'      => $csrf,
      ]) ?>
<?php endif; ?>
<?php if ($hasWeather): ?>
  <?php /* Under the chart from Phase 13, not over it. The totals are the
         no-JavaScript version of the weather panels and they are still true;
         what they are not is the first thing to read about a plant, which is
         how the plant did. */ ?>
  <h3>Weather while it was in the ground</h3>
  <table class="data">
    <tbody>
      <tr><th>Days covered</th><td><?= $e($range['days_held']) ?></td></tr>
      <tr><th>Total rain</th><td><?= $e($totals['rain']) ?></td></tr>
      <tr><th>Total ET&#8320;</th><td><?= $e($totals['et0']) ?></td></tr>
      <tr><th>Water balance</th><td><?= $e($totals['balance']) ?>
        <span class="muted small">rain minus evapotranspiration</span></td></tr>
      <tr><th>Hottest / coldest</th><td><?= $e($totals['temp_range']) ?></td></tr>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Timeline</h2>
<?php if ($events === []): ?>
  <p class="muted">Nothing logged yet.</p>
<?php else: ?>
  <?= $view->partial('plants/timeline', ['events' => $events, 'photos' => $photos]) ?>
<?php endif; ?>
</section>

<?php if ($photos !== []): ?>
<section class="card">
  <h2>Photos</h2>
  <div class="photos">
<?php foreach ($photos as $photo): ?>
    <a href="<?= $e($app->url('photos/' . $photo['id'])) ?>"
       title="<?= $e($U::longDate((string) $photo['taken_on'])) ?>">
      <img src="<?= $e($app->url('photos/' . $photo['id'] . '/thumb')) ?>" alt="" loading="lazy">
    </a>
<?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* Scripts last, as everywhere else. The vendored library first:
       charts.js checks for window.Chart and does nothing without it, and
       both are deferred so the order here is the execution order. */ ?>
<?php if ($hasChart): ?>
<script src="<?= $e($app->asset('assets/vendor/chart.umd.js')) ?>" defer></script>
<script src="<?= $e($app->asset('assets/js/charts.js')) ?>" defer></script>
<?php endif; ?>
<?php /* For the stake grid's "tick the next N" and its count. Every
       behaviour in it is an enhancement; the grid posts without it. */ ?>
<script src="<?= $e($app->asset('assets/js/forms.js')) ?>" defer></script>
