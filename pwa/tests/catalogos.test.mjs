import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
  normalizar, prepararMunicipios, prepararEps, buscarMunicipios, buscarEps, etiquetaMunicipio
} from '../js/catalogos.js';
import { limpiarCampo, validarPersona, CAMPOS } from '../js/validacion.js';

const leer = ruta => JSON.parse(readFileSync(fileURLToPath(new URL(ruta, import.meta.url)), 'utf8'));
const municipios = prepararMunicipios(leer('../data/municipios.json'));
const eps = prepararEps(leer('../data/eps.json'));
const primero = q => buscarMunicipios(municipios, q)[0];

describe('Catálogo de municipios (DIVIPOLA)', () => {
  test('trae los 1.122 municipios y los 33 departamentos', () => {
    assert.equal(municipios.length, 1122);
    assert.equal(new Set(municipios.map(m => m.departamento)).size, 33);
    assert.equal(new Set(municipios.map(m => m.codigo)).size, 1122, 'sin códigos repetidos');
  });

  test('una capital por departamento, y Bogotá como la de Cundinamarca', () => {
    const capitales = municipios.filter(m => m.capital);
    assert.equal(capitales.length, 32);
    assert.ok(capitales.some(m => m.nombre === 'Bogotá D.C.'));
    assert.ok(!capitales.some(m => m.codigo === '25001'), 'Agua de Dios no es capital');
    assert.ok(capitales.every(m => m.principal));
  });

  test('nombres en nombre propio, con tildes y sin caracteres dañados', () => {
    const dañados = municipios.filter(m => /Ã|Â|â€|�|├/.test(m.nombre + m.departamento));
    assert.deepEqual(dañados, []);
    const enMayusculas = municipios.filter(m => m.nombre.length > 3 && m.nombre === m.nombre.toUpperCase());
    assert.deepEqual(enMayusculas, []);
    for (const nombre of ['Medellín', 'Popayán', 'Quibdó', 'Itagüí', 'San José de Cúcuta', 'Bogotá D.C.']) {
      assert.ok(municipios.some(m => m.nombre === nombre), nombre);
    }
    assert.ok(municipios.some(m => m.departamento === 'Archipiélago de San Andrés, Providencia y Santa Catalina'));
  });
});

describe('Búsqueda de municipios', () => {
  test('sin tildes ni mayúsculas', () => {
    assert.equal(normalizar('  POPAYÁN  '), 'popayan');
    assert.equal(primero('popayan').nombre, 'Popayán');
    assert.equal(primero('POPA').nombre, 'Popayán');
    assert.equal(primero('medellin').nombre, 'Medellín');
    assert.equal(primero('itagui').nombre, 'Itagüí');
  });

  test('el nombre de uso común encuentra el nombre oficial', () => {
    assert.equal(primero('cali').nombre, 'Santiago de Cali', 'Cali antes que Calima');
    assert.equal(primero('cucuta').nombre, 'San José de Cúcuta');
    assert.equal(primero('bogota').nombre, 'Bogotá D.C.');
    assert.equal(primero('tumaco').nombre, 'San Andrés de Tumaco');
  });

  test('el departamento lista sus municipios, capital primero', () => {
    const cauca = buscarMunicipios(municipios, 'cauca');
    assert.equal(cauca[0].nombre, 'Popayán');
    assert.ok(cauca.slice(0, 42).every(m => m.departamento === 'Cauca'), 'los 42 del Cauca van primero');
    assert.equal(primero('antioquia').nombre, 'Medellín');
  });

  test('varias palabras: nombre y departamento a la vez', () => {
    const r = buscarMunicipios(municipios, 'san carlos antioquia');
    assert.equal(r[0].nombre, 'San Carlos');
    assert.equal(r[0].departamento, 'Antioquia');
  });

  test('sin consulta muestra las ciudades principales, capitales primero', () => {
    const r = buscarMunicipios(municipios, '');
    assert.ok(r.length > 30);
    assert.ok(r.every(m => m.principal));
    assert.ok(r[0].capital);
  });

  test('lo que no existe no devuelve nada', () => {
    assert.deepEqual(buscarMunicipios(municipios, 'xyzzy'), []);
  });

  test('etiqueta legible al elegir', () => {
    assert.equal(etiquetaMunicipio(primero('popayan')), 'Popayán, Cauca');
    assert.equal(etiquetaMunicipio(primero('bogota')), 'Bogotá D.C.');
    assert.equal(etiquetaMunicipio(municipios.find(m => m.codigo === '81001')), 'Arauca, Arauca', 'Arauca no es Bogotá');
  });
});

describe('Catálogo de EPS', () => {
  test('incluye las principales y los regímenes especiales', () => {
    for (const nombre of ['Nueva EPS', 'EPS Sanitas', 'EPS Sura', 'Asmet Salud', 'Coosalud', 'Mallamas EPSI', 'Magisterio FOMAG', 'No afiliado']) {
      assert.ok(eps.some(e => e.nombre === nombre), nombre);
    }
  });

  test('cada nombre pasa la validación del campo EPS (también en el servidor)', () => {
    for (const e of eps) {
      assert.equal(limpiarCampo('eps', e.nombre), e.nombre, `${e.nombre} no debe perder caracteres`);
      assert.ok(e.nombre.length <= CAMPOS.eps.max);
      assert.equal(validarPersona({
        tipo_documento: 'CC', numero_documento: '1061702334', nombres: 'Ana', apellidos: 'Rojas', eps: e.nombre
      }).eps, undefined, e.nombre);
    }
  });

  test('búsqueda por nombre o por descripción, sin tildes', () => {
    assert.equal(buscarEps(eps, 'sanit')[0].nombre, 'EPS Sanitas');
    assert.equal(buscarEps(eps, 'asmet')[0].nombre, 'Asmet Salud');
    assert.ok(buscarEps(eps, 'indigena').every(e => e.tipo === 'indigena'));
    assert.equal(buscarEps(eps, 'comfachoco')[0].nombre, 'Comfachocó');
    assert.equal(buscarEps(eps, '').length, eps.length);
  });
});
