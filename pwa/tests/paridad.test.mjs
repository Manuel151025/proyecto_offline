import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { DOCUMENTOS, CAMPOS, TIPOS_DOCUMENTO } from '../js/validacion.js';

/**
 * Las reglas de validación viven en tres lugares a propósito (cada cliente
 * valida sin red; el servidor no confía en ninguno):
 *   - pwa/js/validacion.js
 *   - api/personas/validacion.php
 *   - app/.../domain/validation/Validaciones.kt
 *
 * Si alguien cambia una y olvida las otras, el teléfono guardaría datos que
 * el servidor rechaza días después. Esta prueba lee las otras dos fuentes y
 * exige que coincidan con la de la PWA.
 */
const raiz = new URL('../../', import.meta.url);
const leer = ruta => readFileSync(fileURLToPath(new URL(ruta, raiz)), 'utf8');
const php = leer('api/personas/validacion.php');
const kotlin = leer('app/src/main/java/com/minsalud/encuestas/domain/validation/Validaciones.kt');
const enumKotlin = leer('app/src/main/java/com/minsalud/encuestas/domain/model/Enums.kt');

describe('Paridad de reglas entre PWA, servidor y Android', () => {
  test('los mismos tipos de documento', () => {
    const tiposPhp = php.match(/const TIPOS_DOCUMENTO = \[([^\]]+)\]/)[1].match(/'(\w+)'/g).map(t => t.slice(1, -1));
    const tiposKt = [...enumKotlin.matchAll(/^\s+([A-Z]{2,3})\(/gm)].map(m => m[1]);
    assert.deepEqual(tiposPhp, TIPOS_DOCUMENTO);
    assert.deepEqual(tiposKt, TIPOS_DOCUMENTO);
  });

  test('el mismo formato de número por tipo', () => {
    for (const [tipo, regla] of Object.entries(DOCUMENTOS)) {
      const enPhp = php.match(new RegExp(`'${tipo}'\\s*=>\\s*\\[(\\d+),\\s*(\\d+),\\s*(true|false)\\]`));
      assert.ok(enPhp, `${tipo} en validacion.php`);
      assert.deepEqual([+enPhp[1], +enPhp[2], enPhp[3] === 'true'], [regla.min, regla.max, regla.letras], `${tipo} en PHP`);

      const enKt = kotlin.match(new RegExp(`TipoDocumento\\.${tipo} -> FormatoDocumento\\((\\d+), (\\d+), (true|false), "([^"]+)"\\)`));
      assert.ok(enKt, `${tipo} en Validaciones.kt`);
      assert.deepEqual([+enKt[1], +enKt[2], enKt[3] === 'true'], [regla.min, regla.max, regla.letras], `${tipo} en Kotlin`);
      assert.equal(enKt[4], regla.ayuda, `${tipo}: el mismo texto de ayuda`);
    }
  });

  test('los mismos largos y caracteres en los textos libres', () => {
    for (const campo of ['direccion', 'vereda', 'eps', 'ocupacion']) {
      const regla = CAMPOS[campo];
      const enPhp = php.match(new RegExp(`'${campo}'\\s*=>\\s*\\[(\\d+),\\s*(\\d+),\\s*"([^"]+)"\\]`));
      assert.ok(enPhp, `${campo} en validacion.php`);
      assert.deepEqual([+enPhp[1], +enPhp[2]], [regla.min, regla.max], `${campo}: largos en PHP`);
      // En PHP la barra va escapada porque el patrón usa "/" como delimitador.
      assert.equal(enPhp[3].replace(/\\\//g, '/').replace(/\\\\/g, '\\'), regla.permitidos, `${campo}: caracteres en PHP`);

      const enKt = kotlin.match(new RegExp(`${campo.toUpperCase()}\\((\\d+), (\\d+), "([^"]+)"\\)`));
      assert.ok(enKt, `${campo} en Validaciones.kt`);
      assert.deepEqual([+enKt[1], +enKt[2]], [regla.min, regla.max], `${campo}: largos en Kotlin`);
      assert.equal(enKt[3].replace(/\\\\/g, '\\'), regla.permitidos, `${campo}: caracteres en Kotlin`);
    }
  });

  test('el mismo teléfono y el mismo largo de nombres', () => {
    const telefono = '(3\\d{9}|60\\d{8})';
    assert.ok(php.includes(`'/^${telefono}$/'`), 'teléfono en PHP');
    assert.ok(kotlin.includes(telefono.replace(/\\/g, '\\\\')), 'teléfono en Kotlin');
    assert.ok(readFileSync(fileURLToPath(new URL('pwa/js/validacion.js', raiz)), 'utf8').includes(`/^${telefono}$/`), 'teléfono en la PWA');

    assert.equal(CAMPOS.nombres.max, 60);
    assert.ok(php.includes('$largo < 2 || $largo > 60'), 'nombres de 2 a 60 en PHP');
    assert.ok(kotlin.includes('const val MAX_NOMBRE = 60'), 'nombres hasta 60 en Kotlin');
  });
});
