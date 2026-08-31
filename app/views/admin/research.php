<?php
/**
 * The research import route (handoff Section 9.3). Upload, see the preview,
 * confirm. Nothing is written until the confirm.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var Carl\Research\ImportResult|null $result
 * @var list<array<string,mixed>> $imports
 * @var array<string,int> $counts
 */
$e = $view->e(...);
$pageTitle = 'Research import';
?>
<h1 class="page-title">Research import</h1>
<p class="page-sub">
  A dataset is one zip of up to seven CSVs, in the format of
  <code>research-template/README.md</code>. <a href="<?= $e($app->url('admin')) ?>">Admin</a>
</p>

<?php if ($result !== null): ?>
<section class="card">
  <h2>Preview: <?= $e($result->filename) ?></h2>

<?php if ($result->datasetVersion !== ''): ?>
  <p><strong>Version <?= $e($result->datasetVersion) ?></strong>
     for <?= $e(\implode(', ', $result->regionKeys)) ?></p>
<?php endif; ?>

<?php if (!$result->ok()): ?>
  <div class="notice notice-error">
    <p><strong><?= $e(\count($result->errors)) ?> problem<?= \count($result->errors) === 1 ? '' : 's' ?>.
       Nothing has been written.</strong></p>
    <ul class="errors">
<?php foreach ($result->firstErrors() as $error): ?>
      <li><?= \nl2br($e($error)) ?></li>
<?php endforeach; ?>
    </ul>
<?php if (\count($result->errors) > 20): ?>
    <p class="small">and <?= $e(\count($result->errors) - 20) ?> more.</p>
<?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($result->warnings as $warning): ?>
  <p class="notice notice-warn small"><?= $e($warning) ?></p>
<?php endforeach; ?>

  <table class="data">
    <thead><tr><th>File</th><th>Rows</th><th>New</th><th>Changed</th><th>Same</th></tr></thead>
    <tbody>
<?php foreach ($result->files as $name => $file): ?>
      <tr class="<?= $file['present'] ? '' : 'muted' ?>">
        <td><?= $e($name) ?><?= $file['present'] ? '' : ' <span class="muted small">(absent -- skipped)</span>' ?></td>
        <td><?= $e($file['rows']) ?></td>
        <td><?= $e($file['new']) ?></td>
        <td><?= $e($file['changed']) ?></td>
        <td class="muted"><?= $e($file['same']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

<?php if ($result->ok()): ?>
  <form method="post" action="<?= $e($app->url('admin/research-import/confirm')) ?>" class="gap-md">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn btn-block">
      Apply <?= $e($result->totalNew()) ?> new and <?= $e($result->totalChanged()) ?> changed rows
    </button>
    <p class="help">
      One transaction. Plantings reference plant ids, so re-importing changes the
      reference values without touching anything already logged.
    </p>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<form method="post" action="<?= $e($app->url('admin/research-import')) ?>"
      enctype="multipart/form-data" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="field">
    <label for="dataset">Dataset zip</label>
    <input type="file" id="dataset" name="dataset" accept=".zip,application/zip" required>
    <p class="help">Up to 2 MB, which is this server's upload limit.</p>
  </div>
  <button type="submit" class="btn">Validate it</button>
</form>

<section class="card">
  <h2>Import history</h2>
<?php if ($imports === []): ?>
  <p class="muted">Nothing imported yet.</p>
<?php else: ?>
  <table class="data">
    <thead><tr><th>Version</th><th>Regions</th><th>When</th><th>Rows</th></tr></thead>
    <tbody>
<?php foreach ($imports as $import):
    $rows = \json_decode((string) ($import['row_counts'] ?? '[]'), true);
    $total = \is_array($rows) ? \array_sum($rows) : 0;
?>
      <tr>
        <td><?= $e($import['dataset_version']) ?></td>
        <td class="small"><?= $e($import['region_keys']) ?></td>
        <td class="small muted"><?= $e($import['imported_at']) ?></td>
        <td><?= $e($total) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
