/**
 * Control mínimo de un navegador Chromium (Chrome o Edge) por el protocolo
 * DevTools, sin dependencias: solo Node 22 (fetch y WebSocket nativos).
 *
 * Se eligió así en lugar de Playwright o Puppeteer para no sumar cientos de
 * megas de dependencias a un proyecto que hoy no tiene node_modules.
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CANDIDATOS = [
  process.env.NAVEGADOR,
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
].filter(Boolean);

const espera = ms => new Promise(r => setTimeout(r, ms));

export function buscarNavegador() {
  return CANDIDATOS.find(r => existsSync(r)) ?? null;
}

export async function abrirNavegador() {
  const ruta = buscarNavegador();
  if (!ruta) throw new Error('No se encontró Chrome ni Edge. Define NAVEGADOR con la ruta del ejecutable.');
  const perfil = mkdtempSync(join(tmpdir(), 'colo-e2e-'));
  const proceso = spawn(ruta, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--no-sandbox', `--user-data-dir=${perfil}`,
    '--remote-debugging-port=0', 'about:blank'
  ], { stdio: 'ignore' });

  // Con puerto 0 el navegador elige uno libre y lo escribe en este archivo.
  const archivo = join(perfil, 'DevToolsActivePort');
  let puerto = null;
  for (let i = 0; i < 100 && !puerto; i++) {
    if (existsSync(archivo)) puerto = readFileSync(archivo, 'utf8').split('\n')[0].trim() || null;
    if (!puerto) await espera(100);
  }
  if (!puerto) throw new Error('El navegador no abrió el puerto de depuración');

  return {
    puerto,
    async pagina() {
      const r = await fetch(`http://127.0.0.1:${puerto}/json/new?about:blank`, { method: 'PUT' });
      const info = await r.json();
      const p = new Pagina(info.webSocketDebuggerUrl);
      await p.conectar();
      return p;
    },
    async cerrar() {
      proceso.kill();
      await espera(500);
      try { rmSync(perfil, { recursive: true, force: true }); } catch { /* el navegador puede tener archivos abiertos */ }
    }
  };
}

export class Pagina {
  constructor(url) {
    this.url = url;
    this.id = 0;
    this.pendientes = new Map();
    this.oyentes = new Map();
    this.errores = [];
  }

  async conectar() {
    this.ws = new WebSocket(this.url);
    this.ws.onmessage = e => {
      const m = JSON.parse(e.data);
      if (m.id && this.pendientes.has(m.id)) {
        const { ok, mal } = this.pendientes.get(m.id);
        this.pendientes.delete(m.id);
        m.error ? mal(new Error(m.error.message)) : ok(m.result);
      } else if (m.method) {
        if (m.method === 'Runtime.exceptionThrown') this.errores.push(m.params.exceptionDetails?.exception?.description ?? 'excepción');
        (this.oyentes.get(m.method) ?? []).forEach(f => f(m.params));
      }
    };
    await new Promise((ok, mal) => { this.ws.onopen = ok; this.ws.onerror = mal; });
    await this.enviar('Page.enable');
    await this.enviar('Runtime.enable');
    await this.enviar('Network.enable');
    // Capturas siempre en modo claro, sin importar el tema del equipo.
    await this.enviar('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
  }

  enviar(method, params = {}) {
    return new Promise((ok, mal) => {
      const id = ++this.id;
      this.pendientes.set(id, { ok, mal });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  una(evento, ms = 30000) {
    return new Promise((ok, mal) => {
      const t = setTimeout(() => mal(new Error(`Tiempo agotado esperando ${evento}`)), ms);
      const f = p => {
        clearTimeout(t);
        this.oyentes.set(evento, (this.oyentes.get(evento) ?? []).filter(x => x !== f));
        ok(p);
      };
      this.oyentes.set(evento, [...(this.oyentes.get(evento) ?? []), f]);
    });
  }

  async tamano(ancho, alto, movil = ancho < 600, escala = 1) {
    this.ancho = ancho;
    this.alto = alto;
    await this.enviar('Emulation.clearDeviceMetricsOverride');
    await this.enviar('Emulation.setDeviceMetricsOverride', {
      width: ancho, height: alto, deviceScaleFactor: escala, mobile: movil, screenWidth: ancho, screenHeight: alto
    });
  }

  /** Navega y espera a que la página termine de cargar. */
  async ir(url) {
    const carga = this.una('Page.loadEventFired');
    await this.enviar('Page.navigate', { url });
    await carga;
  }

  /** Ejecuta una acción que provoca navegación (enviar un formulario) y espera la carga. */
  async yEsperarCarga(accion) {
    const carga = this.una('Page.loadEventFired');
    await accion();
    await carga;
  }

  async evaluar(expresion) {
    const r = await this.enviar('Runtime.evaluate', { expression: expresion, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error('Error en la página: ' + (r.exceptionDetails.exception?.description ?? r.exceptionDetails.text));
    return r.result.value;
  }

  /** Espera hasta que la expresión sea verdadera. */
  async esperar(expresion, ms = 30000, descripcion = expresion) {
    const fin = Date.now() + ms;
    while (Date.now() < fin) {
      try { if (await this.evaluar(expresion)) return; } catch { /* la página puede estar navegando */ }
      await espera(150);
    }
    throw new Error(`No se cumplió a tiempo: ${descripcion}`);
  }

  texto() { return this.evaluar('document.body.innerText'); }

  /** Escribe como lo haría una persona: cambia el valor y dispara "input". */
  escribir(selector, valor) {
    return this.evaluar(`(() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) throw new Error('No existe ${selector.replace(/'/g, '')}');
      el.focus();
      el.value = ${JSON.stringify(valor)};
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return el.value;
    })()`);
  }

  clic(selector) {
    return this.evaluar(`(() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) throw new Error('No existe ${selector.replace(/'/g, '')}');
      el.click();
      return true;
    })()`);
  }

  /** Simula quedarse sin señal (o recuperarla): dispara offline/online en la página. */
  async sinRed(sinSenal) {
    await this.enviar('Network.emulateNetworkConditions', {
      offline: sinSenal, latency: 0, downloadThroughput: -1, uploadThroughput: -1
    });
  }

  /** Guarda una captura de la ventana (sin los avisos flotantes de la app). */
  async captura(ruta) {
    if (!ruta) return;
    await this.evaluar(`document.getElementById('toast-container')?.remove(); true`);
    await espera(250);
    const r = await this.enviar('Page.captureScreenshot', { format: 'png' });
    mkdirSync(join(ruta, '..'), { recursive: true });
    writeFileSync(ruta, Buffer.from(r.data, 'base64'));
  }

  cerrar() { try { this.ws.close(); } catch { /* ya cerrada */ } }
}
