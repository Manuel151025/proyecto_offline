import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import { validarPersona } from '../js/validacion.js';

const valida = {
  tipo_documento: 'CC', numero_documento: '1061702334', nombres: 'María Fernanda',
  apellidos: 'Rojas Díaz', email: 'maria@correo.co', estrato: 2,
  fecha_nacimiento: Date.UTC(1990, 4, 12), municipio_codigo: '19001'
};
const AHORA = Date.UTC(2026, 9, 8);

describe('Validación de una persona (mismas reglas que el servidor)', () => {
  test('una persona correcta no tiene errores', () => {
    assert.deepEqual(validarPersona(valida, { ahora: AHORA, municipiosValidos: new Set(['19001']) }), {});
  });

  test('documento: entre 6 y 20, solo letras, dígitos y guiones', () => {
    for (const malo of ['123', '1.020.300', '10 20 30', 'x'.repeat(21), '']) {
      assert.ok(validarPersona({ ...valida, numero_documento: malo }, { ahora: AHORA }).numero_documento, malo);
    }
    for (const bueno of ['123456', 'AB-123456', 'PE1234567']) {
      assert.equal(validarPersona({ ...valida, numero_documento: bueno }, { ahora: AHORA }).numero_documento, undefined, bueno);
    }
  });

  test('nombres y apellidos obligatorios y sin números', () => {
    const e = validarPersona({ ...valida, nombres: 'Ana 2', apellidos: '  ' }, { ahora: AHORA });
    assert.ok(e.nombres);
    assert.ok(e.apellidos);
  });

  test('estrato de 1 a 6 o vacío', () => {
    assert.ok(validarPersona({ ...valida, estrato: 9 }, { ahora: AHORA }).estrato);
    assert.ok(validarPersona({ ...valida, estrato: 0 }, { ahora: AHORA }).estrato);
    assert.equal(validarPersona({ ...valida, estrato: null }, { ahora: AHORA }).estrato, undefined);
  });

  test('correo con formato válido o vacío', () => {
    assert.ok(validarPersona({ ...valida, email: 'esto-no-es-correo' }, { ahora: AHORA }).email);
    assert.equal(validarPersona({ ...valida, email: null }, { ahora: AHORA }).email, undefined);
  });

  test('fecha de nacimiento ni futura ni anterior a 1900', () => {
    assert.ok(validarPersona({ ...valida, fecha_nacimiento: AHORA + 86400000 }, { ahora: AHORA }).fecha_nacimiento);
    assert.ok(validarPersona({ ...valida, fecha_nacimiento: Date.UTC(1850, 0, 1) }, { ahora: AHORA }).fecha_nacimiento);
  });

  test('municipio debe existir en la lista conocida', () => {
    assert.ok(validarPersona({ ...valida, municipio_codigo: '99999' }, { ahora: AHORA, municipiosValidos: new Set(['19001']) }).municipio_codigo);
  });
});
