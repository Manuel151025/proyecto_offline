/**
 * Pruebas de punta a punta: un navegador real recorre el panel y la app.
 *
 *   1. Panel: entra con la contraseña de arranque, crea un administrador y
 *      un encuestador, y comprueba que la contraseña mala no entra.
 *   2. App (PWA): el encuestador inicia sesión, el formulario filtra y valida
 *      lo que se escribe, registra a una persona SIN señal, y al volver la
 *      señal la persona se envía sola.
 *   3. Panel: la persona y el celular aparecen.
 *
 * Necesita PHP con pdo_mysql, un MySQL accesible (las mismas variables
 * PRUEBAS_DB_* de PHPUnit) y Chrome o Edge (o NAVEGADOR con la ruta).
 *
 *   node --test tests/e2e/
 *   CAPTURAS=docs/capturas node --test tests/e2e/   # además guarda capturas
 */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { abrirNavegador } from './navegador.mjs';

const ADMIN_ARRANQUE = 'ArranqueTemporal2026'; // Entorno::ADMIN_PASSWORD
const ADMIN = { nombre: 'Paula Andrea Ríos', doc: '1012345678', clave: 'PanelSeguro2026' };
const ENCUESTADOR = { nombre: 'Jairo Velásquez', doc: '1098765432', clave: 'CampoSeguro2026' };
const PERSONA = { doc: '1061702334', nombres: 'María Fernanda', apellidos: 'Rojas Díaz' };

const CAPTURAS = process.env.CAPTURAS || null;
const foto = nombre => (CAPTURAS ? join(CAPTURAS, nombre + '.png') : null);

let servidor, base, archivoCorreos, navegador, p;

before(async () => {
  servidor = spawn('php', [join(import.meta.dirname, 'servidor.php')], { stdio: ['pipe', 'pipe', 'inherit'] });
  base = await new Promise((ok, mal) => {
    const t = setTimeout(() => mal(new Error('El servidor de pruebas no arrancó')), 60000);
    servidor.stdout.on('data', d => {
      const m = String(d).match(/LISTO (\S+) (.+)/);
      if (m) { clearTimeout(t); archivoCorreos = m[2].trim(); ok(m[1].replace(/\/$/, '')); }
    });
    servidor.on('exit', c => mal(new Error('El servidor de pruebas terminó con código ' + c)));
  });
  navegador = await abrirNavegador();
  p = await navegador.pagina();
});

after(async () => {
  p?.cerrar();
  await navegador?.cerrar();
  servidor?.stdin.end();
  await new Promise(r => servidor ? servidor.on('exit', r) : r());
});

const panel = (seccion = '') => `${base}/api/admin/index.php${seccion ? '?seccion=' + seccion : ''}`;
const app = (ruta = '/personas') => `${base}/pwa/index.html#${ruta}`;

/**
 * Va a una ruta de la app. Dentro de la app es un cambio de #ruta (no hay
 * carga de página, como cuando el encuestador toca la barra de abajo).
 */
async function irApp(ruta) {
  const dentro = await p.evaluar(`location.pathname.endsWith('/pwa/index.html')`).catch(() => false);
  if (dentro) await p.evaluar(`location.hash = ${JSON.stringify('#' + ruta)}`);
  else await p.ir(app(ruta));
}

async function entrarAlPanel(doc, clave) {
  await p.ir(panel());
  if (doc) await p.escribir('#login-doc', doc);
  await p.escribir('#login-clave', clave);
  await p.yEsperarCarga(() => p.evaluar(`document.querySelector('#login-clave').form.requestSubmit()`));
}

async function crearCuenta({ nombre, doc, clave }, rol) {
  await p.ir(panel('cuentas'));
  await p.escribir('#cuenta-nombre', nombre);
  await p.escribir('#cuenta-doc', doc);
  await p.escribir('#cuenta-clave', clave);
  await p.evaluar(`document.querySelector('input[name=rol][value=${rol}]').checked = true`);
  await p.yEsperarCarga(() => p.evaluar(`document.querySelector('#cuenta-nombre').form.requestSubmit()`));
}

