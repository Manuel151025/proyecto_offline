<?php
/**
 * Validación de una persona y sus encuestas, compartida por la sincronización
 * (api/personas/sync.php) y la edición desde el panel de administración.
 *
 * Que sea el mismo código es lo importante: una regla que solo aplicara uno de
 * los dos dejaría entrar por la otra puerta datos que la primera rechaza.
 */

/**
 * Un registro concreto del lote no es válido.
 *
 * Antes, cada validación llamaba a responderError, que hace exit: una sola
 * fila mala devolvía 400 y tumbaba el envío entero, hasta 500 registros. Peor
 * aún, el cliente reintentaba ese mismo lote indefinidamente, así que un
 * registro irreparable dejaba la cola de un encuestador bloqueada para
 * siempre. Ahora se lanza esto, se descarta la fila y el resto del lote sigue.
 *
 * Un payload MAL FORMADO (sin los arreglos esperados) sigue siendo 400: eso es
 * un fallo del cliente, no un dato de campo defectuoso.
 */
class DatoInvalido extends RuntimeException
{
}

/** Exige un texto no vacío y lo recorta a la longitud de la columna. */
/** @param array<string, mixed> $fila */
function textoRequerido(array $fila, string $clave, int $max): string
{
    $valor = trim((string)($fila[$clave] ?? ''));
    if ($valor === '') {
        throw new DatoInvalido("Campo obligatorio faltante o vacío: $clave");
    }
    return mb_substr($valor, 0, $max);
}

/** Texto opcional: null si viene vacío o ausente. */
/** @param array<string, mixed> $fila */
function textoOpcional(array $fila, string $clave, int $max): ?string
{
    $valor = trim((string)($fila[$clave] ?? ''));
    return $valor === '' ? null : mb_substr($valor, 0, $max);
}

/** Entero obligatorio (timestamps en milisegundos). */
/** @param array<string, mixed> $fila */
function enteroRequerido(array $fila, string $clave): int
{
    $valor = $fila[$clave] ?? null;
    if (!is_numeric($valor)) {
        throw new DatoInvalido("Campo numérico obligatorio inválido: $clave");
    }
    return (int)$valor;
}

/** Entero opcional: null si viene ausente o no numérico. */
/** @param array<string, mixed> $fila */
function enteroOpcional(array $fila, string $clave): ?int
{
    $valor = $fila[$clave] ?? null;
    return is_numeric($valor) ? (int)$valor : null;
}

/**
 * Tipos de documento admitidos.
 *
 * Es la lista que ofrece el formulario de la PWA y, desde que Android descarga
 * datos ajenos, también la de su enum TipoDocumento. Las tres deben coincidir:
 * un tipo que un cliente no reconozca hace que se salte ese registro.
 */
const TIPOS_DOCUMENTO = ['CC', 'TI', 'RC', 'CE', 'PP', 'NIT', 'PE'];

/**
 * Formato del número por tipo de documento: [mínimo, máximo, admite letras].
 * Es la misma tabla que DOCUMENTOS en pwa/js/validacion.js y que
 * Validaciones.kt en Android. Solo el pasaporte lleva letras.
 */
const FORMATO_DOCUMENTO = [
    'CC'  => [6, 10, false],
    'TI'  => [10, 11, false],
    'RC'  => [10, 11, false],
    'CE'  => [6, 10, false],
    'PP'  => [6, 12, true],
    'NIT' => [9, 10, false],
    'PE'  => [6, 15, false],
];

/**
 * Valida el documento, que es la CLAVE PRIMARIA de personas.
 *
 * Los clientes ya comprueban esto, pero la validación del cliente no es
 * validación: cualquiera con un token y curl puede saltársela. En producción
 * apareció una persona con documento "hola", prueba de que se podía.
 *
 * Cada tipo tiene su largo, y todos son solo dígitos salvo el pasaporte, que
 * puede llevar letras. Un documento con letras en una cédula era un error de
 * digitación que llegaba hasta la base de datos.
 *
 * @param array<string, mixed> $fila
 */
function documentoValidado(array $fila): string
{
    $tipo = strtoupper(trim((string)($fila['tipo_documento'] ?? '')));
    if (!in_array($tipo, TIPOS_DOCUMENTO, true)) {
        throw new DatoInvalido("Tipo de documento no admitido: '$tipo'");
    }

    $numero = trim((string)($fila['numero_documento'] ?? ''));
    [$min, $max, $letras] = FORMATO_DOCUMENTO[$tipo];
    if (!preg_match($letras ? '/^[A-Za-z0-9]+$/' : '/^\d+$/', $numero)) {
        throw new DatoInvalido($letras
            ? 'El número de documento solo admite letras y dígitos'
            : "El número de documento ($tipo) solo admite dígitos");
    }
    $largo = strlen($numero);
    if ($largo < $min || $largo > $max) {
        throw new DatoInvalido($min === $max
            ? "El número de documento ($tipo) debe tener $min dígitos"
            : "El número de documento ($tipo) debe tener entre $min y $max caracteres");
    }

    return $letras ? strtoupper($numero) : $numero;
}

/**
 * Nombres y apellidos: obligatorios, de 2 a 60 caracteres, solo letras
 * (con tildes y ñ), espacios, guion y apóstrofo. Misma regla que la PWA y
 * Android.
 *
 * @param array<string, mixed> $fila
 */
