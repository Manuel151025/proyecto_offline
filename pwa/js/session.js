import { guardarKV, borrarKV } from './db.js';
import { getDeviceId } from './utils.js';

const SESSION_KEY = 'pwa_session';

/**
 * Dónde vive la sesión.
 *
 * Con «Recordar sesión» activo, en localStorage: sobrevive a cerrar la app.
 * Sin él, en sessionStorage: se borra al cerrar la pestaña. Antes la casilla
 * se mostraba pero no hacía nada.
 */
function almacen(remember) {
  return remember === false && globalThis.sessionStorage ? globalThis.sessionStorage : localStorage;
}

/** Los almacenes disponibles (en pruebas de Node solo existe el simulado). */
function almacenes() {
  return [globalThis.localStorage, globalThis.sessionStorage].filter(Boolean);
}

export function getSession() {
  for (const store of almacenes()) {
    try {
      const raw = store.getItem(SESSION_KEY);
      if (raw) return JSON.parse(raw);
    } catch (_) {
      // Almacenamiento bloqueado o JSON corrupto: se prueba el siguiente.
    }
  }
  return null;
}

export function setSession(session) {
  almacenes().forEach(s => s.removeItem(SESSION_KEY));
  almacen(session.remember).setItem(SESSION_KEY, JSON.stringify(session));
  // El service worker no puede leer localStorage: necesita el token en
  // IndexedDB para subir la cola cuando la app está cerrada.
  if (session.remember !== false && session.token) {
    guardarKV('sesion', { token: session.token, expiraEn: session.expiraEn ?? null, deviceId: getDeviceId() }).catch(() => {});
  } else {
    borrarKV('sesion').catch(() => {});
  }
}

export function clearSession() {
  almacenes().forEach(s => s.removeItem(SESSION_KEY));
  borrarKV('sesion').catch(() => {});
}

export function hasActiveSession() {
  return !!getSession();
}

/**
 * Token de API para autenticar la sincronización.
 * Se emite al iniciar sesión en línea; el login offline lo recupera de la
 * credencial guardada en IndexedDB, de modo que un encuestador que entra sin
 * red conserva el token emitido la última vez que estuvo conectado.
 * Devuelve null si no hay token o si ya venció (expira_en va en segundos).
 */
export function getToken() {
  const session = getSession();
  if (!session?.token) return null;
  if (session.expiraEn && Date.now() / 1000 > session.expiraEn) return null;
  return session.token;
}
