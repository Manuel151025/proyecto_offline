import { getToken } from './session.js';

const BASE_URL = '../api';

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

export async function fetchMunicipios() {
  const res = await fetch(`${BASE_URL}/municipios/index.php`);
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);
  return res.json();
}

export async function syncData(payload) {
  const token = getToken();
  if (!token) {
    // Se marca para que quien llame pueda mandar al login en vez de dejar al
    // usuario leyendo un mensaje que no le dice cómo salir del atasco.
    throw Object.assign(
      new Error('Tu sesión no permite sincronizar. Vuelve a iniciar sesión con conexión.'),
      { sesionInvalida: true }
    );
  }

  const res = await fetch(`${BASE_URL}/personas/sync.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(payload)
  });

  if (res.status === 401 || res.status === 403) {
    const data = await res.json().catch(() => ({}));
    throw Object.assign(
      new Error(data.message || 'Tu sesión expiró. Inicia sesión de nuevo.'),
      { sesionInvalida: true }
    );
  }
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);

  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Error en sincronización');
  return data;
}
