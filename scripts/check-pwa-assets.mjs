/**
 * Verifica que todo archivo listado en el service worker exista realmente y que
 * el CSS y los módulos que carga index.html estén cacheados.
 *
 * Motivo: si se divide o renombra un archivo (por ejemplo al partir styles.css)
 * y no se actualiza pwa/sw.js, la instalación del service worker falla y la app
 * deja de funcionar SIN CONEXIÓN, que es justamente su razón de ser. Ese fallo
 * no se nota probando en línea, así que se valida en CI.
 */
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const raizPwa = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'pwa');
const errores = [];

const sw = readFileSync(join(raizPwa, 'sw.js'), 'utf8');
const bloque = sw.match(/const ASSETS = \[([\s\S]*?)\]/);

if (!bloque) {
  console.error('✗ No se encontró el arreglo ASSETS en pwa/sw.js');
  process.exit(1);
}

const cacheados = [...bloque[1].matchAll(/'([^']+)'/g)].map(m => m[1]);

for (const ruta of cacheados) {
  const absoluta = join(raizPwa, ruta.replace(/^\.\//, ''));
  if (!existsSync(absoluta)) {
    errores.push(`sw.js cachea "${ruta}" pero ese archivo no existe`);
  }
}

// Todo recurso referenciado por index.html debe estar en el caché offline.
const html = readFileSync(join(raizPwa, 'index.html'), 'utf8');
const referencias = [
  ...[...html.matchAll(/<link[^>]+href="(\.\/[^"]+)"/g)].map(m => m[1]),
  ...[...html.matchAll(/<script[^>]+src="(\.\/[^"]+)"/g)].map(m => m[1])
];

for (const ref of referencias) {
  if (ref.endsWith('manifest.json') || ref.includes('/icons/')) continue;
  if (!cacheados.includes(ref)) {
    errores.push(`index.html carga "${ref}" pero sw.js no lo cachea (rompería el modo offline)`);
  }
}

// El manifest debe ser JSON válido: si no, la PWA no es instalable.
try {
  JSON.parse(readFileSync(join(raizPwa, 'manifest.json'), 'utf8'));
} catch (e) {
  errores.push(`manifest.json no es JSON válido: ${e.message}`);
}

// El HTML debe servirse red-primero. Con caché primero, un index.html obsoleto
// sigue enlazando archivos que quizá ya no existen y la app se queda sin CSS.
// Pasó de verdad al dividir styles.css, así que se vigila.
if (!/request\.mode === 'navigate'/.test(sw)) {
  errores.push('sw.js debe tratar la navegación aparte (red primero); si no, un index.html obsoleto rompe la app');
}

// Cada versión de caché debe quedar anotada, para poder reconstruir qué
// cambió en cada despliegue cuando algo falla en un cliente concreto.
const version = sw.match(/const CACHE = 'encuestas-(v\d+)'/);
if (!version) {
  errores.push('No se pudo leer la versión de CACHE en sw.js');
} else if (!new RegExp(`// ${version[1]}:`).test(sw)) {
  errores.push(`CACHE está en ${version[1]} pero no hay una línea "// ${version[1]}:" que explique el cambio`);
}

if (errores.length) {
  console.error('✗ Verificación de la PWA fallida:');
  errores.forEach(e => console.error(`  - ${e}`));
  process.exit(1);
}

console.log(`✓ PWA verificada: ${cacheados.length} recursos cacheados, todos presentes.`);
