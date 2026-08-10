import {
  getRetriableSync, updateSyncItems, markPersonasSynced, marcarRechazados,
  getMarcaDescarga, setMarcaDescarga, mezclarPersonasDescargadas
} from './db.js';
import { syncData, descargarCambios } from './api.js';

let syncing = false;

/** Tope de páginas por sincronización, para no bloquear la app en el primer arranque. */
const MAX_PAGINAS = 10;

/**
 * Reparte lo enviado entre aceptado y rechazado según responda el servidor.
 *
 * Está fuera de syncNow y sin tocar IndexedDB para poder probarla: es la pieza
 * que decide qué se reintenta. Si clasificara mal un rechazo como pendiente,
 * ese registro volvería en cada sincronización arrastrando a su lote, que es
 * exactamente el fallo que motivó este cambio.
 *
 * @param {Array<{id: string, persona: object}>} pendientes lo que se envió
 * @param {{rechazadas?: Array<{id: string, motivo: string}>}} respuesta
 */
export function repartirRespuesta(pendientes, respuesta) {
  const rechazos = respuesta?.rechazadas || [];
  const idsRechazados = new Set(rechazos.map(r => r.id));

  const aceptados = pendientes.filter(i => !idsRechazados.has(i.id));

  return {
    rechazos,
    idsAceptados: aceptados.map(i => i.id),
    // Solo se limpia la marca de pendiente de las personas que sí subieron.
    clavesAceptadas: aceptados.map(
      i => [i.persona.tipo_documento, i.persona.numero_documento]
    )
  };
}

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

  // Lo que está EN VUELO. Se vacía en cuanto el servidor responde, porque el
  // catch de abajo lo marca como ERROR: si siguiera lleno, un fallo en la
  // descarga posterior degradaría a ERROR registros que ya subieron bien y se
  // reenviarían enteros en la siguiente sincronización.
  let enVuelo = [];
  let enviados = 0;
  let rechazadas = 0;
  try {
    // --- 1. SUBIR ---
    const pending = await getRetriableSync();

    if (pending.length > 0) {
      const personas = pending.map(i => {
        const { _pendingSync, ...clean } = i.persona;
        return clean;
      });
      const encuestas = pending.map(i => i.encuesta);
      enVuelo = pending.map(i => i.id);

      const respuesta = await syncData({ personas, encuestas });

      // El servidor puede aceptar unos y descartar otros. Antes se daba por
      // enviado todo el lote; ahora se separa, porque un registro rechazado
      // que se reintenta en cada sincronización bloquea la cola para siempre.
      const { rechazos, idsAceptados, clavesAceptadas } =
        repartirRespuesta(pending, respuesta);

      await updateSyncItems(idsAceptados, 'SENT');
      await marcarRechazados(rechazos);
      await markPersonasSynced(clavesAceptadas);

      enviados = idsAceptados.length;
      rechazadas = rechazos.length;
      enVuelo = []; // Ya tienen estado definitivo: el catch no debe tocarlos.
    }

    // --- 2. BAJAR ---
    const recibidas = await descargarTodo();

    return {
      synced: enviados,
      recibidas,
      rechazadas,
      message: construirMensaje(enviados, recibidas, rechazadas)
    };
  } catch (err) {
    // Solo se marcan como ERROR los que seguían en vuelo; getRetriableSync los
    // volverá a tomar en el siguiente intento.
    if (enVuelo.length) await updateSyncItems(enVuelo, 'ERROR');
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

function construirMensaje(enviados, recibidas, rechazadas) {
  const partes = [];
  if (enviados > 0) partes.push(`${enviados} enviado(s)`);
  if (recibidas > 0) partes.push(`${recibidas} recibido(s)`);
  // Se nombra explícitamente: un rechazo no se reintenta, así que si no se
  // dice aquí el registro desaparece sin que nadie se entere.
  if (rechazadas > 0) partes.push(`${rechazadas} rechazado(s)`);
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
