import {
  getRetriableSync, updateSyncItems, markPersonasSynced, marcarRechazados,
  getMarcaDescarga, setMarcaDescarga, mezclarPersonasDescargadas, limpiarEnviados
} from './db.js';
import { syncData, descargarCambios } from './api.js';

let syncing = false;

/** Tope de páginas por sincronización, para no bloquear la app en el primer arranque. */
const MAX_PAGINAS = 10;

/**
 * Registros por envío.
 *
 * Antes se mandaba TODA la cola en una sola petición. El servidor admite 500
 * como máximo: un encuestador que pasaba semanas sin señal acumulaba más,
 * recibía 413 y reintentaba el mismo envío imposible para siempre.
 */
export const TAMANO_LOTE = 100;

/**
 * Margen hacia atrás al empezar cada descarga.
 *
 * Dos sincronizaciones de otros celulares pueden confirmarse en el servidor en
 * orden distinto al de sus sellos; sin este solape, la que se confirmó tarde
 * con un sello anterior quedaría detrás de la marca y no bajaría nunca. Volver
 * a recibir unos registros es inofensivo: la mezcla es idempotente.
 */
export const SOLAPE_MS = 2 * 60 * 1000;

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

  const aceptados = pendientes.filter(i => !idsRechazados.has(i.encuesta?.id ?? i.id));

  return {
    // El servidor identifica por id de ENCUESTA; la cola, por su propio id.
    rechazos: rechazos
      .map(r => {
        const item = pendientes.find(i => (i.encuesta?.id ?? i.id) === r.id);
        return item ? { id: item.id, motivo: r.motivo } : null;
      })
      .filter(Boolean),
    idsAceptados: aceptados.map(i => i.id),
    // Solo se limpia la marca de pendiente de las personas que sí subieron.
    clavesAceptadas: aceptados.map(
      i => [i.persona.tipo_documento, i.persona.numero_documento]
    )
  };
}

/** Divide una lista en trozos de `tamano`. */
export function enLotes(lista, tamano = TAMANO_LOTE) {
  const lotes = [];
  for (let i = 0; i < lista.length; i += tamano) lotes.push(lista.slice(i, i + tamano));
  return lotes;
}

/**
 * Sincronización completa: primero sube lo pendiente, después baja lo ajeno.
 *
 * El orden no es casual. Subiendo primero, los cambios locales llegan al
 * servidor antes de pedirle nada, así que lo que baja ya los tiene en cuenta.
 *
 * @param {{onProgreso?: (p: {enviados:number, total:number}) => void}} [opciones]
 */
export async function syncNow({ onProgreso } = {}) {
  if (syncing) return { synced: 0, recibidas: 0, rechazadas: 0, message: 'Sincronización en curso' };
  syncing = true;

  try {
    const subida = await subirPendientes(onProgreso);

    // Sin red o sin sesión, la descarga fallaría igual: se informa el error de subida.
    if (subida.error && (subida.error.sinConexion || subida.error.sesionInvalida)) throw subida.error;

    const recibidas = await descargarTodo();
    limpiarEnviados().catch(() => {});

    // Un lote que el servidor no aceptó (5xx, 413) se reintentará; se avisa
    // después de haber descargado lo ajeno, que no depende de él.
    if (subida.error) throw subida.error;

    return {
      synced: subida.enviados,
      recibidas,
      rechazadas: subida.rechazadas,
      message: construirMensaje(subida.enviados, recibidas, subida.rechazadas)
    };
  } finally {
    syncing = false;
  }
}

/**
 * Sube la cola por lotes. Un lote fallido queda en ERROR (se reintentará) y
 * no impide intentar los siguientes, salvo que falte red o sesión: entonces
 * no tiene sentido seguir.
 */
async function subirPendientes(onProgreso) {
  const pendientes = await getRetriableSync();
  let enviados = 0;
  let rechazadas = 0;
  let error = null;
  let procesados = 0;

  for (const lote of enLotes(pendientes)) {
    onProgreso?.({ enviados: procesados, total: pendientes.length });
    try {
      const respuesta = await syncData({
        personas: lote.map(i => {
          const { _pendingSync, ...limpia } = i.persona;
          return limpia;
        }),
        encuestas: lote.map(i => i.encuesta)
      });

      const { rechazos, idsAceptados, clavesAceptadas } = repartirRespuesta(lote, respuesta);
      await updateSyncItems(idsAceptados, 'SENT');
      await marcarRechazados(rechazos);
      await markPersonasSynced(clavesAceptadas);

      enviados += idsAceptados.length;
      rechazadas += rechazos.length;
    } catch (err) {
      await updateSyncItems(lote.map(i => i.id), 'ERROR');
      error ??= err;
      if (err.sinConexion || err.sesionInvalida) break;
    }
    procesados += lote.length;
  }

  if (pendientes.length) onProgreso?.({ enviados: procesados, total: pendientes.length });
  return { enviados, rechazadas, error };
}

/**
 * Descarga por páginas con el cursor compuesto (sello, tipo, número).
 *
 * La primera página empieza un poco antes de la marca guardada (SOLAPE_MS);
 * las siguientes continúan exactamente donde terminó la anterior. La marca
 * solo avanza cuando la página se guardó de verdad.
 */
async function descargarTodo() {
  const marcaGuardada = getMarcaDescarga();
  let cursor = { sello: Math.max(0, marcaGuardada - SOLAPE_MS) };
  let total = 0;

  for (let pagina = 0; pagina < MAX_PAGINAS; pagina++) {
    const datos = await descargarCambios(cursor);
    if (!datos.personas || datos.personas.length === 0) break;

    const r = await mezclarPersonasDescargadas(datos.personas);
    total += r.nuevas + r.actualizadas;

    // Un servidor anterior a esta versión no devuelve `cursor`.
    cursor = datos.cursor || { sello: datos.marca };
    if (cursor.sello > getMarcaDescarga()) setMarcaDescarga(cursor.sello);

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

/**
 * Pide al navegador una sincronización en segundo plano (Chrome y Edge en
 * Android). El service worker la ejecuta aunque la app esté cerrada.
 */
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
