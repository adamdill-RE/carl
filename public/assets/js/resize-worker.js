/*
 * Photo resize, off the main thread (handoff Section 10).
 *
 * Shipped as a real file rather than a blob: URL so it stays inside the
 * page's own script-src 'self' policy.
 *
 * Steps quality down first, then dimensions, until the encoded blob fits.
 * upload_max_filesize is 2 MB on this host, so the target is 1.5 MB with
 * room to spare for the multipart envelope.
 *
 * `imageOrientation` is asked for by name for the reason `photos.js` gives at
 * its own decode(): the default has moved across spec vintages, a photo taken
 * on the camera button is nearly always portrait, and GD will not straighten
 * one that arrives sideways.
 */
function decode(file) {
  try {
    return createImageBitmap(file, { imageOrientation: 'from-image' })
      .catch(function () { return createImageBitmap(file); });
  } catch (e) {
    return createImageBitmap(file);
  }
}

self.onmessage = function (event) {
  var file = event.data.file;
  var longEdge = event.data.longEdge || 1920;
  var maxBytes = event.data.maxBytes || 1500000;
  var id = event.data.id;

  if (typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap === 'undefined') {
    self.postMessage({ id: id, ok: false, reason: 'unsupported' });
    return;
  }

  decode(file)
    .then(function (bitmap) {
      var qualities = [0.85, 0.75, 0.65];
      var edges = [longEdge, 1600, 1280, 1024];

      function draw(edge) {
        var scale = Math.min(1, edge / Math.max(bitmap.width, bitmap.height));
        var width = Math.max(1, Math.round(bitmap.width * scale));
        var height = Math.max(1, Math.round(bitmap.height * scale));
        var canvas = new OffscreenCanvas(width, height);
        var context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0, width, height);
        return canvas;
      }

      function attempt(edgeIndex, qualityIndex) {
        if (edgeIndex >= edges.length) {
          self.postMessage({ id: id, ok: false, reason: 'too-large' });
          return;
        }
        var canvas = draw(edges[edgeIndex]);
        canvas.convertToBlob({ type: 'image/jpeg', quality: qualities[qualityIndex] })
          .then(function (blob) {
            if (blob.size <= maxBytes) {
              self.postMessage({ id: id, ok: true, blob: blob });
              bitmap.close();
              return;
            }
            if (qualityIndex + 1 < qualities.length) {
              attempt(edgeIndex, qualityIndex + 1);
            } else {
              attempt(edgeIndex + 1, 0);
            }
          })
          .catch(function () {
            self.postMessage({ id: id, ok: false, reason: 'encode-failed' });
          });
      }

      attempt(0, 0);
    })
    .catch(function () {
      self.postMessage({ id: id, ok: false, reason: 'decode-failed' });
    });
};