function nombreValidado(array $fila, string $clave): string
{
    $valor = (string)preg_replace('/\s+/u', ' ', trim((string)($fila[$clave] ?? '')));
    if ($valor === '') {
        throw new DatoInvalido("Campo obligatorio faltante o vacío: $clave");
    }
    if (preg_match('/\d/u', $valor)) {
        throw new DatoInvalido("El campo $clave no puede contener números");
    }
    if (!preg_match("/^\p{L}[\p{L}\p{M} '\-]*$/u", $valor)) {
        throw new DatoInvalido("El campo $clave solo admite letras, espacios, guion o apóstrofo");
    }
    $largo = mb_strlen($valor);
    if ($largo < 2 || $largo > 60) {
        throw new DatoInvalido("El campo $clave debe tener entre 2 y 60 caracteres");
    }
    return $valor;
}

/**
 * Teléfono opcional: celular de 10 dígitos que empieza por 3, o fijo de 10
 * que empieza por 60 (marcación nacional desde 2021).
 *
 * @param array<string, mixed> $fila
 */
function telefonoValidado(array $fila): ?string
{
    $tel = textoOpcional($fila, 'telefono', 20);
    if ($tel !== null && !preg_match('/^(3\d{9}|60\d{8})$/', $tel)) {
        throw new DatoInvalido('El teléfono debe ser un celular de 10 dígitos (empieza por 3) o un fijo de 10 (empieza por 60)');
    }
    return $tel;
}

/**
 * Textos libres opcionales (dirección, vereda, EPS, ocupación): largo y
 * caracteres admitidos, los mismos que filtra el formulario al escribir.
 */
const TEXTOS_LIBRES = [
    'direccion' => [5, 150, "\p{L}\p{M}0-9 #\-.,\/°º"],
    'vereda'    => [3, 100, "\p{L}\p{M}0-9 .'\-"],
    'eps'       => [3, 50,  "\p{L}\p{M}0-9 .&\-"],
    'ocupacion' => [3, 60,  "\p{L}\p{M} ,.\-"],
];

/** @param array<string, mixed> $fila */
function textoLibreValidado(array $fila, string $clave): ?string
{
    $valor = trim((string)($fila[$clave] ?? ''));
    if ($valor === '') {
        return null;
    }
    $valor = (string)preg_replace('/\s+/u', ' ', $valor);
    [$min, $max, $permitidos] = TEXTOS_LIBRES[$clave];
    $largo = mb_strlen($valor);
    if ($largo < $min || $largo > $max) {
        throw new DatoInvalido("El campo $clave debe tener entre $min y $max caracteres");
    }
    if (!preg_match('/^[' . $permitidos . ']+$/u', $valor) || !preg_match('/\p{L}/u', $valor)) {
        throw new DatoInvalido("El campo $clave tiene caracteres no permitidos");
    }
    return $valor;
}

/**
 * Estrato socioeconómico: 1 a 6, o vacío.
 *
 * Antes se guardaba cualquier entero: el formulario de la PWA no validaba y
 * llegaron estratos como 9.
 *
 * @param array<string, mixed> $fila
 */
function estratoValidado(array $fila): ?int
{
    $valor = $fila['estrato'] ?? null;
    if ($valor === null || $valor === '') {
        return null;
    }
    if (!is_numeric($valor) || (float)$valor !== (float)(int)$valor) {
        throw new DatoInvalido('El estrato debe ser un número entero');
    }
    $estrato = (int)$valor;
    if ($estrato < 1 || $estrato > 6) {
        throw new DatoInvalido('El estrato debe estar entre 1 y 6');
    }
    return $estrato;
}

/** @param array<string, mixed> $fila */
function emailValidado(array $fila): ?string
{
    $email = textoOpcional($fila, 'email', 100);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new DatoInvalido('El correo electrónico no es válido');
    }
    return $email;
}

/** Medianoche UTC del 1 de enero de 1900, en milisegundos. */
const FECHA_NACIMIENTO_MINIMA = -2208988800000;

/**
 * Fecha de nacimiento: un día de calendario guardado como medianoche UTC.
 * No puede ser futura ni anterior a 1900.
 *
 * @param array<string, mixed> $fila
 */
function fechaNacimientoValidada(array $fila): ?int
{
    $fecha = enteroOpcional($fila, 'fecha_nacimiento');
    if ($fecha === null) {
        return null;
    }
    $manana = ((int)(time() / 86400) + 1) * 86400000;
    if ($fecha < FECHA_NACIMIENTO_MINIMA || $fecha > $manana) {
        throw new DatoInvalido('La fecha de nacimiento no es válida');
    }
    return $fecha;
}

/**
 * Municipio: debe existir en la tabla `municipios`, o venir vacío.
 *
 * Sin esta comprobación, un código desconocido violaba la clave foránea en
 * medio de la transacción: 500, el lote entero perdido (también las personas
 * válidas) y el cliente reintentándolo para siempre.
 *
 * @param array<string, mixed> $fila
 * @param array<string, int> $codigos códigos válidos como claves
 */
function municipioValidado(array $fila, array $codigos): ?string
{
    $codigo = textoOpcional($fila, 'municipio_codigo', 10);
    if ($codigo !== null && !isset($codigos[$codigo])) {
        throw new DatoInvalido("Municipio no reconocido: $codigo");
    }
    return $codigo;
}

/** @param array<string, mixed> $fila */
function tipoDocumentoValidado(array $fila): string
{
    $tipo = strtoupper(trim((string)($fila['tipo_documento'] ?? '')));
    if (!in_array($tipo, TIPOS_DOCUMENTO, true)) {
        throw new DatoInvalido("Tipo de documento no admitido: '$tipo'");
    }
    return $tipo;
}
