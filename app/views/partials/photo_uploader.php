<?php
/**
 * Photo attachment (handoff Section 10).
 *
 * One photo per XHR with progress, resized client-side before it is sent, so
 * the form never posts a photo alongside the other fields -- which is also
 * what keeps it away from post_max_size and max_input_vars (hosting 4).
 *
 * Without JavaScript the file input is still here and still works; it just
 * posts one photo with the form and relies on the server-side resize.
 *
 * @var Carl\Core\App $app
 * @var Carl\Core\View $view
 * @var int|null $plantingId
 * @var int|null $gardenId
 */
$e = $view->e(...);
$plantingId = $plantingId ?? null;
$gardenId = $gardenId ?? null;
?>
<div class="field photo-uploader"
     data-endpoint="<?= $e($app->url('photos')) ?>"
     data-csrf="<?= $e($csrf) ?>"
     <?= $plantingId !== null ? 'data-planting-id="' . $e($plantingId) . '"' : '' ?>
     <?= $gardenId !== null ? 'data-garden-id="' . $e($gardenId) . '"' : '' ?>>
  <label for="photo-input">Photos</label>
  <input type="file" id="photo-input" name="photo" accept="image/*" multiple capture="environment">
  <p class="help">
    Photos are shrunk on your phone before sending, so they stay under the 2 MB
    this server accepts. They upload as you pick them.
  </p>
  <div class="upload-progress hidden"><span></span></div>
  <div class="photos uploaded"></div>
  <div class="photo-ids"></div>
  <p class="upload-error notice notice-error hidden"></p>
</div>
