<?php
/**
 * Connect Claude Code (Phase 16).
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var list<array<string,mixed>> $tokens
 * @var array{id:int,token:string,label:string}|null $fresh
 * @var string $endpoint @var int $perMinute
 */
$e = $view->e(...);
$pageTitle = 'Connect Claude Code';
$live = \array_filter($tokens, static fn (array $t): bool => $t['revoked_at'] === null);
?>
<h1 class="page-title">Connect Claude Code</h1>
<p class="page-sub">
  Let Claude Code read your garden directly &mdash; your plants, their history, the
  weather that happened, today's watering &mdash; instead of pasting an export into it.
  It reads; it never writes.
</p>

<?php if ($fresh !== null): ?>
<section class="card">
  <h2>Your new token</h2>
  <div class="notice notice-warn">
    <strong>Copy it now.</strong> This is the only time Carl will show it. If it is lost,
    revoke it below and make another.
  </div>
  <p><code class="token mono"><?= $e($fresh['token']) ?></code></p>

  <h3>In a terminal, once</h3>
  <pre class="snippet"><code>claude mcp add --transport http carl <?= $e($endpoint) ?> \
  --header "Authorization: Bearer <?= $e($fresh['token']) ?>"</code></pre>

  <h3>Or in a project's <code>.mcp.json</code></h3>
  <pre class="snippet"><code>{
  "mcpServers": {
    "carl": {
      "type": "http",
      "url": "<?= $e($endpoint) ?>",
      "headers": { "Authorization": "Bearer ${CARL_TOKEN}" }
    }
  }
}</code></pre>
  <p class="small muted">
    With <code>CARL_TOKEN</code> set in the environment, so the token itself is not in a
    file that gets committed.
  </p>
</section>
<?php endif; ?>

<section class="card">
  <h2>Make a token</h2>
  <form method="post" action="<?= $e($app->url('connect/tokens')) ?>" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field">
      <span>What is it for?</span>
      <input type="text" name="label" maxlength="60" placeholder="laptop, work machine&hellip;"
             autocomplete="off">
      <span class="help small">
        One token per machine, named, so the one you lose is the one you revoke.
      </span>
    </label>
    <button type="submit" class="btn">Make a token</button>
  </form>
</section>

<section class="card">
  <h2>Tokens</h2>
<?php if ($tokens === []): ?>
  <p class="muted small">None yet.</p>
<?php else: ?>
  <ul class="list small">
<?php foreach ($tokens as $token): $dead = $token['revoked_at'] !== null; ?>
    <li>
      <div class="grow<?= $dead ? ' muted' : '' ?>">
        <strong><?= $e($token['label']) ?></strong>
        <span class="mono tiny muted"><?= $e(\substr((string) $token['selector'], 0, 8)) ?>&hellip;</span><br>
        <span class="tiny muted">
          made <?= $e(Carl\Support\Units::shortDate((string) $token['created_at'])) ?>
          &middot;
<?php if ($dead): ?>
          revoked <?= $e(Carl\Support\Units::shortDate((string) $token['revoked_at'])) ?>
<?php elseif ($token['last_used_at'] === null): ?>
          never used
<?php else: ?>
          last used <?= $e(Carl\Support\Units::shortDate((string) $token['last_used_at'])) ?>
          &middot; <?= $e($token['calls']) ?> call<?= (int) $token['calls'] === 1 ? '' : 's' ?>
<?php endif; ?>
        </span>
      </div>
<?php if (!$dead): ?>
      <form method="post" action="<?= $e($app->url('connect/tokens/' . $token['id'] . '/revoke')) ?>" class="flush">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="btn btn-secondary btn-small">Revoke</button>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section class="card">
  <h2>What it can read</h2>
  <p class="small">
    Eight tools and one document, all read-only: your gardens and zones, your plants
    (with each one's whole timeline, yield and tags), the weather over any range, today's
    watering advice, garden-level actions, the research card for any plant type, the
    pest reference, and the same season summary Recommendations sends. Each call is
    bounded &mdash; a big answer says how to narrow it rather than arriving truncated
    &mdash; and a token is limited to <?= $e($perMinute) ?> calls a minute.
  </p>
  <p class="small muted">
    Anything a token can read, the person holding it can read. Treat it like a password:
    it goes in Claude Code's config and nowhere else.
  </p>
</section>

<p><a class="btn btn-secondary" href="<?= $e($app->url('reports')) ?>">Back to Reports</a></p>
