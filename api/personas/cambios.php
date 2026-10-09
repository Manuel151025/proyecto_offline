<?php
/**
 * Descarga incremental de personas.
 *
 * Cierra la mitad que faltaba de la sincronización: hasta ahora los
 * dispositivos SUBÍAN pero nunca bajaban, así que cada uno solo veía lo que él
 * mismo había registrado. Una persona encuestada desde el celular quedaba
 * invisible en el resto de dispositivos.
 *
 *   GET /api/personas/cambios.php?desde=<marca>&limite=<n>
 *
 * `desde` es la marca de agua del cliente: la mayor `server_updated_at` que ya
 * tiene. Se piden solo los registros posteriores, así una sincronización
 * habitual mueve unas pocas filas en vez de la tabla entera.
 *
 * Se filtra por `server_updated_at` y NO por `updated_at`, que es la marca del
 * dispositivo: un teléfono con el reloj atrasado escribiría filas con fecha
 * vieja que los demás ya habrían superado, y no se descargarían nunca.
 *
 * Se devuelven también las personas borradas (con `deleted_at`), porque un
 * borrado es un cambio que los otros dispositivos deben conocer. Omitirlas
 * haría reaparecer lo eliminado en la siguiente subida.
 */

require_once __DIR__ . '/../cors.php';
aplicarCors('GET, OPTIONS');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_token.php';
require_once __DIR__ . '/../esquema.php';
$pdo = conectarBD();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderError(405, 'Método no permitido');
}

// Leer datos ajenos exige token, igual que escribirlos.
$sesion = requerirAutenticacion($pdo);

/** Tope por petición: el cliente pagina con la marca que le devolvemos. */
const MAX_POR_PAGINA = 500;

$desde = filter_input(INPUT_GET, 'desde', FILTER_VALIDATE_INT) ?: 0;
$desde = max(0, $desde);

$limite = filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT) ?: 200;
$limite = max(1, min(MAX_POR_PAGINA, $limite));

/*
 * CURSOR COMPUESTO: (server_updated_at, tipo_documento, numero_documento).
 *
 * sync.php sella todo un lote con el mismo milisegundo. Con solo el sello como
 * marca, `> marca` saltaba el resto de un grupo cuando una página lo cortaba
 * por la mitad: esas personas no llegaban nunca a los demás celulares. Se
 * demostró con 3 personas de un mismo lote descargadas en páginas de 2.
 *
 * Con el documento como desempate, el orden es total y cada página empieza
 * exactamente donde terminó la anterior. Los clientes que solo envían `desde`
 * (versiones anteriores ya instaladas) siguen funcionando como antes.
 */
$tipoCursor = trim((string)filter_input(INPUT_GET, 'tipo'));
$numeroCursor = trim((string)filter_input(INPUT_GET, 'numero'));
$conCursor = $tipoCursor !== '' && $numeroCursor !== '';

asegurarServerUpdatedAt($pdo);

try {
    if ($conCursor) {
        $where = 'server_updated_at > ?
                  OR (server_updated_at = ? AND (tipo_documento > ?
                      OR (tipo_documento = ? AND numero_documento > ?)))';
        $params = [$desde, $desde, $tipoCursor, $tipoCursor, $numeroCursor];
    } else {
        $where = 'server_updated_at > ?';
        $params = [$desde];
    }

    // LIMIT no admite parámetro con EMULATE_PREPARES desactivado; al forzarlo
    // a entero por filter_input no hay riesgo de inyección.
    $stmt = $pdo->prepare(
        "SELECT tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento,
                telefono, email, direccion, vereda, eps, ocupacion, estrato,
                municipio_codigo, updated_at, device_id, deleted_at, server_updated_at
         FROM personas
         WHERE server_updated_at IS NOT NULL AND ($where)
         ORDER BY server_updated_at ASC, tipo_documento ASC, numero_documento ASC
         LIMIT $limite"
    );
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    // Los enteros vuelven como cadena por PDO; el cliente compara números.
    $personas = [];
    foreach ($filas as $f) {
        $personas[] = [
            'tipo_documento'    => (string)$f['tipo_documento'],
            'numero_documento'  => (string)$f['numero_documento'],
            'nombres'           => (string)$f['nombres'],
            'apellidos'         => (string)$f['apellidos'],
            'fecha_nacimiento'  => $f['fecha_nacimiento'] === null ? null : (int)$f['fecha_nacimiento'],
            'telefono'          => $f['telefono'],
            'email'             => $f['email'],
            'direccion'         => $f['direccion'],
            'vereda'            => $f['vereda'],
            'eps'               => $f['eps'],
            'ocupacion'         => $f['ocupacion'],
            'estrato'           => $f['estrato'] === null ? null : (int)$f['estrato'],
            'municipio_codigo'  => $f['municipio_codigo'],
            'updated_at'        => (int)$f['updated_at'],
            'device_id'         => (string)$f['device_id'],
            'deleted_at'        => $f['deleted_at'] === null ? null : (int)$f['deleted_at'],
            'server_updated_at' => (int)$f['server_updated_at'],
        ];
    }

    // La nueva marca de agua es la del último registro entregado. Si no vino
    // nada, se devuelve la que el cliente ya tenía para que no retroceda.
    $ultima = $personas === [] ? null : end($personas);
    $marca = $ultima === null ? $desde : $ultima['server_updated_at'];

    registrarDispositivo($pdo, $sesion['id_encuestador'], 'descarga');

    echo json_encode([
        'success'  => true,
        'personas' => $personas,
        'marca'    => $marca,
        // Cursor completo para pedir la página siguiente: desde=sello&tipo=&numero=
        'cursor'   => $ultima === null
            ? ($conCursor ? ['sello' => $desde, 'tipo' => $tipoCursor, 'numero' => $numeroCursor] : null)
            : ['sello' => $marca, 'tipo' => $ultima['tipo_documento'], 'numero' => $ultima['numero_documento']],
        // Con la página llena es probable que queden más: el cliente vuelve a
        // pedir con la marca nueva hasta que esto sea falso.
        'hay_mas'  => count($personas) >= $limite,
    ]);
} catch (Exception $e) {
    error_log('[cambios] ' . $e->getMessage());
    responderError(500, 'No se pudieron obtener los cambios');
}
