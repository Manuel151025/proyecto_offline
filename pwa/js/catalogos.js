/**
 * Catálogos de municipios (DIVIPOLA) y EPS.
 *
 * Se sirven como archivos estáticos (pwa/data/*.json) que el service worker
 * guarda con el resto de la app, así que están disponibles sin señal desde la
 * primera visita. Antes la app pedía los municipios a la API UNA sola vez y
 * nunca los volvía a pedir: un teléfono podía quedarse con una lista vieja de
 * 3 departamentos y tildes dañadas para siempre. Ahora cada versión de la app
 * trae su catálogo; al cambiar, el service worker lo reemplaza.
 *
 * La búsqueda es pura (sin DOM) para poder probarla en Node.
 */

/** Minúsculas, sin tildes ni espacios repetidos: «Popayán» y «popayan» son iguales. */
export function normalizar(texto) {
  return String(texto ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/\s+/g, ' ').trim();
}

/** Convierte el formato compacto [código, nombre, departamento, principal] en objetos listos para buscar. */
export function prepararMunicipios(datos) {
  return datos.municipios.map(([codigo, nombre, departamento, principal]) => ({
    codigo,
    nombre,
    departamento,
    principal: principal === 1,
    // La capital de cada departamento tiene código XX001, salvo Cundinamarca
    // (25001 es Agua de Dios; su capital es Bogotá).
    capital: codigo.endsWith('001') && codigo !== '25001',
    _nombre: normalizar(nombre),
    _depto: normalizar(departamento)
  }));
}

export function prepararEps(datos) {
  return datos.eps.map(e => ({ ...e, _nombre: normalizar(e.nombre), _detalle: normalizar(e.detalle) }));
}

let municipios = null;
let eps = null;

async function leerJson(ruta) {
  const res = await fetch(ruta);
  if (!res.ok) throw new Error(`No se pudo leer ${ruta}`);
  return res.json();
}

export async function cargarMunicipios() {
  municipios ??= prepararMunicipios(await leerJson('./data/municipios.json'));
  return municipios;
}

export async function cargarEps() {
  eps ??= prepararEps(await leerJson('./data/eps.json'));
  return eps;
}

/** ¿Alguna palabra del texto empieza por `prefijo`? */
function empiezaPalabra(texto, prefijo) {
  return texto.startsWith(prefijo) || texto.includes(' ' + prefijo) || texto.includes('-' + prefijo);
}

/**
 * Busca municipios por nombre o por departamento.
 *
 * - Sin consulta: las ciudades principales (capitales primero).
 * - «popa» → Popayán · «cauca» → los municipios del Cauca, capital primero ·
 *   «cali» → Santiago de Cali · «san jose cucuta» → San José de Cúcuta.
 * Cada palabra de la consulta debe aparecer en el nombre o el departamento.
 *
 * @returns {Array} hasta `limite` municipios, del más al menos probable
 */
export function buscarMunicipios(lista, consulta, limite = 60) {
  const q = normalizar(consulta);
  if (!q) {
    return lista.filter(m => m.principal)
      .sort((a, b) => (b.capital - a.capital) || a.nombre.localeCompare(b.nombre, 'es'))
      .slice(0, limite);
  }
  const palabras = q.split(' ');
  const resultados = [];
  for (const m of lista) {
    const texto = m._nombre + ' ' + m._depto;
    if (!palabras.every(p => texto.includes(p))) continue;
    // De más a menos probable: «cali» es Santiago de Cali antes que Calima,
    // y «cauca» son los municipios del Cauca antes que Caucasia.
    let puntaje;
    if (m._nombre === q) puntaje = 0;
    else if (m._depto === q) puntaje = 1;
    else if (m._nombre.split(/[ -]/).includes(q)) puntaje = 2;
    else if (m._nombre.startsWith(q)) puntaje = 3;
    else if (empiezaPalabra(m._nombre, q)) puntaje = 4;
    else if (palabras.every(p => empiezaPalabra(m._nombre, p))) puntaje = 5;
    else if (m._depto.startsWith(q) || palabras.every(p => empiezaPalabra(m._depto, p) || empiezaPalabra(m._nombre, p))) puntaje = 6;
    else puntaje = 7;
    resultados.push({ m, puntaje });
  }
  resultados.sort((a, b) =>
    (a.puntaje - b.puntaje) ||
    (b.m.capital - a.m.capital) ||
    (b.m.principal - a.m.principal) ||
    a.m.nombre.localeCompare(b.m.nombre, 'es'));
  return resultados.slice(0, limite).map(r => r.m);
}

/** Busca EPS por nombre o por su descripción («indígena», «Valle»…). Sin consulta, todas en su orden. */
export function buscarEps(lista, consulta, limite = 40) {
  const q = normalizar(consulta);
  if (!q) return lista.slice(0, limite);
  const palabras = q.split(' ');
  return lista
    .filter(e => palabras.every(p => (e._nombre + ' ' + e._detalle).includes(p)))
    .map(e => ({ e, puntaje: e._nombre.startsWith(q) ? 0 : empiezaPalabra(e._nombre, q) ? 1 : e._nombre.includes(q) ? 2 : 3 }))
    .sort((a, b) => a.puntaje - b.puntaje)
    .slice(0, limite)
    .map(r => r.e);
}

/** Código DANE de Bogotá: es a la vez municipio y departamento. */
export const BOGOTA = '11001';

/** Texto que se muestra al elegir un municipio: «Popayán, Cauca», «Bogotá D.C.». */
export function etiquetaMunicipio(m) {
  return m.codigo === BOGOTA ? m.nombre : `${m.nombre}, ${m.departamento}`;
}
