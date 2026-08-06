import { getPersonas, resumenLocal } from '../db.js';
import { navigate } from '../router.js';
import { formatDate } from '../utils.js';

/**
 * Lista de personas con renderizado incremental.
 *
 * Antes se construía el HTML de la lista COMPLETA de una vez y se registraban
 * dos listeners por tarjeta. Con unos miles de registros eso son miles de nodos
 * en el DOM y decenas de miles de listeners, en gama baja de campo.
 *
 * Ahora se pinta una página cada vez y se amplía al acercarse al final, y los
 * eventos se manejan por delegación: dos listeners en total, sin importar
 * cuántas tarjetas haya.
 */

const TAMANO_PAGINA = 50;

/** Margen para pedir la siguiente página antes de tocar fondo. */
const MARGEN_PRECARGA = '300px';

export async function render(container) {
  container.innerHTML = `
    <div class="screen">
      <div class="screen-top">
        <div class="search-bar">
          <span class="search-icon">&#128269;</span>
          <input type="search" id="search-input" placeholder="Buscar por nombre o documento..." autocomplete="off" />
        </div>
      </div>
      <div class="screen-content" id="area-scroll">
        <div id="resumen-dia"></div>
        <div id="persona-list"></div>
      </div>
      <button class="fab" id="btn-nueva" title="Registrar nueva persona">&#43;</button>
    </div>
  `;

  document.getElementById('btn-nueva').onclick = () => navigate('/nueva');

  // El área de scroll y la lista son elementos distintos a propósito: al
  // filtrar se vacía la lista, y el resumen debe sobrevivir a eso.
  const areaScroll = document.getElementById('area-scroll');
  const contenedor = document.getElementById('persona-list');
  let todas = [];
  let visibles = [];   // resultado del filtro actual
  let pintadas = 0;
  let observador = null;

  pintarResumen();

  try {
    const all = await getPersonas();
    todas = all.filter(p => !p.deleted_at);
  } catch (e) {
    contenedor.innerHTML = `<div class="error-state">Error al cargar personas. Intenta de nuevo.</div>`;
    return;
  }

  // Delegación: un listener para toda la lista, no dos por tarjeta.
  contenedor.addEventListener('click', e => {
    const tarjeta = e.target.closest('.persona-card');
    if (tarjeta) abrir(tarjeta);
  });
  contenedor.addEventListener('keydown', e => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const tarjeta = e.target.closest('.persona-card');
    if (tarjeta) { e.preventDefault(); abrir(tarjeta); }
  });

  // La búsqueda recorre todo el arreglo; con la lista larga conviene no
  // rehacerlo en cada pulsación.
  let temporizador;
  document.getElementById('search-input').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    clearTimeout(temporizador);
    temporizador = setTimeout(() => aplicarFiltro(q), 150);
  });

  mostrar(todas);

  function abrir(tarjeta) {
    navigate(`/editar/${tarjeta.dataset.tipo}/${tarjeta.dataset.numero}`);
  }

  /**
   * Resumen del trabajo de este dispositivo.
   *
   * Va ARRIBA de la lista y no debajo: abajo solo se vería mientras haya pocos
   * registros, y en cuanto la lista crezca quedaría enterrado justo cuando el
   * resumen empieza a ser más útil.
   */
  async function pintarResumen() {
    const caja = document.getElementById('resumen-dia');
    if (!caja) return;

    let r;
    try {
      r = await resumenLocal(7);
    } catch (_) {
      return; // Un fallo aquí no debe impedir ver la lista.
    }

    const maximo = Math.max(1, ...r.porDia.map(d => d.total));
    const barras = r.porDia.map(d => `
      <div class="mini-col" title="${escHtml(d.dia)}: ${d.total}">
        <div class="mini-barra" style="height:${d.total ? Math.max(8, Math.round(100 * d.total / maximo)) : 2}%"></div>
        <span class="mini-eti">${escHtml(d.dia)}</span>
      </div>`).join('');

    caja.innerHTML = `
      <div class="resumen">
        <div class="resumen-cifras">
          <div class="resumen-dato">
            <span class="resumen-n">${r.hoy}</span>
            <span class="resumen-t">Hoy</span>
          </div>
          <div class="resumen-dato">
            <span class="resumen-n">${r.total}</span>
            <span class="resumen-t">En total</span>
          </div>
          <div class="resumen-dato ${r.pendientes ? 'pendiente' : ''}">
            <span class="resumen-n">${r.pendientes}</span>
            <span class="resumen-t">Sin enviar</span>
          </div>
        </div>
        <div class="resumen-grafico" aria-label="Registros de los últimos 7 días">${barras}</div>
      </div>
    `;
  }

  function aplicarFiltro(q) {
    if (!q) { mostrar(todas); return; }
    mostrar(todas.filter(p =>
      `${p.nombres} ${p.apellidos}`.toLowerCase().includes(q) ||
      p.numero_documento.toLowerCase().includes(q) ||
      (p.tipo_documento + p.numero_documento).toLowerCase().includes(q)
    ));
  }

  function mostrar(lista) {
    visibles = lista;
    pintadas = 0;
    observador?.disconnect();
    contenedor.innerHTML = '';

    if (!lista.length) {
      contenedor.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">&#128100;</div>
          <p class="empty-title">Sin personas registradas</p>
          <p class="empty-sub">Toca el botón &#43; para agregar la primera persona</p>
        </div>
      `;
      return;
    }

    pintarPagina();
  }

  function pintarPagina() {
    const pagina = visibles.slice(pintadas, pintadas + TAMANO_PAGINA);
    if (!pagina.length) return;

    document.getElementById('centinela-lista')?.remove();
    contenedor.insertAdjacentHTML('beforeend', pagina.map(tarjeta).join(''));
    pintadas += pagina.length;

    if (pintadas < visibles.length) colocarCentinela();
  }

  /** Elemento invisible al final: al asomarse, se pide la siguiente página. */
  function colocarCentinela() {
    contenedor.insertAdjacentHTML('beforeend', '<div id="centinela-lista" aria-hidden="true"></div>');
    const centinela = document.getElementById('centinela-lista');

    observador?.disconnect();
    observador = new IntersectionObserver(entradas => {
      if (entradas.some(x => x.isIntersecting)) pintarPagina();
    }, { root: areaScroll, rootMargin: MARGEN_PRECARGA });

    observador.observe(centinela);
  }
}

function tarjeta(p) {
  const pendiente = p._pendingSync;
  const clase = pendiente ? 'badge-warning' : 'badge-success';
  const texto = pendiente ? 'Pendiente' : 'Sincronizado';
  const iniciales = ((p.nombres || '')[0] || '') + ((p.apellidos || '')[0] || '');
  return `
    <div class="card persona-card"
         data-tipo="${escHtml(p.tipo_documento)}"
         data-numero="${escHtml(p.numero_documento)}"
         role="button" tabindex="0">
      <div class="persona-avatar">${escHtml(iniciales.toUpperCase())}</div>
      <div class="persona-info">
        <div class="persona-name">${escHtml(p.nombres)} ${escHtml(p.apellidos)}</div>
        <div class="persona-doc">${escHtml(p.tipo_documento)}: ${escHtml(p.numero_documento)}</div>
        ${p.fecha_nacimiento ? `<div class="persona-meta">Nac: ${formatDate(p.fecha_nacimiento)}</div>` : ''}
      </div>
      <div class="persona-status">
        <span class="badge ${clase}">${texto}</span>
      </div>
    </div>
  `;
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
