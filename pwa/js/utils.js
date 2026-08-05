export function generateUUID() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

export function getDeviceId() {
  let id = localStorage.getItem('pwa_device_id');
  if (!id) {
    id = 'pwa_' + generateUUID();
    localStorage.setItem('pwa_device_id', id);
  }
  return id;
}

export function nowMs() {
  return Date.now();
}

/**
 * La fecha de nacimiento es una fecha de CALENDARIO, no un instante: el 15 de
 * enero es el 15 de enero en cualquier huso. Se guarda como la medianoche UTC
 * de ese día —que es lo que entrega el selector de fecha— y por tanto hay que
 * leerla y mostrarla también en UTC.
 *
 * Mezclar los dos criterios restaba un día en Colombia (UTC-5) y el error se
 * acumulaba: cada edición del registro corría la fecha una jornada más atrás.
 */
export function formatDate(ms) {
  if (!ms) return '—';
  return new Date(ms).toLocaleDateString('es-CO', {
    year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC'
  });
}

/**
 * Esta sí es un instante real (cuándo ocurrió una sincronización), así que se
 * muestra en la hora local del dispositivo. No lleva timeZone a propósito.
 */
export function formatDateTime(ms) {
  if (!ms) return '—';
  return new Date(ms).toLocaleString('es-CO');
}

/** 'YYYY-MM-DD' -> medianoche UTC de ese día. */
export function dateToMs(dateString) {
  if (!dateString) return null;
  return new Date(dateString).getTime();
}

/** Inversa exacta de dateToMs: lee en UTC para no correr el día. */
export function msToDateInput(ms) {
  if (!ms) return '';
  const d = new Date(ms);
  const yyyy = d.getUTCFullYear();
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

export function showToast(message, type = 'info') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;
  container.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('toast-show'));
  setTimeout(() => {
    toast.classList.remove('toast-show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}
