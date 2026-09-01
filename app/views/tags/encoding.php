<?php
/**
 * What is actually encoded on a tag, shown rather than assumed.
 *
 * docs/QR-TAGS-SPEC.md Section 2.2 buys a smaller symbol with better error
 * correction by upper-casing the URL, and Carl\Qr\TagUrl explains why that is
 * only safe when the web server will answer an upper-case path. This is the
 * panel that keeps the trade-off visible: the exact URL, the mode, the
 * version and the millimetres per module on a 25.4 mm stake -- measured by
 * encoding a real code, not quoted from the spec.
 *
 * @var Carl\Core\View $view
 * @var array{uppercase:bool,sample:string,mode:string,version:int,size:int,module_mm:float,headroom:int} $encoding
 */
$e = $view->e(...);
?>
<details class="card card-tight">
  <summary>What a tag encodes</summary>
  <p class="mono small break"><?= $e($encoding['sample']) ?></p>
  <ul class="list small">
    <li><span class="grow">Mode</span><span><?= $e($encoding['mode']) ?></span></li>
    <li><span class="grow">Symbol</span>
      <span>version <?= $e($encoding['version']) ?>,
        <?= $e($encoding['size']) ?>&times;<?= $e($encoding['size']) ?>, level Q (25% damage)</span></li>
    <li><span class="grow">On a 1 in stake</span>
      <span><?= $e(\number_format($encoding['module_mm'], 3)) ?> mm per module</span></li>
    <li><span class="grow">Characters spare</span><span><?= $e($encoding['headroom']) ?></span></li>
  </ul>
<?php if (!$encoding['uppercase']): ?>
  <p class="help small">
    Lower case, so this is byte mode and one symbol version larger than it needs to be.
    An all-upper-case URL would give <?= $e(\number_format(0.649, 3)) ?> mm modules instead
    &mdash; but the mount point of the address is a real directory on the server, and Apache
    matches directory names case-sensitively, so an upper-case address is only safe where
    the server actually answers one. It is off until somebody has checked.
    <?= $e(\number_format($encoding['module_mm'], 3)) ?> mm is still more than twice ISO
    18004's practical print floor, and the thing that limits a phone here is how close it
    can focus, not how small the modules are.
  </p>
<?php endif; ?>
</details>
