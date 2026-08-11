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
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->query('SELECT id, nombre, numero_documento, activo, rol FROM encuestadores ORDER BY id');
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
function contarPersonas(PDO $pdo, string $busqueda = '', bool $borradas = false): int
{
    $filtro = $borradas ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';

    if ($busqueda === '') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM personas WHERE $filtro");
        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM personas
         WHERE $filtro
           AND (nombres LIKE ? OR apellidos LIKE ? OR numero_documento LIKE ?)"
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
function consultarPersonas(
    PDO $pdo,
    string $busqueda = '',
    int $limite = 25,
    int $desde = 0,
    bool $borradas = false
): array {
    // LIMIT y OFFSET no admiten parámetros en todas las versiones de MySQL con
    // EMULATE_PREPARES desactivado, así que se fuerzan a entero y se
    // interpolan. Al ser (int) no hay riesgo de inyección.
    $limite = max(1, min(200, $limite));
    $desde  = max(0, $desde);

    $where = $borradas ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
    $params = [];
    if ($busqueda !== '') {
        $where .= ' AND (p.nombres LIKE ? OR p.apellidos LIKE ? OR p.numero_documento LIKE ?)';
        $like = '%' . $busqueda . '%';
        $params = [$like, $like, $like];
    }

    $stmt = $pdo->prepare(
        "SELECT p.tipo_documento, p.numero_documento, p.nombres, p.apellidos,
                p.telefono, p.eps, p.estrato, p.vereda, p.updated_at, p.device_id,
                p.deleted_at,
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

/**
 * Borra una persona de forma SUAVE, marcándola en vez de eliminarla.
 *
 * Tiene que ser suave. La descarga incremental entrega las filas cuyo
 * `server_updated_at` supera la marca del cliente, incluidas las borradas,
 * justamente para que los dispositivos se enteren del borrado. Un DELETE real
 * quitaría la fila y no quedaría nada que enviar: cada teléfono que ya la
 * hubiera descargado se la quedaría para siempre, sin forma de corregirlo.
 *
 * `updated_at` se pone al reloj del servidor y no se conserva el anterior,
 * porque los clientes resuelven por Last-Write-Wins: con una marca menor que
 * la copia local, el borrado se descartaría al llegar.
 *
 * Un dispositivo con cambios locales sin enviar conserva su versión hasta que
 * la suba (así lo decide la regla de mezcla). Es deliberado: el borrado del
 * administrador no debe destruir trabajo de campo que aún no se ha visto.
 *
 * @return bool false si esa persona no existe.
 */
function borrarPersona(PDO $pdo, string $tipoDocumento, string $numeroDocumento, string $autor = 'panel-admin'): bool
{
    asegurarServerUpdatedAt($pdo);

    $ahora = (int)round(microtime(true) * 1000);

    $stmt = $pdo->prepare(
        'UPDATE personas
            SET deleted_at = ?, updated_at = ?, server_updated_at = ?, device_id = ?
          WHERE tipo_documento = ? AND numero_documento = ?'
    );
    $stmt->execute([$ahora, $ahora, $ahora, mb_substr($autor, 0, 50), $tipoDocumento, $numeroDocumento]);

    return $stmt->rowCount() > 0;
}

/**
 * Deshace un borrado suave.
 *
 * Sella igual que borrarPersona y por lo mismo: sin `server_updated_at` nuevo
 * la descarga no la reparte, y sin `updated_at` nuevo los clientes la
 * descartarían por Last-Write-Wins al tener una copia local más reciente (la
 * que ellos mismos marcaron como borrada al sincronizar el borrado).
 *
 * No restaura las encuestas asociadas porque nunca se borraron: `encuestas` es
 * el registro de trazabilidad y no se toca en ningún caso.
 *
 * @return bool false si esa persona no existe o no estaba borrada.
 */
function restaurarPersona(PDO $pdo, string $tipoDocumento, string $numeroDocumento, string $autor = 'panel-admin'): bool
{
    asegurarServerUpdatedAt($pdo);

    $ahora = (int)round(microtime(true) * 1000);

    $stmt = $pdo->prepare(
        'UPDATE personas
            SET deleted_at = NULL, updated_at = ?, server_updated_at = ?, device_id = ?
          WHERE tipo_documento = ? AND numero_documento = ? AND deleted_at IS NOT NULL'
    );
    $stmt->execute([$ahora, $ahora, mb_substr($autor, 0, 50), $tipoDocumento, $numeroDocumento]);

    return $stmt->rowCount() > 0;
}

/**
 * Cuántas cuentas de administrador ACTIVAS hay.
 *
 * Es lo que decide si ADMIN_PASSWORD sigue sirviendo. Mientras no exista
 * ningún administrador, la contraseña compartida es la única forma de entrar
 * y crear el primero; en cuanto hay uno, deja de aceptarse. Así el secreto
 * compartido se apaga solo, sin dejar nunca el panel inaccesible.
 *
 * Se cuentan solo las activas: dejar una cuenta desactivada como único
 * administrador equivaldría a no tener ninguno.
 */
function contarAdminsActivos(PDO $pdo): int
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->query("SELECT COUNT(*) FROM encuestadores WHERE rol = 'admin' AND activo = 1");
    return $stmt === false ? 0 : (int)$stmt->fetchColumn();
}

/**
 * Busca una cuenta de administrador activa por documento.
 *
 * Devuelve la fila sin comprobar la contraseña: quien llame debe verificarla
 * con password_verify. Se separa así para que el mensaje de error pueda ser
 * el mismo tanto si la cuenta no existe como si la contraseña es incorrecta,
 * y no se pueda averiguar qué documentos son administradores.
 *
 * @return array<string, mixed>|null
 */
function buscarAdminPorDocumento(PDO $pdo, string $documento): ?array
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, nombre, password_hash
           FROM encuestadores
          WHERE numero_documento = ? AND rol = 'admin' AND activo = 1"
    );
    $stmt->execute([$documento]);

    return $stmt->fetch() ?: null;
}

/**
 * ¿Esta cuenta es el último administrador activo que queda?
 *
 * Sirve para impedir que alguien se deje fuera del panel quitándose el rol o
 * desactivándose. Recuperarse de eso exigiría entrar a la base de datos por
 * SSH, que es justo lo que este sistema de cuentas venía a evitar.
 */
function esUltimoAdminActivo(PDO $pdo, int $id): bool
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM encuestadores
          WHERE rol = 'admin' AND activo = 1 AND id <> ?"
    );
    $stmt->execute([$id]);

    return (int)$stmt->fetchColumn() === 0;
}
