<?php
/**
 * The event timeline, sorted the way the state derivation sorts it.
 * A row that came from a garden action is marked as derived so a reader can
 * tell a hand-logged watering from a zone watering (handoff Section 4.7).
 *
 * A `split_out` row is the one that links out: it says how many plants left
 * this planting and points at the planting they became, so the parent's
 * history reads "6 moved out -- see them" rather than "-6 living".
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $photos
 */
$e = $view->e(...);
$E = Carl\Domain\EventType::class;
$U = Carl\Support\Units::class;

$photosByEvent = [];
foreach ($photos as $photo) {
    if ($photo['plant_event_id'] !== null) {
        $photosByEvent[(int) $photo['plant_event_id']][] = $photo;
    }
}
?>
<ul class="timeline">
<?php foreach ($events as $event):
    $derived = $event['source_garden_event_id'] !== null;
    $eventId = (int) $event['id'];
    $wentTo = ($event['split_planting_id'] ?? null) !== null ? (int) $event['split_planting_id'] : null;
?>
  <li class="<?= $derived ? 'derived' : '' ?>">
    <div class="when"><?= $e($U::longDate((string) $event['event_date'])) ?></div>
    <div class="what"><?= $e($E::label((string) $event['event_type'])) ?>
<?php if ($derived): ?>
      <span class="badge badge-muted">from a garden action</span>
<?php endif; ?>
    </div>
    <div class="small">
<?php
    $bits = [];
    if (!empty($event['ref_name'])) {
        $bits[] = (string) $event['ref_name'];
    }
    if (!empty($event['ref_name_2'])) {
        $bits[] = (string) $event['ref_name_2'];
    }
    if ($event['duration_min'] !== null) {
        $bits[] = $event['duration_min'] . ' min';
    }
    if ($event['count_qty'] !== null && $wentTo === null) {
        $bits[] = $event['count_qty'] . ' ' . ((string) $event['unit'] !== '' ? (string) $event['unit'] : 'count');
    }
    if ($event['weight_g'] !== null) {
        $bits[] = $units->weight($event['weight_g']);
    }
    // Height and diameter are separate bits, not one "14 x 8": either may
    // stand alone (migration 024), and a pair printed with a separator reads
    // as a dimension when only one of the two was measured.
    if (($event['height_mm'] ?? null) !== null) {
        $bits[] = $units->size($event['height_mm']) . ' tall';
    }
    if (($event['diameter_mm'] ?? null) !== null) {
        $bits[] = $units->size($event['diameter_mm']) . ' across';
    }
    // A dispersal says its own count on the line below, as "6 moved out".
    // Printing the delta and the count beside it would read as six more
    // plants gone and six of something else.
    if ($event['quantity_delta'] !== null && (int) $event['quantity_delta'] !== 0 && $wentTo === null) {
        $bits[] = (int) $event['quantity_delta'] . ' living';
    }
    if (!empty($event['garden_name'])) {
        $bits[] = \trim((string) $event['garden_name'] . ' ' . (string) ($event['row_name'] ?? ''));
    }
    if (!empty($event['container_name'])) {
        $bits[] = (string) $event['container_name'];
    }
    // Each part is escaped on its own and the separator is joined in raw --
    // escaping the joined string would turn the entity into &amp;middot;.
    echo \implode(' &middot; ', \array_map($e, $bits));
?>
    </div>
<?php if ($wentTo !== null): ?>
    <div class="small">
      <?= $e((int) ($event['count_qty'] ?? 0)) ?> moved out and are now tracked on their own:
      <a href="<?= $e($app->url('plants/' . $wentTo)) ?>"><?php
        $name = \trim((string) ($event['split_category'] ?? '') . ' ' . (string) ($event['split_type'] ?? ''));
        echo $e($name !== '' ? $name : 'see them');
      ?></a>
    </div>
<?php endif; ?>
<?php if (!empty($event['narrative'])): ?>
    <div class="small"><?= $e($event['narrative']) ?></div>
<?php endif; ?>
<?php if (isset($photosByEvent[$eventId])): ?>
    <div class="photos gap-xs">
<?php foreach ($photosByEvent[$eventId] as $photo): ?>
      <a href="<?= $e($app->url('photos/' . $photo['id'] . '/view')) ?>"
         data-full="<?= $e($app->url('photos/' . $photo['id'])) ?>"
         data-caption="<?= $e(\Carl\Support\Units::longDate((string) $photo['taken_on'])
             . ((string) ($photo['caption'] ?? '') !== '' ? ' -- ' . $photo['caption'] : '')) ?>">
        <img src="<?= $e($app->url('photos/' . $photo['id'] . '/thumb')) ?>" alt="" loading="lazy">
      </a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
