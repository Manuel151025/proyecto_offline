#!/usr/bin/env node
/**
 * Propaga la paleta de design/tokens.json a las tres superficies del producto.
 *
 * Antes los colores vivían duplicados a mano en la PWA, el panel y Android, y
 * nada impedía que divergieran. Ahora el JSON es la fuente única: este script
 * reescribe SOLO los valores de las declaraciones conocidas, sin tocar
 * comentarios ni estructura.
 *
 *   node scripts/tokens.mjs              aplica los valores
 *   node scripts/tokens.mjs --verificar  falla si algún archivo no coincide (CI)
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const { color } = JSON.parse(readFileSync(join(raiz, 'design/tokens.json'), 'utf8'));
const verificar = process.argv.includes('--verificar');

/** Variable CSS → token, por archivo. */
const CSS = {
  'pwa/css/base.css': {
    'primary': 'primario', 'primary-dark': 'primario-oscuro', 'primary-hover': 'primario-hover',
    'primary-tint': 'primario-tinte', 'primary-light': 'primario-claro',
    'accent': 'acento', 'accent-tint': 'acento-tinte',
    'surface': 'superficie', 'surface-alt': 'superficie-alt', 'bg': 'fondo',
    'on-surface': 'texto', 'on-surface-secondary': 'texto-2', 'on-surface-muted': 'texto-3',
    'divider': 'divisor', 'border-input': 'borde',
    'error': 'error', 'error-bg': 'error-fondo', 'warning': 'advertencia', 'warning-bg': 'advertencia-fondo',
    'success': 'exito', 'success-bg': 'exito-fondo', 'info': 'primario', 'info-bg': 'primario-tinte'
  },
  'api/admin/admin.css': {
    'primary': 'primario', 'primary-dark': 'primario-oscuro', 'primary-hover': 'primario-hover',
    'primary-tint': 'primario-tinte', 'primary-soft': 'primario-suave',
    'acento': 'acento', 'acento-tinte': 'acento-tinte',
    'surface': 'superficie', 'surface-alt': 'superficie-alt', 'bg': 'fondo',
    'texto': 'texto', 'texto-2': 'texto-2', 'texto-3': 'texto-3', 'divisor': 'divisor', 'borde': 'borde',
    'error': 'error', 'error-bg': 'error-fondo', 'error-borde': 'error-borde',
    'advertencia': 'advertencia-texto', 'advertencia-bg': 'advertencia-fondo', 'advertencia-borde': 'advertencia-borde',
    'ok': 'exito', 'ok-bg': 'exito-fondo', 'ok-borde': 'exito-borde'
  }
};

/** Constante de Compose → token. */
const KOTLIN = {
  'app/src/main/java/com/minsalud/encuestas/presentation/theme/Theme.kt': {
    'BrandPrimary': 'primario', 'BrandPrimaryDark': 'primario-oscuro', 'BrandPrimaryTint': 'primario-tinte',
    'BrandAccent': 'acento', 'BrandAccentTint': 'acento-tinte',
    'StatusSuccess': 'exito', 'StatusSuccessBg': 'exito-fondo', 'StatusWarning': 'advertencia',
    'StatusWarningBg': 'advertencia-fondo', 'Fondo': 'fondo', 'Superficie': 'superficie',
    'SuperficieAlt': 'superficie-alt', 'TextoPrincipal': 'texto', 'TextoSecundario': 'texto-2',
    'Borde': 'borde', 'Divisor': 'divisor'
  }
};

function valor(token, archivo) {
  const hex = color[token];
  if (!/^#[0-9A-F]{6}$/i.test(hex ?? '')) {
    throw new Error(`Token "${token}" (usado en ${archivo}) no existe o no es un color #RRGGBB`);
  }
  return hex.toUpperCase();
}

let diferencias = 0;

function aplicar(archivo, mapa, patron, sustituto) {
  const ruta = join(raiz, archivo);
  const original = readFileSync(ruta, 'utf8');
  let texto = original;
  for (const [nombre, token] of Object.entries(mapa)) {
    const re = patron(nombre);
    if (!re.test(texto)) throw new Error(`No se encontró la declaración de "${nombre}" en ${archivo}`);
    texto = texto.replace(re, (_, antes, despues = '') => antes + sustituto(valor(token, archivo)) + despues);
  }
  if (texto !== original) {
    diferencias++;
    if (verificar) console.error(`✗ ${archivo} no coincide con design/tokens.json`);
    else { writeFileSync(ruta, texto); console.log(`✓ ${archivo} actualizado`); }
  } else {
    console.log(`✓ ${archivo} al día`);
  }
}

const escapar = s => s.replace(/[-]/g, '\\-');
for (const [archivo, mapa] of Object.entries(CSS)) {
  aplicar(archivo, mapa, n => new RegExp(`^(\\s*--${escapar(n)}:\\s*)#[0-9A-Fa-f]{6}()`, 'm'), hex => hex);
}
for (const [archivo, mapa] of Object.entries(KOTLIN)) {
  aplicar(archivo, mapa, n => new RegExp(`^((?:private )?val ${n} = Color\\(0xFF)[0-9A-Fa-f]{6}(\\))`, 'm'), hex => hex.slice(1));
}

if (verificar && diferencias > 0) {
  console.error('Ejecuta `node scripts/tokens.mjs` y commitea el resultado.');
  process.exit(1);
}
