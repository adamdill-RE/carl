<?php
/**
 * Regions needing research (handoff Section 9.4). This is the queue the owner
 * brings to Claude to produce the next dataset.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $queue
 * @var list<array<string,mixed>> $regions
 * @var list<array<string,mixed>> $zipsNeedingCounty
 */
$e = $view->e(...);
$pageTitle = 'Regions needing research';
?>
<h1 class="page-title">Regions needing research</h1>
<p class="page-sub"><a href="<?= $e($app->url('admin')) ?>">Admin</a></p>

<section class="card">
  <h2>The queue</h2>
<?php if ($queue === []): ?>
  <p class="muted">Every user's county has researched data. Nothing to do.</p>
<?php else: ?>
  <p class="small">
    Ask Claude to research each county below, naming the region key, and upload the
    zip it produces at <a href="<?= $e($app->url('admin/research-import')) ?>">Research import</a>.
  </p>
  <table class="data">
    <thead><tr><th>Region key</th><th>County</th><th>Users</th><th>First seen</th></tr></thead>
    <tbody>
<?php foreach ($queue as $row): ?>
      <tr>
        <td><code>US-<?= $e($row['county_fips']) ?></code></td>
        <td>
          <?= $e($row['county_name'] ?? $row['region_label'] ?? 'unknown') ?>
          <?php if (!empty($row['state'])): ?>, <?= $e($row['state']) ?><?php endif; ?>
          <br><span class="muted small">ZIP <?= $e($row['sample_zip'] ?? '--') ?>
<?php if (!empty($row['research_status'])): ?>
            &middot; <?= $e($row['research_status']) ?>
<?php endif; ?>
          </span>
        </td>
        <td><?= $e($row['user_count']) ?></td>
        <td class="small muted"><?= $e($row['first_seen_at']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<?php if ($zipsNeedingCounty !== []): ?>
<section class="card">
  <h2>ZIP codes with no county</h2>
  <p class="small">
    These were resolved through the Zippopotam fallback because the Census table did
    not carry them, so Carl has coordinates but no county and cannot pick a region.
  </p>
  <table class="data">
    <thead><tr><th>ZIP</th><th>Place</th><th>Coordinates</th></tr></thead>
    <tbody>
<?php foreach ($zipsNeedingCounty as $zip): ?>
      <tr>
        <td><?= $e($zip['zip']) ?></td>
        <td><?= $e($zip['place_name'] ?? '--') ?><?php if (!empty($zip['state'])): ?>, <?= $e($zip['state']) ?><?php endif; ?></td>
        <td class="small muted"><?= $e($zip['latitude']) ?>, <?= $e($zip['longitude']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="card">
  <h2>All regions</h2>
  <table class="data">
    <thead><tr><th>Key</th><th>Label</th><th>Status</th><th>Version</th><th>Users</th></tr></thead>
    <tbody>
<?php foreach ($regions as $region): ?>
      <tr>
        <td><code><?= $e($region['region_key']) ?></code></td>
        <td><?= $e($region['label']) ?></td>
        <td>
          <span class="badge<?= $region['research_status'] === 'researched' ? '' : ' badge-muted' ?>">
            <?= $e($region['research_status']) ?></span>
        </td>
        <td class="small muted"><?= $e($region['dataset_version'] ?? '--') ?></td>
        <td><?= $e($region['user_count']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
