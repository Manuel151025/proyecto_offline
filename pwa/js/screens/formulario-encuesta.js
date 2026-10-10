import { getPersona, savePersona, addSyncItem, softDeletePersona } from '../db.js';
import { navigate } from '../router.js';
import { generateUUID, getDeviceId, nowMs, dateToMs, msToDateInput, showToast } from '../utils.js';
import { registerBackgroundSync } from '../sync.js';
import { getSession } from '../session.js';
import { validarPersona, limpiarCampo, TIPOS_DOCUMENTO, DOCUMENTOS, CAMPOS } from '../validacion.js';
import { cargarMunicipios, cargarEps, buscarMunicipios, buscarEps, etiquetaMunicipio, BOGOTA } from '../catalogos.js';
import { crearBuscador } from '../componentes/buscador.js';

const TIPOS_DOC = TIPOS_DOCUMENTO;

function currentEncuestadorId() {
  return getSession()?.encuestadorId || 1;
}

export async function render(container, params) {
  const isEdit = !!(params.tipo && params.numero);
  const title = isEdit ? 'Editar Persona' : 'Registrar Persona';

  let municipios = [];
  let eps = [];
  try { [municipios, eps] = await Promise.all([cargarMunicipios(), cargarEps()]); } catch (_) {}

  let persona = null;
  if (isEdit) {
    persona = await getPersona(params.tipo, params.numero);
    if (!persona) {
      showToast('Persona no encontrada', 'error');
      navigate('/personas');
      return;
    }
  }

  const tipoInicial = persona?.tipo_documento || 'CC';
  const hoyIso = msToDateInput(Date.UTC(new Date().getFullYear(), new Date().getMonth(), new Date().getDate()));
  const estratoActual = persona?.estrato ?? '';
  const estratoOptions = ['', 1, 2, 3, 4, 5, 6].map(n =>
    `<option value="${n}" ${String(estratoActual) === String(n) ? 'selected' : ''}>${n === '' ? '— Sin dato —' : n}</option>`
  ).join('');
  const municipioActual = municipios.find(m => m.codigo === persona?.municipio_codigo) ?? null;
  const tipoOptions = TIPOS_DOC.map(t =>
    `<option value="${t}" ${(persona?.tipo_documento || 'CC') === t ? 'selected' : ''}>${t}</option>`
  ).join('');

  container.innerHTML = `
    <div class="screen screen-form">
      <div class="form-header">
        <button class="btn-back" id="btn-back" aria-label="Volver"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>
        <h2>${title}</h2>
        ${isEdit ? '<button class="btn-delete" id="btn-delete" title="Eliminar" aria-label="Eliminar persona"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>' : ''}
      </div>
      <div class="screen-content">
        <form id="encuesta-form" novalidate>
          <div class="form-section-title">Documento</div>

          <div class="form-row">
            <div class="form-field">
              <label for="tipo_documento">Tipo *</label>
              <select id="tipo_documento" name="tipo_documento" ${isEdit ? 'disabled' : ''} required>
                ${tipoOptions}
              </select>
            </div>
            <div class="form-field form-field-grow">
              <label for="numero_documento">Número *</label>
              <input type="text" id="numero_documento" name="numero_documento"
                     value="${esc(persona?.numero_documento || '')}"
                     inputmode="${DOCUMENTOS[tipoInicial]?.letras ? 'text' : 'numeric'}"
                     maxlength="${DOCUMENTOS[tipoInicial]?.max ?? 20}" autocomplete="off"
                     ${isEdit ? 'readonly' : ''} required />
            </div>
          </div>
          ${isEdit ? '' : `<p class="field-hint" id="ayuda-documento">${DOCUMENTOS[tipoInicial]?.ayuda ?? ''}</p>`}

          <div class="form-section-title">Datos personales</div>

          <div class="form-field">
            <label for="nombres">Nombres *</label>
            <input type="text" id="nombres" name="nombres"
                   value="${esc(persona?.nombres || '')}" maxlength="${CAMPOS.nombres.max}" autocomplete="off" autocapitalize="words" required />
          </div>
          <div class="form-field">
            <label for="apellidos">Apellidos *</label>
            <input type="text" id="apellidos" name="apellidos"
                   value="${esc(persona?.apellidos || '')}" maxlength="${CAMPOS.apellidos.max}" autocomplete="off" autocapitalize="words" required />
          </div>
          <div class="form-field">
            <label for="fecha_nacimiento">Fecha de nacimiento</label>
            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento"
                   value="${msToDateInput(persona?.fecha_nacimiento)}" min="1900-01-01" max="${hoyIso}" />
          </div>

          <div class="form-section-title">Contacto</div>

          <div class="form-field">
            <label for="telefono">Teléfono</label>
            <input type="tel" id="telefono" name="telefono"
                   value="${esc(persona?.telefono || '')}" maxlength="10" inputmode="numeric"
                   placeholder="10 dígitos, ej: 3001234567" autocomplete="off" />
          </div>
          <div class="form-field">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email"
                   value="${esc(persona?.email || '')}" maxlength="${CAMPOS.email.max}" autocomplete="off" />
          </div>
          <div class="form-field">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion"
                   value="${esc(persona?.direccion || '')}" maxlength="${CAMPOS.direccion.max}" autocomplete="off" />
          </div>

          <div class="form-section-title">Ubicación</div>

          <div class="form-field">
            <label for="municipio_buscar">Municipio</label>
            <div class="buscador${municipioActual ? ' elegido' : ''}">
              <svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
              <input type="text" id="municipio_buscar" placeholder="Escribe la ciudad o el departamento"
                     value="${esc(municipioActual ? etiquetaMunicipio(municipioActual) : '')}" />
              <svg class="buscador-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
              <button type="button" class="buscador-limpiar" aria-label="Borrar municipio"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
              <ul class="buscador-lista" id="municipio_lista" hidden></ul>
            </div>
            <input type="hidden" id="municipio_codigo" name="municipio_codigo" value="${esc(persona?.municipio_codigo || '')}" />
            <p class="field-hint">Los ${(municipios.length || 1122).toLocaleString('es-CO')} municipios de Colombia. Escribe parte del nombre o el departamento: «popa», «cauca», «cali».</p>
          </div>
          <div class="form-field">
            <label for="vereda">Vereda <span class="field-optional">(opcional)</span></label>
            <input type="text" id="vereda" name="vereda"
                   value="${esc(persona?.vereda || '')}" maxlength="${CAMPOS.vereda.max}" autocomplete="off"
                   placeholder="Ej: Vereda El Carmen" />
          </div>

          <div class="form-section-title">Información socioeconómica</div>

          <div class="form-field">
            <label for="eps">EPS</label>
            <div class="buscador${persona?.eps ? ' elegido' : ''}">
              <svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 21s-7-4.5-7-11a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 6.5-7 11-7 11z"/><path d="M12 9v5M9.5 11.5h5"/></svg>
              <input type="text" id="eps" name="eps" placeholder="Busca la EPS o escríbela"
                     value="${esc(persona?.eps || '')}" maxlength="${CAMPOS.eps.max}" />
              <svg class="buscador-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
              <button type="button" class="buscador-limpiar" aria-label="Borrar EPS"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
              <ul class="buscador-lista" id="eps_lista" hidden></ul>
            </div>
          </div>
          <div class="form-field">
            <label for="ocupacion">Ocupación</label>
            <input type="text" id="ocupacion" name="ocupacion"
                   value="${esc(persona?.ocupacion || '')}" maxlength="${CAMPOS.ocupacion.max}" autocomplete="off" />
          </div>
          <div class="form-field">
            <label for="estrato">Estrato</label>
            <select id="estrato" name="estrato">${estratoOptions}</select>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-full" id="btn-guardar">
              Guardar
            </button>
          </div>
        </form>
      </div>
    </div>
  `;

  document.getElementById('btn-back').onclick = () => navigate('/personas');

  if (isEdit) {
    document.getElementById('btn-delete').onclick = () => confirmDelete(params.tipo, params.numero);
  }

  document.getElementById('encuesta-form').addEventListener('submit', async e => {
    e.preventDefault();
    await handleSubmit(isEdit, persona, municipios);
  });

  setupFiltros(isEdit);
  setupBuscadores(municipios, eps);
}

