/*
 * Service worker: caches the shell only (handoff Section 15 -- offline is
 * deferred; the paper field sheet is the answer for now), and, from Phase
 * 16, shows a push notification for a browser that has no declarative Web
 * Push of its own.
 *
 * It is registered by push.js on the tap that subscribes, and not before:
 * nothing else needs a worker, and a worker nobody asked for is a cache
 * nobody asked for.
 *
 * Never cache a page: every page carries personal data and is served
 * no-store. This caches the stylesheet and scripts so a poor signal in a
 * field does not leave the app unstyled.
 *
 * Served from the app root, not assets/, so its scope is the whole app -- and
 * it is deliberately exempt from the one-year Expires (hosting Section 9).
 */
var CACHE = 'carl-shell-v1';

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        return key === CACHE ? null : caches.delete(key);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') { return; }

  var url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }

  // Only the shell. Pages, photos and API responses are never cached.
  if (!/\/assets\/(css|js)\//.test(url.pathname)) { return; }

  event.respondWith(
    caches.open(CACHE).then(function (cache) {
      return cache.match(request).then(function (hit) {
        var network = fetch(request).then(function (response) {
          if (response && response.status === 200) { cache.put(request, response.clone()); }
          return response;
        }).catch(function () { return hit; });
        return hit || network;
      });
    })
  );
});

/*
 * The watering timer's push (Phase 16). The body is the declarative Web
 * Push shape -- {web_push: 8030, notification: {title, body, navigate}} --
 * which Safari 18.4+ shows by itself; here it is shown by hand for every
 * other browser. The tap opens the timer's own page.
 */
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }
  var n = data.notification || {};
  event.waitUntil(self.registration.showNotification(n.title || 'Carl', {
    body: n.body || '',
    tag: n.tag || 'carl',
    data: { url: n.navigate || '' }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = event.notification.data && event.notification.data.url;
  if (url) { event.waitUntil(clients.openWindow(url)); }
});
