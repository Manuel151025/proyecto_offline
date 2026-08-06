import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

// db.js usa localStorage e indexedDB al importarse en el navegador; en Node
// basta con que exista localStorage, porque decidirMezcla no toca la base.
const almacen = new Map();
globalThis.localStorage = {
  getItem: k => (almacen.has(k) ? almacen.get(k) : null),
  setItem: (k, v) => almacen.set(k, String(v)),
  removeItem: k => almacen.delete(k)
};

const { decidirMezcla } = await import('../js/db.js');

/**
 * Regla de mezcla al descargar del servidor.
 *
 * Es la pieza que decide si el trabajo de campo se conserva o se pisa. Un
 * error aquí no da un mensaje de error: borra en silencio una encuesta recién
 * hecha, y nadie se entera hasta que falta el dato.
 */

const persona = (updated_at, pendiente = false) => ({
  tipo_documento: 'CC',
  numero_documento: '111',
  nombres: 'Ana',
  updated_at,
  _pendingSync: pendiente
});

describe('Qué hacer con una persona que llega del servidor', () => {

  test('si no existe en local, se guarda', () => {
    assert.equal(decidirMezcla(undefined, persona(1000)), 'nueva');
    assert.equal(decidirMezcla(null, persona(1000)), 'nueva');
  });

  test('si la remota es más reciente, se actualiza', () => {
    assert.equal(decidirMezcla(persona(1000), persona(2000)), 'actualizar');
  });

  test('si la local es más reciente, se conserva', () => {
    assert.equal(decidirMezcla(persona(2000), persona(1000)), 'conservar');
  });

  test('ante el mismo updated_at no se toca nada', () => {
    // Reescribir sin necesidad solo genera trabajo y riesgo.
    assert.equal(decidirMezcla(persona(1000), persona(1000)), 'conservar');
  });

  test('los cambios locales SIN ENVIAR nunca se pisan', () => {
    // El caso que de verdad importa: el encuestador acaba de registrar algo
    // que todavía no subió. Lo que baja es por fuerza anterior, aunque su
    // updated_at parezca mayor por un reloj mal puesto en otro dispositivo.
    assert.equal(
      decidirMezcla(persona(1000, true), persona(9999)),
      'conservar',
      'sobrescribir aquí borraría una encuesta recién hecha'
    );
  });

  test('un registro ya sincronizado sí acepta cambios ajenos', () => {
    assert.equal(decidirMezcla(persona(1000, false), persona(2000)), 'actualizar');
  });

  test('un borrado remoto se aplica como cualquier otro cambio', () => {
    const borrada = { ...persona(5000), deleted_at: 5000 };
    assert.equal(decidirMezcla(persona(1000), borrada), 'actualizar');
  });

  test('un borrado remoto NO pisa trabajo local sin enviar', () => {
    const borrada = { ...persona(9999), deleted_at: 9999 };
    assert.equal(decidirMezcla(persona(1000, true), borrada), 'conservar');
  });
});
