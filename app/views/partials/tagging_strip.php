<?php
/**
 * The tagging-session strip (docs/QR-TAGS-SPEC.md Section 6.5).
 *
 * "An expiry, an explicit stop, and a visible banner." This is the third, and
 * it is rendered from the LAYOUT so it really is on every page: a binding mode
 * that runs silently is a way to attach a tag to the wrong plant a week later
 * and never find out.
 *
 * IT COSTS NO STATEMENT ANYWHERE EXCEPT WHERE IT IS FREE. `tagging_started_at`
 * is on the user row that Auth::user() already selects on every request, so
 * every page can tell whether a session is running and when it expires for
 * nothing. The cursor -- "Next: Cherokee Purple", the counts -- is three more
 * statements, so it is shown only on the tag screens, which were making those
 * reads anyway. Everywhere else the strip says a session is running and offers
 * the stop, which is what the rule is actually for.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var Carl\Auth\User|null $user
 * @var array{next:?array<string,mixed>,bound:list<array<string,mixed>>,remaining:int}|null $session
 */
$startedAt = $user?->taggingStartedAt;
if ($startedAt === null) {
    return;
}
// The two-hour expiry, read the same way the controller reads it. An expired
// session is simply not there.
if (\strtotime($startedAt . ' UTC') + Carl\Repo\TagRepository::SESSION_HOURS * 3600 < \time()) {
    return;
}
$session = $session ?? null;
$e = $view->e(...);
$nameOf = static fn (array $p): string => \trim((string) $p['label']) !== ''
    ? (string) $p['label']
    : \trim((string) $p['category'] . ' ' . (string) $p['type']);
?>
<div class="notice notice-info tagging-strip">
  <span class="grow">
<?php if ($session === null): ?>
    <strong>Tagging.</strong> Scan a tag and it goes on the next plant that has none.
<?php elseif ($session['next'] === null): ?>
    <strong>Everything is tagged.</strong>
    <?= $e(\count($session['bound'])) ?> tagged in this session. Nothing left to do.
<?php else: ?>
    <strong>Next: <?= $e($nameOf($session['next'])) ?></strong> &mdash; scan a tag.
    <span class="muted small">
      <?= $e(\count($session['bound'])) ?> bound &middot; <?= $e($session['remaining']) ?> to go
    </span>
<?php endif; ?>
  </span>
  <form method="post" action="<?= $e($app->url('tags/session')) ?>" class="flush inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="stop">
    <button type="submit" class="btn-link">Stop tagging</button>
  </form>
</div>
