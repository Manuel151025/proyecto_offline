<?php
require_once __DIR__ . '/../cors.php';
aplicarCors('POST, OPTIONS');

require_once __DIR__ . '/../db.php';
$pdo = conectarBD();
require_once __DIR__ . '/../auth_token.php';
require_once __DIR__ . '/../esquema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError(405, 'Método no permitido');
}

// Autenticación obligatoria: este endpoint escribe en la base de datos.
// Antes era anónimo, de modo que cualquiera con curl podía insertar registros.
$sesion = requerirAutenticacion($pdo);

const MAX_LOTE = 500;

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

$data = json_decode(leerCuerpo(), true);

if (!is_array($data) || !isset($data['personas']) || !isset($data['encuestas'])
    || !is_array($data['personas']) || !is_array($data['encuestas'])) {
    responderError(400, 'El payload debe incluir los arreglos "personas" y "encuestas"');
}

if (count($data['personas']) > MAX_LOTE || count($data['encuestas']) > MAX_LOTE) {
    responderError(413, 'Lote demasiado grande: máximo ' . MAX_LOTE . ' registros por envío');
}

/**
 * Clave de la persona TAL COMO VINO, sin validar.
 *
 * Sirve para relacionar una persona descartada con las encuestas que la
 * referencian. No se puede usar la clave normalizada, porque justamente puede
 * ser el dato que falló.
 *
 * @param array<string, mixed> $fila
 */
function claveCruda(array $fila): string
{
    return strtoupper(trim((string)($fila['tipo_documento'] ?? '')))
        . '|' . trim((string)($fila['numero_documento'] ?? ''));
}

/** Encuestas rechazadas: id => motivo. Se devuelven al cliente. */
$rechazadas = [];

/** Documento de cada encuesta rechazada, para guardarlo en sync_rechazos. */
$documentoRechazo = [];

$stmtMunicipios = $pdo->query('SELECT codigo FROM municipios');
$codigosMunicipio = $stmtMunicipios === false
    ? []
    : array_flip(array_map('strval', $stmtMunicipios->fetchAll(PDO::FETCH_COLUMN)));

/** Personas descartadas: clave cruda => motivo. */
$personasRechazadas = [];

// Normalizamos y validamos ANTES de abrir la transacción, para que un payload
// malformado no deje la conexión a mitad de camino.
$personas = [];
foreach ($data['personas'] as $p) {
    if (!is_array($p)) {
        responderError(400, 'Cada persona debe ser un objeto');
    }
    try {
        $personas[] = [
            // El documento es la clave primaria: se valida aquí y no se confía
            // en que el cliente lo haya hecho.
            'tipo_documento'   => tipoDocumentoValidado($p),
            'numero_documento' => documentoValidado($p),
            'nombres'          => nombreValidado($p, 'nombres'),
            'apellidos'        => nombreValidado($p, 'apellidos'),
            'fecha_nacimiento' => fechaNacimientoValidada($p),
            'telefono'         => textoOpcional($p, 'telefono', 20),
            'email'            => emailValidado($p),
            'direccion'        => textoOpcional($p, 'direccion', 150),
            'vereda'           => textoOpcional($p, 'vereda', 100),
            'eps'              => textoOpcional($p, 'eps', 50),
            'ocupacion'        => textoOpcional($p, 'ocupacion', 100),
            'estrato'          => estratoValidado($p),
            'municipio_codigo' => municipioValidado($p, $codigosMunicipio),
            'updated_at'       => enteroRequerido($p, 'updated_at'),
            'device_id'        => textoRequerido($p, 'device_id', 50),
            'deleted_at'       => enteroOpcional($p, 'deleted_at'),
        ];
    } catch (DatoInvalido $ex) {
        $personasRechazadas[claveCruda($p)] = $ex->getMessage();
    }
}

