const CACHE_NAME = 'muscle-quest-v2';
const APP_SHELL = [
  './',
  './index.html',
  './Quiz1.html',
  './Quiz2.html',
  './Quiz3.html',
  './Score.html',
  './Leaderboard.html',
  './flappy-muscles.html',
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
  if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;

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
