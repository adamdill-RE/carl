<?php
/**
 * Photo attachment (handoff Section 10).
 *
 * TWO INPUTS, ONE PIPELINE. `capture="environment"` is not a hint that a
 * camera exists -- it is an instruction to skip the file picker and open the
 * camera. An input carrying it therefore offers the camera INSTEAD of the
 * camera roll on a phone, never both, and one input cannot be made to offer
 * both whatever else is spelled on it. So the roll and the camera are
 * separate controls feeding the same uploader:
 *
 *   - the roll input is `multiple` and carries NO `capture`
 *   - the camera input carries `capture` and is deliberately NOT `multiple`,
 *     because a capture returns one photo and the two attributes pull against
 *     each other in every browser that honours them at all
 *
 * That is the whole of the change: the field can now be filled a plant at a
 * time, at the plant, instead of by remembering the order a morning's photos
 * were taken in. `tests/check_photo_capture.php` pins the split, because
 * putting `capture` back on the roll input takes the camera roll away on
 * Android and reports nothing anywhere.
 *
 * The labels are the visible controls and the inputs are `.sr-only` rather
 * than `display: none`, so both stay focusable and stay in the accessibility
 * tree; `carl.css` borrows the focus ring onto the label, because the input
 * wearing it is a pixel wide and off the page.
 *
 * One photo per XHR with progress, resized on the phone before it is sent, so
 * the form never posts a photo alongside the other fields -- which is also
 * what keeps it away from post_max_size and max_input_vars (hosting 4).
 *
 * NOTE ON JAVASCRIPT. The forms including this partial carry no `enctype`,
 * and their controllers read `photo_ids[]` rather than `$_FILES`, so these
 * inputs are the JavaScript path and only that. Without it no photo is
 * attached -- which has been true since Phase 2 and is recorded here because
 * this docblock used to claim the opposite.
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
  <span class="photo-legend">Photos</span>
  <div class="photo-actions">
    <input type="file" id="photo-input" name="photo" accept="image/*" multiple class="sr-only">
    <label class="btn btn-secondary" for="photo-input">Choose photos</label>

    <input type="file" id="photo-camera" name="photo_camera" accept="image/*" capture="environment" class="sr-only">
    <label class="btn btn-secondary" for="photo-camera">Take a photo</label>
  </div>
  <p class="help">
    Take one now, or choose photos already on your phone. They are shrunk
    before sending, so they stay under the 2 MB this server accepts, and they
    upload as you pick them.
  </p>
  <div class="upload-progress hidden"><span></span></div>
  <div class="photos uploaded"></div>
  <div class="photo-ids"></div>
  <p class="upload-error notice notice-error hidden"></p>
</div>
