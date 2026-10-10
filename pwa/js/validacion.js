/**
 * Reglas de validación de una persona: las MISMAS que aplica el servidor en
 * api/personas/validacion.php y Android en Validaciones.kt.
 *
 * Antes el formulario solo exigía que los campos obligatorios no estuvieran
 * vacíos. Un documento de 3 dígitos se guardaba sin queja en el teléfono y el
 * servidor lo rechazaba días después, cuando el encuestador ya no estaba
 * frente a la persona para corregirlo.
 *
 * Hay dos capas:
 *  - `limpiarCampo` filtra lo que se escribe: un carácter que no puede ir en
 *    el campo ni siquiera entra (letras en el teléfono, números en el nombre).
 *  - `validarPersona` revisa el conjunto al guardar: longitudes mínimas,
 *    formatos y rangos que no se pueden imponer tecla a tecla.
 *
 * Son funciones puras, sin DOM ni IndexedDB, para poder probarlas en Node.
 */

export const TIPOS_DOCUMENTO = ['CC', 'TI', 'RC', 'CE', 'PP', 'NIT', 'PE'];

/**
 * Formato del número según el tipo de documento.
 * Solo el pasaporte lleva letras; los demás son únicamente dígitos.
 */
export const DOCUMENTOS = {
  CC:  { min: 6,  max: 10, letras: false, ayuda: 'Cédula: de 6 a 10 dígitos' },
  TI:  { min: 10, max: 11, letras: false, ayuda: 'Tarjeta de identidad: 10 u 11 dígitos' },
  RC:  { min: 10, max: 11, letras: false, ayuda: 'Registro civil (NUIP): 10 u 11 dígitos' },
  CE:  { min: 6,  max: 10, letras: false, ayuda: 'Cédula de extranjería: de 6 a 10 dígitos' },
  PP:  { min: 6,  max: 12, letras: true,  ayuda: 'Pasaporte: de 6 a 12 letras o dígitos' },
  NIT: { min: 9,  max: 10, letras: false, ayuda: 'NIT: 9 o 10 dígitos, sin guion' },
  PE:  { min: 6,  max: 15, letras: false, ayuda: 'Permiso especial: de 6 a 15 dígitos' }
};

/**
 * Campos de texto: caracteres admitidos y longitudes.
 * `permitidos` es la clase de caracteres (sin corchetes) que admite el campo.
 */
export const CAMPOS = {
  nombres:   { min: 2, max: 60,  permitidos: "\\p{L}\\p{M} '\\-" },
  apellidos: { min: 2, max: 60,  permitidos: "\\p{L}\\p{M} '\\-" },
  telefono:  { min: 10, max: 10, permitidos: '0-9' },
  email:     { min: 0, max: 100, permitidos: "A-Za-z0-9._%+\\-@" },
  direccion: { min: 5, max: 150, permitidos: "\\p{L}\\p{M}0-9 #\\-.,/°º" },
  vereda:    { min: 3, max: 100, permitidos: "\\p{L}\\p{M}0-9 .'\\-" },
  eps:       { min: 3, max: 50,  permitidos: "\\p{L}\\p{M}0-9 .&\\-" },
  ocupacion: { min: 3, max: 60,  permitidos: "\\p{L}\\p{M} ,.\\-" }
};

/** Medianoche UTC del 1 de enero de 1900. */
const FECHA_MINIMA = Date.UTC(1900, 0, 1);

