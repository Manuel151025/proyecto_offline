import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Ningún archivo del producto tiene tildes dañadas («PopayÃ¡n», «mostrarÃ¡»).
 *
 * Aparecen cuando un texto UTF-8 se guarda o se lee como Latin-1. Llegaron a
 * verse en los teléfonos (una lista de municipios vieja) y en el código de
 * Android. Esta prueba recorre la app, la API, la PWA y los catálogos.
 */
const raiz = fileURLToPath(new URL('../../', import.meta.url));
const CARPETAS = ['app/src/main', 'api', 'pwa/js', 'pwa/css', 'pwa/data', 'pwa/index.html', 'database', 'design'];
const EXTENSIONES = /\.(kt|kts|xml|php|js|mjs|css|html|json|sql)$/;
// Secuencias que solo produce un UTF-8 leído como Latin-1, y el carácter de reemplazo.
const DAÑADO = /Ã[\u0080-¿¡-ÿ]|Â[ -¿]|â€|�/;

function* archivos(ruta) {
  const abs = join(raiz, ruta);
  if (statSync(abs).isFile()) { yield abs; return; }
  for (const nombre of readdirSync(abs)) {
    if (nombre === 'build' || nombre === 'node_modules') continue;
    yield* archivos(join(ruta, nombre));
  }
}

test('ningún archivo del producto tiene tildes dañadas', () => {
  const hallazgos = [];
  for (const carpeta of CARPETAS) {
    for (const archivo of archivos(carpeta)) {
      if (!EXTENSIONES.test(archivo)) continue;
      readFileSync(archivo, 'utf8').split('\n').forEach((linea, i) => {
        if (DAÑADO.test(linea)) hallazgos.push(`${relative(raiz, archivo)}:${i + 1}: ${linea.trim().slice(0, 80)}`);
      });
    }
  }
  assert.deepEqual(hallazgos, []);
});
