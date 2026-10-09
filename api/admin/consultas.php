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

/**
 * Cuentas con cuántas encuestas llevan y cuándo sincronizaron la última.
 *
 * La actividad sale de `encuestas` y no de `sesiones`: una sesión se abre en la
 * oficina y dura 30 días, así que no dice si esa persona está trabajando.
 *
 * @return array<int, array<string, mixed>>
 */
function consultarEncuestadores(PDO $pdo): array
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->query(
        "SELECT e.id, e.nombre, e.numero_documento, e.activo, e.rol,
                COUNT(en.id) AS encuestas, MAX(en.server_sync_time) AS ultima_actividad
           FROM encuestadores e
           LEFT JOIN encuestas en ON en.id_encuestador = e.id
          GROUP BY e.id, e.nombre, e.numero_documento, e.activo, e.rol
          ORDER BY e.activo DESC, e.rol = 'admin' DESC, e.nombre"
    );
    return $stmt === false ? [] : $stmt->fetchAll();
}

/**
 * Números generales del sistema.
 *
 * Los dispositivos se cuentan en `encuestas` y no en `personas`. En personas,
 * `device_id` guarda solo el ÚLTIMO que escribió la fila: Last-Write-Wins
 * pisa el rastro de los anteriores. Y el panel escribe ahí "admin:<nombre>" al
 * borrar o restaurar, así que cada administrador contaba como un celular más.
 *
 * @return array{personas: int, borradas: int, encuestas: int, encuestadores: int, cuentas: int, dispositivos: int, ultima_sync: ?int}
 */
function resumenGeneral(PDO $pdo): array
{
    asegurarRolEncuestador($pdo);

    $uno = function (string $sql) use ($pdo): int {
        $stmt = $pdo->query($sql);
        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    };

    $ultima = $uno('SELECT COALESCE(MAX(server_sync_time), 0) FROM encuestas');

    return [
        'personas'      => $uno('SELECT COUNT(*) FROM personas WHERE deleted_at IS NULL'),
        'borradas'      => $uno('SELECT COUNT(*) FROM personas WHERE deleted_at IS NOT NULL'),
        'encuestas'     => $uno('SELECT COUNT(*) FROM encuestas'),
        // Solo cuentas de campo. Antes se contaban también los administradores.
        'encuestadores' => $uno("SELECT COUNT(*) FROM encuestadores WHERE activo = 1 AND rol = 'encuestador'"),
        'cuentas'       => $uno('SELECT COUNT(*) FROM encuestadores'),
        'dispositivos'  => $uno('SELECT COUNT(DISTINCT device_id) FROM encuestas'),
        'ultima_sync'   => $ultima === 0 ? null : $ultima,
    ];
}

/**
 * WHERE compartido por el conteo y el listado, para que nunca discrepen.
 *
 * El nombre se busca sobre nombres y apellidos UNIDOS. Buscando cada columna
 * por separado, "Juan Pérez" no encontraba a nadie: ninguna de las dos contiene
 * el texto completo.
 *
 * `%` y `_` se escapan porque son comodines de LIKE: buscar "_" devolvía la
 * tabla entera. El carácter de escape es '!' y no la barra invertida, cuyo
 * comportamiento depende del sql_mode del servidor.
 *
 * @return array{0: string, 1: list<string>}
 */
