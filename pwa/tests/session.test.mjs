import { test, describe, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

/**
 * El token decide si la sincronización puede salir. Si getToken devolviera uno
 * vencido, la app intentaría enviar y recibiría 401; si devolviera null
 * teniendo uno bueno, el encuestador quedaría sin poder sincronizar.
 *
 * session.js usa localStorage, que no existe en Node, así que se sustituye por
 * un objeto mínimo con el mismo contrato antes de importar el módulo.
 */

const almacen = new Map();
globalThis.localStorage = {
  getItem: clave => (almacen.has(clave) ? almacen.get(clave) : null),
  setItem: (clave, valor) => almacen.set(clave, String(valor)),
  removeItem: clave => almacen.delete(clave)
};

const { getToken, setSession, clearSession, getSession, hasActiveSession } =
  await import('../js/session.js');

const EN_SEGUNDOS = () => Math.floor(Date.now() / 1000);

describe('Token de sincronización', () => {

  beforeEach(() => almacen.clear());

  test('sin sesión no hay token', () => {
    assert.equal(getToken(), null);
  });

  test('devuelve el token cuando la sesión está vigente', () => {
    setSession({ documento: '1000000001', token: 'abc123', expiraEn: EN_SEGUNDOS() + 3600 });
    assert.equal(getToken(), 'abc123');
  });

  test('no devuelve un token vencido', () => {
    setSession({ documento: '1000000001', token: 'abc123', expiraEn: EN_SEGUNDOS() - 60 });
    assert.equal(getToken(), null, 'un token vencido solo produciría un 401');
  });

  test('una sesión sin token devuelve null', () => {
    // Es el caso del login sin conexión en un dispositivo que nunca se conectó.
    setSession({ documento: '1000000001', offline: true });
    assert.equal(getToken(), null);
  });

  test('sin fecha de expiración se acepta el token', () => {
    // Compatibilidad con sesiones guardadas antes de que existiera expiraEn.
    setSession({ documento: '1000000001', token: 'abc123' });
    assert.equal(getToken(), 'abc123');
  });

  test('cerrar sesión borra el token', () => {
    setSession({ documento: '1000000001', token: 'abc123', expiraEn: EN_SEGUNDOS() + 3600 });
    clearSession();
    assert.equal(getToken(), null);
    assert.equal(hasActiveSession(), false);
  });
});

describe('Lectura de la sesión', () => {

  beforeEach(() => almacen.clear());

  test('un JSON corrupto no rompe la app', () => {
    // Si getSession lanzara, la pantalla de login quedaría en blanco.
    localStorage.setItem('pwa_session', '{esto no es json');
    assert.equal(getSession(), null);
    assert.equal(hasActiveSession(), false);
  });

  test('conserva los datos guardados', () => {
    setSession({ documento: '1000000001', nombre: 'Docente Demo', encuestadorId: 7 });
    const s = getSession();
    assert.equal(s.documento, '1000000001');
    assert.equal(s.nombre, 'Docente Demo');
    assert.equal(s.encuestadorId, 7);
  });
});
