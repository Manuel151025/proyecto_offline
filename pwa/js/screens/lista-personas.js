import { getPersonas } from '../db.js';
import { navigate } from '../router.js';
import { formatDate } from '../utils.js';

export async function render(container) {
  container.innerHTML = `
    <div class="screen">
      <div class="screen-top">
        <div class="search-bar">
          <span class="search-icon">&#128269;</span>
          <input type="search" id="search-input" placeholder="Buscar por nombre o documento..." autocomplete="off" />
        </div>
      </div>
      <div class="screen-content" id="persona-list"></div>
      <button class="fab" id="btn-nueva" title="Registrar nueva persona">&#43;</button>
    </div>
  `;

  document.getElementById('btn-nueva').onclick = () => navigate('/nueva');

  let allPersonas = [];

  try {
    const all = await getPersonas();
    allPersonas = all.filter(p => !p.deleted_at);
  } catch (e) {
    showError();
    return;
  }

  renderList(allPersonas);

  document.getElementById('search-input').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    if (!q) { renderList(allPersonas); return; }
    const filtered = allPersonas.filter(p =>
      `${p.nombres} ${p.apellidos}`.toLowerCase().includes(q) ||
      p.numero_documento.toLowerCase().includes(q) ||
      (p.tipo_documento + p.numero_documento).toLowerCase().includes(q)
    );
    renderList(filtered);
  });

  function renderList(list) {
    const el = document.getElementById('persona-list');
    if (!el) return;

    if (!list.length) {
      el.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">&#128100;</div>
          <p class="empty-title">Sin personas registradas</p>
          <p class="empty-sub">Toca el botón &#43; para agregar la primera persona</p>
        </div>
      `;
      return;
    }

    el.innerHTML = list.map(p => {
      const pending = p._pendingSync;
      const badgeClass = pending ? 'badge-warning' : 'badge-success';
      const badgeText = pending ? 'Pendiente' : 'Sincronizado';
      const initials = ((p.nombres || '')[0] || '') + ((p.apellidos || '')[0] || '');
      return `
        <div class="card persona-card"
             data-tipo="${escHtml(p.tipo_documento)}"
             data-numero="${escHtml(p.numero_documento)}"
             role="button" tabindex="0">
          <div class="persona-avatar">${escHtml(initials.toUpperCase())}</div>
          <div class="persona-info">
            <div class="persona-name">${escHtml(p.nombres)} ${escHtml(p.apellidos)}</div>
            <div class="persona-doc">${escHtml(p.tipo_documento)}: ${escHtml(p.numero_documento)}</div>
            ${p.fecha_nacimiento ? `<div class="persona-meta">Nac: ${formatDate(p.fecha_nacimiento)}</div>` : ''}
          </div>
          <div class="persona-status">
            <span class="badge ${badgeClass}">${badgeText}</span>
          </div>
        </div>
      `;
    }).join('');

    el.querySelectorAll('.persona-card').forEach(card => {
      const handler = () => navigate(`/editar/${card.dataset.tipo}/${card.dataset.numero}`);
      card.addEventListener('click', handler);
      card.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') handler(); });
    });
  }

  function showError() {
    const el = document.getElementById('persona-list');
    if (el) el.innerHTML = `<div class="error-state">Error al cargar personas. Intenta de nuevo.</div>`;
  }
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
