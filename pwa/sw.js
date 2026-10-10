// Subir esta versión en cada cambio de JS/CSS: el fetch es cache-first, así que
// sin bump los navegadores seguirían sirviendo los archivos viejos.
// v23: marca neutral (sin «Ministerio de Salud»).
// v22: «¿Olvidaste tu contraseña?» con código por correo.
// v21: catálogo completo de municipios (1.122) y EPS, con buscador en el formulario.
// v20: login del panel, plural en la semana y pruebas de punta a punta.
// v19: validación estricta por campo y filtro de lo que se escribe en el formulario.
// v18: rediseño "Cálida de territorio" y fuente Figtree empaquetada (sin red).
// v17: subida por lotes, cursor de descarga, reintentos, validación del formulario,
//      rechazados visibles y subida en segundo plano desde el service worker.
// v16: un registro rechazado por el servidor deja de reintentarse (bloqueaba la cola).
// v15: la sincronización ahora también DESCARGA lo de otros dispositivos.
// v14: resumen del dispositivo en inicio y refresco tras sincronizar.
// v13: mensaje claro cuando el dispositivo no tiene credenciales guardadas.
// v12: cerrar sesión revoca el token en el servidor.
// v11: botón de cerrar sesión; un fallo de token lleva al login.
// v10: lista con renderizado incremental y eventos por delegación.
// v9: el HTML pasa a red-primero (servirlo obsoleto dejaba la app sin CSS).
// v8: rediseño institucional del login y paleta unificada.
// v7: .hidden pasa a !important (el spinner del login se veía siempre).
// v6: styles.css se dividió en 7 hojas por responsabilidad.
// v5: api.js y session.js ahora envían el token de autenticación en la sincronización.
const CACHE = 'encuestas-v23';

const ASSETS = [
  './index.html',
  './manifest.json',
  './icons/icon.svg',
  './fonts/figtree-latin.woff2',
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
  './js/validacion.js',
  './js/catalogos.js',
  './js/componentes/buscador.js',
  './data/municipios.json',
  './data/eps.json',
  './js/app.js',
  './js/screens/lista-personas.js',
  './js/screens/formulario-encuesta.js',
  './js/screens/estado-sincronizacion.js',
  './js/screens/login.js',
  './js/screens/recuperar.js'
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

/*
 * Sincronización en segundo plano (Chrome y Edge en Android).
 *
 * Antes este evento solo avisaba a las ventanas abiertas: con la app cerrada
 * no había ninguna, el evento terminaba "con éxito" y el navegador daba la
 * tarea por hecha sin haber subido nada. Ahora, si no hay ventana, el propio
 * service worker sube la cola. Si falla por falta de red, la promesa se
 * rechaza y el navegador vuelve a intentarlo más tarde.
 *
 * Solo SUBE: la descarga y la mezcla se hacen al abrir la app. iOS no
 * implementa este evento; allí la cola se envía al abrir la app.
 */
self.addEventListener('sync', e => {
  if (e.tag !== 'sync-encuestas') return;
  e.waitUntil((async () => {
    const ventanas = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    if (ventanas.length) {
      ventanas.forEach(c => c.postMessage({ type: 'SYNC_NOW' }));
      return;
    }
    await subirEnSegundoPlano();
  })());
});

const LOTE_SEGUNDO_PLANO = 100;

function abrirBase() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('encuestas_minsalud');
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function leer(db, almacen, clave) {
  return new Promise((resolve, reject) => {
    const req = db.transaction(almacen, 'readonly').objectStore(almacen).get(clave);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function leerTodo(db, almacen) {
  return new Promise((resolve, reject) => {
    const req = db.transaction(almacen, 'readonly').objectStore(almacen).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

/** Aplica estados a la cola y limpia la marca de pendiente de las personas aceptadas. */
function aplicarResultado(db, aceptados, rechazos) {
  return new Promise((resolve, reject) => {
    const t = db.transaction(['sync_queue', 'personas'], 'readwrite');
    const cola = t.objectStore('sync_queue');
    const personas = t.objectStore('personas');
    for (const item of aceptados) {
      cola.put({ ...item, status: 'SENT' });
      const req = personas.get([item.persona.tipo_documento, item.persona.numero_documento]);
      req.onsuccess = () => {
        if (req.result) personas.put({ ...req.result, _pendingSync: false });
      };
    }
    for (const { item, motivo } of rechazos) cola.put({ ...item, status: 'RECHAZADO', error: motivo });
    t.oncomplete = resolve;
    t.onerror = () => reject(t.error);
  });
}

async function subirEnSegundoPlano() {
  const db = await abrirBase();
  if (!db.objectStoreNames.contains('kv')) return;

  const sesion = await leer(db, 'kv', 'sesion');
  // Sin sesión válida no hay nada que hacer aquí: lo resolverá la app al abrirse.
  if (!sesion?.token || (sesion.expiraEn && Date.now() / 1000 > sesion.expiraEn)) return;

  const pendientes = (await leerTodo(db, 'sync_queue'))
    .filter(i => i.status === 'PENDING' || i.status === 'ERROR');
  const url = new URL('../api/personas/sync.php', self.registration.scope);

  for (let i = 0; i < pendientes.length; i += LOTE_SEGUNDO_PLANO) {
    const lote = pendientes.slice(i, i + LOTE_SEGUNDO_PLANO);
    // Un fallo de red lanza aquí, y el navegador reprograma el evento.
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${sesion.token}`,
        'X-Device-Id': sesion.deviceId || '',
        'X-Plataforma': 'pwa',
        'X-App-Version': 'sw'
      },
      body: JSON.stringify({
        personas: lote.map(({ persona }) => {
          const { _pendingSync, ...limpia } = persona;
          return limpia;
        }),
        encuestas: lote.map(item => item.encuesta)
      })
    });
    if (res.status === 401 || res.status === 403) return; // la app pedirá entrar de nuevo
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    const datos = await res.json();
    const motivos = new Map((datos.rechazadas || []).map(r => [r.id, r.motivo]));
    await aplicarResultado(
      db,
      lote.filter(item => !motivos.has(item.encuesta?.id)),
      lote.filter(item => motivos.has(item.encuesta?.id)).map(item => ({ item, motivo: motivos.get(item.encuesta.id) }))
    );
  }
}
