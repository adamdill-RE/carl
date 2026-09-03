/*
 * Photos, large, without leaving the page (Phase 17).
 *
 * A thumbnail is a link to the photo's own page (/photos/{id}/view), which
 * has the picture, the way back and previous/next -- and that page is the
 * whole answer where this script does not run. Where it does, a tap on a
 * thumbnail opens the same set in a <dialog> instead: full screen, swipe or
 * arrow keys to move along it, a tap outside, Escape or the close button to
 * leave it, and the focus handed back to the thumbnail that opened it.
 *
 * Why not just the page? Because on a phone every photo would be a page
 * load, and because the reason this exists at all is the home-screen app on
 * an iPhone, where Safari strips its own chrome: a raw image there was a
 * screen with no way out (Phase 17). A dialog that closes into the page it
 * came from can never strand anybody.
 *
 * On the photo page itself, the same script gives swipe and arrow keys to
 * the previous/next links.
 */
(function () {
  'use strict';

  var galleries = document.querySelectorAll('.photos');
  var viewer = document.querySelector('.photo-view');
  if (!galleries.length && !viewer) { return; }

  /* A horizontal swipe of at least 50px that is more across than down. */
  function swipe(element, onLeft, onRight) {
    var startX = null, startY = null;
    element.addEventListener('touchstart', function (event) {
      var touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
    }, { passive: true });
    element.addEventListener('touchend', function (event) {
      if (startX === null) { return; }
      var touch = event.changedTouches[0];
      var dx = touch.clientX - startX, dy = touch.clientY - startY;
      startX = startY = null;
      if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx)) { return; }
      if (dx < 0) { onLeft(); } else { onRight(); }
    }, { passive: true });
  }

  // -- The photo page: swipe and arrow keys follow its own links ----------
  if (viewer) {
    var prevUrl = viewer.getAttribute('data-prev');
    var nextUrl = viewer.getAttribute('data-next');
    var go = function (url) { if (url) { window.location.href = url; } };
    document.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { go(prevUrl); }
      if (event.key === 'ArrowRight') { go(nextUrl); }
    });
    swipe(viewer, function () { go(nextUrl); }, function () { go(prevUrl); });
  }

  // -- The in-page viewer ---------------------------------------------------
  if (!galleries.length
      || typeof HTMLDialogElement === 'undefined'
      || !HTMLDialogElement.prototype.showModal) {
    return;
  }

  var dialog = null, image = null, caption = null, counter = null;
  var prevButton = null, nextButton = null, closeButton = null;
  var list = [], index = 0, opener = null;

  function button(className, text, label) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = className;
    b.textContent = text;
    b.setAttribute('aria-label', label);
    return b;
  }

  function build() {
    dialog = document.createElement('dialog');
    dialog.className = 'lightbox';
    dialog.setAttribute('aria-label', 'Photo');

    var figure = document.createElement('figure');
    image = document.createElement('img');
    image.alt = '';
    caption = document.createElement('figcaption');
    figure.appendChild(image);
    figure.appendChild(caption);

    counter = document.createElement('p');
    counter.className = 'lightbox-count';

    closeButton = button('lightbox-close', '×', 'Close');
    prevButton = button('lightbox-prev', '‹', 'Previous photo');
    nextButton = button('lightbox-next', '›', 'Next photo');

    dialog.appendChild(figure);
    dialog.appendChild(counter);
    dialog.appendChild(prevButton);
    dialog.appendChild(nextButton);
    dialog.appendChild(closeButton);
    document.body.appendChild(dialog);

    closeButton.addEventListener('click', function () { dialog.close(); });
    prevButton.addEventListener('click', function () { show(index - 1); });
    nextButton.addEventListener('click', function () { show(index + 1); });
    // A tap on the backdrop is a tap on the dialog element itself; a tap on
    // the picture or a button is not.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || event.target === image.parentNode) { dialog.close(); }
    });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { show(index - 1); event.preventDefault(); }
      if (event.key === 'ArrowRight') { show(index + 1); event.preventDefault(); }
    });
    dialog.addEventListener('close', function () {
      image.removeAttribute('src');
      if (opener) { opener.focus(); }
    });
    swipe(dialog, function () { show(index + 1); }, function () { show(index - 1); });
  }

  function show(i) {
    if (i < 0 || i >= list.length) { return; }
    index = i;
    image.src = list[i].full;
    caption.textContent = list[i].caption;
    counter.textContent = (i + 1) + ' of ' + list.length;
    prevButton.hidden = i === 0;
    nextButton.hidden = i === list.length - 1;
    // Warm the neighbours, so a swipe does not wait on the network.
    [i - 1, i + 1].forEach(function (j) {
      if (list[j]) { (new Image()).src = list[j].full; }
    });
  }

  Array.prototype.forEach.call(galleries, function (gallery) {
    var anchors = gallery.querySelectorAll('a[data-full]');
    if (!anchors.length) { return; }
    var items = Array.prototype.map.call(anchors, function (a) {
      return { full: a.getAttribute('data-full'), caption: a.getAttribute('data-caption') || '' };
    });
    Array.prototype.forEach.call(anchors, function (a, i) {
      a.addEventListener('click', function (event) {
        // A modified click means a new tab; let the link do that.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) { return; }
        event.preventDefault();
        if (!dialog) { build(); }
        list = items;
        opener = a;
        show(i);
        dialog.showModal();
        closeButton.focus();
      });
    });
  });
}());