function filtroPersonas(string $busqueda, bool $borradas): array
{
    $where = $borradas ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
    if ($busqueda === '') {
        return [$where, []];
    }

    $like = '%' . strtr($busqueda, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $where .= " AND (CONCAT_WS(' ', p.nombres, p.apellidos) LIKE ? ESCAPE '!'"
            . " OR p.numero_documento LIKE ? ESCAPE '!')";

    return [$where, [$like, $like]];
}

/** Total de personas que coinciden con la búsqueda, para paginar. */
function contarPersonas(PDO $pdo, string $busqueda = '', bool $borradas = false): int
{
    [$where, $params] = filtroPersonas($busqueda, $borradas);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM personas p WHERE $where");
    $stmt->execute($params);
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

    [$where, $params] = filtroPersonas($busqueda, $borradas);
    // En la papelera interesa lo último que se borró, no lo último editado.
    $orden = $borradas ? 'p.deleted_at DESC' : 'p.updated_at DESC';

    $stmt = $pdo->prepare(
        "SELECT p.tipo_documento, p.numero_documento, p.nombres, p.apellidos,
                p.telefono, p.eps, p.estrato, p.vereda, p.updated_at, p.device_id,
                p.deleted_at,
                m.nombre AS municipio, m.departamento
         FROM personas p
         LEFT JOIN municipios m ON m.codigo = p.municipio_codigo
         WHERE $where
         ORDER BY $orden
         LIMIT $limite OFFSET $desde"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Encuestas por día de los últimos N días, con los días vacíos en cero.
 *
 * Corrige dos fallos. Los días sin encuestas no aparecían, así que el gráfico
 * juntaba barras de fechas separadas y parecía actividad continua. Y el día se
 * sacaba con FROM_UNIXTIME, que usa la zona del servidor MySQL (UTC en el
 * contenedor): una encuesta de las 8 de la noche en Colombia contaba para el
 * día siguiente.
 *
 * El día se agrupa sumando a mano el desfase de la zona, sin depender de cómo
 * esté configurado MySQL. Es exacto para Colombia, que no cambia de hora; en
 * una zona con horario de verano, las encuestas cercanas a medianoche de los
 * días de cambio podrían caer en el día vecino.
 *
 * @return list<array{dia: string, total: int}>
 */
function encuestasPorDia(PDO $pdo, DateTimeZone $zona, int $dias = 14): array
{
    $dias = max(1, min(90, $dias));
    $hoy = new DateTimeImmutable('today', $zona);
    $inicio = $hoy->modify('-' . ($dias - 1) . ' days');
    // Entero calculado aquí, no dato del usuario: se interpola como LIMIT.
    $desfaseMs = $zona->getOffset($hoy) * 1000;

    $stmt = $pdo->prepare(
        "SELECT FLOOR((fecha_encuesta + $desfaseMs) / 86400000) AS dia, COUNT(*) AS total
           FROM encuestas
          WHERE fecha_encuesta >= ?
          GROUP BY dia"
    );
    $stmt->execute([$inicio->getTimestamp() * 1000]);

    $porNumeroDeDia = [];
    foreach ($stmt->fetchAll() as $f) {
        $porNumeroDeDia[(int)$f['dia']] = (int)$f['total'];
    }

    $filas = [];
    for ($i = 0; $i < $dias; $i++) {
        $dia = $inicio->modify("+$i days");
        $numero = intdiv($dia->getTimestamp() * 1000 + $desfaseMs, 86400000);
        $filas[] = ['dia' => $dia->format('Y-m-d'), 'total' => $porNumeroDeDia[$numero] ?? 0];
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
 * Salen los encuestadores activos aunque lleven cero, porque saber quién no ha
 * enviado nada también sirve, y cualquier cuenta que tenga encuestas. Los
 * administradores sin encuestas y las cuentas desactivadas vacías solo
 * alargaban la lista.
 *
 * @return array<int, array{nombre: string, total: int}>
 */
function encuestasPorEncuestador(PDO $pdo, int $limite = 8): array
{
    asegurarRolEncuestador($pdo);

    $limite = max(1, min(50, $limite));
    $stmt = $pdo->query(
        "SELECT e.nombre, COUNT(en.id) AS total
         FROM encuestadores e
         LEFT JOIN encuestas en ON en.id_encuestador = e.id
         GROUP BY e.id, e.nombre, e.rol, e.activo
         HAVING total > 0 OR (e.rol = 'encuestador' AND e.activo = 1)
         ORDER BY total DESC, e.nombre
         LIMIT $limite"
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
 * Una cuenta por id, con su hash de contraseña.
 *
 * El hash hace falta para revalidar la sesión del panel: si la contraseña
 * cambió desde que se entró, esa sesión deja de valer. Quien pinte la cuenta
 * en pantalla no debe imprimir ese campo.
 *
 * @return array<string, mixed>|null
 */
function buscarCuentaPorId(PDO $pdo, int $id): ?array
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, nombre, numero_documento, password_hash, activo, rol
           FROM encuestadores
          WHERE id = ?'
    );
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/**
 * ¿Esta cuenta es el último administrador activo que queda?
 *
 * Sirve para impedir que alguien se deje fuera del panel quitándose el rol o
 * desactivándose. Recuperarse de eso exigiría entrar a la base de datos por
 * SSH, que es justo lo que este sistema de cuentas venía a evitar.
 *
 * Solo puede serlo si ella misma ES un administrador activo. Antes se contaban
 * únicamente los demás, así que en modo arranque (cero administradores) toda
 * cuenta parecía "la última": no se podía ni corregir el nombre de un
 * encuestador sin convertirlo en administrador.
 */
function esUltimoAdminActivo(PDO $pdo, int $id): bool
{
    asegurarRolEncuestador($pdo);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(id = ?), 0) AS ella, COALESCE(SUM(id <> ?), 0) AS otros
           FROM encuestadores
          WHERE rol = 'admin' AND activo = 1"
    );
    $stmt->execute([$id, $id]);
    $fila = $stmt->fetch();

    return is_array($fila) && (int)$fila['ella'] > 0 && (int)$fila['otros'] === 0;
}

/**
 * Cierra todas las sesiones de API (las de los celulares) de una cuenta.
 *
 * Sin esto, cambiarle la contraseña a la cuenta de un celular perdido no
 * servía de nada: el token ya emitido seguía valiendo sus 30 días.
 */
function revocarSesionesApi(PDO $pdo, int $idCuenta): void
{
    $pdo->prepare('DELETE FROM sesiones WHERE id_encuestador = ?')->execute([$idCuenta]);
}
