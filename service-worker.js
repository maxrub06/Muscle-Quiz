const CACHE_NAME = 'muscle-quest-v4';
const APP_SHELL = [
  './',
  './index.html',
  './Quiz1.html',
  './Quiz2.html',
  './Quiz3.html',
  './Score.html',
  './Leaderboard.html',
  './flappy-muscles.html',
  './muscario.html',
  './rhode.jpg',
  './app-icon.svg',
  './click.wav',
  './correct.wav',
  './fail.wav',
  './manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.includes('/api/')) return;

  event.respondWith(
    caches.match(event.request).then(cached => cached || fetch(event.request).then(response => {
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
      }
      return response;
    }))
  );
});
