<?php
/**
 * Print a sheet of blank tags (docs/QR-TAGS-SPEC.md Section 5.1).
 *
 * THE FORM ASKS HOW MANY SHEETS, NOT HOW MANY TAGS, and there is no
 * start-at-position control on it at all. Blank tags are never printed to
 * demand: you print a sheet, the codes go in a box, and you take one out
 * whenever a plant needs a tag. The pre-printed pool exists precisely so the
 * common job has no count to choose -- and it means the physical sheet is its
 * own state, because you can see which positions are empty. Partial sheets
 * happen on the NAMED label path and nowhere else.
 *
 * @var Carl\Core\App $app @var Carl\Core\View $view @var string $csrf
 * @var string $stock @var array<string,string> $stocks
 * @var array{total:int,bound:int,free:int,retired:int} $pool
 * @var array{uppercase:bool,sample:string,mode:string,version:int,size:int,module_mm:float,headroom:int} $encoding
 */
$e = $view->e(...);
$LS = Carl\Domain\LabelStock::class;
$pageTitle = 'Print tags';
?>
<h1 class="page-title">Print a sheet of tags</h1>
<p class="page-sub">
  Blank codes, printed whole sheets at a time. They are not for any plant yet &mdash;
  a tag becomes a plant's when you scan it.
</p>

<?php /* Section 5.7: this is where scaling actually happens, so it is said in
        words next to the button rather than in a help page. The calibration
        rule on the sheet is the backstop, not the instruction. */ ?>
<div class="notice notice-warn">
  <strong>Download the PDF and print it at 100% scale.</strong>
  In the print dialog set scaling to "None", "Actual size" or 100% &mdash; never
  "Fit to page". Chrome's preview defaults to shrinking the page a few per cent to
  clear the printer's unprintable margin, which is enough to both misalign every
  label and take the code below the size it is drawn for. Every sheet carries a
  100 mm rule at the foot: measure it before you peel anything.
</div>

<section class="card">
  <form method="post" action="<?= $e($app->url('tags/batches')) ?>" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

    <label class="field">
      <span>How many sheets?</span>
      <input type="number" name="sheets" value="1" min="1" max="10" inputmode="numeric" required>
      <span class="help small">
        <?= $e($LS::perSheet($stock)) ?> tags a sheet on <?= $e($LS::name($stock)) ?>.
        You have <?= $e($pool['free']) ?> printed and free already.
      </span>
    </label>

    <label class="field">
      <span>Label stock</span>
      <select name="stock_sku">
<?php foreach ($stocks as $sku => $label): ?>
        <option value="<?= $e($sku) ?>"<?= $sku === $stock ? ' selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
      <span class="help small">
        <?= $e($LS::printer($stock)) ?>. <?= $e($LS::note($stock)) ?>
      </span>
    </label>

    <?php /* A per-print override, not a settings change (Section 5.3): trying
            the other stock for one sheet should not mean changing a preference
            and changing it back. */ ?>
    <label class="check">
      <input type="checkbox" name="remember_stock" value="1">
      <span>Remember this stock for next time</span>
    </label>

    <button type="submit" class="btn">Mint the codes</button>
    <p class="help small">
      Minting writes the codes; the sheet is a plain download afterwards, so a paper
      jam costs you a sheet of paper and not the codes.
    </p>
  </form>
</section>

<section class="card">
  <h2>Before the first sheet on real stock</h2>
  <p class="muted small">
    Mint one sheet, then print its <strong>registration test</strong> on plain paper and
    hold it against a real label sheet up to a window. Every outline should sit on a
    label. That is what proves the layout for a stock &mdash; some of the sheet geometry is
    derived rather than published by Avery, and this is a sheet of plain paper against
    a wasted sheet of polyester.
  </p>
  <p class="muted small">
    Two other things worth doing once: wipe each stake with isopropyl alcohol before
    applying, because PVC carries a mould-release film from manufacture and adhesive on
    it lifts within a month; and burnish the label edges hard with a thumbnail, because
    a label flush to the edge of a stake is the classic peel-start.
  </p>
</section>

<?= $view->partial('tags/encoding', ['encoding' => $encoding]) ?>
