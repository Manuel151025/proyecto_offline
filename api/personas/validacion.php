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

/** Longitud mínima del documento; la misma que ya exigen los clientes. */
const MIN_LONGITUD_DOCUMENTO = 6;

/**
 * Valida el documento, que es la CLAVE PRIMARIA de personas.
 *
 * Los clientes ya comprueban esto, pero la validación del cliente no es
 * validación: cualquiera con un token y curl puede saltársela. En producción
 * apareció una persona con documento "hola", prueba de que se podía.
 *
 * No se exige que sean solo dígitos a propósito: los pasaportes y algunas
 * cédulas de extranjería llevan letras, y rechazarlos dejaría fuera a personas
 * reales. Se comprueba la longitud y que no haya caracteres imposibles en un
 * identificador.
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
    if (mb_strlen($numero) < MIN_LONGITUD_DOCUMENTO) {
        throw new DatoInvalido('El número de documento debe tener al menos ' . MIN_LONGITUD_DOCUMENTO . ' caracteres');
    }
    if (mb_strlen($numero) > 20) {
        throw new DatoInvalido('El número de documento no puede superar 20 caracteres');
    }
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $numero)) {
        throw new DatoInvalido('El número de documento solo admite letras, dígitos y guiones');
    }

    return $numero;
}

/**
 * Nombres y apellidos: obligatorios y sin dígitos (la misma regla que aplica
 * Android en Validaciones.kt).
 *
 * @param array<string, mixed> $fila
 */
function nombreValidado(array $fila, string $clave): string
{
    $valor = textoRequerido($fila, $clave, 100);
    if (preg_match('/\d/u', $valor)) {
        throw new DatoInvalido("El campo $clave no puede contener números");
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
