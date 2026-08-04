// Subir esta versión en cada cambio de JS/CSS: el fetch es cache-first, así que
// sin bump los navegadores seguirían sirviendo los archivos viejos.
// v6: styles.css se dividió en 7 hojas por responsabilidad.
// v5: api.js y session.js ahora envían el token de autenticación en la sincronización.
const CACHE = 'encuestas-v6';

const ASSETS = [
  './index.html',
  './manifest.json',
  './icons/icon.svg',
  './css/base.css',
  './css/layout.css',
  './css/components.css',
  './css/forms.css',
  './css/sync.css',
  './css/feedback.css',
  './css/login.css',
  './js/utils.js',
  './js/db.js',
  './js/api.js',
  './js/sync.js',
  './js/router.js',
  './js/session.js',
  './js/app.js',
  './js/screens/lista-personas.js',
  './js/screens/formulario-encuesta.js',
  './js/screens/estado-sincronizacion.js',
  './js/screens/login.js'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  if (url.pathname.includes('/api/')) {
    e.respondWith(
      fetch(e.request).catch(() =>
        new Response(JSON.stringify({ success: false, message: 'Sin conexión al servidor' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  e.respondWith(
    caches.match(e.request).then(cached =>
      cached || fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      })
    )
  );
});

self.addEventListener('sync', e => {
  if (e.tag === 'sync-encuestas') {
    e.waitUntil(
      self.clients.matchAll({ includeUncontrolled: true }).then(clients =>
        clients.forEach(c => c.postMessage({ type: 'SYNC_NOW' }))
      )
    );
  }
});
