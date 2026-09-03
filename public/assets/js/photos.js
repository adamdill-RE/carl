/*
 * Photo upload (handoff Section 10).
 *
 * One photo per XHR with progress, resized on the phone first, so a form
 * never posts a photo alongside its other fields -- which is also what keeps
 * it clear of post_max_size and max_input_vars (hosting Section 4).
 *
 * XMLHttpRequest rather than fetch, because fetch has no upload progress and
 * a slow phone upload with no feedback reads as a hang.
 *
 * The partial ships TWO file inputs -- the camera roll and the camera itself,
 * because `capture` replaces the picker rather than adding to it -- and this
 * file does not care which one a photo arrived through. Everything below the
 * change handler is shared.
 */
(function () {
  'use strict';

  var holder = document.querySelector('.photo-uploader');
  if (!holder) { return; }

  var inputs = holder.querySelectorAll('input[type="file"]');
  if (!inputs.length) { return; }

  var progress = holder.querySelector('.upload-progress');
  var bar = progress ? progress.querySelector('span') : null;
  var gallery = holder.querySelector('.photos.uploaded');
  var idHolder = holder.querySelector('.photo-ids');
  var errorBox = holder.querySelector('.upload-error');

  var endpoint = holder.getAttribute('data-endpoint');
  var csrf = holder.getAttribute('data-csrf');
  var plantingId = holder.getAttribute('data-planting-id');
  var gardenId = holder.getAttribute('data-garden-id');

  var basePath = document.currentScript
    ? document.currentScript.src.replace(/assets\/js\/[^/]*$/, '')
    : '/';

  var worker = null;
  var pending = {};
  var nextId = 1;

  try {
    worker = new Worker(basePath + 'assets/js/resize-worker.js');
    worker.onmessage = function (event) {
      var job = pending[event.data.id];
      if (!job) { return; }
      delete pending[event.data.id];
      if (event.data.ok) {
        send(event.data.blob, job.name);
      } else {
        // The worker could not do it; fall back to the main thread, and if
        // that fails too, send the original and let the server resize.
        resizeOnMainThread(job.file, job.name);
      }
    };
    worker.onerror = function () { worker = null; };
  } catch (e) {
    worker = null;
  }

  function fail(message) {
    if (!errorBox) { return; }
    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
  }

  function clearError() {
    if (errorBox) { errorBox.classList.add('hidden'); }
  }

  function showProgress(percent) {
    if (!progress || !bar) { return; }
    progress.classList.remove('hidden');
    bar.style.width = percent + '%';
    if (percent >= 100) {
      setTimeout(function () { progress.classList.add('hidden'); bar.style.width = '0'; }, 400);
    }
  }

  /*
   * A photo straight from a camera is stored in the sensor's orientation with
   * an EXIF tag saying which way up it goes, and the default for that tag has
   * moved across spec vintages -- older browsers ignored it, newer ones honour
   * it. Asking for it by name is the only way to get the same answer from
   * both, and it matters far more now that the camera is a button: portrait is
   * how a phone is held at a plant. A browser that does not know the value
   * rejects the call rather than throwing, so the retry covers both.
   *
   * GD ignores EXIF orientation too, so nothing downstream would have fixed
   * this: a photo that arrives sideways is stored sideways.
   */
  function decode(file) {
    try {
      return createImageBitmap(file, { imageOrientation: 'from-image' })
        .catch(function () { return createImageBitmap(file); });
    } catch (e) {
      return createImageBitmap(file);
    }
  }

  function resizeOnMainThread(file, name) {
    if (typeof createImageBitmap === 'undefined') { send(file, name); return; }
    decode(file).then(function (bitmap) {
      var edge = 1920;
      var scale = Math.min(1, edge / Math.max(bitmap.width, bitmap.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) {
        send(blob || file, name);
        bitmap.close();
      }, 'image/jpeg', 0.85);
    }).catch(function () { send(file, name); });
  }

  function send(blob, name) {
    var form = new FormData();
    form.append('_csrf', csrf);
    // A capture can arrive with no name at all on some Android browsers, and
    // stripping the extension off "" leaves a file called ".jpg".
    var base = String(name || '').replace(/\.[^.]+$/, '');
    form.append('photo', blob, (base || 'photo') + '.jpg');
    if (plantingId) { form.append('planting_id', plantingId); }
    if (gardenId) { form.append('garden_id', gardenId); }

    var dateField = document.getElementById('event_date') || document.getElementById('start_date');
    if (dateField && dateField.value) { form.append('event_date', dateField.value); }

    var request = new XMLHttpRequest();
    request.open('POST', endpoint, true);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    request.upload.onprogress = function (event) {
      if (event.lengthComputable) {
        showProgress(Math.round((event.loaded / event.total) * 100));
      }
    };

    request.onload = function () {
      showProgress(100);
      var data;
      try { data = JSON.parse(request.responseText); } catch (e) { data = null; }

      if (!data || !data.ok) {
        fail(data && data.message ? data.message : 'That photo could not be uploaded.');
        return;
      }

      clearError();

      var link = document.createElement('a');
      // The viewer page, not the raw file: it has a way back, which the
      // home-screen app on an iPhone otherwise lacks (Phase 17).
      link.href = data.view || data.url;
      link.target = '_blank';
      link.rel = 'noopener';
      var image = document.createElement('img');
      image.src = data.thumb;
      image.alt = '';
      link.appendChild(image);
      if (gallery) { gallery.appendChild(link); }

      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'photo_ids[]';
      hidden.value = data.id;
      if (idHolder) { idHolder.appendChild(hidden); }
    };

    request.onerror = function () {
      showProgress(100);
      fail('The upload failed. Check your signal and try again.');
    };

    request.send(form);
  }

  function accept(input) {
    clearError();
    var files = Array.prototype.slice.call(input.files || []);

    files.forEach(function (file) {
      // A capture sometimes arrives with an empty type, and the server is the
      // one that actually decides what an image is -- getimagesize plus a mime
      // whitelist. So only a type that is present AND not an image is refused
      // here; an absent one is passed on and answered for properly.
      if (file.type && !/^image\//.test(file.type)) {
        fail('Only images can be attached.');
        return;
      }
      if (worker) {
        var id = nextId++;
        pending[id] = { file: file, name: file.name };
        worker.postMessage({ id: id, file: file, longEdge: 1920, maxBytes: 1500000 });
      } else {
        resizeOnMainThread(file, file.name);
      }
    });

    // The same photo can be picked twice in a row otherwise.
    input.value = '';
  }

  Array.prototype.forEach.call(inputs, function (input) {
    input.addEventListener('change', function () { accept(input); });
  });

  /*
   * Phones and tablets act on `capture`; everything else ignores it, and there
   * the camera button would open the very same file dialog as the button
   * beside it -- two controls doing one thing, which reads as a bug. A coarse
   * pointer is the closest honest test for "this device will act on capture",
   * and it is deliberately a cheap one: a touchscreen laptop keeps a button it
   * does not need, which costs nothing, where the reverse would take the
   * camera away from a phone.
   *
   * The control is removed here rather than hidden in CSS so that a device
   * without JavaScript -- where neither input does anything anyway -- is not
   * the case that decides the markup.
   */
  var camera = holder.querySelector('input[capture]');
  if (camera) {
    var coarse = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
      || navigator.maxTouchPoints > 0;
    if (!coarse) {
      var label = camera.id ? holder.querySelector('label[for="' + camera.id + '"]') : null;
      if (label) { label.classList.add('hidden'); }
      camera.classList.add('hidden');
    }
  }
}());
