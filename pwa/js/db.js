const DB_NAME = 'encuestas_minsalud';
const DB_VERSION = 2;

let dbInstance = null;

function openDB() {
  if (dbInstance) return Promise.resolve(dbInstance);
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('personas')) {
        db.createObjectStore('personas', { keyPath: ['tipo_documento', 'numero_documento'] });
      }
      if (!db.objectStoreNames.contains('municipios')) {
        db.createObjectStore('municipios', { keyPath: 'codigo' });
      }
      if (!db.objectStoreNames.contains('sync_queue')) {
        const qs = db.createObjectStore('sync_queue', { keyPath: 'id', autoIncrement: true });
        qs.createIndex('by_status', 'status');
      }
      if (!db.objectStoreNames.contains('credenciales')) {
        db.createObjectStore('credenciales', { keyPath: 'documento' });
      }
    };
    req.onsuccess = e => { dbInstance = e.target.result; resolve(dbInstance); };
    req.onerror = e => reject(e.target.error);
  });
}

function request(storeName, mode, fn) {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction(storeName, mode);
    let result;
    const store = t.objectStore(storeName);
    const req = fn(store);
    if (req) req.onsuccess = e => { result = e.target.result; };
    t.oncomplete = () => resolve(result);
    t.onerror = e => reject(e.target.error);
  }));
}

// --- Personas ---

export async function getPersonas() {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('personas', 'readonly');
    const req = t.objectStore('personas').getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = e => reject(e.target.error);
  }));
}

export async function getPersona(tipo, numero) {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('personas', 'readonly');
    const req = t.objectStore('personas').get([tipo, numero]);
    req.onsuccess = () => resolve(req.result);
    req.onerror = e => reject(e.target.error);
  }));
}

export async function savePersona(persona) {
  return request('personas', 'readwrite', store => store.put(persona));
}

export async function softDeletePersona(tipo, numero) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const t = db.transaction('personas', 'readwrite');
    const store = t.objectStore('personas');
    const getReq = store.get([tipo, numero]);
    getReq.onsuccess = () => {
      const p = getReq.result;
      if (p) {
        p.deleted_at = Date.now();
        p.updated_at = Date.now();
        p._pendingSync = true;
        store.put(p);
      }
    };
    t.oncomplete = resolve;
    t.onerror = e => reject(e.target.error);
  });
}

export async function markPersonasSynced(keys) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const t = db.transaction('personas', 'readwrite');
    const store = t.objectStore('personas');
    keys.forEach(([tipo, numero]) => {
      const req = store.get([tipo, numero]);
      req.onsuccess = () => {
        const p = req.result;
        if (p) { p._pendingSync = false; store.put(p); }
      };
    });
    t.oncomplete = resolve;
    t.onerror = e => reject(e.target.error);
  });
}

// --- Municipios ---

export async function getMunicipios() {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('municipios', 'readonly');
    const req = t.objectStore('municipios').getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = e => reject(e.target.error);
  }));
}

export async function saveMunicipios(list) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const t = db.transaction('municipios', 'readwrite');
    const store = t.objectStore('municipios');
    list.forEach(m => store.put(m));
    t.oncomplete = resolve;
    t.onerror = e => reject(e.target.error);
  });
}

// --- Sync Queue ---

export async function addSyncItem(item) {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('sync_queue', 'readwrite');
    const req = t.objectStore('sync_queue').add(item);
    req.onsuccess = () => resolve(req.result);
    t.onerror = e => reject(e.target.error);
  }));
}

export async function getPendingSync() {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('sync_queue', 'readonly');
    const req = t.objectStore('sync_queue').index('by_status').getAll('PENDING');
    req.onsuccess = () => resolve(req.result);
    t.onerror = e => reject(e.target.error);
  }));
}

// Items que deben (re)intentarse: pendientes + los que fallaron antes.
// Sin esto, un fallo transitorio deja los registros en ERROR para siempre.
export async function getRetriableSync() {
  const all = await getAllSyncItems();
  return all.filter(i => i.status === 'PENDING' || i.status === 'ERROR');
}

export async function updateSyncItems(ids, status) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const t = db.transaction('sync_queue', 'readwrite');
    const store = t.objectStore('sync_queue');
    ids.forEach(id => {
      const req = store.get(id);
      req.onsuccess = () => {
        const item = req.result;
        if (item) { item.status = status; store.put(item); }
      };
    });
    t.oncomplete = resolve;
    t.onerror = e => reject(e.target.error);
  });
}

export async function getAllSyncItems() {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('sync_queue', 'readonly');
    const req = t.objectStore('sync_queue').getAll();
    req.onsuccess = () => resolve(req.result);
    t.onerror = e => reject(e.target.error);
  }));
}

export async function getSyncCounts() {
  const all = await getAllSyncItems();
  return {
    pending: all.filter(i => i.status === 'PENDING').length,
    sent: all.filter(i => i.status === 'SENT').length,
    error: all.filter(i => i.status === 'ERROR').length,
    total: all.length
  };
}

// --- Descarga: mezclar lo que llega del servidor ---