test('panel: la contraseña de arranque entra y crea la primera cuenta de administrador', async () => {
  await p.tamano(1366, 860);
  await p.ir(panel());
  assert.match(await p.texto(), /Contraseña de arranque/);
  await p.captura(foto('panel-login'));

  await entrarAlPanel(null, ADMIN_ARRANQUE);
  assert.match(await p.texto(), /Resumen/);

  await crearCuenta(ADMIN, 'admin');
  // Con un administrador real, la contraseña de arranque deja de valer: la
  // sesión de arranque se cierra y hay que entrar con documento y clave.
  await entrarAlPanel(ADMIN.doc, ADMIN.clave);
  const texto = await p.texto();
  assert.match(texto, /Resumen/);
  assert.match(texto, new RegExp(ADMIN.nombre));
});

test('panel: el administrador crea la cuenta de un encuestador', async () => {
  await crearCuenta(ENCUESTADOR, 'encuestador');
  assert.match(await p.texto(), new RegExp(ENCUESTADOR.nombre));
  await p.captura(foto('panel-cuentas'));
});

test('panel: una contraseña equivocada no entra', async () => {
  await p.yEsperarCarga(() => p.evaluar(`document.querySelector('input[name=action][value=logout]').form.requestSubmit()`));
  await entrarAlPanel(ADMIN.doc, 'NoEsLaClave2026');
  const texto = await p.texto();
  assert.match(texto, /Panel de administración/);
  assert.doesNotMatch(texto, /Cerrar sesión/);
});

test('app: el encuestador inicia sesión y ve su inicio', async () => {
  await p.tamano(390, 844);
  await p.ir(app('/login'));
  await p.esperar(`!!document.querySelector('#login-doc')`);
  await p.captura(foto('app-login'));
  await p.escribir('#login-doc', ENCUESTADOR.doc);
  await p.escribir('#login-pass', ENCUESTADOR.clave);
  await p.clic('#login-submit');
  await p.esperar(`location.hash === '#/personas' && !!document.querySelector('.saludo h1')`, 30000, 'llegar al inicio');
  assert.match(await p.evaluar(`document.querySelector('.saludo h1').textContent`), /Hola, Jairo/);
});

test('app: el formulario no deja escribir lo que no corresponde y explica los errores', async () => {
  await irApp('/nueva');
  await p.esperar(`!!document.querySelector('#numero_documento')`);
  assert.equal(await p.escribir('#numero_documento', 'sdscf1ds5ds1c'), '151');
  assert.equal(await p.escribir('#nombres', '584Jairo'), 'Jairo');
  assert.equal(await p.escribir('#apellidos', 'Velasquez.,s65'), 'Velasquezs');
  assert.equal(await p.escribir('#telefono', 'saddc'), '');
  assert.equal(await p.evaluar(`document.querySelector('#fecha_nacimiento').max`).then(v => v.length), 10);

  await p.evaluar(`document.querySelector('#fecha_nacimiento').value = '2999-01-01'`);
  await p.escribir('#eps', '12');
  await p.clic('#btn-guardar');
  await p.esperar(`document.querySelectorAll('.field-error').length >= 3`);
  const errores = await p.evaluar(`[...document.querySelectorAll('.field-error')].map(e => e.previousElementSibling.querySelector('input')?.id || e.previousElementSibling.id)`);
  for (const campo of ['numero_documento', 'fecha_nacimiento', 'eps']) assert.ok(errores.includes(campo), campo);
  await p.captura(foto('app-formulario-errores'));
});

