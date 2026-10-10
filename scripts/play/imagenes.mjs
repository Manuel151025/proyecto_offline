#!/usr/bin/env node
/**
 * Genera el ícono (512×512) y el gráfico destacado (1024×500) para la ficha de
 * Google Play, en docs/play/. Las capturas de pantalla (1080×1920) las genera
 * la prueba de punta a punta con CAPTURAS_PLAY=docs/play.
 *
 *   node scripts/play/imagenes.mjs
 *
 * Necesita Chrome o Edge (ver tests/e2e/navegador.mjs).
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { abrirNavegador } from '../../tests/e2e/navegador.mjs';

const aqui = dirname(fileURLToPath(import.meta.url));
const salida = join(aqui, '..', '..', 'docs', 'play');
mkdirSync(salida, { recursive: true });

const navegador = await abrirNavegador();
const p = await navegador.pagina();
for (const [archivo, ancho, alto, destino] of [
  ['icono.html', 512, 512, 'icono-512.png'],
  ['grafico.html', 1024, 500, 'grafico-destacado-1024x500.png']
]) {
  await p.enviar('Emulation.setDeviceMetricsOverride', { width: ancho, height: alto, deviceScaleFactor: 1, mobile: false });
  await p.ir(pathToFileURL(join(aqui, archivo)).href);
  await p.evaluar('document.fonts.ready.then(() => true)');
  const r = await p.enviar('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: ancho, height: alto, scale: 1 } });
  writeFileSync(join(salida, destino), Buffer.from(r.data, 'base64'));
  console.log('✓', join('docs/play', destino));
}
p.cerrar();
await navegador.cerrar();
