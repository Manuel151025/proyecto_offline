import { getCredencial, saveCredencial } from '../db.js';
import { setSession } from '../session.js';
import { navigate } from '../router.js';
import { showToast } from '../utils.js';
import { syncNow } from '../sync.js';
import { login } from '../api.js';

const DOC_REGEX = /^[0-9]{6,12}$/;

export async function render(container) {
  container.innerHTML = `
    <div class="login-screen">
      <span class="login-blob login-blob-1"></span>
      <span class="login-blob login-blob-2"></span>
      <div class="login-header">
        <div class="login-logo">
          <svg width="66" height="66" viewBox="0 0 192 192" aria-hidden="true">
            <circle cx="96" cy="96" r="96" fill="#ffffff"/>
            <rect x="82" y="52" width="28" height="88" rx="6" fill="#0E7A41"/>
            <rect x="52" y="82" width="88" height="28" rx="6" fill="#0E7A41"/>
          </svg>
        </div>
        <div class="login-brand">ColOffline</div>
        <div class="login-subtitle">Ministerio de Salud &middot; Encuestas demogr&aacute;ficas</div>
      </div>
      <div class="login-card">
        <div class="login-conn-pill" id="login-conn-pill">
          <span class="login-conn-dot"></span>
          <span id="login-conn-text">En l&iacute;nea</span>
        </div>
        <div id="login-body"></div>
      </div>
    </div>
  `;

  const pillEl = document.getElementById('login-conn-pill');
  const connText = document.getElementById('login-conn-text');
  const bodyEl = document.getElementById('login-body');

  let loading = false;
  let done = false;
  let remember = true;

  bodyEl.innerHTML = `
    <div class="login-offline-notice hidden" id="login-offline-notice">
      Puedes ingresar con tus credenciales guardadas en este dispositivo.
    </div>
    <form id="login-form" novalidate>
      <div class="login-field">
        <input type="text" id="login-doc" inputmode="numeric" maxlength="12" placeholder=" " autocomplete="username" />
        <label for="login-doc">N&uacute;mero de documento</label>
      </div>
      <div class="login-field login-field-pass">
        <input type="password" id="login-pass" placeholder=" " autocomplete="current-password" />
        <label for="login-pass">Contrase&ntilde;a</label>
        <button type="button" class="login-eye" id="login-eye">Ver</button>
      </div>
      <div class="login-error hidden" id="login-error"></div>
      <div class="login-row">
        <button type="button" class="login-switch on" id="login-remember" aria-pressed="true">
          <span class="login-switch-track"><span class="login-switch-knob"></span></span>
          <span class="login-switch-label">Recordar sesi&oacute;n</span>
        </button>
        <a href="#" class="login-forgot" id="login-forgot">&iquest;Olvidaste tu contrase&ntilde;a?</a>
      </div>
      <button type="submit" class="login-submit" id="login-submit">
        <span class="login-spinner hidden" id="login-spinner"></span>
        <span id="login-submit-label">Ingresar</span>
      </button>
    </form>
    <div class="login-demo-note">
      <strong>Cuenta de prueba (docente)</strong><br>
      Documento: <b>1000000001</b> &middot; Contrase&ntilde;a: <b>Demo2026Salud</b>
    </div>
  `;

  const docInput = document.getElementById('login-doc');
  const passInput = document.getElementById('login-pass');
  const eyeBtn = document.getElementById('login-eye');
  const rememberBtn = document.getElementById('login-remember');
  const forgotLink = document.getElementById('login-forgot');
  const form = document.getElementById('login-form');
  const submitBtn = document.getElementById('login-submit');
  const spinnerEl = document.getElementById('login-spinner');
  const errorEl = document.getElementById('login-error');

  docInput.addEventListener('input', () => {
    docInput.value = docInput.value.replace(/\D/g, '').slice(0, 12);
    clearError();
  });
  passInput.addEventListener('input', clearError);

  eyeBtn.addEventListener('click', () => {
    const show = passInput.type === 'password';
    passInput.type = show ? 'text' : 'password';
    eyeBtn.textContent = show ? 'Ocultar' : 'Ver';
  });

  rememberBtn.addEventListener('click', () => {
    remember = !remember;
    rememberBtn.classList.toggle('on', remember);
    rememberBtn.classList.toggle('off', !remember);
    rememberBtn.setAttribute('aria-pressed', String(remember));
  });

  forgotLink.addEventListener('click', e => {
    e.preventDefault();
    showToast('Disponible próximamente', 'info');
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (loading || done) return;

    const doc = docInput.value.trim();
    const pass = passInput.value;

    if (!DOC_REGEX.test(doc)) {
      showError('Ingresa un número de documento válido (6–12 dígitos)');
      return;
    }
    if (pass.length < 4) {
      showError('La contraseña debe tener al menos 4 caracteres');
      return;
    }

    clearError();
    setLoading(true);

    try {
      const offline = !navigator.onLine;
      let encuestadorId, nombre;

      if (!offline) {
        const encuestador = await login(doc, pass);
        encuestadorId = encuestador.id;
        nombre = encuestador.nombre;
        const salt = randomSalt();
        const passwordHash = await sha256Hex(salt + pass);
        await saveCredencial({ documento: doc, passwordHash, salt, encuestadorId, nombre, updated_at: Date.now() });
      } else {
        const cred = await getCredencial(doc);
        if (!cred) throw new Error('Documento o contraseña incorrectos');
        const hash = await sha256Hex(cred.salt + pass);
        if (hash !== cred.passwordHash) throw new Error('Documento o contraseña incorrectos');
        encuestadorId = cred.encuestadorId;
        nombre = cred.nombre;
      }

      setSession({ documento: doc, encuestadorId, nombre, offline, remember, createdAt: Date.now() });
      showSuccess(offline);
    } catch (err) {
      setLoading(false);
      showError(err.message || 'Documento o contraseña incorrectos');
    }
  });

  updateConnection();
  window.addEventListener('online', updateConnection);
  window.addEventListener('offline', updateConnection);

  function setLoading(isLoading) {
    loading = isLoading;
    submitBtn.disabled = isLoading;
    spinnerEl.classList.toggle('hidden', !isLoading);
    document.getElementById('login-submit-label').textContent = isLoading
      ? 'Verificando…'
      : (navigator.onLine ? 'Ingresar' : 'Ingresar sin conexión');
  }

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.classList.remove('hidden');
    errorEl.classList.remove('login-error-anim');
    void errorEl.offsetWidth;
    errorEl.classList.add('login-error-anim');
  }

  function clearError() {
    errorEl.classList.add('hidden');
    errorEl.textContent = '';
  }

  function updateConnection() {
    const online = navigator.onLine;
    pillEl.classList.toggle('online', online);
    pillEl.classList.toggle('offline', !online);
    connText.textContent = online ? 'En línea' : 'Sin conexión';

    const notice = document.getElementById('login-offline-notice');
    if (notice) notice.classList.toggle('hidden', online);

    if (!loading && !done) {
      const label = document.getElementById('login-submit-label');
      if (label) label.textContent = online ? 'Ingresar' : 'Ingresar sin conexión';
    }
  }

  function showSuccess(offline) {
    done = true;
    const sub = offline
      ? 'Sesión iniciada sin conexión. Se sincronizará al recuperar la red.'
      : 'Sincronizando datos con el servidor…';
    bodyEl.innerHTML = `
      <div class="login-success">
        <div class="login-success-icon">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 12.5 L9.5 18 L20 6.5" stroke="#0E7A41" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="login-check-path"/>
          </svg>
        </div>
        <div class="login-success-title">&iexcl;Bienvenido!</div>
        <div class="login-success-sub">${sub}</div>
      </div>
    `;
    setTimeout(() => {
      navigate('/personas');
      if (navigator.onLine) {
        syncNow().then(result => {
          if (result.synced > 0) showToast(`${result.synced} registro(s) sincronizados`, 'success');
        }).catch(() => {});
      }
    }, 1000);
  }
}

function randomSalt() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function sha256Hex(text) {
  const data = new TextEncoder().encode(text);
  const digest = await crypto.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
}
