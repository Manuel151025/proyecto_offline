import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import { validarPersona, limpiarCampo } from '../js/validacion.js';

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

  test('cédula: de 6 a 10 dígitos, sin letras ni puntos', () => {
    for (const malo of ['123', '1.020.300', '10 20 30', 'sdscf1ds5ds1c', '12345678901', '']) {
      assert.ok(validarPersona({ ...valida, numero_documento: malo }, { ahora: AHORA }).numero_documento, malo);
    }
    for (const bueno of ['123456', '1061702334']) {
      assert.equal(validarPersona({ ...valida, numero_documento: bueno }, { ahora: AHORA }).numero_documento, undefined, bueno);
    }
  });

  test('cada tipo de documento tiene su formato', () => {
    const doc = (tipo, numero) => validarPersona({ ...valida, tipo_documento: tipo, numero_documento: numero }, { ahora: AHORA }).numero_documento;
    assert.equal(doc('TI', '1061702334'), undefined);
    assert.ok(doc('TI', '123456'));
    assert.equal(doc('PP', 'AB123456'), undefined);
    assert.ok(doc('PP', 'AB-123456'));
    assert.equal(doc('NIT', '900123456'), undefined);
  });

  test('al escribir solo entra lo que admite cada campo', () => {
    assert.equal(limpiarCampo('numero_documento', 'sdscf1ds5ds1c', 'CC'), '151');
    assert.equal(limpiarCampo('numero_documento', '123456789012', 'CC'), '1234567890');
    assert.equal(limpiarCampo('numero_documento', 'ab-12.3', 'PP'), 'AB123');
    assert.equal(limpiarCampo('nombres', '584Jairo'), 'Jairo');
    assert.equal(limpiarCampo('apellidos', 'Velasquez.,s65'), 'Velasquezs');
    assert.equal(limpiarCampo('nombres', 'María  José'), 'María José');
    assert.equal(limpiarCampo('telefono', 'saddc300 123'), '300123');
    assert.equal(limpiarCampo('estrato', '9'), '');
  });

  test('nombres y apellidos: obligatorios, solo letras y al menos 2', () => {
    const e = validarPersona({ ...valida, nombres: 'Ana 2', apellidos: '  ' }, { ahora: AHORA });
    assert.ok(e.nombres);
    assert.ok(e.apellidos);
    assert.ok(validarPersona({ ...valida, apellidos: 'Velasquez.,s' }, { ahora: AHORA }).apellidos);
    assert.ok(validarPersona({ ...valida, nombres: 'A' }, { ahora: AHORA }).nombres);
    assert.equal(validarPersona({ ...valida, apellidos: "D'Angelo Pérez-Gómez" }, { ahora: AHORA }).apellidos, undefined);
  });

  test('teléfono: celular (3…) o fijo (60…) de 10 dígitos', () => {
    assert.equal(validarPersona({ ...valida, telefono: '3001234567' }, { ahora: AHORA }).telefono, undefined);
    assert.equal(validarPersona({ ...valida, telefono: '6012345678' }, { ahora: AHORA }).telefono, undefined);
    for (const malo of ['saddc', '6012345', '1234567890', '30012345678']) {
      assert.ok(validarPersona({ ...valida, telefono: malo }, { ahora: AHORA }).telefono, malo);
    }
  });

  test('textos libres: largo mínimo y caracteres permitidos', () => {
    assert.equal(validarPersona({ ...valida, direccion: 'Calle 5 # 10-20, apto 3' }, { ahora: AHORA }).direccion, undefined);
    assert.ok(validarPersona({ ...valida, vereda: 'ab' }, { ahora: AHORA }).vereda);
    assert.ok(validarPersona({ ...valida, eps: '12345' }, { ahora: AHORA }).eps);
    assert.ok(validarPersona({ ...valida, ocupacion: 'Agricultor <b>' }, { ahora: AHORA }).ocupacion);
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