/**
 * Municipio y EPS se eligen escribiendo: «popa» → Popayán, «cauca» → los
 * municipios del Cauca. Antes eran dos listas desplegables con 3 departamentos.
 */
function setupBuscadores(municipios, eps) {
  const codigo = document.getElementById('municipio_codigo');
  crearBuscador({
    input: document.getElementById('municipio_buscar'),
    lista: document.getElementById('municipio_lista'),
    buscar: q => buscarMunicipios(municipios, q, 60),
    pintar: m => ({
      titulo: m.nombre,
      detalle: m.codigo === BOGOTA ? 'Distrito Capital' : m.departamento,
      etiqueta: m.capital ? 'Capital' : ''
    }),
    texto: etiquetaMunicipio,
    alElegir: m => {
      codigo.value = m?.codigo ?? '';
      if (m) limpiarError(document.getElementById('municipio_buscar'));
    },
    encabezado: q => (q.trim() ? '' : 'Ciudades principales'),
    vacio: municipios.length ? 'Ningún municipio coincide. Revisa cómo está escrito.' : 'El catálogo aún no se ha cargado. Conéctate una vez para descargarlo.'
  });

  crearBuscador({
    input: document.getElementById('eps'),
    lista: document.getElementById('eps_lista'),
    buscar: q => buscarEps(eps, q, 40),
    pintar: e => ({ titulo: e.nombre, detalle: e.detalle }),
    texto: e => e.nombre,
    alElegir: e => { if (e) limpiarError(document.getElementById('eps')); },
    encabezado: q => (q.trim() ? '' : 'EPS en Colombia'),
    vacio: 'No está en la lista: puedes dejarla escrita así.'
  });
}

