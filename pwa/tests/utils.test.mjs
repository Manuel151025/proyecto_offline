import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import {
  dateToMs,
  msToDateInput,
  formatDate,
  formatDateTime,
  generateUUID,
  nowMs
} from '../js/utils.js';

/**
 * Estas pruebas existen por un fallo real: la fecha de nacimiento se guardaba
 * como medianoche UTC pero se leía con métodos locales, así que en Colombia
 * (UTC-5) se mostraba el día anterior. Peor aún, el error se acumulaba: cada
 * edición del registro corría la fecha una jornada más atrás.
 *
 * Las comprobaciones son independientes del huso a propósito: si alguien vuelve
 * a mezclar criterios, fallan aquí en cualquier máquina.
 */

describe('Fecha de nacimiento (fecha de calendario)', () => {

  test('ida y vuelta conserva el día', () => {
    for (const fecha of ['2000-01-15', '1985-06-30', '2010-12-31', '1999-02-28']) {
      assert.equal(msToDateInput(dateToMs(fecha)), fecha, `se corrió el día en ${fecha}`);
    }
  });

  test('editar el registro varias veces no corre la fecha', () => {
    // El fallo original restaba un día en CADA edición.
    let fecha = '2000-01-15';
    for (let i = 0; i < 10; i++) fecha = msToDateInput(dateToMs(fecha));
    assert.equal(fecha, '2000-01-15');
  });

  test('formatDate muestra el día seleccionado, no el anterior', () => {
    // 15 de enero de 2000 a medianoche UTC.
    const ms = Date.UTC(2000, 0, 15);
    assert.match(formatDate(ms), /15/, 'debería mostrar el día 15');
  });

  test('se cruza bien el cambio de año', () => {
    assert.equal(msToDateInput(dateToMs('2024-01-01')), '2024-01-01');
    assert.equal(msToDateInput(dateToMs('2023-12-31')), '2023-12-31');
  });

  test('el año bisiesto se conserva', () => {
    assert.equal(msToDateInput(dateToMs('2024-02-29')), '2024-02-29');
  });

  test('valores vacíos no revientan', () => {
    assert.equal(dateToMs(''), null);
    assert.equal(dateToMs(null), null);
    assert.equal(msToDateInput(null), '');
    assert.equal(msToDateInput(0), '');
    assert.equal(formatDate(null), '—');
  });
});

describe('Marcas de sincronización (instantes reales)', () => {

  test('formatDateTime NO fuerza UTC: son instantes, van en hora local', () => {
    // Una sincronización ocurrió en un momento concreto; mostrarla en UTC
    // confundiría al encuestador. Se comprueba que incluye la hora.
    const texto = formatDateTime(Date.UTC(2024, 5, 15, 14, 30));
    assert.match(texto, /\d{1,2}:\d{2}/, 'debería incluir la hora');
  });

  test('sin valor devuelve guion', () => {
    assert.equal(formatDateTime(null), '—');
  });
});

describe('Identificadores y tiempo', () => {

  test('generateUUID devuelve el formato esperado', () => {
    assert.match(
      generateUUID(),
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
    );
  });

  test('generateUUID no repite', () => {
    const generados = new Set(Array.from({ length: 500 }, generateUUID));
    assert.equal(generados.size, 500);
  });

  test('nowMs devuelve milisegundos plausibles', () => {
    const t = nowMs();
    assert.ok(Number.isInteger(t));
    assert.ok(Math.abs(Date.now() - t) < 1000);
  });
});
