import { getToken } from './session.js';
import { getDeviceId } from './utils.js';

const BASE_URL = '../api';

/** Versión de la app que el servidor registra en el monitor de dispositivos. */
export const APP_VERSION = '2.0.0';

/** Cabeceras que identifican a este celular ante el servidor. */
function cabecerasDispositivo() {
  return {
    'X-Device-Id': getDeviceId(),
    'X-Plataforma': 'pwa',
    'X-App-Version': APP_VERSION
  };
}

/**
 * fetch que distingue "no hubo red" de "el servidor respondió con error".
 *
 * Sin red, fetch lanza TypeError; con la app controlada por el service worker,
 * llega un 503 fabricado por él. Ambos casos se marcan con `sinConexion` para
 * que la sincronización deje de intentar el resto de lotes en vez de
 * marcarlos todos como fallidos uno a uno.
 */
async function pedir(url, opciones) {
  let res;
  try {
    res = await fetch(url, opciones);
  } catch (_) {
    throw Object.assign(new Error('Sin conexión al servidor'), { sinConexion: true });
  }
  if (res.status === 503) {
    throw Object.assign(new Error('Sin conexión al servidor'), { sinConexion: true });
  }
  return res;
}

async function errorDeSesion(res) {
  const data = await res.json().catch(() => ({}));
  return Object.assign(
    new Error(data.message || 'Tu sesión expiró. Inicia sesión de nuevo.'),
    { sesionInvalida: true }
  );
}

function sinToken() {
  // Se marca para que quien llame pueda mandar al login en vez de dejar al
  // usuario leyendo un mensaje que no le dice cómo salir del atasco.
  return Object.assign(
    new Error('Tu sesión no permite sincronizar. Vuelve a iniciar sesión con conexión.'),
    { sesionInvalida: true }
  );
}

export async function login(numero_documento, password) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 10000);
  let res;
  try {
    res = await fetch(`${BASE_URL}/auth/login.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ numero_documento, password }),
      signal: controller.signal
    });
  } catch (err) {
    throw new Error(err.name === 'AbortError'
      ? 'El servidor no respondió. Verifica tu conexión.'
      : 'No se pudo conectar con el servidor');
  } finally {
    clearTimeout(timeout);
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.success) throw new Error(data.message || 'Documento o contraseña incorrectos');
  return { encuestador: data.encuestador, token: data.token, expiraEn: data.expira_en };
}

/**
 * Revoca el token en el servidor. Se hace en el mejor esfuerzo: si no hay red
 * o el servidor falla, el cierre de sesión local debe completarse igual — dejar
 * al usuario dentro porque no hubo señal sería peor que no revocar.
 */
export async function logout() {
  const token = getToken();
  if (!token) return;
  try {
    await fetch(`${BASE_URL}/auth/logout.php`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` }
    });
  } catch (_) {
    // Sin conexión: el token caducará solo por vigencia.
  }
}

/**
 * Descarga las personas que cambiaron en el servidor después del cursor.
 *
 * El cursor es (sello, tipo, número). Con solo el sello, una página que
 * cortaba un lote sellado en el mismo milisegundo dejaba el resto del lote
 * sin descargar nunca.
 *
 * @param {{sello:number, tipo?:string, numero?:string}} cursor
 */
export async function descargarCambios(cursor = { sello: 0 }, limite = 200) {
  const token = getToken();
  if (!token) throw sinToken();

  const consulta = new URLSearchParams({ desde: String(cursor.sello || 0), limite: String(limite) });
  if (cursor.tipo && cursor.numero) {
    consulta.set('tipo', cursor.tipo);
    consulta.set('numero', cursor.numero);
  }

  const res = await pedir(`${BASE_URL}/personas/cambios.php?${consulta}`, {
    headers: { 'Authorization': `Bearer ${token}`, ...cabecerasDispositivo() }
  });

  if (res.status === 401 || res.status === 403) throw await errorDeSesion(res);
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);

  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Error al descargar cambios');
  return data;
}

export async function fetchMunicipios() {
  const res = await fetch(`${BASE_URL}/municipios/index.php`);
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);
  return res.json();
}

export async function syncData(payload) {
  const token = getToken();
  if (!token) throw sinToken();

  const res = await pedir(`${BASE_URL}/personas/sync.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
      ...cabecerasDispositivo()
    },
    body: JSON.stringify(payload)
  });

  if (res.status === 401 || res.status === 403) throw await errorDeSesion(res);
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);

  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Error en sincronización');
  return data;
}