/**
 * Filtra lo que se escribe en cada campo: lo que no puede ir ni siquiera
 * entra, y el error de ese campo se borra en cuanto el usuario lo corrige.
 */
function setupFiltros(isEdit) {
  const form = document.getElementById('encuesta-form');
  const tipo = document.getElementById('tipo_documento');
  const doc = document.getElementById('numero_documento');

  for (const campo of ['numero_documento', 'nombres', 'apellidos', 'telefono', 'email', 'direccion', 'vereda', 'eps', 'ocupacion']) {
    const input = document.getElementById(campo);
    if (!input || input.readOnly) continue;
    input.addEventListener('input', () => {
      const limpio = limpiarCampo(campo, input.value, tipo.value);
      if (limpio !== input.value) input.value = limpio;
      limpiarError(input);
    });
  }
  form.querySelectorAll('select, input[type="date"]').forEach(el =>
    el.addEventListener('change', () => limpiarError(el)));

  // Al cambiar el tipo cambian el teclado, el largo y la ayuda del número.
  if (!isEdit) {
    tipo.addEventListener('change', () => {
      const regla = DOCUMENTOS[tipo.value];
      doc.inputMode = regla.letras ? 'text' : 'numeric';
      doc.maxLength = regla.max;
      doc.value = limpiarCampo('numero_documento', doc.value, tipo.value);
      document.getElementById('ayuda-documento').textContent = regla.ayuda;
      limpiarError(doc);
    });
  }
}

function limpiarError(input) {
  if (!input.classList.contains('input-error')) return;
  input.classList.remove('input-error');
  input.removeAttribute('aria-invalid');
  const msg = (input.closest('.buscador') ?? input).nextElementSibling;
  if (msg?.classList.contains('field-error')) msg.remove();
}

/** Campo oculto → campo que ve el encuestador. */
const VISIBLE = { municipio_codigo: 'municipio_buscar' };

/**
 * Marca los campos con error y deja el mensaje debajo de cada uno.
 * Devuelve true si hubo errores.
 */
function mostrarErrores(form, errores) {
  form.querySelectorAll('.field-error').forEach(el => el.remove());
  form.querySelectorAll('.input-error').forEach(el => {
    el.classList.remove('input-error');
    el.removeAttribute('aria-invalid');
  });

  const campos = Object.keys(errores);
  for (const campo of campos) {
    const input = document.getElementById(VISIBLE[campo] ?? campo);
    if (!input) continue;
    input.classList.add('input-error');
    input.setAttribute('aria-invalid', 'true');
    const msg = document.createElement('p');
    msg.className = 'field-error';
    msg.textContent = errores[campo];
    (input.closest('.buscador') ?? input).insertAdjacentElement('afterend', msg);
  }
  if (campos.length) {
    document.getElementById(VISIBLE[campos[0]] ?? campos[0])?.focus();
    showToast('Revisa los campos marcados', 'error');
  }
  return campos.length > 0;
}

