import {
  getRetriableSync, updateSyncItems, markPersonasSynced,
  getMarcaDescarga, setMarcaDescarga, mezclarPersonasDescargadas
} from './db.js';
import { syncData, descargarCambios } from './api.js';

let syncing = false;

/** Tope de páginas por sincronización, para no bloquear la app en el primer arranque. */
const MAX_PAGINAS = 10;

/**
 * Sincronización completa: primero sube lo pendiente, después baja lo ajeno.
 *
 * El orden no es casual. Subiendo primero, los cambios locales llegan al
 * servidor antes de pedirle nada, así que lo que baja ya los tiene en cuenta;
 * al revés, se descargaría una versión anterior de un registro que estaba a
 * punto de enviarse y habría que resolver un conflicto evitable.
 */
export async function syncNow() {
  if (syncing) return { synced: 0, message: 'Sincronización en curso' };
  syncing = true;

  let ids = [];
  try {
    // --- 1. SUBIR ---
    const pending = await getRetriableSync();

    if (pending.length > 0) {
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
    }

    // --- 2. BAJAR ---
    const recibidas = await descargarTodo();

    return {
      synced: ids.length,
      recibidas,
      message: construirMensaje(ids.length, recibidas)
    };
  } catch (err) {
    // Solo se marcan como ERROR los que se intentaron ahora; getRetriableSync
    // los volverá a tomar en el siguiente intento.
    if (ids.length) await updateSyncItems(ids, 'ERROR');
    throw err;
  } finally {
    syncing = false;
  }
}

/**
 * Descarga por páginas hasta agotar los cambios pendientes.
 *
 * La marca de agua solo avanza cuando la página se guardó de verdad: si algo
 * falla a mitad, la siguiente sincronización repite desde donde quedó en vez
 * de saltarse registros.
 */
async function descargarTodo() {
  let total = 0;

  for (let pagina = 0; pagina < MAX_PAGINAS; pagina++) {
    const datos = await descargarCambios(getMarcaDescarga());
    if (!datos.personas || datos.personas.length === 0) break;

    const r = await mezclarPersonasDescargadas(datos.personas);
    setMarcaDescarga(datos.marca);
    total += r.nuevas + r.actualizadas;

    if (!datos.hay_mas) break;
  }

  return total;
}

function construirMensaje(enviados, recibidas) {
  const partes = [];
  if (enviados > 0) partes.push(`${enviados} enviado(s)`);
  if (recibidas > 0) partes.push(`${recibidas} recibido(s)`);
  return partes.length ? partes.join(' · ') : 'Todo al día';
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
