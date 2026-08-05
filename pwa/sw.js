// Subir esta versión en cada cambio de JS/CSS: el fetch es cache-first, así que
// sin bump los navegadores seguirían sirviendo los archivos viejos.
// v12: cerrar sesión revoca el token en el servidor.
// v11: botón de cerrar sesión; un fallo de token lleva al login.
// v10: lista con renderizado incremental y eventos por delegación.
// v9: el HTML pasa a red-primero (servirlo obsoleto dejaba la app sin CSS).
// v8: rediseño institucional del login y paleta unificada.
// v7: .hidden pasa a !important (el spinner del login se veía siempre).
// v6: styles.css se dividió en 7 hojas por responsabilidad.
// v5: api.js y session.js ahora envían el token de autenticación en la sincronización.
const CACHE = 'encuestas-v12';

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

  // El HTML va por RED PRIMERO, con la caché como respaldo.
  //
  // Antes iba por caché primero, igual que el resto, y eso rompió la app al
  // dividir styles.css: los navegadores servían el index.html viejo, que
  // enlazaba una hoja que ya no existe, y la app quedaba sin ningún estilo.
  // El HTML es el índice de todo lo demás, así que servirlo obsoleto puede
  // dejar referencias colgando; conviene que sea lo primero en refrescarse.
  //
  // El modo offline se conserva: si no hay red, se responde desde la caché.
  if (e.request.mode === 'navigate' || e.request.destination === 'document') {
    e.respondWith(
      fetch(e.request)
        .then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE).then(c => c.put('./index.html', clone));
          }
          return res;
        })
        .catch(() => caches.match('./index.html'))
    );
    return;
  }

  // El resto (CSS, JS, iconos) sí va por caché primero: son recursos estáticos
  // y la versión de CACHE se encarga de invalidarlos cuando cambian.
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
