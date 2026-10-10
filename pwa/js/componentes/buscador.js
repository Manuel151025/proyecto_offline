/**
 * Campo de búsqueda con lista de sugerencias (patrón «combobox» de WAI-ARIA).
 *
 * Reemplaza a los <select> de departamento y municipio: con 1.122 municipios,
 * bajar por una lista era imposible en campo. Aquí se escribe «popa» y aparece
 * Popayán; se escribe «cauca» y aparecen los municipios del Cauca.
 *
 * Se usa con el teclado (flechas, Enter, Escape), con el dedo y con lector de
 * pantalla: el input anuncia cuántas opciones hay y cuál está activa.
 *
 * @param {object} op
 * @param {HTMLInputElement} op.input       el campo visible
 * @param {HTMLElement}      op.lista       el <ul role="listbox">
 * @param {(q: string) => Array} op.buscar  devuelve las opciones para la consulta
 * @param {(o) => {titulo: string, detalle?: string, etiqueta?: string}} op.pintar
 * @param {(o|null) => void} op.alElegir    recibe la opción elegida, o null si se borró
 * @param {(o) => string}    op.texto       texto que queda en el campo al elegir
 * @param {(q: string, n: number) => string} [op.encabezado] título sobre la lista
 * @param {string} [op.vacio]               mensaje cuando no hay resultados
 */
export function crearBuscador(op) {
  const { input, lista } = op;
  const contenedor = input.closest('.buscador');
  let opciones = [];
  let activa = -1;

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', lista.id);
  input.setAttribute('autocomplete', 'off');
  lista.setAttribute('role', 'listbox');

  function abrir() {
    const q = input.value;
    opciones = op.buscar(q);
    activa = -1;
    pintarLista(q);
    lista.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    contenedor?.classList.add('abierto');
  }

  function cerrar() {
    lista.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    contenedor?.classList.remove('abierto');
  }

  function pintarLista(q) {
    const titulo = op.encabezado?.(q, opciones.length);
    const filas = opciones.map((o, i) => {
      const { titulo: t, detalle, etiqueta } = op.pintar(o);
      return `<li role="option" id="${lista.id}-${i}" data-i="${i}" aria-selected="false" class="buscador-opcion">
        <span class="buscador-texto">
          <span class="buscador-titulo">${resaltar(t, q)}</span>
          ${detalle ? `<span class="buscador-detalle">${resaltar(detalle, q)}</span>` : ''}
        </span>
        ${etiqueta ? `<span class="buscador-etiqueta">${esc(etiqueta)}</span>` : ''}
      </li>`;
    }).join('');
    lista.innerHTML = (titulo ? `<li class="buscador-encabezado" role="presentation">${esc(titulo)}</li>` : '') +
      (filas || `<li class="buscador-vacio" role="presentation">${esc(op.vacio ?? 'Sin resultados')}</li>`);
  }

  function mover(paso) {
    if (!opciones.length) return;
    activa = (activa + paso + opciones.length) % opciones.length;
    lista.querySelectorAll('[role="option"]').forEach((li, i) => {
      li.setAttribute('aria-selected', String(i === activa));
      li.classList.toggle('activa', i === activa);
      if (i === activa) li.scrollIntoView({ block: 'nearest' });
    });
    input.setAttribute('aria-activedescendant', `${lista.id}-${activa}`);
  }

  function elegir(i) {
    const o = opciones[i];
    if (!o) return;
    input.value = op.texto(o);
    contenedor?.classList.add('elegido');
    op.alElegir(o);
    cerrar();
  }

  input.addEventListener('focus', () => {
    abrir();
    // En el celular el teclado tapa la mitad de la pantalla: se sube el campo
    // para que las sugerencias queden a la vista.
    if (window.matchMedia?.('(max-width: 600px)').matches) {
      setTimeout(() => contenedor?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 250);
    }
  });
  input.addEventListener('input', () => {
    contenedor?.classList.remove('elegido');
    op.alElegir(null);
    abrir();
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); lista.hidden ? abrir() : mover(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
    else if (e.key === 'Enter' && !lista.hidden && activa >= 0) { e.preventDefault(); elegir(activa); }
    else if (e.key === 'Escape') cerrar();
  });
  input.addEventListener('blur', () => setTimeout(cerrar, 150));

  // pointerdown + preventDefault: elige antes de que el campo pierda el foco.
  lista.addEventListener('pointerdown', e => {
    const li = e.target.closest('[role="option"]');
    if (!li) return;
    e.preventDefault();
    elegir(Number(li.dataset.i));
  });

  contenedor?.querySelector('.buscador-limpiar')?.addEventListener('click', () => {
    input.value = '';
    contenedor.classList.remove('elegido');
    op.alElegir(null);
    input.focus();
  });

  return { abrir, cerrar };
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Pone en negrita las partes que coinciden con lo escrito, sin importar tildes. */
function resaltar(texto, consulta) {
  const palabras = consulta.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().split(/\s+/).filter(Boolean);
  if (!palabras.length) return esc(texto);
  const plano = texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  // Cada carácter del texto original conserva su posición en el plano si la
  // normalización no cambió la longitud; si cambió (raro), no se resalta.
  if (plano.length !== texto.length) return esc(texto);
  const marcar = new Array(texto.length).fill(false);
  for (const p of palabras) {
    let i = plano.indexOf(p);
    while (i !== -1) {
      for (let k = i; k < i + p.length; k++) marcar[k] = true;
      i = plano.indexOf(p, i + p.length);
    }
  }
  let html = '';
  let dentro = false;
  for (let i = 0; i < texto.length; i++) {
    if (marcar[i] && !dentro) { html += '<mark>'; dentro = true; }
    if (!marcar[i] && dentro) { html += '</mark>'; dentro = false; }
    html += esc(texto[i]);
  }
  return html + (dentro ? '</mark>' : '');
}