const NOMBRE = /^\p{L}[\p{L}\p{M} '\-]*$/u;
const EMAIL = /^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/;
/** Celular (3xx) o fijo con indicativo nacional (60x): siempre 10 dígitos. */
const TELEFONO = /^(3\d{9}|60\d{8})$/;

/**
 * Quita lo que no puede ir en el campo mientras se escribe y corta al máximo.
 * No recorta espacios al final: el usuario puede estar escribiendo "María ".
 *
 * @param {string} campo nombre del campo (o 'numero_documento')
 * @param {string} valor lo que hay escrito
 * @param {string} [tipoDocumento] necesario para 'numero_documento'
 */
export function limpiarCampo(campo, valor, tipoDocumento = 'CC') {
  let v = String(valor ?? '');
  if (campo === 'numero_documento') {
    const regla = DOCUMENTOS[tipoDocumento] || DOCUMENTOS.CC;
    v = regla.letras ? v.toUpperCase().replace(/[^A-Z0-9]/g, '') : v.replace(/\D/g, '');
    return v.slice(0, regla.max);
  }
  if (campo === 'estrato') return v.replace(/[^1-6]/g, '').slice(0, 1);
  const regla = CAMPOS[campo];
  if (!regla) return v;
  v = v.replace(new RegExp(`[^${regla.permitidos}]`, 'gu'), '');
  if (campo === 'email') v = v.toLowerCase();
  // Nunca dos espacios seguidos ni espacio al principio.
  v = v.replace(/ {2,}/g, ' ').replace(/^ /, '');
  return v.slice(0, regla.max);
}

/** Valida un texto opcional contra su regla. Devuelve el mensaje o null. */
function textoLibre(campo, valor, etiqueta) {
  const v = String(valor ?? '').trim();
  if (!v) return null;
  const regla = CAMPOS[campo];
  if (v.length < regla.min) return `${etiqueta}: mínimo ${regla.min} caracteres`;
  if (v.length > regla.max) return `${etiqueta}: máximo ${regla.max} caracteres`;
  if (!new RegExp(`^[${regla.permitidos}]+$`, 'u').test(v)) return `${etiqueta} tiene caracteres no permitidos`;
  if (!/\p{L}/u.test(v)) return `${etiqueta} debe tener letras`;
  return null;
}

/**
 * @param {object} p persona con los nombres de campo de la API
 * @param {{municipiosValidos?: Set<string>|null, ahora?: number}} [opciones]
 * @returns {Record<string, string>} campo → mensaje; vacío si todo es válido
 */
export function validarPersona(p, { municipiosValidos = null, ahora = Date.now() } = {}) {
  const errores = {};

  const tipo = p.tipo_documento;
  if (!TIPOS_DOCUMENTO.includes(tipo)) {
    errores.tipo_documento = 'Elige un tipo de documento';
  }

  const doc = String(p.numero_documento ?? '').trim();
  const reglaDoc = DOCUMENTOS[tipo] || DOCUMENTOS.CC;
  if (!doc) errores.numero_documento = 'El número de documento es obligatorio';
  else if (!(reglaDoc.letras ? /^[A-Za-z0-9]+$/ : /^\d+$/).test(doc)) {
    errores.numero_documento = reglaDoc.letras ? 'Solo letras y dígitos, sin puntos ni espacios' : 'Solo dígitos, sin puntos ni espacios';
  } else if (doc.length < reglaDoc.min || doc.length > reglaDoc.max) {
    errores.numero_documento = reglaDoc.ayuda;
  }

  for (const [campo, etiqueta] of [['nombres', 'Los nombres'], ['apellidos', 'Los apellidos']]) {
    const valor = String(p[campo] ?? '').trim();
    const regla = CAMPOS[campo];
    if (!valor) errores[campo] = `${etiqueta} son obligatorios`;
    else if (/\d/.test(valor)) errores[campo] = `${etiqueta} no pueden llevar números`;
    else if (!NOMBRE.test(valor)) errores[campo] = `${etiqueta} solo llevan letras, espacios, guion o apóstrofo`;
    else if (valor.length < regla.min) errores[campo] = `${etiqueta} deben tener al menos ${regla.min} letras`;
    else if (valor.length > regla.max) errores[campo] = `${etiqueta}: máximo ${regla.max} caracteres`;
  }

  const tel = String(p.telefono ?? '').trim();
  if (tel && !TELEFONO.test(tel)) {
    errores.telefono = 'Celular de 10 dígitos que empiece por 3, o fijo de 10 que empiece por 60';
  }

  const email = String(p.email ?? '').trim();
  if (email && (email.length > CAMPOS.email.max || !EMAIL.test(email))) {
    errores.email = 'El correo no es válido';
  }

  for (const [campo, etiqueta] of [['direccion', 'La dirección'], ['vereda', 'La vereda'], ['eps', 'La EPS'], ['ocupacion', 'La ocupación']]) {
    const msg = textoLibre(campo, p[campo], etiqueta);
    if (msg) errores[campo] = msg;
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
