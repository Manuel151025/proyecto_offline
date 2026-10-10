/**
 * «¿Olvidaste tu contraseña?»
 *
 * 1. El encuestador escribe su documento y pide un código.
 * 2. Le llega al correo de su cuenta un código de 6 dígitos (15 minutos).
 * 3. Escribe el código y su contraseña nueva.
 *
 * Necesita señal: el código lo envía y lo comprueba el servidor. Si la cuenta
 * no tiene correo, el mensaje le indica que pida el cambio a su administrador.
 */
import { navigate } from '../router.js';
import { pedirCodigoRecuperacion, restablecerContrasena } from '../api.js';

const DOC_REGEX = /^[0-9]{6,12}$/;
const MIN_CLAVE = 10;
const ESPERA_REENVIO_S = 60;

export async function render(container) {
  const docInicial = (sessionStorage.getItem('recuperar_doc') || '').replace(/\D/g, '').slice(0, 12);
  sessionStorage.removeItem('recuperar_doc');

  container.innerHTML = `
    <div class="login-screen">
      <div class="login-bar"></div>
      <div class="login-container">
        <header class="login-header">
          <div class="login-logo">
            <svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z" fill="#ffffff"/>
            </svg>
          </div>
          <div>
            <h1 class="login-brand">ColOffline</h1>
            <p class="login-subtitle">Encuestas demogr&aacute;ficas sin conexi&oacute;n</p>
          </div>
        </header>

        <div class="login-card">
          <div class="login-card-head">
            <h2 class="login-card-title">Recuperar contrase&ntilde;a</h2>
          </div>
          <ol class="recuperar-pasos" aria-label="Pasos">
            <li id="paso-1" class="activo">Documento</li>
            <li id="paso-2">C&oacute;digo</li>
            <li id="paso-3">Listo</li>
          </ol>
          <div class="login-offline-notice hidden" id="recuperar-sin-red">
            Necesitas se&ntilde;al para recuperar tu contrase&ntilde;a: el c&oacute;digo llega por correo.
          </div>
          <div class="login-error hidden" id="recuperar-error" role="alert"></div>
          <div id="recuperar-cuerpo"></div>
        </div>

        <a href="#/login" class="recuperar-volver" id="recuperar-volver">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
          Volver a iniciar sesi&oacute;n
        </a>
      </div>
    </div>
  `;

  const cuerpo = document.getElementById('recuperar-cuerpo');
  const errorEl = document.getElementById('recuperar-error');
  const sinRed = document.getElementById('recuperar-sin-red');
  let documento = docInicial;
  let ocupado = false;
  let reloj = null;

  function mostrarError(mensaje) {
    errorEl.textContent = mensaje;
    errorEl.classList.remove('hidden');
  }
  function limpiarError() {
    errorEl.classList.add('hidden');
    errorEl.textContent = '';
  }
  function marcarPaso(n) {
    [1, 2, 3].forEach(i => {
      const li = document.getElementById(`paso-${i}`);
      li.classList.toggle('activo', i === n);
      li.classList.toggle('hecho', i < n);
      if (i === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
  }
  function actualizarRed() {
    sinRed.classList.toggle('hidden', navigator.onLine);
  }
  window.addEventListener('online', actualizarRed);
  window.addEventListener('offline', actualizarRed);
  actualizarRed();

  async function conBoton(boton, etiqueta, accion) {
    if (ocupado) return;
    ocupado = true;
    const original = boton.innerHTML;
    boton.disabled = true;
    boton.innerHTML = `<span class="login-spinner"></span><span>${etiqueta}</span>`;
    try {
      await accion();
    } catch (e) {
      mostrarError(e.message);
    } finally {
      ocupado = false;
      if (boton.isConnected) {
        boton.disabled = false;
        boton.innerHTML = original;
      }
    }
  }

  // ---- Paso 1: documento ----
  function pasoDocumento() {
    marcarPaso(1);
    cuerpo.innerHTML = `
      <p class="recuperar-texto">Escribe tu n&uacute;mero de documento. Te enviaremos un c&oacute;digo de 6 d&iacute;gitos al correo registrado en tu cuenta.</p>
      <form id="form-documento" novalidate>
        <div class="login-field">
          <label for="recuperar-doc">N&uacute;mero de documento</label>
          <input type="text" id="recuperar-doc" inputmode="numeric" maxlength="12"
                 placeholder="6 a 12 d&iacute;gitos" autocomplete="username" value="${documento}" />
        </div>
        <button type="submit" class="login-submit" id="btn-pedir">Enviarme un c&oacute;digo</button>
      </form>
      <p class="recuperar-nota">&iquest;Tu cuenta no tiene correo? P&iacute;dele a tu administrador que te asigne una contrase&ntilde;a nueva desde el panel.</p>
    `;
    const doc = document.getElementById('recuperar-doc');
    doc.addEventListener('input', () => {
      doc.value = doc.value.replace(/\D/g, '').slice(0, 12);
      limpiarError();
    });
    doc.focus();
    document.getElementById('form-documento').addEventListener('submit', e => {
      e.preventDefault();
      limpiarError();
      if (!DOC_REGEX.test(doc.value)) {
        mostrarError('El documento debe tener entre 6 y 12 dígitos.');
        return;
      }
      documento = doc.value;
      conBoton(document.getElementById('btn-pedir'), 'Enviando…', async () => {
        const r = await pedirCodigoRecuperacion(documento);
        pasoCodigo(r.message);
      });
    });
  }

  // ---- Paso 2: código y contraseña nueva ----
  function pasoCodigo(mensaje) {
    marcarPaso(2);
    cuerpo.innerHTML = `
      <div class="recuperar-enviado" role="status">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
        <span>${escapar(mensaje)}</span>
      </div>
      <form id="form-codigo" novalidate>
        <div class="login-field">
          <label for="recuperar-codigo">C&oacute;digo del correo</label>
          <input type="text" id="recuperar-codigo" class="recuperar-codigo" inputmode="numeric" maxlength="6"
                 placeholder="000000" autocomplete="one-time-code" />
        </div>
        <div class="login-field">
          <label for="recuperar-clave">Contrase&ntilde;a nueva</label>
          <div class="login-input-group">
            <input type="password" id="recuperar-clave" autocomplete="new-password" placeholder="M&iacute;nimo ${MIN_CLAVE} caracteres" />
            <button type="button" class="login-eye" id="recuperar-ver" aria-label="Mostrar contrase&ntilde;a">Ver</button>
          </div>
        </div>
        <div class="login-field">
          <label for="recuperar-confirmar">Repite la contrase&ntilde;a nueva</label>
          <input type="password" id="recuperar-confirmar" autocomplete="new-password" />
        </div>
        <button type="submit" class="login-submit" id="btn-cambiar">Cambiar contrase&ntilde;a</button>
      </form>
      <div class="recuperar-acciones">
        <button type="button" class="recuperar-enlace" id="btn-reenviar" disabled>Pedir otro c&oacute;digo</button>
        <button type="button" class="recuperar-enlace" id="btn-otro-doc">Usar otro documento</button>
      </div>
    `;
    const codigo = document.getElementById('recuperar-codigo');
    const clave = document.getElementById('recuperar-clave');
    const confirmar = document.getElementById('recuperar-confirmar');
    const reenviar = document.getElementById('btn-reenviar');
    codigo.addEventListener('input', () => { codigo.value = codigo.value.replace(/\D/g, '').slice(0, 6); limpiarError(); });
    [clave, confirmar].forEach(c => c.addEventListener('input', limpiarError));
    document.getElementById('recuperar-ver').addEventListener('click', e => {
      const ver = clave.type === 'password';
      clave.type = confirmar.type = ver ? 'text' : 'password';
      e.currentTarget.textContent = ver ? 'Ocultar' : 'Ver';
    });
    document.getElementById('btn-otro-doc').addEventListener('click', () => { limpiarError(); pasoDocumento(); });
    codigo.focus();

    // Pedir otro código: se habilita al minuto para no gastar los 5 intentos.
    let restante = ESPERA_REENVIO_S;
    clearInterval(reloj);
    const pintarReloj = () => {
      reenviar.disabled = restante > 0;
      reenviar.textContent = restante > 0 ? `Pedir otro código (${restante} s)` : 'Pedir otro código';
    };
    pintarReloj();
    reloj = setInterval(() => {
      restante -= 1;
      if (!reenviar.isConnected) { clearInterval(reloj); return; }
      pintarReloj();
      if (restante <= 0) clearInterval(reloj);
    }, 1000);
    reenviar.addEventListener('click', () => conBoton(reenviar, 'Enviando…', async () => {
      limpiarError();
      const r = await pedirCodigoRecuperacion(documento);
      pasoCodigo(r.message);
    }));

    document.getElementById('form-codigo').addEventListener('submit', e => {
      e.preventDefault();
      limpiarError();
      if (!/^\d{6}$/.test(codigo.value)) { mostrarError('El código tiene 6 dígitos. Cópialo del correo.'); codigo.focus(); return; }
      if (clave.value.length < MIN_CLAVE) { mostrarError(`La contraseña nueva debe tener al menos ${MIN_CLAVE} caracteres.`); clave.focus(); return; }
      if (clave.value !== confirmar.value) { mostrarError('Las dos contraseñas no coinciden.'); confirmar.focus(); return; }
      conBoton(document.getElementById('btn-cambiar'), 'Cambiando…', async () => {
        await restablecerContrasena(documento, codigo.value, clave.value);
        pasoListo();
      });
    });
  }

  // ---- Paso 3: listo ----
  function pasoListo() {
    clearInterval(reloj);
    marcarPaso(3);
    cuerpo.innerHTML = `
      <div class="login-success">
        <div class="login-success-icon">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1E6B44" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path class="login-check-path" d="M5 12.5l4.5 4.5L19 7"/></svg>
        </div>
        <div class="login-success-title">Contrase&ntilde;a cambiada</div>
        <div class="login-success-sub">Ya puedes entrar con tu contrase&ntilde;a nueva. Por seguridad se cerr&oacute; la sesi&oacute;n en tus otros celulares.</div>
      </div>
      <button type="button" class="login-submit" id="btn-ir-login">Ir a iniciar sesi&oacute;n</button>
    `;
    document.getElementById('btn-ir-login').addEventListener('click', () => {
      sessionStorage.setItem('login_doc', documento);
      navigate('/login');
    });
  }

  pasoDocumento();
}

function escapar(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
