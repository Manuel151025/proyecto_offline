import { getSyncCounts, getAllSyncItems } from '../db.js';
import { syncNow, isSyncing } from '../sync.js';
import { showToast, formatDateTime } from '../utils.js';

export async function render(container) {
  container.innerHTML = `
    <div class="screen">
      <div class="screen-content sync-screen">
        <div class="sync-status-card" id="sync-status-card">
          <div class="sync-connection" id="sync-connection">
            <span class="connection-dot" id="conn-dot"></span>
            <span id="conn-label">Verificando conexión...</span>
          </div>
        </div>

        <div class="sync-counts" id="sync-counts">
          <div class="count-card count-pending">
            <div class="count-number" id="count-pending">—</div>
            <div class="count-label">Pendientes</div>
          </div>
          <div class="count-card count-sent">
            <div class="count-number" id="count-sent">—</div>
            <div class="count-label">Enviados</div>
          </div>
          <div class="count-card count-error">
            <div class="count-number" id="count-error">—</div>
            <div class="count-label">Con error</div>
          </div>
        </div>

        <button class="btn btn-primary btn-full btn-sync" id="btn-sync">
          &#8635; Sincronizar ahora
        </button>

        <div class="sync-history-title">Historial de cola</div>
        <div id="sync-history"></div>
      </div>
    </div>
  `;

  await refreshScreen();

  document.getElementById('btn-sync').onclick = async () => {
    if (isSyncing()) return;
    const btn = document.getElementById('btn-sync');
    btn.disabled = true;
    btn.textContent = 'Sincronizando...';
    try {
      const result = await syncNow();
      showToast(result.message || 'Sincronización completada', 'success');
    } catch (err) {
      showToast('Error: ' + err.message, 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = '⟳ Sincronizar ahora';
      await refreshScreen();
    }
  };

  window.addEventListener('online', updateConnection);
  window.addEventListener('offline', updateConnection);
  updateConnection();
}

async function refreshScreen() {
  const counts = await getSyncCounts();
  const pending = document.getElementById('count-pending');
  const sent = document.getElementById('count-sent');
  const error = document.getElementById('count-error');
  if (pending) pending.textContent = counts.pending;
  if (sent) sent.textContent = counts.sent;
  if (error) error.textContent = counts.error;

  const items = await getAllSyncItems();
  const histEl = document.getElementById('sync-history');
  if (!histEl) return;

  if (!items.length) {
    histEl.innerHTML = `<div class="empty-state" style="padding:24px 0">
      <p class="empty-sub">Sin elementos en la cola de sincronización</p>
    </div>`;
    return;
  }

  const sorted = [...items].reverse().slice(0, 30);
  histEl.innerHTML = sorted.map(item => {
    const statusClass = {
      PENDING: 'status-pending',
      SENT: 'status-sent',
      ERROR: 'status-error'
    }[item.status] || '';
    const statusLabel = { PENDING: 'Pendiente', SENT: 'Enviado', ERROR: 'Error' }[item.status] || item.status;
    const p = item.persona;
    const name = p ? `${p.nombres || ''} ${p.apellidos || ''}`.trim() : '—';
    const accion = item.encuesta?.accion || '—';
    return `
      <div class="sync-item">
        <div class="sync-item-info">
          <div class="sync-item-name">${escHtml(name)}</div>
          <div class="sync-item-meta">${escHtml(accion)} · ${formatDateTime(item.created_at)}</div>
        </div>
        <span class="badge ${statusClass}">${statusLabel}</span>
      </div>
    `;
  }).join('');
}

function updateConnection() {
  const dot = document.getElementById('conn-dot');
  const label = document.getElementById('conn-label');
  if (!dot || !label) return;
  if (navigator.onLine) {
    dot.className = 'connection-dot online';
    label.textContent = 'En línea — sincronización disponible';
  } else {
    dot.className = 'connection-dot offline';
    label.textContent = 'Sin conexión — datos guardados localmente';
  }
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
