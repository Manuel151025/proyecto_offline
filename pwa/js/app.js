import { onRoute, initRouter, navigate } from './router.js';
import { render as renderLista } from './screens/lista-personas.js';
import { render as renderFormulario } from './screens/formulario-encuesta.js';
import { render as renderSync } from './screens/estado-sincronizacion.js';
import { render as renderLogin } from './screens/login.js';
import { getMunicipios, saveMunicipios } from './db.js';
import { fetchMunicipios } from './api.js';
import { syncNow } from './sync.js';
import { showToast } from './utils.js';
import { hasActiveSession } from './session.js';

const appRoot = document.getElementById('app-root');

function getRoot() { return appRoot; }

function protect(handler) {
  return params => {
    if (!hasActiveSession()) { navigate('/login'); return; }
    handler(params);
  };
}

onRoute('/login', () => renderLogin(getRoot()));
onRoute('/personas', protect(() => renderLista(getRoot())));
onRoute('/nueva', protect(() => renderFormulario(getRoot(), {})));
onRoute('/editar/:tipo/:numero', protect(params => renderFormulario(getRoot(), params)));
onRoute('/sync', protect(() => renderSync(getRoot())));

async function loadMunicipios() {
  try {
    const local = await getMunicipios();
    if (local.length) return;
    const remote = await fetchMunicipios();
    await saveMunicipios(remote);
  } catch (_) {}
}

async function autoSync() {
  if (!navigator.onLine) return;
  try {
    const result = await syncNow();
    if (result.synced > 0) showToast(`${result.synced} registro(s) sincronizados`, 'success');
  } catch (_) {}
}

function setupOnlineSync() {
  window.addEventListener('online', () => {
    showToast('Conexión restaurada', 'info');
    autoSync();
  });
}

function registerSW() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('./sw.js').then(reg => {
      navigator.serviceWorker.addEventListener('message', e => {
        if (e.data?.type === 'SYNC_NOW') autoSync();
      });
    }).catch(() => {});
  }
}

function setupBottomNav() {
  document.querySelectorAll('.nav-item').forEach(el => {
    el.addEventListener('click', () => navigate(el.dataset.route));
  });
}

function updateChrome() {
  const hash = window.location.hash.replace('#', '') || '/personas';
  const isLogin = hash === '/login';
  document.querySelector('.app-header')?.classList.toggle('chrome-hidden', isLogin);
  document.querySelector('.bottom-nav')?.classList.toggle('chrome-hidden', isLogin);
}

async function init() {
  registerSW();
  setupBottomNav();
  setupOnlineSync();
  window.addEventListener('hashchange', updateChrome);
  await loadMunicipios();
  initRouter();
  updateChrome();
  autoSync();
}

init();
