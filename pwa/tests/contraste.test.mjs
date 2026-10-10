import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Contraste de color según WCAG 2.1.
 *
 * La app se usa de pie y al sol: el texto tiene que leerse. Esta prueba toma
 * las combinaciones reales de texto sobre fondo que usan la PWA y el panel
 * (desde design/tokens.json y la paleta oscura de base.css) y exige el
 * mínimo AA: 4.5:1 para texto normal, 3:1 para texto grande y para los
 * elementos gráficos (bordes de campo, iconos, barras).
 */
const raiz = new URL('../../', import.meta.url);
const tokens = JSON.parse(readFileSync(fileURLToPath(new URL('design/tokens.json', raiz)), 'utf8')).color;
const base = readFileSync(fileURLToPath(new URL('pwa/css/base.css', raiz)), 'utf8');

/** Variables de la paleta oscura declaradas en base.css. */
const oscuro = Object.fromEntries(
  [...(base.split('@media (prefers-color-scheme: dark)')[1] ?? '').matchAll(/--([\w-]+):\s*(#[0-9A-Fa-f]{6})/g)].map(m => [m[1], m[2]])
);

function luminancia(hex) {
  const [r, g, b] = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map(c => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contraste(a, b) {
  const [l1, l2] = [luminancia(a), luminancia(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

const BLANCO = '#FFFFFF';
const t = tokens;

// [descripción, texto, fondo, mínimo]
const CLARO = [
  ['texto principal sobre fondo', t.texto, t.fondo, 4.5],
  ['texto principal sobre tarjeta', t.texto, t.superficie, 4.5],
  ['texto secundario sobre fondo', t['texto-2'], t.fondo, 4.5],
  ['texto secundario sobre tarjeta', t['texto-2'], t.superficie, 4.5],
  ['enlaces y navegación activa', t.primario, t.superficie, 4.5],
  ['botón principal', BLANCO, t.primario, 4.5],
  ['botón Registrar (terracota)', BLANCO, t.acento, 4.5],
  ['tarjeta del día', BLANCO, t.primario, 4.5],
  ['estado Enviada', t.exito, t['exito-fondo'], 4.5],
  ['estado Pendiente', t.advertencia, t['advertencia-fondo'], 4.5],
  ['aviso de advertencia del panel', t['advertencia-texto'], t['advertencia-fondo'], 4.5],
  ['mensaje de error', t.error, t['error-fondo'], 4.5],
  ['error debajo de un campo', t.error, t.superficie, 4.5],
  ['aviso informativo', t['primario-oscuro'], t['primario-tinte'], 4.5],
  ['iniciales en el avatar', t.primario, t['primario-tinte'], 4.5],
  ['etiqueta sobre la tarjeta del día', '#D5E2F1', t.primario, 4.5],
  ['«sin enviar» resaltado en la tarjeta del día', '#F6C77A', t.primario, 4.5],
  ['marca de la sección del formulario (gráfico)', t.acento, t.fondo, 3],
  ['barra de hoy en el gráfico (gráfico)', t.acento, t.superficie, 3],
  ['texto terciario (pistas, texto grande)', t['texto-3'], t.superficie, 3]
];

const OSCURO = [
  ['texto principal', oscuro['on-surface'], oscuro.surface, 4.5],
  ['texto principal sobre fondo', oscuro['on-surface'], oscuro.bg, 4.5],
  ['texto secundario', oscuro['on-surface-secondary'], oscuro.surface, 4.5],
  ['navegación activa', oscuro.primary, oscuro.surface, 4.5],
  ['estado Enviada', oscuro.success, oscuro['success-bg'], 4.5],
  ['estado Pendiente', oscuro.warning, oscuro['warning-bg'], 4.5],
  ['error', oscuro.error, oscuro['error-bg'], 4.5],
  ['botón Registrar (terracota)', BLANCO, oscuro.accent, 3]
];

describe('Contraste WCAG AA de la paleta', () => {
  test('la paleta oscura se pudo leer de base.css', () => {
    for (const clave of ['on-surface', 'surface', 'bg', 'primary', 'success', 'warning', 'error', 'accent']) {
      assert.match(oscuro[clave] ?? '', /^#/, clave);
    }
  });

  test('el cálculo coincide con valores de referencia', () => {
    assert.equal(contraste('#000000', '#FFFFFF').toFixed(1), '21.0');
    assert.equal(contraste('#777777', '#FFFFFF').toFixed(2), '4.48');
  });

  for (const [modo, casos] of [['claro', CLARO], ['oscuro', OSCURO]]) {
    test(`modo ${modo}: todas las combinaciones de texto sobre fondo cumplen`, () => {
      const fallas = casos
        .map(([nombre, texto, fondo, minimo]) => ({ nombre, texto, fondo, minimo, valor: contraste(texto, fondo) }))
        .filter(c => c.valor < c.minimo)
        .map(c => `${c.nombre}: ${c.texto} sobre ${c.fondo} = ${c.valor.toFixed(2)} (mínimo ${c.minimo})`);
      assert.deepEqual(fallas, []);
    });
  }
});
