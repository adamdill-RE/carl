/*
 * The menu drawer under the top bar's Menu pill (Phase 15).
 *
 * The drawer is a <details> element, so it opens and closes with no script
 * at all; everything here is manners. A disclosure that stays open after a
 * tap on the page behind it, or after Escape, reads as stuck -- so a tap
 * anywhere outside it closes it, and Escape closes it and hands focus back
 * to the pill that opened it.
 */
(function () {
  'use strict';

  var drawer = document.querySelector('.nav-drawer');
  if (!drawer) { return; }

  document.addEventListener('click', function (event) {
    if (drawer.open && !drawer.contains(event.target)) { drawer.open = false; }
  });

  // A keyboard that tabs out of the panel leaves it open behind it (Phase
  // 16 handoff Section 3.5): focusout fires before the next element takes
  // focus, so the check waits a tick and closes if focus landed outside.
  drawer.addEventListener('focusout', function () {
    setTimeout(function () {
      if (drawer.open && !drawer.contains(document.activeElement)) { drawer.open = false; }
    }, 0);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !drawer.open) { return; }
    drawer.open = false;
    var pill = drawer.querySelector('summary');
    if (pill) { pill.focus(); }
  });
}());
