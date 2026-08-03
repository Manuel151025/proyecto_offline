const SESSION_KEY = 'pwa_session';

export function getSession() {
  try {
    const raw = localStorage.getItem(SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (_) {
    return null;
  }
}

export function setSession(session) {
  localStorage.setItem(SESSION_KEY, JSON.stringify(session));
}

export function clearSession() {
  localStorage.removeItem(SESSION_KEY);
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
