import { test, describe, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

/**
 * Los errores de sesión se marcan con `sesionInvalida` para que la interfaz
 * pueda llevar al login en lugar de mostrar un mensaje sin salida.
 *
 * Existe por un atasco real: al exigirse el token, quien tenía una sesión sin
 * él leía "inicia sesión con conexión" y no había ningún botón para hacerlo.
 */

const almacen = new Map();
globalThis.localStorage = {
  getItem: k => (almacen.has(k) ? almacen.get(k) : null),
  setItem: (k, v) => almacen.set(k, String(v)),
  removeItem: k => almacen.delete(k)
};

const { syncData } = await import('../js/api.js');
const { setSession } = await import('../js/session.js');

const EN_SEGUNDOS = () => Math.floor(Date.now() / 1000);
const cargaVacia = { personas: [], encuestas: [] };

describe('Errores de sesión al sincronizar', () => {

  beforeEach(() => {
    almacen.clear();
    globalThis.fetch = () => { throw new Error('no debería llegar a la red'); };
  });

  test('sin token no toca la red y marca la sesión como inválida', async () => {
    let seLlamoFetch = false;
    globalThis.fetch = async () => { seLlamoFetch = true; };

    const err = await syncData(cargaVacia).then(() => null, e => e);

    assert.ok(err, 'debería fallar');
    assert.equal(err.sesionInvalida, true, 'la interfaz necesita esta marca para redirigir');
    assert.equal(seLlamoFetch, false, 'sin token no hay nada que enviar');
  });

  test('un token vencido se trata igual que no tenerlo', async () => {
    setSession({ token: 'abc', expiraEn: EN_SEGUNDOS() - 60 });

    const err = await syncData(cargaVacia).then(() => null, e => e);

    assert.equal(err.sesionInvalida, true);
  });

  test('un 401 del servidor marca la sesión como inválida', async () => {
    setSession({ token: 'abc', expiraEn: EN_SEGUNDOS() + 3600 });
    globalThis.fetch = async () => ({
      ok: false, status: 401,
      json: async () => ({ success: false, message: 'Token inválido' })
    });

    const err = await syncData(cargaVacia).then(() => null, e => e);

    assert.equal(err.sesionInvalida, true);
    assert.match(err.message, /Token inválido/);
  });

  test('un fallo de red NO marca la sesión: la cola debe reintentarse', async () => {
    setSession({ token: 'abc', expiraEn: EN_SEGUNDOS() + 3600 });
    globalThis.fetch = async () => ({ ok: false, status: 503, json: async () => ({}) });

    const err = await syncData(cargaVacia).then(() => null, e => e);

    assert.notEqual(
      err.sesionInvalida, true,
      'cerrar la sesión por un 503 expulsaría al encuestador por un fallo del servidor'
    );
  });

  test('envía el token en la cabecera Authorization', async () => {
    setSession({ token: 'token-de-prueba', expiraEn: EN_SEGUNDOS() + 3600 });
    let cabeceras;
    globalThis.fetch = async (_url, opciones) => {
      cabeceras = opciones.headers;
      return { ok: true, status: 200, json: async () => ({ success: true }) };
    };

    await syncData(cargaVacia);

    assert.equal(cabeceras.Authorization, 'Bearer token-de-prueba');
  });
});
