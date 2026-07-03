const DB_NAME = 'encuestas_minsalud';
const DB_VERSION = 1;

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
