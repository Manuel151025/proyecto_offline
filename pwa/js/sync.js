import { getRetriableSync, updateSyncItems, markPersonasSynced } from './db.js';
import { syncData } from './api.js';

let syncing = false;

export async function syncNow() {
  if (syncing) return { synced: 0, message: 'Sincronización en curso' };
  syncing = true;

  let ids = [];
  try {
    const pending = await getRetriableSync();
    if (pending.length === 0) return { synced: 0, message: 'Sin pendientes' };

    const personas = pending.map(i => {
      const { _pendingSync, ...clean } = i.persona;
      return clean;
    });
    const encuestas = pending.map(i => i.encuesta);
    ids = pending.map(i => i.id);
    const personaKeys = pending.map(i => [i.persona.tipo_documento, i.persona.numero_documento]);

    await syncData({ personas, encuestas });
    await updateSyncItems(ids, 'SENT');
    await markPersonasSynced(personaKeys);

    return { synced: ids.length, message: `${ids.length} registro(s) sincronizados` };
  } catch (err) {
    // Marcamos ERROR solo los que intentamos ahora; getRetriableSync los volverá a tomar.
    if (ids.length) await updateSyncItems(ids, 'ERROR');
    throw err;
  } finally {
    syncing = false;
  }
}

export function registerBackgroundSync() {
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready
      .then(sw => sw.sync.register('sync-encuestas'))
      .catch(() => {});
  }
}

export function isSyncing() {
  return syncing;
}
