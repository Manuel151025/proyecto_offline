<?php
/**
 * Consultas del panel de administración.
 *
 * Separadas de la vista por dos razones: index.php mezclaba lógica y HTML en
 * un solo archivo que iba a crecer sin control al añadir personas y
 * estadísticas, y aquí el SQL queda en un sitio donde se puede leer y revisar
 * junto, en vez de repartido entre etiquetas.
 *
 * Todas usan sentencias preparadas. La búsqueda entra como parámetro, nunca
 * concatenada.
 *
 * Las fechas se guardan como milisegundos (BIGINT) porque las genera el
 * dispositivo; para agrupar por día hay que dividir entre 1000.
 */

/** @return array<int, array<string, mixed>> */
function consultarEncuestadores(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, nombre, numero_documento, activo FROM encuestadores ORDER BY id');
    return $stmt === false ? [] : $stmt->fetchAll();
}

/**
 * Números generales del sistema.
 *
 * @return array{personas: int, encuestas: int, encuestadores: int, dispositivos: int, ultima_sync: ?int}
 */
function resumenGeneral(PDO $pdo): array
{
    $uno = function (string $sql) use ($pdo): int {
        $stmt = $pdo->query($sql);
        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    };

    $stmt = $pdo->query('SELECT MAX(server_sync_time) FROM encuestas');
    $ultima = $stmt === false ? null : $stmt->fetchColumn();

    return [
        'personas'      => $uno('SELECT COUNT(*) FROM personas WHERE deleted_at IS NULL'),
        'encuestas'     => $uno('SELECT COUNT(*) FROM encuestas'),
        'encuestadores' => $uno('SELECT COUNT(*) FROM encuestadores WHERE activo = 1'),
        'dispositivos'  => $uno('SELECT COUNT(DISTINCT device_id) FROM personas'),
        'ultima_sync'   => $ultima === false || $ultima === null ? null : (int)$ultima,
    ];
}

/** Total de personas que coinciden con la búsqueda, para paginar. */
function contarPersonas(PDO $pdo, string $busqueda = ''): int
{
    if ($busqueda === '') {
        $stmt = $pdo->query('SELECT COUNT(*) FROM personas WHERE deleted_at IS NULL');
        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM personas
         WHERE deleted_at IS NULL
           AND (nombres LIKE ? OR apellidos LIKE ? OR numero_documento LIKE ?)'
    );
    $like = '%' . $busqueda . '%';
    $stmt->execute([$like, $like, $like]);
    return (int)$stmt->fetchColumn();
}

/**
 * Personas registradas, con el municipio resuelto a nombre.
 *
 * @return array<int, array<string, mixed>>
 */
function consultarPersonas(PDO $pdo, string $busqueda = '', int $limite = 25, int $desde = 0): array
{
    // LIMIT y OFFSET no admiten parámetros en todas las versiones de MySQL con
    // EMULATE_PREPARES desactivado, así que se fuerzan a entero y se
    // interpolan. Al ser (int) no hay riesgo de inyección.
    $limite = max(1, min(200, $limite));
    $desde  = max(0, $desde);

    $where = 'p.deleted_at IS NULL';
    $params = [];
    if ($busqueda !== '') {
        $where .= ' AND (p.nombres LIKE ? OR p.apellidos LIKE ? OR p.numero_documento LIKE ?)';
        $like = '%' . $busqueda . '%';
        $params = [$like, $like, $like];
    }

    $stmt = $pdo->prepare(
        "SELECT p.tipo_documento, p.numero_documento, p.nombres, p.apellidos,
                p.telefono, p.eps, p.estrato, p.vereda, p.updated_at, p.device_id,
                m.nombre AS municipio, m.departamento
         FROM personas p
         LEFT JOIN municipios m ON m.codigo = p.municipio_codigo
         WHERE $where
         ORDER BY p.updated_at DESC
         LIMIT $limite OFFSET $desde"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Encuestas por día de los últimos N días, para el gráfico.
 *
 * @return array<int, array{dia: string, total: int}>
 */
function encuestasPorDia(PDO $pdo, int $dias = 14): array
{
    $dias = max(1, min(90, $dias));
    $desde = (time() - ($dias * 86400)) * 1000;

    $stmt = $pdo->prepare(
        'SELECT DATE(FROM_UNIXTIME(fecha_encuesta / 1000)) AS dia, COUNT(*) AS total
         FROM encuestas
         WHERE fecha_encuesta >= ?
         GROUP BY dia
         ORDER BY dia'
    );
    $stmt->execute([$desde]);

    $filas = [];
    foreach ($stmt->fetchAll() as $f) {
        $filas[] = ['dia' => (string)$f['dia'], 'total' => (int)$f['total']];
    }
    return $filas;
}

/**
 * Municipios con más personas registradas.
 *
 * @return array<int, array{municipio: string, departamento: string, total: int}>
 */
function personasPorMunicipio(PDO $pdo, int $limite = 8): array
{
    $limite = max(1, min(50, $limite));
    $stmt = $pdo->query(
        "SELECT COALESCE(m.nombre, 'Sin municipio') AS municipio,
                COALESCE(m.departamento, '—') AS departamento,
                COUNT(*) AS total
         FROM personas p
         LEFT JOIN municipios m ON m.codigo = p.municipio_codigo
         WHERE p.deleted_at IS NULL
         GROUP BY municipio, departamento
         ORDER BY total DESC
         LIMIT $limite"
    );
    if ($stmt === false) {
        return [];
    }

    $filas = [];
    foreach ($stmt->fetchAll() as $f) {
        $filas[] = [
            'municipio'    => (string)$f['municipio'],
            'departamento' => (string)$f['departamento'],
            'total'        => (int)$f['total'],
        ];
    }
    return $filas;
}

/**
 * Cuántas encuestas lleva cada encuestador.
 *
 * @return array<int, array{nombre: string, total: int}>
 */
function encuestasPorEncuestador(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT e.nombre, COUNT(en.id) AS total
         FROM encuestadores e
         LEFT JOIN encuestas en ON en.id_encuestador = e.id
         GROUP BY e.id, e.nombre
         ORDER BY total DESC"
    );
    if ($stmt === false) {
        return [];
    }

    $filas = [];
    foreach ($stmt->fetchAll() as $f) {
        $filas[] = ['nombre' => (string)$f['nombre'], 'total' => (int)$f['total']];
    }
    return $filas;
}

/**
 * Todas las personas para exportar a CSV. Sin paginar: el archivo se descarga
 * entero, y el volumen esperado (miles, no millones) lo permite.
 *
 * @return array<int, array<string, mixed>>
 */
function personasParaExportar(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT p.tipo_documento, p.numero_documento, p.nombres, p.apellidos,
                p.fecha_nacimiento, p.telefono, p.email, p.direccion, p.vereda,
                p.eps, p.ocupacion, p.estrato,
                COALESCE(m.nombre, '') AS municipio, COALESCE(m.departamento, '') AS departamento,
                p.updated_at, p.device_id
         FROM personas p
         LEFT JOIN municipios m ON m.codigo = p.municipio_codigo
         WHERE p.deleted_at IS NULL
         ORDER BY p.apellidos, p.nombres"
    );
    return $stmt === false ? [] : $stmt->fetchAll();
}