async function handleSubmit(isEdit, existing, municipios = []) {
  const form = document.getElementById('encuesta-form');
  const btn = document.getElementById('btn-guardar');

  const tipo = existing?.tipo_documento || form.tipo_documento.value.trim();
  const numero = existing?.numero_documento || form.numero_documento.value.trim();
  const nombres = form.nombres.value.trim();
  const apellidos = form.apellidos.value.trim();
  const ts = nowMs();

  const borrador = {
    tipo_documento: tipo,
    numero_documento: numero,
    nombres,
    apellidos,
    fecha_nacimiento: dateToMs(form.fecha_nacimiento.value) || null,
    telefono: form.telefono.value.trim() || null,
    email: form.email.value.trim() || null,
    direccion: form.direccion.value.trim() || null,
    vereda: form.vereda.value.trim() || null,
    eps: form.eps.value.trim() || null,
    ocupacion: form.ocupacion.value.trim() || null,
    estrato: form.estrato.value === '' ? null : Number(form.estrato.value),
    municipio_codigo: form.municipio_codigo.value || null
  };
  // Mismas reglas que el servidor: lo que no pasaría allí no se guarda aquí,
  // mientras el encuestador todavía está frente a la persona para corregirlo.
  const errores = validarPersona(borrador, {
    municipiosValidos: municipios.length ? new Set(municipios.map(m => m.codigo)) : null,
    ahora: ts
  });
  // Al editar, el documento no se puede cambiar: no se bloquea por él.
  if (isEdit) delete errores.numero_documento;
  // Se escribió algo en el municipio pero no se eligió de la lista.
  if (!borrador.municipio_codigo && form.querySelector('#municipio_buscar').value.trim()) {
    errores.municipio_codigo = 'Elige el municipio de la lista de sugerencias';
  }
  if (mostrarErrores(form, errores)) return;

  btn.disabled = true;
  btn.textContent = 'Guardando...';

  try {
    const persona = {
      tipo_documento: tipo,
      numero_documento: numero,
      nombres,
      apellidos,
      fecha_nacimiento: borrador.fecha_nacimiento,
      telefono: borrador.telefono,
      email: borrador.email,
      direccion: borrador.direccion,
      vereda: borrador.vereda,
      eps: borrador.eps,
      ocupacion: borrador.ocupacion,
      estrato: borrador.estrato,
      municipio_codigo: form.municipio_codigo.value || null,
      updated_at: ts,
      device_id: getDeviceId(),
      deleted_at: existing?.deleted_at ?? null,
      _pendingSync: true
    };

    const encuesta = {
      id: generateUUID(),
      tipo_documento: tipo,
      numero_documento: numero,
      id_encuestador: currentEncuestadorId(),
      fecha_encuesta: ts,
      device_id: getDeviceId(),
      accion: isEdit ? 'ACTUALIZACION' : 'REGISTRO'
    };

    await savePersona(persona);
    await addSyncItem({ persona, encuesta, status: 'PENDING', created_at: ts });
    registerBackgroundSync();

    // Sin señal se dice explícitamente dónde quedó: es la duda que más
    // inquieta en campo.
    showToast(navigator.onLine
      ? (isEdit ? 'Persona actualizada' : 'Persona registrada')
      : 'Guardado en el teléfono. Se enviará cuando haya señal', 'success');
    navigate('/personas');
  } catch (err) {
    showToast('Error al guardar: ' + err.message, 'error');
    btn.disabled = false;
    btn.textContent = 'Guardar';
  }
}

async function confirmDelete(tipo, numero) {
  if (!confirm('¿Eliminar esta persona? La eliminación se sincronizará al servidor.')) return;

  try {
    const ts = nowMs();
    await softDeletePersona(tipo, numero);

    const persona = await getPersona(tipo, numero) || {
      tipo_documento: tipo, numero_documento: numero,
      updated_at: ts, device_id: getDeviceId(), deleted_at: ts
    };

    const encuesta = {
      id: generateUUID(),
      tipo_documento: tipo,
      numero_documento: numero,
      id_encuestador: currentEncuestadorId(),
      fecha_encuesta: ts,
      device_id: getDeviceId(),
      accion: 'ELIMINACION'
    };

    await addSyncItem({ persona, encuesta, status: 'PENDING', created_at: ts });
    registerBackgroundSync();
    showToast('Persona eliminada', 'success');
    navigate('/personas');
  } catch (err) {
    showToast('Error al eliminar: ' + err.message, 'error');
  }
}

function esc(str) {
  return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