$encuestas = [];
foreach ($data['encuestas'] as $e) {
    if (!is_array($e)) {
        responderError(400, 'Cada encuesta debe ser un objeto');
    }

    // El id se lee aparte y sin validar el resto: es la única forma de decirle
    // al cliente CUÁL de sus elementos se rechazó. Sin id no hay nada que
    // informar, así que ese sí es un fallo de cliente.
    $idEncuesta = trim((string)($e['id'] ?? ''));
    if ($idEncuesta === '') {
        responderError(400, 'Cada encuesta debe traer un id');
    }

    // Si su persona se descartó, la encuesta no puede entrar: la clave foránea
    // apunta a una fila que no existirá.
    $clave = claveCruda($e);
    $documentoRechazo[$idEncuesta] = explode('|', $clave, 2);
    if (isset($personasRechazadas[$clave])) {
        $rechazadas[$idEncuesta] = 'Persona inválida: ' . $personasRechazadas[$clave];
        continue;
    }

    try {
        $encuestas[] = [
            'id'               => mb_substr($idEncuesta, 0, 50),
            // Misma validación: la encuesta apunta a la persona por estas dos
            // columnas, así que un valor inválido rompería la clave foránea.
            'tipo_documento'   => tipoDocumentoValidado($e),
            'numero_documento' => documentoValidado($e),
            'fecha_encuesta'   => enteroRequerido($e, 'fecha_encuesta'),
            'device_id'        => textoRequerido($e, 'device_id', 50),
            'accion'           => textoRequerido($e, 'accion', 20),
            // id_encuestador NO se toma del payload: se usa el del token, para
            // que un cliente no pueda atribuir encuestas a otro encuestador.
            'id_encuestador'   => $sesion['id_encuestador'],
        ];
    } catch (DatoInvalido $ex) {
        $rechazadas[$idEncuesta] = $ex->getMessage();
    }
}

// Antes de la transacción: un ALTER TABLE hace commit implícito y partiría
// el lote a la mitad si se ejecutara dentro.
asegurarServerUpdatedAt($pdo);
asegurarTablasSincronizacion($pdo);

/**
 * Margen para relojes de dispositivo adelantados.
 *
 * Last-Write-Wins compara el `updated_at` del dispositivo. Un teléfono con la
 * fecha un año adelantada escribía registros que ninguna corrección posterior
 * podía superar. Se recorta a la hora del servidor más este margen.
 */
const MARGEN_RELOJ_MS = 5 * 60 * 1000;

$processedEncuestas = [];
$pdo->beginTransaction();