const CLAVE_MARCA = 'pwa_marca_sync';

/** Marca de agua: hasta dónde llegó la última descarga. */
export function getMarcaDescarga() {
  return Number(localStorage.getItem(CLAVE_MARCA) || 0);
}

export function setMarcaDescarga(marca) {
  localStorage.setItem(CLAVE_MARCA, String(marca));
}

/**
 * Decide qué hacer con una persona que llega del servidor.
 *
 * Está aparte y sin tocar IndexedDB a propósito: es la regla que evita perder
 * trabajo de campo, y así se puede comprobar sin navegador.
 *
 * - Si no existe en local, se guarda.
 * - Si la copia local tiene cambios SIN ENVIAR, se conserva. Esos cambios aún
 *   no llegaron al servidor, así que lo que vuelve es por definición anterior;
 *   sobrescribirlos borraría una encuesta recién hecha en el dispositivo.
 * - Si no, gana el `updated_at` más reciente: el mismo criterio Last-Write-Wins
 *   que aplica el servidor al recibir, de modo que ambos lados resuelven igual.
 *
 * @returns {'nueva'|'actualizar'|'conservar'}
 */
export function decidirMezcla(local, remota) {
  if (!local) return 'nueva';
  if (local._pendingSync) return 'conservar';
  return remota.updated_at > local.updated_at ? 'actualizar' : 'conservar';
}

/**
 * Integra en la base local las personas que llegan del servidor.
 *
 * @returns {Promise<{nuevas:number, actualizadas:number, conservadas:number}>}
 */
export async function mezclarPersonasDescargadas(lista) {
  if (!lista || lista.length === 0) return { nuevas: 0, actualizadas: 0, conservadas: 0 };

  const db = await openDB();
  return new Promise((resolve, reject) => {
    const t = db.transaction('personas', 'readwrite');
    const store = t.objectStore('personas');
    let nuevas = 0, actualizadas = 0, conservadas = 0;

    lista.forEach(remota => {
      const req = store.get([remota.tipo_documento, remota.numero_documento]);
      req.onsuccess = () => {
        switch (decidirMezcla(req.result, remota)) {
          case 'nueva':
            store.put({ ...remota, _pendingSync: false });
            nuevas++;
            break;
          case 'actualizar':
            store.put({ ...remota, _pendingSync: false });
            actualizadas++;
            break;
          default:
            conservadas++;
        }
      };
    });

    t.oncomplete = () => resolve({ nuevas, actualizadas, conservadas });
    t.onerror = e => reject(e.target.error);
  });
}

// --- Resumen para la pantalla de inicio ---

/**
 * Cifras del trabajo hecho en este dispositivo.
 *
 * Todo sale de IndexedDB: el encuestador puede verlo sin conexión, que es
 * cuando más falta le hace saber cuánto lleva y cuánto le queda por enviar.
 *
 * @returns {Promise<{hoy:number, total:number, pendientes:number, porDia:Array<{dia:string,total:number}>}>}
 */
export async function resumenLocal(dias = 7) {
  const [personas, cola] = await Promise.all([getPersonas(), getAllSyncItems()]);
  const vivas = personas.filter(p => !p.deleted_at);

  const inicioDelDia = new Date();
  inicioDelDia.setHours(0, 0, 0, 0);
  const desdeHoy = inicioDelDia.getTime();

  // Serie de los últimos N días, incluidos los que no tuvieron actividad:
  // un hueco en el gráfico también informa.
  const porDia = [];
  for (let i = dias - 1; i >= 0; i--) {
    const d = new Date(inicioDelDia);
    d.setDate(d.getDate() - i);
    const ini = d.getTime();
    const fin = ini + 86400000;
    porDia.push({
      dia: d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' }),
      total: vivas.filter(p => p.updated_at >= ini && p.updated_at < fin).length
    });
  }

  return {
    hoy: vivas.filter(p => p.updated_at >= desdeHoy).length,
    total: vivas.length,
    pendientes: cola.filter(i => i.status === 'PENDING' || i.status === 'ERROR').length,
    porDia
  };
}

// --- Credenciales (login offline) ---

export async function getCredencial(documento) {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('credenciales', 'readonly');
    const req = t.objectStore('credenciales').get(documento);
    req.onsuccess = () => resolve(req.result);
    req.onerror = e => reject(e.target.error);
  }));
}

export async function saveCredencial(cred) {
  return request('credenciales', 'readwrite', store => store.put(cred));
}

/**
 * ¿Este dispositivo tiene alguna credencial guardada?
 *
 * Sirve para avisar por adelantado cuando no hay ninguna: sin ella el ingreso
 * sin conexión es imposible, y dejar que el usuario lo intente solo produce un
 * error que parece culpa suya.
 */
export async function hayCredencialesGuardadas() {
  return openDB().then(db => new Promise((resolve, reject) => {
    const t = db.transaction('credenciales', 'readonly');
    const req = t.objectStore('credenciales').count();
    req.onsuccess = () => resolve(req.result > 0);
    req.onerror = e => reject(e.target.error);
  }));
}
