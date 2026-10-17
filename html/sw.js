// Blanko service worker: cache app shell, network-first untuk konten dinamis.
const CACHE = 'blanko-v1';
const SHELL = ['./?page=dashboard', './assets/app.css', './assets/app.js', './manifest.json'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (url.pathname.endsWith('app.css') || url.pathname.endsWith('app.js') || url.pathname.endsWith('manifest.json')) {
    e.respondWith(caches.match(e.request).then((r) => r || fetch(e.request)));
    return;
  }
  e.respondWith(fetch(e.request).catch(() => caches.match('./?page=dashboard')));
});
