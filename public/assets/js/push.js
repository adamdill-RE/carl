/*
 * "Notify this phone" (Phase 16), and "Send a test notification" (Phase 17).
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
 * because "nothing happened" is the failure a person cannot act on. Phase
 * 17 added the sentences that name the failure a phone actually has: this
 * is Safari and not the home-screen app; notifications were refused once
 * and iOS remembers; and, from the test button, what the push service
 * itself answered when Carl pushed to this phone just now.
 */
(function () {
  'use strict';

  var root = document.querySelector('.push-setup');
  if (!root || !root.dataset.key) { return; }

  var status = root.querySelector('.push-status');
  var controls = root.querySelector('.push-controls');
  var enable = root.querySelector('.push-enable');
  var disable = root.querySelector('.push-disable');
  var test = root.querySelector('.push-test');
  var result = root.querySelector('.push-result');

  function say(text) { if (status) { status.textContent = text; } }
  function report(text) {
    if (!result) { return; }
    result.textContent = text;
    result.hidden = text === '';
  }

  // An iPhone, and whether this is the home-screen app or Safari. An iPad
  // asking for the desktop site calls itself a Mac, hence the touch check.
  var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = navigator.standalone === true
    || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);

  var supported = ('Notification' in window)
    && (('pushManager' in window) || ('serviceWorker' in navigator && 'PushManager' in window));
  if (!supported) {
    say(isIOS && !standalone
      ? 'This is Safari, not the home-screen app, and Safari itself cannot be told. Add Carl to '
        + 'the Home Screen (Share, then Add to Home Screen), open it from that icon, and the '
        + 'button will be here. Until then Carl will email you.'
      : 'This browser cannot receive push notifications, so Carl will email you instead.');
    return;
  }
  controls.hidden = false;

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

  function host(endpoint) {
    try { return new URL(endpoint).host; } catch (e) { return 'the push service'; }
  }

  function denied() {
    return ('Notification' in window) && Notification.permission === 'denied';
  }

  function show(subscription) {
    if (subscription) {
      say('This phone will be told when a timer finishes, through ' + host(subscription.endpoint) + '.');
      enable.hidden = true;
      disable.hidden = false;
      if (test) { test.hidden = false; }
      return;
    }
    if (denied()) {
      say('Notifications are blocked for Carl on this phone, so it cannot be told. On an iPhone: '
        + 'Settings, Notifications, Carl, Allow Notifications -- or remove Carl from the Home '
        + 'Screen, add it again, and allow when it asks.');
    } else {
      var count = parseInt(status && status.dataset.count || '0', 10);
      say(count > 0 ? count + (count === 1 ? ' phone' : ' phones') + ' will be told, not yet this one.'
                    : 'Carl will email you unless a phone asks to be told.');
    }
    enable.hidden = false;
    disable.hidden = true;
    if (test) { test.hidden = true; }
  }

  // What this browser already holds, without asking for anything.
  manager().then(function (pm) { return pm.getSubscription(); })
    .then(show)
    .catch(function () { show(null); });

  enable.addEventListener('click', function () {
    enable.disabled = true;
    report('');
    say('Asking...');
    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') {
        throw new Error(isIOS
          ? 'Notifications were not allowed. An earlier "Don\'t Allow" sticks: remove Carl from '
            + 'the Home Screen, add it again, open it from the icon, and allow when it asks.'
          : 'Notifications were not allowed by this browser.');
      }
      return manager();
    }).then(function (pm) {
      return pm.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(root.dataset.key) });
    }).then(function (subscription) {
      return post(root.dataset.subscribe, {
        endpoint: subscription.endpoint,
        p256dh: fromBytes(subscription.getKey('p256dh')),
        auth: fromBytes(subscription.getKey('auth'))
      }).then(function () {
        show(subscription);
        report('Subscribed. Now send a test notification: it says what the push service answered.');
      });
    }).catch(function (error) {
      say(error && error.message ? error.message : 'That did not work.');
      show(null);
    }).then(function () { enable.disabled = false; });
  });

  disable.addEventListener('click', function () {
    disable.disabled = true;
    report('');
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

  /*
   * A push to this phone, now, and the push service's answer in words. The
   * server sends it (the keys never leave there); this only says which
   * phone, by its endpoint, so a household's other phone is not the one
   * that buzzes.
   */
  if (test) {
    test.addEventListener('click', function () {
      test.disabled = true;
      report('Sending...');
      manager().then(function (pm) { return pm.getSubscription(); }).then(function (subscription) {
        return post(root.dataset.test, subscription ? { endpoint: subscription.endpoint } : {});
      }).then(function (data) {
        var text = data.message || (data.ok ? 'Sent.' : 'That did not work.');
        if (data.ok) {
          text += ' If nothing shows within a minute, check that Carl is allowed under Settings, '
            + 'Notifications, and that no Focus mode is silencing it.';
        }
        report(text);
      }).catch(function (error) {
        report(error && error.message ? error.message : 'That did not work.');
      }).then(function () { test.disabled = false; });
    });
  }
}());
