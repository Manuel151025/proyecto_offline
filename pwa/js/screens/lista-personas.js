import { getPersonas, resumenLocal } from '../db.js';
import { getSession } from '../session.js';
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
  const nombre = (getSession()?.nombre || '').split(' ')[0];
  const hoy = new Date().toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });
  container.innerHTML = `
    <div class="screen">
      <div class="screen-content" id="area-scroll">
        <div class="saludo">
          <h1>${nombre ? `Hola, ${escHtml(nombre)}` : 'Hola'}</h1>
          <p>${escHtml(hoy.charAt(0).toUpperCase() + hoy.slice(1))}</p>
        </div>
        <div id="resumen-dia"></div>
        <div class="lista-titulo">Personas <span id="lista-total"></span></div>
        <label class="search-label" for="search-input">Buscar persona</label>
        <div class="search-bar">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input type="search" id="search-input" placeholder="Nombre o documento" autocomplete="off" />
        </div>
        <div id="persona-list" style="margin-top: 10px"></div>
      </div>
    </div>
  `;

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
    const ultimo = r.porDia.length - 1;
    const semanaTotal = r.porDia.reduce((s, d) => s + d.total, 0);
    const barras = r.porDia.map((d, i) => `
      <div class="mini-col ${i === ultimo ? 'hoy' : ''}" title="${escHtml(d.dia)}: ${d.total}">
        <div class="mini-barra" style="height:${d.total ? Math.max(6, Math.round(64 * d.total / maximo)) : 3}px"></div>
        <span class="mini-eti">${i === ultimo ? 'hoy' : escHtml(d.semana)}</span>
      </div>`).join('');

    const total = document.getElementById('lista-total');
    if (total) total.textContent = `${r.total}`;

    caja.innerHTML = `
      <section class="hero">
        <svg class="hero-curvas" viewBox="0 0 220 120" aria-hidden="true" fill="none" stroke="#E7A07B" stroke-width="1.6" opacity="0.55"><path d="M0 110 C40 70 70 90 100 60 S160 30 220 50"/><path d="M10 120 C50 85 80 104 112 76 S170 50 220 66"/><path d="M24 128 C62 100 92 118 124 92 S180 70 220 82"/></svg>
        <div class="hero-principal">
          <span class="hero-n">${r.hoy}</span>
          <span>${r.hoy === 1 ? 'persona registrada hoy' : 'personas registradas hoy'}</span>
        </div>
        <div class="hero-datos">
          <div class="hero-dato ${r.pendientes ? 'pendiente' : ''}"><b>${r.pendientes}</b><span>sin enviar</span></div>
          <div class="hero-dato"><b>${r.total}</b><span>en total</span></div>
        </div>
        <p class="hero-nota">${r.pendientes
          ? 'Todo queda guardado en el teléfono. Se enviará solo cuando haya señal.'
          : 'Todo lo registrado ya llegó al servidor.'}</p>
      </section>
      <section class="semana" aria-label="Registros de los últimos 7 días">
        <div class="semana-cabecera"><h2>Esta semana</h2><span>${semanaTotal} registros</span></div>
        <div class="resumen-grafico">${barras}</div>
      </section>
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
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="8" r="4"/><path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M19 8v6M16 11h6"/></svg>
          <p class="empty-title">Todavía no hay personas</p>
          <p class="empty-sub">Toca el botón naranja de abajo para registrar la primera.</p>
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
  // El estado lleva icono y palabra: el color nunca es la única señal.
  const icono = pendiente
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';
  const texto = pendiente ? 'Pendiente' : 'Enviada';
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
        <span class="badge ${clase}">${icono}${texto}</span>
      </div>
    </div>
  `;
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