try {
    // 1. Sincronizar Personas mediante Last-Write-Wins
    $stmtPersonaCheck = $pdo->prepare("SELECT updated_at FROM personas WHERE tipo_documento = ? AND numero_documento = ? FOR UPDATE");
    $stmtPersonaInsert = $pdo->prepare("
        INSERT INTO personas (
            tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento,
            telefono, email, direccion, vereda, eps, ocupacion, estrato, municipio_codigo,
            updated_at, device_id, deleted_at, server_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtPersonaUpdate = $pdo->prepare("
        UPDATE personas SET
            nombres = ?, apellidos = ?, fecha_nacimiento = ?, telefono = ?, email = ?,
            direccion = ?, vereda = ?, eps = ?, ocupacion = ?, estrato = ?, municipio_codigo = ?,
            updated_at = ?, device_id = ?, deleted_at = ?, server_updated_at = ?
        WHERE tipo_documento = ? AND numero_documento = ?
    ");

    // Sello del servidor, el mismo para todo el lote. Es la marca de agua que
    // usa la descarga incremental: al venir del reloj del servidor avanza de
    // forma monótona, mientras que `updated_at` depende del reloj de cada
    // dispositivo y podría ir hacia atrás.
    $selloServidor = (int)round(microtime(true) * 1000);
    $limiteReloj = $selloServidor + MARGEN_RELOJ_MS;

    foreach ($personas as $p) {
        if ($p['updated_at'] > $limiteReloj) {
            error_log("[sync] updated_at futuro recortado ({$p['updated_at']}) de {$p['device_id']}");
            $p['updated_at'] = $limiteReloj;
        }
        $stmtPersonaCheck->execute([$p['tipo_documento'], $p['numero_documento']]);
        $existing = $stmtPersonaCheck->fetch();

        // ALGORITMO LAST-WRITE-WINS (LWW)
        if ($existing) {
            // Ya existe. ¿El registro entrante es más reciente que el de la BD?
            if ($p['updated_at'] > $existing['updated_at']) {
                $stmtPersonaUpdate->execute([
                    $p['nombres'], $p['apellidos'], $p['fecha_nacimiento'], $p['telefono'],
                    $p['email'], $p['direccion'], $p['vereda'], $p['eps'], $p['ocupacion'],
                    $p['estrato'], $p['municipio_codigo'], $p['updated_at'], $p['device_id'],
                    $p['deleted_at'], $selloServidor,
                    $p['tipo_documento'], $p['numero_documento']
                ]);
            }
            // Si el entrante es más viejo (updated_at menor o igual), lo ignoramos pacíficamente.
        } else {
            // No existe, insertar
            $stmtPersonaInsert->execute([
                $p['tipo_documento'], $p['numero_documento'], $p['nombres'], $p['apellidos'],
                $p['fecha_nacimiento'], $p['telefono'], $p['email'], $p['direccion'],
                $p['vereda'], $p['eps'], $p['ocupacion'], $p['estrato'],
                $p['municipio_codigo'], $p['updated_at'], $p['device_id'], $p['deleted_at'],
                $selloServidor
            ]);
        }
    }

    // 2. Registrar las Encuestas (Trazabilidad)
    $stmtEncuestaInsert = $pdo->prepare("
        INSERT IGNORE INTO encuestas (
            id, tipo_documento, numero_documento, id_encuestador,
            fecha_encuesta, device_id, accion, server_sync_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $now = round(microtime(true) * 1000);

    foreach ($encuestas as $e) {
        $stmtEncuestaInsert->execute([
            $e['id'], $e['tipo_documento'], $e['numero_documento'], $e['id_encuestador'],
            min($e['fecha_encuesta'], $limiteReloj), $e['device_id'], $e['accion'], $now
        ]);
        $processedEncuestas[] = $e['id'];
    }

    $pdo->commit();

    // Fuera de la transacción: el monitoreo nunca debe deshacer un envío.
    if ($rechazadas !== []) {
        try {
            $stmtRechazo = $pdo->prepare(
                'INSERT INTO sync_rechazos
                    (id_encuesta, tipo_documento, numero_documento, motivo, device_id, id_encuestador, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $deviceRechazo = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? '')) ?: null;
            foreach ($rechazadas as $id => $motivo) {
                [$tipoR, $numeroR] = $documentoRechazo[$id] ?? ['', ''];
                $stmtRechazo->execute([
                    mb_substr((string)$id, 0, 50), mb_substr($tipoR, 0, 10) ?: null, mb_substr($numeroR, 0, 40) ?: null,
                    mb_substr($motivo, 0, 255), $deviceRechazo, $sesion['id_encuestador'], $now,
                ]);
            }
        } catch (PDOException $ex) {
            error_log('[sync] no se pudieron guardar los rechazos: ' . $ex->getMessage());
        }
    }
    registrarDispositivo($pdo, $sesion['id_encuestador'], 'subida', $encuestas[0]['device_id'] ?? null);

    // Las rechazadas se informan una a una para que el cliente las marque como
    // terminales y deje de reintentarlas. Si solo se devolviera el conteo, el
    // cliente no sabría cuáles descartar y las reenviaría en cada sincronización.
    $listaRechazadas = [];
    foreach ($rechazadas as $id => $motivo) {
        $listaRechazadas[] = ['id' => (string)$id, 'motivo' => $motivo];
    }

    echo json_encode([
        "success" => true,
        "message" => $listaRechazadas === []
            ? "Sincronización completada. Conflictos resueltos vía LWW."
            : "Sincronización completada con " . count($listaRechazadas) . " registro(s) rechazado(s).",
        "processed_encuestas" => $processedEncuestas,
        "rechazadas" => $listaRechazadas
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[sync] ' . $e->getMessage());
    responderError(500, 'Error durante la sincronización. Intenta de nuevo.');
}
