/*
 * "Notify this phone" (Phase 16).
 *
 * The page works without this: a timer is a row and the fallback is email.
 * What this adds is the subscription -- the browser's push endpoint and its
 * two keys, posted to Carl as a form so the CSRF token rides along like any
 * other POST. Two ways of subscribing, tried in order:
 *
 *   1. window.pushManager: declarative Web Push (Safari 18.4 / iOS 18.4+),
 *      no service worker needed; the push body carries the notification.
 *   2. A service worker: sw.js registered here, on this tap and never
 *      before it, and its pushManager. Chrome, Firefox, older Safari.
 *
 * Every step is optional and every failure is a sentence on the page,
 * because "nothing happened" is the failure a person cannot act on.
 */
(function () {
  'use strict';

  var root = document.querySelector('.push-setup');
  if (!root || !root.dataset.key) { return; }

  var status = root.querySelector('.push-status');
  var controls = root.querySelector('.push-controls');
  var enable = root.querySelector('.push-enable');
  var disable = root.querySelector('.push-disable');

  var supported = ('Notification' in window)
    && (('pushManager' in window) || ('serviceWorker' in navigator && 'PushManager' in window));
  if (!supported) {
    say('This browser cannot receive push notifications, so Carl will email you instead.');
    return;
  }
  controls.hidden = false;

  function say(text) { if (status) { status.textContent = text; } }

  function toBytes(base64url) {
    var padded = (base64url + '==='.slice((base64url.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(padded);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }

  function fromBytes(buffer) {
    var bytes = new Uint8Array(buffer), s = '';
    for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function manager() {
    if (window.pushManager) { return Promise.resolve(window.pushManager); }
    return navigator.serviceWorker.register(root.dataset.sw)
      .then(function () { return navigator.serviceWorker.ready; })
      .then(function (registration) { return registration.pushManager; });
  }

  function post(url, fields) {
    var body = new URLSearchParams(fields);
    body.append('_csrf', root.dataset.csrf);
    return fetch(url, {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (!response.ok) { throw new Error('Carl answered ' + response.status); }
      return response.json();
    });
  }

  function show(subscription) {
    if (subscription) {
      say('This phone will be told when a timer finishes.');
      enable.hidden = true; disable.hidden = false;
    } else {
      var count = parseInt(status && status.dataset.count || '0', 10);
      say(count > 0 ? count + (count === 1 ? ' phone' : ' phones') + ' will be told, not yet this one.'
                    : 'Carl will email you unless a phone asks to be told.');
      enable.hidden = false; disable.hidden = true;
    }
  }

  // What this browser already holds, without asking for anything.
  manager().then(function (pm) { return pm.getSubscription(); })
    .then(show)
    .catch(function () { show(null); });

  enable.addEventListener('click', function () {
    enable.disabled = true;
    say('Asking...');
    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') {
        throw new Error('Notifications were not allowed. On an iPhone this needs the home-screen app.');
      }
      return manager();
    }).then(function (pm) {
      return pm.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(root.dataset.key) });
    }).then(function (subscription) {
      return post(root.dataset.subscribe, {
        endpoint: subscription.endpoint,
        p256dh: fromBytes(subscription.getKey('p256dh')),
        auth: fromBytes(subscription.getKey('auth'))
      }).then(function () { show(subscription); });
    }).catch(function (error) {
      say(error && error.message ? error.message : 'That did not work.');
      show(null);
    }).then(function () { enable.disabled = false; });
  });

  disable.addEventListener('click', function () {
    disable.disabled = true;
    manager().then(function (pm) { return pm.getSubscription(); }).then(function (subscription) {
      if (!subscription) { return null; }
      var endpoint = subscription.endpoint;
      return subscription.unsubscribe().then(function () {
        return post(root.dataset.unsubscribe, { endpoint: endpoint });
      });
    }).then(function () { show(null); })
      .catch(function (error) { say(error && error.message ? error.message : 'That did not work.'); })
      .then(function () { disable.disabled = false; });
  });
}());