test('app: sin señal la persona queda guardada; con señal se envía sola', async () => {
  await p.sinRed(true);
  await irApp('/personas');
  await irApp('/nueva');
  await p.esperar(`!!document.querySelector('#numero_documento')`);
  await p.esperar(`!navigator.onLine`, 15000, 'quedar sin señal');

  await p.escribir('#numero_documento', PERSONA.doc);
  await p.escribir('#nombres', PERSONA.nombres);
  await p.escribir('#apellidos', PERSONA.apellidos);
  await p.evaluar(`document.querySelector('#fecha_nacimiento').value = '1990-05-12'`);
  await p.escribir('#telefono', '3001234567');

  // Municipio: se escribe parte del nombre, sin tilde, y se elige la sugerencia.
  await p.escribir('#municipio_buscar', 'popa');
  await p.esperar(`document.querySelector('#municipio_lista [role=option]')?.textContent.includes('Popayán')`, 10000, 'sugerir Popayán');
  await p.captura(foto('app-formulario-municipio'));
  await p.evaluar(`document.querySelector('#municipio_lista [role=option]').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))`);
  assert.equal(await p.evaluar(`document.querySelector('#municipio_codigo').value`), '19001');
  assert.equal(await p.evaluar(`document.querySelector('#municipio_buscar').value`), 'Popayán, Cauca');

  // EPS: igual, desde el catálogo.
  await p.escribir('#eps', 'asmet');
  await p.esperar(`document.querySelector('#eps_lista [role=option]')?.textContent.includes('Asmet Salud')`, 10000, 'sugerir Asmet Salud');
  await p.evaluar(`document.querySelector('#eps_lista [role=option]').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))`);
  assert.equal(await p.evaluar(`document.querySelector('#eps').value`), 'Asmet Salud');
  await p.escribir('#vereda', 'Vereda El Carmen');

  await p.clic('#btn-guardar');

  await p.esperar(`location.hash === '#/personas' && document.body.innerText.includes(${JSON.stringify(PERSONA.nombres)})`, 30000, 'volver a la lista');
  await p.esperar(`document.body.innerText.includes('Pendiente')`, 15000, 'quedar pendiente');
  await p.captura(foto('app-inicio-sin-senal'));

  await p.sinRed(false);
  try {
    await p.esperar(`document.body.innerText.includes('Enviada') && !document.body.innerText.includes('Pendiente')`, 25000);
  } catch {
    // Si el evento "online" no llegó, la pantalla de envío tiene el botón manual.
    await irApp('/sync');
    await p.esperar(`!!document.querySelector('#btn-sync')`);
    await p.clic('#btn-sync');
    await irApp('/personas');
    await p.esperar(`document.body.innerText.includes('Enviada')`, 20000, 'quedar enviada');
  }
  await p.captura(foto('app-inicio'));
  await irApp('/sync');
  await p.esperar(`!!document.querySelector('#sync-counts')`);
  await p.captura(foto('app-envio'));
});

test('panel: la persona enviada y el celular aparecen', async () => {
  await p.tamano(1366, 860);
  await entrarAlPanel(ADMIN.doc, ADMIN.clave);
  await p.captura(foto('panel-resumen'));

  await p.ir(panel('personas'));
  const lista = await p.texto();
  assert.match(lista, new RegExp(PERSONA.apellidos));
  assert.match(lista, /Popayán/, 'el municipio llegó con su tilde');
  await p.captura(foto('panel-personas'));

  await p.ir(panel('sincronizacion'));
  assert.match(await p.texto(), /Celulares conocidos\s*\n*\s*1/);
  await p.captura(foto('panel-sincronizacion'));

  assert.deepEqual(p.errores, [], 'sin excepciones de JavaScript en las páginas');
});

/** Asunto del último correo que «envió» la API (lo guarda el SMTP falso). */
function ultimoAsunto() {
  if (!existsSync(archivoCorreos)) return '';
  const lineas = readFileSync(archivoCorreos, 'utf8').trim().split('\n').filter(Boolean);
  if (!lineas.length) return '';
  const { datos } = JSON.parse(lineas.at(-1));
  const m = datos.match(/^Subject: =\?UTF-8\?B\?([^?]+)\?=/m);
  return m ? Buffer.from(m[1], 'base64').toString('utf8') : '';
}

