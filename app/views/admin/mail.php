<?php
/**
 * Admin: mail health and the test send (handoff Section 12.1 step 7).
 *
 * Nothing on this page sends. It queues, and shows the outbox so the outcome
 * can be read off it after the drain has run -- no third-party call on the
 * request path (Phase 3 handoff Section 5).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view
 * @var string $driver @var string $description @var bool $configured
 * @var array{queued:int,sent:int,failed:int,oldest_queued:?string} $health
 * @var list<array<string,mixed>> $recent
 * @var array<string,mixed>|null $lastRun
 * @var string $fromEmail @var string $toEmail
 * @var string $localConfigPath
 */
$e = $view->e(...);
$pageTitle = 'Mail';
?>
<h1 class="page-title">Mail</h1>
<p class="page-sub"><a href="<?= $e($app->url('admin')) ?>">Admin</a></p>

<section class="card">
  <h2>Driver</h2>
  <table class="data">
    <tbody>
      <tr><th>Configured</th><td><code><?= $e($driver) ?></code></td></tr>
      <tr><th>Resolves to</th><td class="small"><?= $e($description) ?></td></tr>
      <tr><th>From</th><td><?= $e($fromEmail) ?></td></tr>
    </tbody>
  </table>

<?php if (!$configured): ?>
  <div class="notice notice-info gap-md">
    <p class="flush"><strong>No driver yet, and that is the expected state.</strong></p>
    <p class="small">
      Messages are still queued and nothing is lost. The moment the file below
      carries SMTP credentials or a Brevo key, the backlog goes out on the next
      drain. Until then the temporary password for a new account is shown on
      screen, which is the path that has always worked.
    </p>
    <p class="small">
      This is the one file that decides it, and it is the only one &mdash; the
      git checkout has a <code>config/</code> directory of its own that the
      running application never reads:
    </p>
    <p class="small"><code><?= $e($localConfigPath) ?></code></p>
    <p class="small">
      The steps are handoff &sect;12.1, written out in <code>docs/deploy.md</code>
      &sect;7.5: create <code>carl@reshiftmanager.com</code> in cPanel Email
      Accounts, install SPF and DKIM under Email Deliverability, edit the DMARC
      TXT record in Zone Editor, then add the <code>mail</code> block to that
      file.
    </p>
  </div>
<?php endif; ?>
</section>

<section class="card">
  <h2>Outbox</h2>
  <table class="data">
    <tbody>
      <tr><th>Queued</th><td><?= $e($health['queued']) ?></td></tr>
      <tr><th>Sent</th><td><?= $e($health['sent']) ?></td></tr>
      <tr><th>Failed</th><td><?= $e($health['failed']) ?></td></tr>
<?php if ($health['oldest_queued'] !== null): ?>
      <tr><th>Oldest waiting</th><td class="small"><?= $e($health['oldest_queued']) ?> UTC</td></tr>
<?php endif; ?>
<?php if ($lastRun !== null): ?>
      <tr><th>Last drain</th><td class="small">
        <?= $e($lastRun['started_at']) ?> UTC &middot; <?= $e($lastRun['outcome']) ?>
        &middot; <?= $e($lastRun['sent']) ?> sent, <?= $e($lastRun['failed']) ?> failed
      </td></tr>
<?php else: ?>
      <tr><th>Last drain</th><td class="small muted">never -- is the cron entry in place?</td></tr>
<?php endif; ?>
    </tbody>
  </table>

  <form method="post" action="<?= $e($app->url('admin/mail-test')) ?>" class="gap-md">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn">Queue a test to <?= $e($toEmail) ?></button>
    <p class="help">
      It is sent by the next drain, not by this button. Reload to see the outcome, or
      run the drain now with <code>/tasks/mail-send?key=&lt;cron_key&gt;</code>.
    </p>
  </form>
</section>

<?php if ($recent !== []): ?>
<section class="card">
  <h2>Recent messages</h2>
  <table class="data">
    <thead><tr><th>#</th><th>To</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($recent as $row): ?>
      <tr>
        <td class="nowrap"><?= $e($row['id']) ?>
          <br><span class="muted tiny"><?= $e($row['kind']) ?></span></td>
        <td class="small"><?= $e($row['to_email']) ?>
          <br><span class="muted tiny"><?= $e($row['subject']) ?></span></td>
        <td class="small">
          <?= $e($row['status']) ?>
<?php if ((int) $row['attempts'] > 0): ?>
          <span class="muted tiny">(<?= $e($row['attempts']) ?> attempt<?= (int) $row['attempts'] === 1 ? '' : 's' ?><?php
            if (!empty($row['driver'])): ?>, <?= $e($row['driver']) ?><?php endif; ?>)</span>
<?php endif; ?>
<?php if (!empty($row['last_error'])): ?>
          <br><span class="tiny"><?= $e($row['last_error']) ?></span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
