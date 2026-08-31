<?php
/**
 * What Recommendations has cost (Phase 6 handoff Section 3.5).
 *
 * The money on this page is an ESTIMATE and every part of it says so. The
 * token counts are facts -- the API returned them and Carl stored them per
 * row; the dollars are those counts multiplied by rates typed into
 * `config/app.php` by hand. Presenting the second as confidently as the
 * first is how an admin plans a budget around a number nobody checked.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var list<array<string,mixed>> $months
 * @var array<string,array{input:float,output:float}> $prices
 * @var string $model @var bool $configured @var string $describe
 * @var array{queued:int,done:int,failed:int,oldest_queued:?string} $health
 * @var array<string,mixed>|null $lastRun
 */
$e = $view->e(...);
$U = Carl\Support\Units::class;
$pageTitle = 'Recommendations cost';

$totalIn = 0;
$totalOut = 0;
$totalCost = 0.0;
$anyUnpriced = false;
foreach ($months as $row) {
    $totalIn += (int) $row['input_tokens'];
    $totalOut += (int) $row['output_tokens'];
    if ($row['cost'] === null) {
        $anyUnpriced = true;
    } else {
        $totalCost += (float) $row['cost'];
    }
}
$money = static fn (?float $v): string => $v === null ? '--' : '$' . \number_format($v, 2);
?>
<h1 class="page-title">Recommendations cost</h1>
<p class="page-sub">
  Every analysis is a paid API call. These are the tokens Carl actually sent and
  received, per month and per model, priced at the rates in
  <code>config/app.php</code>.
</p>

<?php if (!$configured): ?>
  <div class="notice notice-info">
    <p class="small">
      No analysis key is configured, so nothing has been sent and nothing has
      been charged. Requests queue and wait, which is a working state.
      <?= $e($describe) ?>
    </p>
  </div>
<?php endif; ?>

<section class="card">
  <h2>The queue</h2>
  <table class="data">
    <tbody>
      <tr><th>Waiting</th><td><?= $e($health['queued']) ?></td></tr>
      <tr><th>Answered</th><td><?= $e($health['done']) ?></td></tr>
      <tr><th>Failed</th><td><?= $e($health['failed']) ?></td></tr>
<?php if ($health['oldest_queued'] !== null): ?>
      <tr><th>Oldest still waiting</th><td><?= $e($health['oldest_queued']) ?> UTC</td></tr>
<?php endif; ?>
<?php if ($lastRun !== null): ?>
      <tr><th>Last drain</th><td><?= $e($lastRun['started_at']) ?> UTC
        &middot; <?= $e($lastRun['outcome'] ?? '') ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>By month</h2>
<?php if ($months === []): ?>
  <p class="small muted">Nothing has been asked for yet.</p>
<?php else: ?>
  <div class="matrix-scroll">
    <table class="matrix">
      <thead>
        <tr>
          <th>Month</th><th>Model</th><th>Runs</th><th>Failed</th>
          <th>In</th><th>Out</th><th>Document</th><th>Estimate</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($months as $row): ?>
        <tr>
          <td><?= $e($row['month']) ?></td>
          <td><?= $e($row['model']) ?></td>
          <td><?= $e(\number_format((int) $row['runs'])) ?></td>
          <td<?= (int) $row['failed'] > 0 ? ' class="confidence-approx"' : '' ?>>
            <?= $e(\number_format((int) $row['failed'])) ?></td>
          <td><?= $e(\number_format((int) $row['input_tokens'])) ?></td>
          <td><?= $e(\number_format((int) $row['output_tokens'])) ?></td>
          <td><?= $e(\number_format((int) $row['document_bytes'] / 1024, 0)) ?> KB</td>
          <td><?= $e($money($row['cost'] === null ? null : (float) $row['cost'])) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="4">Total</th>
          <th><?= $e(\number_format($totalIn)) ?></th>
          <th><?= $e(\number_format($totalOut)) ?></th>
          <th></th>
          <th><?= $e($money($totalCost)) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
<?php endif; ?>

  <p class="small muted">
    <strong>The token counts are facts</strong> &mdash; the API returned them and
    Carl stored them against each row, including the runs that failed.
    <strong>The money is an estimate</strong>: those counts multiplied by rates
    typed into <code>config/app.php</code> by hand and last checked
    2026-08-31. Nothing here fetches a price list, deliberately, and a rate
    that has moved since will be quietly wrong until somebody edits that file.
<?php if ($anyUnpriced): ?>
    Rows showing &ldquo;--&rdquo; ran on a model with no rate configured; they
    are left blank rather than counted as free.
<?php endif; ?>
  </p>
</section>

<section class="card">
  <h2>Rates in force</h2>
  <div class="matrix-scroll">
    <table class="matrix">
      <thead><tr><th>Model</th><th>Input / 1M</th><th>Output / 1M</th></tr></thead>
      <tbody>
<?php foreach ($prices as $name => $rate): ?>
        <tr<?= $name === $model ? ' class="confidence-verified"' : '' ?>>
          <td><?= $e($name) ?><?= $name === $model ? ' &mdash; in use' : '' ?></td>
          <td>$<?= $e(\number_format((float) $rate['input'], 2)) ?></td>
          <td>$<?= $e(\number_format((float) $rate['output'], 2)) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted">
    The daily cap per account and the model itself are also in
    <code>config/app.php</code>; the key is in <code>config/local.php</code> and
    is never in the repository.
  </p>
</section>

<p><a class="btn btn-secondary" href="<?= $e($app->url('admin')) ?>">Back to admin</a></p>