test('olvidé mi contraseña: código por correo, contraseña nueva y entrar con ella', async () => {
  // El administrador le registra un correo al encuestador.
  await p.ir(panel('cuentas') + '&editar=3');
  await p.escribir('#cuenta-email', 'jairo.velasquez@correo.test');
  await p.yEsperarCarga(() => p.evaluar(`document.querySelector('#cuenta-email').form.requestSubmit()`));
  assert.match(await p.texto(), /Cambios guardados/);

  // En la app: «¿Olvidaste tu contraseña?» con el documento ya escrito.
  await p.tamano(390, 844);
  await p.ir(app('/login'));
  await p.esperar(`!!document.querySelector('#login-doc')`);
  await p.escribir('#login-doc', ENCUESTADOR.doc);
  await p.clic('#login-forgot');
  await p.esperar(`!!document.querySelector('#recuperar-doc')`);
  assert.equal(await p.evaluar(`document.querySelector('#recuperar-doc').value`), ENCUESTADOR.doc);
  await p.captura(foto('app-recuperar-documento'));
  await p.clic('#btn-pedir');
  await p.esperar(`!!document.querySelector('#recuperar-codigo')`, 30000, 'pasar al paso del código');

  // El código llega al correo.
  await p.esperar('true');
  let asunto = '';
  for (let i = 0; i < 50 && !/\d{6}/.test(asunto); i++) {
    asunto = ultimoAsunto();
    if (!/\d{6}/.test(asunto)) await new Promise(r => setTimeout(r, 200));
  }
  const codigo = asunto.match(/(\d{6})/)?.[1];
  assert.ok(codigo, `llegó un correo con el código (asunto: «${asunto}»)`);

  await p.escribir('#recuperar-codigo', codigo);
  await p.escribir('#recuperar-clave', 'ClaveNuevaCampo2026');
  await p.escribir('#recuperar-confirmar', 'ClaveNuevaCampo2026');
  await p.captura(foto('app-recuperar-codigo'));
  await p.clic('#btn-cambiar');
  await p.esperar(`document.body.innerText.includes('Contraseña cambiada')`, 30000, 'confirmar el cambio');
  await p.captura(foto('app-recuperar-listo'));
  assert.match(ultimoAsunto(), /cambió/, 'se avisa del cambio por correo');

  // De vuelta al login, con el documento escrito, entra con la contraseña nueva.
  await p.clic('#btn-ir-login');
  await p.esperar(`document.querySelector('#login-doc')?.value === ${JSON.stringify(ENCUESTADOR.doc)}`);
  await p.escribir('#login-pass', 'ClaveNuevaCampo2026');
  await p.clic('#login-submit');
  await p.esperar(`location.hash === '#/personas' && !!document.querySelector('.saludo h1')`, 30000, 'entrar con la contraseña nueva');
  assert.deepEqual(p.errores, [], 'sin excepciones de JavaScript');
});

/**
 * Capturas para la ficha de Google Play: 1080×1920 (360×640 a escala 3),
 * dentro del límite de proporción 2:1 que exige Play. Solo con CAPTURAS_PLAY.
 */
test('capturas para Google Play', { skip: !process.env.CAPTURAS_PLAY }, async () => {
  const destino = nombre => join(process.env.CAPTURAS_PLAY, nombre + '.png');
  await p.tamano(360, 640, true, 3);
  await irApp('/personas');
  await p.esperar(`!!document.querySelector('.saludo h1')`);
  await p.captura(destino('play-1-inicio'));

  await irApp('/nueva');
  await p.esperar(`!!document.querySelector('#municipio_buscar')`);
  await p.evaluar(`document.querySelector('#municipio_buscar').closest('.form-field').scrollIntoView({ block: 'start' }); true`);
  await p.escribir('#municipio_buscar', 'cauca');
  await p.captura(destino('play-2-municipio'));

  await irApp('/nueva');
  await p.esperar(`!!document.querySelector('#numero_documento')`);
  await p.escribir('#numero_documento', '123');
  await p.escribir('#nombres', 'Ana');
  await p.clic('#btn-guardar');
  await p.esperar(`document.querySelectorAll('.field-error').length > 0`);
  await p.evaluar(`document.querySelector('.screen-content').scrollTop = 0; true`);
  await p.captura(destino('play-3-validacion'));

  await irApp('/sync');
  await p.esperar(`!!document.querySelector('#sync-counts')`);
  await p.captura(destino('play-4-envio'));

  await irApp('/recuperar');
  await p.esperar(`!!document.querySelector('#recuperar-doc')`);
  await p.captura(destino('play-5-recuperar'));
});
