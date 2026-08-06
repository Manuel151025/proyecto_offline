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

/** Exige un texto no vacío y lo recorta a la longitud de la columna. */
/** @param array<string, mixed> $fila */
function textoRequerido(array $fila, string $clave, int $max): string
{
    $valor = trim((string)($fila[$clave] ?? ''));
    if ($valor === '') {
        responderError(400, "Campo obligatorio faltante o vacío: $clave");
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
        responderError(400, "Campo numérico obligatorio inválido: $clave");
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

$data = json_decode(leerCuerpo(), true);

if (!is_array($data) || !isset($data['personas']) || !isset($data['encuestas'])
    || !is_array($data['personas']) || !is_array($data['encuestas'])) {
    responderError(400, 'El payload debe incluir los arreglos "personas" y "encuestas"');
}

if (count($data['personas']) > MAX_LOTE || count($data['encuestas']) > MAX_LOTE) {
    responderError(413, 'Lote demasiado grande: máximo ' . MAX_LOTE . ' registros por envío');
}

// Normalizamos y validamos ANTES de abrir la transacción, para que un payload
// malformado no deje la conexión a mitad de camino.
$personas = [];
foreach ($data['personas'] as $p) {
    if (!is_array($p)) {
        responderError(400, 'Cada persona debe ser un objeto');
    }
    $personas[] = [
        'tipo_documento'   => textoRequerido($p, 'tipo_documento', 10),
        'numero_documento' => textoRequerido($p, 'numero_documento', 20),
        'nombres'          => textoRequerido($p, 'nombres', 100),
        'apellidos'        => textoRequerido($p, 'apellidos', 100),
        'fecha_nacimiento' => enteroOpcional($p, 'fecha_nacimiento'),
        'telefono'         => textoOpcional($p, 'telefono', 20),
        'email'            => textoOpcional($p, 'email', 100),
        'direccion'        => textoOpcional($p, 'direccion', 150),
        'vereda'           => textoOpcional($p, 'vereda', 100),
        'eps'              => textoOpcional($p, 'eps', 50),
        'ocupacion'        => textoOpcional($p, 'ocupacion', 100),
        'estrato'          => enteroOpcional($p, 'estrato'),
        'municipio_codigo' => textoOpcional($p, 'municipio_codigo', 10),
        'updated_at'       => enteroRequerido($p, 'updated_at'),
        'device_id'        => textoRequerido($p, 'device_id', 50),
        'deleted_at'       => enteroOpcional($p, 'deleted_at'),
    ];
}

$encuestas = [];
foreach ($data['encuestas'] as $e) {
    if (!is_array($e)) {
        responderError(400, 'Cada encuesta debe ser un objeto');
    }
    $encuestas[] = [
        'id'               => textoRequerido($e, 'id', 50),
        'tipo_documento'   => textoRequerido($e, 'tipo_documento', 10),
        'numero_documento' => textoRequerido($e, 'numero_documento', 20),
        'fecha_encuesta'   => enteroRequerido($e, 'fecha_encuesta'),
        'device_id'        => textoRequerido($e, 'device_id', 50),
        'accion'           => textoRequerido($e, 'accion', 20),
        // id_encuestador NO se toma del payload: se usa el del token, para que
        // un cliente no pueda atribuir encuestas a otro encuestador.
        'id_encuestador'   => $sesion['id_encuestador'],
    ];
}

// Antes de la transacción: un ALTER TABLE hace commit implícito y partiría
// el lote a la mitad si se ejecutara dentro.
asegurarServerUpdatedAt($pdo);

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

    foreach ($personas as $p) {
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
            $e['fecha_encuesta'], $e['device_id'], $e['accion'], $now
        ]);
        $processedEncuestas[] = $e['id'];
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Sincronización completada. Conflictos resueltos vía LWW.",
        "processed_encuestas" => $processedEncuestas
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[sync] ' . $e->getMessage());
    responderError(500, 'Error durante la sincronización. Intenta de nuevo.');
}
