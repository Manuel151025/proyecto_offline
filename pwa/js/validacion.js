/**
 * Reglas de validación de una persona: las MISMAS que aplica el servidor en
 * api/personas/sync.php.
 *
 * Antes el formulario solo exigía que los campos obligatorios no estuvieran
 * vacíos. Un documento de 3 dígitos se guardaba sin queja en el teléfono y el
 * servidor lo rechazaba días después, cuando el encuestador ya no estaba
 * frente a la persona para corregirlo.
 *
 * Es una función pura, sin DOM ni IndexedDB, para poder probarla en Node.
 */

export const TIPOS_DOCUMENTO = ['CC', 'TI', 'RC', 'CE', 'PP', 'NIT', 'PE'];

/** Medianoche UTC del 1 de enero de 1900. */
const FECHA_MINIMA = Date.UTC(1900, 0, 1);

/**
 * @param {object} p persona con los nombres de campo de la API
 * @param {{municipiosValidos?: Set<string>|null, ahora?: number}} [opciones]
 * @returns {Record<string, string>} campo → mensaje; vacío si todo es válido
 */
export function validarPersona(p, { municipiosValidos = null, ahora = Date.now() } = {}) {
  const errores = {};

  if (!TIPOS_DOCUMENTO.includes(p.tipo_documento)) {
    errores.tipo_documento = 'Elige un tipo de documento';
  }

  const doc = String(p.numero_documento ?? '').trim();
  if (!doc) errores.numero_documento = 'El número de documento es obligatorio';
  else if (doc.length < 6 || doc.length > 20) errores.numero_documento = 'Debe tener entre 6 y 20 caracteres';
  else if (!/^[A-Za-z0-9-]+$/.test(doc)) errores.numero_documento = 'Solo letras, dígitos y guiones, sin puntos ni espacios';

  for (const [campo, etiqueta] of [['nombres', 'Los nombres'], ['apellidos', 'Los apellidos']]) {
    const valor = String(p[campo] ?? '').trim();
    if (!valor) errores[campo] = `${etiqueta} son obligatorios`;
    else if (/\d/.test(valor)) errores[campo] = `${etiqueta} no pueden llevar números`;
  }

  if (p.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(p.email)) {
    errores.email = 'El correo no es válido';
  }

  if (p.estrato !== null && p.estrato !== undefined && p.estrato !== '') {
    const n = Number(p.estrato);
    if (!Number.isInteger(n) || n < 1 || n > 6) errores.estrato = 'El estrato va de 1 a 6';
  }

  if (p.fecha_nacimiento !== null && p.fecha_nacimiento !== undefined) {
    if (p.fecha_nacimiento < FECHA_MINIMA || p.fecha_nacimiento > ahora) {
      errores.fecha_nacimiento = 'La fecha de nacimiento no puede ser futura ni anterior a 1900';
    }
  }

  if (p.municipio_codigo && municipiosValidos && !municipiosValidos.has(p.municipio_codigo)) {
    errores.municipio_codigo = 'Municipio no reconocido';
  }

  return errores;
}
