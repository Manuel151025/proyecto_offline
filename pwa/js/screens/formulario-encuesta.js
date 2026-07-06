import { getPersona, savePersona, addSyncItem, softDeletePersona, getMunicipios } from '../db.js';
import { navigate } from '../router.js';
import { generateUUID, getDeviceId, nowMs, dateToMs, msToDateInput, showToast } from '../utils.js';
import { registerBackgroundSync } from '../sync.js';
import { getSession } from '../session.js';

const TIPOS_DOC = ['CC', 'TI', 'RC', 'CE', 'PP', 'NIT', 'PE'];

function currentEncuestadorId() {
  return getSession()?.encuestadorId || 1;
}

export async function render(container, params) {
  const isEdit = !!(params.tipo && params.numero);
  const title = isEdit ? 'Editar Persona' : 'Registrar Persona';

  let municipios = [];
  try { municipios = await getMunicipios(); } catch (_) {}

  let persona = null;
  if (isEdit) {
    persona = await getPersona(params.tipo, params.numero);
    if (!persona) {
      showToast('Persona no encontrada', 'error');
      navigate('/personas');
      return;
    }
  }

  const tipoOptions = TIPOS_DOC.map(t =>
    `<option value="${t}" ${(persona?.tipo_documento || 'CC') === t ? 'selected' : ''}>${t}</option>`
  ).join('');

  container.innerHTML = `
    <div class="screen screen-form">
      <div class="form-header">
        <button class="btn-back" id="btn-back">&#8592;</button>
        <h2>${title}</h2>
        ${isEdit ? '<button class="btn-delete" id="btn-delete" title="Eliminar">&#128465;</button>' : ''}
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
                     maxlength="20" ${isEdit ? 'readonly' : ''} required />
            </div>
          </div>

          <div class="form-section-title">Datos personales</div>

          <div class="form-field">
            <label for="nombres">Nombres *</label>
            <input type="text" id="nombres" name="nombres"
                   value="${esc(persona?.nombres || '')}" maxlength="100" required />
          </div>
          <div class="form-field">
            <label for="apellidos">Apellidos *</label>
            <input type="text" id="apellidos" name="apellidos"
                   value="${esc(persona?.apellidos || '')}" maxlength="100" required />
          </div>
          <div class="form-field">
            <label for="fecha_nacimiento">Fecha de nacimiento</label>
            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento"
                   value="${msToDateInput(persona?.fecha_nacimiento)}" />
          </div>

          <div class="form-section-title">Contacto</div>

          <div class="form-field">
            <label for="telefono">Teléfono</label>
            <input type="tel" id="telefono" name="telefono"
                   value="${esc(persona?.telefono || '')}" maxlength="20" />
          </div>
          <div class="form-field">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email"
                   value="${esc(persona?.email || '')}" maxlength="100" />
          </div>
          <div class="form-field">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion"
                   value="${esc(persona?.direccion || '')}" maxlength="150" />
          </div>

          <div class="form-section-title">Ubicación</div>

          <div class="form-field">
            <label for="departamento_filtro">Departamento</label>
            <select id="departamento_filtro">
              <option value="">— Seleccionar departamento —</option>
            </select>
          </div>
          <div class="form-field">
            <label for="municipio_codigo">Municipio</label>
            <select id="municipio_codigo" name="municipio_codigo">
              <option value="">— Seleccionar municipio —</option>
            </select>
          </div>
          <div class="form-field">
            <label for="vereda">Vereda <span class="field-optional">(opcional)</span></label>
            <input type="text" id="vereda" name="vereda"
                   value="${esc(persona?.vereda || '')}" maxlength="100"
                   placeholder="Ej: Vereda El Carmen" />
          </div>

          <div class="form-section-title">Información socioeconómica</div>

          <div class="form-field">
            <label for="eps">EPS</label>
            <input type="text" id="eps" name="eps"
                   value="${esc(persona?.eps || '')}" maxlength="50" />
          </div>
          <div class="form-field">
            <label for="ocupacion">Ocupación</label>
            <input type="text" id="ocupacion" name="ocupacion"
                   value="${esc(persona?.ocupacion || '')}" maxlength="100" />
          </div>
          <div class="form-field">
            <label for="estrato">Estrato (1–6)</label>
            <input type="number" id="estrato" name="estrato"
                   value="${persona?.estrato ?? ''}" min="1" max="6" />
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
    await handleSubmit(isEdit, persona);
  });

  setupDepartamentoFilter(municipios, persona?.municipio_codigo ?? null);
}

function setupDepartamentoFilter(municipios, selectedCodigo) {
  const depSelect = document.getElementById('departamento_filtro');
  const muniSelect = document.getElementById('municipio_codigo');

  const departamentos = [...new Set(municipios.map(m => m.departamento))].sort();
  const currentMuni = municipios.find(m => m.codigo === selectedCodigo);
  const currentDep = currentMuni?.departamento ?? '';

  depSelect.innerHTML = '<option value="">— Seleccionar departamento —</option>' +
    departamentos.map(d =>
      `<option value="${esc(d)}" ${d === currentDep ? 'selected' : ''}>${esc(d)}</option>`
    ).join('');

  renderMunicipios(currentDep);

  depSelect.addEventListener('change', () => {
    renderMunicipios(depSelect.value);
    muniSelect.value = '';
  });

  function renderMunicipios(dep) {
    const filtered = dep ? municipios.filter(m => m.departamento === dep) : municipios;
    muniSelect.innerHTML = '<option value="">— Seleccionar municipio —</option>' +
      filtered.map(m =>
        `<option value="${esc(m.codigo)}" ${m.codigo === selectedCodigo ? 'selected' : ''}>${esc(m.nombre)}</option>`
      ).join('');
  }
}

async function handleSubmit(isEdit, existing) {
  const form = document.getElementById('encuesta-form');
  const btn = document.getElementById('btn-guardar');

  const tipo = existing?.tipo_documento || form.tipo_documento.value.trim();
  const numero = existing?.numero_documento || form.numero_documento.value.trim();
  const nombres = form.nombres.value.trim();
  const apellidos = form.apellidos.value.trim();

  if (!tipo || !numero || !nombres || !apellidos) {
    showToast('Completa los campos obligatorios (*)', 'error');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Guardando...';

  try {
    const ts = nowMs();
    const persona = {
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
      estrato: form.estrato.value ? parseInt(form.estrato.value) : null,
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

    showToast(isEdit ? 'Persona actualizada' : 'Persona registrada', 'success');
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
