/*
 * Confirm the ZIP code during onboarding before the form is submitted.
 * Purely a convenience: the POST resolves it again server-side and does not
 * trust anything this file learned.
 */
(function () {
  'use strict';

  var zip = document.getElementById('zip');
  var help = document.getElementById('zip-help');
  if (!zip || !help) { return; }

  var basePath = document.currentScript
    ? document.currentScript.src.replace(/assets\/js\/[^/]*$/, '')
    : '/';

  var original = help.textContent;

  zip.addEventListener('blur', function () {
    var value = zip.value.trim();
    if (!/^\d{5}(-\d{4})?$/.test(value)) { help.textContent = original; return; }

    var field = document.querySelector('input[name="_csrf"]');
    var body = new URLSearchParams();
    body.set('_csrf', field ? field.value : '');
    body.set('zip', value);

    help.textContent = 'Looking that up...';

    fetch(basePath + 'onboarding/zip', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.found) { help.textContent = data.message || 'That ZIP was not recognised.'; return; }
        help.textContent = data.place + ' (' + data.timezone + ')'
          + (data.region ? '' : ' -- no research loaded for this county yet.');
      })
      .catch(function () { help.textContent = original; });
  });
}());
