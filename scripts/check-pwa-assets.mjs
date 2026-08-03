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

if (errores.length) {
  console.error('✗ Verificación de la PWA fallida:');
  errores.forEach(e => console.error(`  - ${e}`));
  process.exit(1);
}

console.log(`✓ PWA verificada: ${cacheados.length} recursos cacheados, todos presentes.`);
