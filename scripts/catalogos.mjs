#!/usr/bin/env node
/**
 * Propaga los catálogos de database/catalogos/ (municipios DIVIPOLA y EPS) a
 * todo lo que los usa, igual que tokens.mjs hace con la paleta.
 *
 * Antes cada parte tenía su propia lista: la PWA guardaba en el teléfono la
 * primera que descargaba y no la volvía a pedir, Android sembraba 200
 * municipios escritos a mano y el servidor tenía 162. Un teléfono podía
 * quedarse con 3 departamentos y tildes dañadas para siempre. Ahora hay una
 * sola fuente y cada copia lleva su versión: al cambiar, todos se actualizan.
 *
 *   node scripts/catalogos.mjs              escribe las copias
 *   node scripts/catalogos.mjs --verificar  falla si alguna copia no coincide (CI)
 *
 * Copias que escribe:
 *   pwa/data/municipios.json · pwa/data/eps.json           (la app las cachea sin red)
 *   app/src/main/assets/catalogos/municipios.json · eps.json (Android las siembra)
 *   api/municipios/catalogo.php                              (el servidor completa su tabla)
 *   database/schema.sql, entre los marcadores CATALOGO:MUNICIPIOS
 */
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const verificar = process.argv.includes('--verificar');
const leer = ruta => readFileSync(join(raiz, ruta), 'utf8');

const fuenteMunicipios = leer('database/catalogos/municipios.json');
const fuenteEps = leer('database/catalogos/eps.json');
const { municipios } = JSON.parse(fuenteMunicipios);
const { eps } = JSON.parse(fuenteEps);

/** Versión = huella del contenido: cambia sola si cambia un solo nombre. */
const huella = texto => createHash('sha256').update(texto.replace(/\r\n/g, '\n')).digest('hex').slice(0, 12);
const versionMunicipios = huella(fuenteMunicipios);
const versionEps = huella(fuenteEps);

// Formato compacto para los clientes: [código, nombre, departamento, principal].
const jsonMunicipios = JSON.stringify({
  version: versionMunicipios,
  municipios: municipios.map(m => [m.codigo, m.nombre, m.departamento, m.principal ? 1 : 0])
}) + '\n';
const jsonEps = JSON.stringify({ version: versionEps, eps }) + '\n';

const php = (() => {
  const esc = s => "'" + s.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  const filas = municipios.map(m => `    [${esc(m.codigo)}, ${esc(m.nombre)}, ${esc(m.departamento)}],`).join('\n');
  return `<?php
/**
 * Catálogo de municipios (DIVIPOLA). GENERADO por scripts/catalogos.mjs desde
 * database/catalogos/municipios.json: no editar a mano.
 *
 * api/esquema.php lo usa para completar o corregir la tabla municipios cuando
 * la versión guardada en la base no coincide con esta.
 */
return [
    'version' => '${versionMunicipios}',
    'municipios' => [
${filas}
    ],
];
`;
})();

const sql = (() => {
  const esc = s => "'" + s.replace(/'/g, "''") + "'";
  const filas = municipios.map(m => `(${esc(m.codigo)}, ${esc(m.nombre)}, ${esc(m.departamento)})`).join(',\n');
  return `-- CATALOGO:MUNICIPIOS:INICIO (generado por scripts/catalogos.mjs; no editar a mano)
-- Los ${municipios.length} municipios de Colombia (DIVIPOLA/DANE) en ${new Set(municipios.map(m => m.departamento)).size} departamentos.
INSERT INTO municipios (codigo, nombre, departamento) VALUES
${filas}
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), departamento = VALUES(departamento);
-- CATALOGO:MUNICIPIOS:FIN`;
})();

const salidas = {
  'pwa/data/municipios.json': jsonMunicipios,
  'pwa/data/eps.json': jsonEps,
  'app/src/main/assets/catalogos/municipios.json': jsonMunicipios,
  'app/src/main/assets/catalogos/eps.json': jsonEps,
  'api/municipios/catalogo.php': php
};

let fallas = 0;
function comparar(ruta, esperado) {
  const actual = existsSync(join(raiz, ruta)) ? leer(ruta).replace(/\r\n/g, '\n') : null;
  if (actual === esperado) {
    console.log(`✓ ${ruta} al día`);
    return;
  }
  if (verificar) {
    console.error(`✗ ${ruta} no coincide con database/catalogos/. Ejecuta: node scripts/catalogos.mjs`);
    fallas++;
    return;
  }
  mkdirSync(dirname(join(raiz, ruta)), { recursive: true });
  writeFileSync(join(raiz, ruta), esperado);
  console.log(`↻ ${ruta} actualizado`);
}

for (const [ruta, contenido] of Object.entries(salidas)) comparar(ruta, contenido);

// schema.sql: solo el bloque entre marcadores.
const esquema = leer('database/schema.sql').replace(/\r\n/g, '\n');
const bloque = /-- CATALOGO:MUNICIPIOS:INICIO[\s\S]*?-- CATALOGO:MUNICIPIOS:FIN/;
if (!bloque.test(esquema)) {
  console.error('✗ database/schema.sql no tiene los marcadores CATALOGO:MUNICIPIOS');
  process.exit(1);
}
comparar('database/schema.sql', esquema.replace(bloque, () => sql));

console.log(`Municipios ${municipios.length} (versión ${versionMunicipios}) · EPS ${eps.length} (versión ${versionEps})`);
if (fallas) process.exit(1);
