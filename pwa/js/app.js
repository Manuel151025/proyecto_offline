import { onRoute, initRouter, navigate } from './router.js';
import { render as renderLista } from './screens/lista-personas.js';
import { render as renderFormulario } from './screens/formulario-encuesta.js';
import { render as renderSync } from './screens/estado-sincronizacion.js';
import { render as renderLogin } from './screens/login.js';
import { getMunicipios, saveMunicipios } from './db.js';
import { fetchMunicipios, logout } from './api.js';
import { syncNow } from './sync.js';
import { showToast } from './utils.js';
import { hasActiveSession, clearSession, getToken } from './session.js';

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
    // También hay que repintar cuando solo se RECIBIÓ: la lista acaba de
    // ganar personas de otros dispositivos y sin refresco no se verían.
    if (result.synced > 0 || result.recibidas > 0) {
      showToast(result.message, 'success');
      refrescarPantallaActual();
    }
  } catch (_) {}
}

/**
 * Vuelve a pintar la pantalla visible tras sincronizar.
 *
 * La lista lee IndexedDB una sola vez al entrar. Cuando la sincronización
 * marcaba los registros como enviados, nadie avisaba a la pantalla: había que
 * salir y volver para ver el cambio, y parecía que la sincronización no había
 * funcionado.
 */
function refrescarPantallaActual() {
  const ruta = window.location.hash.replace('#', '') || '/personas';
  if (ruta === '/personas') renderLista(getRoot());
  else if (ruta === '/sync') renderSync(getRoot());
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

/**
 * Cerrar sesión.
 *
 * Faltaba por completo: al exigirse el token para sincronizar, quien tuviera
 * una sesión sin él veía "inicia sesión con conexión" sin ninguna forma de
 * hacerlo. Solo se borra la sesión; las personas y la cola viven en IndexedDB
 * y deben sobrevivir, porque pueden ser trabajo de campo sin enviar.
 */
function setupLogout() {
  const btn = document.getElementById('btn-salir');
  if (!btn) return;
  btn.addEventListener('click', () => {
    if (!confirm('¿Cerrar sesión? Los registros sin sincronizar se conservan en este dispositivo.')) return;
    cerrarSesion();
  });
}

export async function cerrarSesion(mensaje) {
  // Primero se revoca en el servidor, mientras el token todavía está a mano.
  // Si falla —sin red, servidor caído— el cierre local se hace igual.
  await logout();
  clearSession();
  if (mensaje) showToast(mensaje, 'info');
  navigate('/login');
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
  setupLogout();
  setupOnlineSync();
  window.addEventListener('hashchange', updateChrome);
  await loadMunicipios();
  initRouter();
  updateChrome();
  autoSync();
}

init();
