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

export function formatDate(ms) {
  if (!ms) return '—';
  return new Date(ms).toLocaleDateString('es-CO', {
    year: 'numeric', month: 'short', day: 'numeric'
  });
}

export function formatDateTime(ms) {
  if (!ms) return '—';
  return new Date(ms).toLocaleString('es-CO');
}

export function dateToMs(dateString) {
  if (!dateString) return null;
  return new Date(dateString).getTime();
}

export function msToDateInput(ms) {
  if (!ms) return '';
  const d = new Date(ms);
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
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
