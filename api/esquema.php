<?php
/**
 * Ajuste de esquema que se aplica solo.
 *
 * El despliegue de producción no tiene acceso SSH ni consola de base de datos,
 * así que una migración manual dejaría una ventana en la que el código nuevo
 * ya está desplegado y la tabla todavía no cambió. Para `server_updated_at`
 * esa ventana rompería la sincronización ENTERA, no solo la descarga: sync.php
 * escribe esa columna en cada envío.
 *
 * Se comprueba con information_schema y NO con un try/catch alrededor de la
 * escritura, porque en MySQL un ALTER TABLE hace commit implícito: ejecutarlo
 * dentro de la transacción de sincronización partiría el lote a la mitad,
 * dejando unas personas guardadas y otras no.
 *
 * `database/migrations/005_server_updated_at.sql` sigue disponible para quien
 * pueda aplicarla por adelantado; esto es la red de seguridad.
 */

/**
 * Garantiza que exista `personas.server_updated_at`.
 *
 * La bandera estática evita repetir la comprobación dentro de una misma
 * petición; entre peticiones es una consulta ligera a information_schema.
 */
function asegurarServerUpdatedAt(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['personas', 'server_updated_at']);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE personas ADD COLUMN server_updated_at BIGINT NULL AFTER updated_at');

        // El índice va aparte: si la columna faltaba pero el índice existía,
        // un ALTER conjunto habría fallado entero.
        try {
            $pdo->exec('CREATE INDEX idx_personas_server_updated ON personas (server_updated_at)');
        } catch (PDOException $e) {
            // Ya existía; no es un problema.
        }

        // Las filas anteriores no tienen sello. Se les pone el momento actual
        // para que entren en la primera descarga en vez de quedar invisibles.
        $pdo->prepare('UPDATE personas SET server_updated_at = ? WHERE server_updated_at IS NULL')
            ->execute([(int)round(microtime(true) * 1000)]);

        error_log('[esquema] columna server_updated_at creada automáticamente');
    }

    $verificado = true;
}

/**
 * Crea las tablas del monitor de sincronización si faltan.
 *
 * - `sync_rechazos`: cada registro que sync.php descarta, con su motivo. Antes
 *   el rechazo solo viajaba en la respuesta al celular y nadie en la oficina
 *   podía saber que un encuestador tenía registros atascados.
 * - `dispositivos`: un renglón por celular con su última sincronización, para
 *   detectar equipos que llevan días sin enviar nada.
 *
 * Debe llamarse FUERA de cualquier transacción: CREATE TABLE hace commit
 * implícito en MySQL.
 */
function asegurarTablasSincronizacion(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sync_rechazos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_encuesta VARCHAR(50) NULL,
            tipo_documento VARCHAR(10) NULL,
            numero_documento VARCHAR(40) NULL,
            motivo VARCHAR(255) NOT NULL,
            device_id VARCHAR(50) NULL,
            id_encuestador INT NULL,
            creado_en BIGINT NOT NULL,
            INDEX idx_rechazos_fecha (creado_en)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS dispositivos (
            device_id VARCHAR(50) PRIMARY KEY,
            id_encuestador INT NULL,
            plataforma VARCHAR(20) NULL,
            version_app VARCHAR(20) NULL,
            ultima_subida BIGINT NULL,
            ultima_descarga BIGINT NULL,
            ultima_actividad BIGINT NOT NULL,
            INDEX idx_dispositivos_actividad (ultima_actividad)
        )'
    );

    $verificado = true;
}

/**
 * Crea la tabla de auditoría del panel si falta.
 *
 * Antes, de una acción de administración solo quedaba "admin:<nombre>" en el
 * device_id de la persona, y la siguiente sincronización lo sobrescribía. No
 * había forma de saber quién borró, restauró o editó qué, ni cuándo.
 */
function asegurarTablaAuditoria(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auditoria_admin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_admin INT NULL,
            nombre_admin VARCHAR(100) NOT NULL,
            accion VARCHAR(40) NOT NULL,
            objeto VARCHAR(80) NOT NULL,
            detalle TEXT NULL,
            creado_en BIGINT NOT NULL,
            INDEX idx_auditoria_fecha (creado_en),
            INDEX idx_auditoria_objeto (objeto)
        )'
    );
    $verificado = true;
}

/**
 * Crea la tabla de municipios asignados a cada cuenta si falta.
 *
 * Una cuenta SIN filas aquí descarga todas las personas (el comportamiento de
 * siempre). Con filas, cada celular recibe solo las personas de esos
 * municipios y las que su encuestador registró o actualizó: un teléfono
 * perdido en campo ya no expone los datos de salud de todo el país.
 */
function asegurarTablaAsignaciones(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS encuestador_municipios (
            id_encuestador INT NOT NULL,
            municipio_codigo VARCHAR(10) NOT NULL,
            PRIMARY KEY (id_encuestador, municipio_codigo)
        )'
    );
    $verificado = true;
}

/**
 * Municipios asignados a una cuenta. Vacío = sin restricción.
 *
 * @return list<string>
 */
function municipiosAsignados(PDO $pdo, int $idCuenta): array
{
    asegurarTablaAsignaciones($pdo);
    $stmt = $pdo->prepare('SELECT municipio_codigo FROM encuestador_municipios WHERE id_encuestador = ? ORDER BY municipio_codigo');
    $stmt->execute([$idCuenta]);
    return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Registra que un celular se comunicó con el servidor.
 *
 * El identificador llega en la cabecera X-Device-Id; si falta (clientes
 * anteriores a esta versión), se usa el que traiga el propio envío. Un fallo
 * aquí nunca debe tumbar la sincronización: es información de monitoreo.
 *
 * @param 'subida'|'descarga' $tipo
 */
function registrarDispositivo(PDO $pdo, int $idEncuestador, string $tipo, ?string $deviceIdRespaldo = null): void
{
    $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
    if ($deviceId === '') {
        $deviceId = trim((string)$deviceIdRespaldo);
    }
    if ($deviceId === '') {
        return;
    }

    $plataforma = mb_substr(trim((string)($_SERVER['HTTP_X_PLATAFORMA'] ?? '')), 0, 20) ?: null;
    $version = mb_substr(trim((string)($_SERVER['HTTP_X_APP_VERSION'] ?? '')), 0, 20) ?: null;
    $ahora = (int)round(microtime(true) * 1000);
    $columna = $tipo === 'subida' ? 'ultima_subida' : 'ultima_descarga';

    try {
        asegurarTablasSincronizacion($pdo);
        $pdo->prepare(
            "INSERT INTO dispositivos (device_id, id_encuestador, plataforma, version_app, $columna, ultima_actividad)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id_encuestador = VALUES(id_encuestador),
                plataforma = COALESCE(VALUES(plataforma), plataforma),
                version_app = COALESCE(VALUES(version_app), version_app),
                $columna = VALUES($columna),
                ultima_actividad = VALUES(ultima_actividad)"
        )->execute([mb_substr($deviceId, 0, 50), $idEncuestador, $plataforma, $version, $ahora, $ahora]);
    } catch (PDOException $e) {
        error_log('[dispositivos] ' . $e->getMessage());
    }
}

/**
 * Añade la columna `rol` a encuestadores si falta.
 *
 * Antes había dos sistemas de autenticación sin relación: los encuestadores
 * contra esta tabla, y el panel contra una única contraseña compartida en
 * ADMIN_PASSWORD. Eso significaba que el panel no sabía QUIÉN entraba, así que
 * ninguna acción administrativa tenía autor.
 *
 * Se usa el mismo patrón de comprobación por information_schema que
 * asegurarServerUpdatedAt, y por el mismo motivo: permite desplegar sin acceso
 * SSH a la base.
 *
 * El valor por defecto es 'encuestador' a propósito. Las cuentas que ya existen
 * se crearon para trabajo de campo, y darles rol de administrador al migrar
 * convertiría a toda la plantilla en administradores de golpe.
 */
function asegurarRolEncuestador(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['encuestadores', 'rol']);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE encuestadores
                ADD COLUMN rol ENUM('encuestador', 'admin') NOT NULL DEFAULT 'encuestador'"
        );
        error_log('[esquema] columna rol creada automáticamente en encuestadores');
    }

    $verificado = true;
}

/**
 * Completa y corrige la tabla `municipios` con el catálogo DIVIPOLA.
 *
 * Producción tenía 162 municipios cargados a mano. El catálogo completo
 * (1.122) vive en api/municipios/catalogo.php, generado desde
 * database/catalogos/municipios.json, y lleva una versión. Si la versión
 * guardada en la tabla `ajustes` no coincide, se insertan los que falten y se
 * corrigen nombres y departamentos de los que ya estaban. Los códigos no
 * cambian nunca, así que ninguna persona pierde su municipio.
 *
 * Debe llamarse FUERA de transacciones (CREATE TABLE hace commit implícito).
 */
function asegurarCatalogoMunicipios(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS ajustes (
        clave VARCHAR(40) PRIMARY KEY,
        valor VARCHAR(100) NOT NULL
    )');

    /** @var array{version: string, municipios: list<array{0: string, 1: string, 2: string}>} $catalogo */
    $catalogo = require __DIR__ . '/municipios/catalogo.php';
    // Versión del catálogo + revisión de este procedimiento: si el
    // procedimiento cambia (p. ej. ahora retira códigos inexistentes), subir
    // la revisión hace que se ejecute otra vez aunque el catálogo sea el mismo.
    $version = $catalogo['version'] . ':2';
    $stmt = $pdo->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $stmt->execute(['catalogo_municipios']);
    if ($stmt->fetchColumn() === $version) {
        $verificado = true;
        return;
    }

    // En bloques de 200 filas: una sola sentencia con 1.122 filas funciona,
    // pero así no depende de max_allowed_packet.
    foreach (array_chunk($catalogo['municipios'], 200) as $bloque) {
        $marcas = implode(', ', array_fill(0, count($bloque), '(?, ?, ?)'));
        $pdo->prepare("INSERT INTO municipios (codigo, nombre, departamento) VALUES $marcas
                       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), departamento = VALUES(departamento)")
            ->execute(array_merge(...$bloque));
    }
    retirarCodigosInexistentes($pdo, $catalogo['municipios']);

    $pdo->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute(['catalogo_municipios', $version]);

    error_log('[esquema] catálogo de municipios actualizado a la versión ' . $version);
    $verificado = true;
}

/**
 * Retira los códigos que no existen en el DIVIPOLA.
 *
 * La lista cargada a mano traía al menos uno equivocado (95040 para
 * Miraflores, Guaviare, cuyo código es 95200). Si el catálogo tiene un
 * municipio con el mismo nombre y departamento, las personas se pasan a ese
 * código y se sellan para que el cambio llegue a los celulares; luego se
 * borra el código falso. Si no hay equivalente y nadie lo usa, se borra; si
 * alguien lo usa, se deja y se avisa en el log.
 *
 * @param list<array{0: string, 1: string, 2: string}> $catalogo
 */
function retirarCodigosInexistentes(PDO $pdo, array $catalogo): void
{
    $validos = array_flip(array_column($catalogo, 0));
    $porNombre = [];
    foreach ($catalogo as [$codigo, $nombre, $depto]) {
        $porNombre[mb_strtolower($nombre . '|' . $depto)] = $codigo;
    }

    $stmt = $pdo->query('SELECT codigo, nombre, departamento FROM municipios');
    foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $viejo = (string)$fila['codigo'];
        if (isset($validos[$viejo])) {
            continue;
        }
        $nuevo = $porNombre[mb_strtolower($fila['nombre'] . '|' . $fila['departamento'])] ?? null;
        if ($nuevo !== null) {
            $ahora = (int)round(microtime(true) * 1000);
            $pdo->prepare('UPDATE personas SET municipio_codigo = ?, updated_at = ?, server_updated_at = ? WHERE municipio_codigo = ?')
                ->execute([$nuevo, $ahora, $ahora, $viejo]);
            try {
                $pdo->prepare('UPDATE IGNORE encuestador_municipios SET municipio_codigo = ? WHERE municipio_codigo = ?')
                    ->execute([$nuevo, $viejo]);
                $pdo->prepare('DELETE FROM encuestador_municipios WHERE municipio_codigo = ?')->execute([$viejo]);
            } catch (PDOException $e) {
                // La tabla aún no existe: nada que mover.
            }
        }
        $usos = $pdo->prepare('SELECT COUNT(*) FROM personas WHERE municipio_codigo = ?');
        $usos->execute([$viejo]);
        if ((int)$usos->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM municipios WHERE codigo = ?')->execute([$viejo]);
            error_log("[esquema] código de municipio inexistente retirado: $viejo" . ($nuevo !== null ? " (personas pasadas a $nuevo)" : ''));
        } else {
            error_log("[esquema] código de municipio inexistente en uso, sin equivalente: $viejo");
        }
    }
}

/**
 * Correo de cada cuenta (opcional) y códigos para recuperar la contraseña.
 *
 * - `encuestadores.email`: a dónde se envía el código. Opcional: muchos
 *   encuestadores de campo no tienen correo; para ellos el administrador
 *   cambia la contraseña desde el panel.
 * - `recuperaciones`: un renglón por código pedido. Se guarda solo el hash del
 *   código, vence a los 15 minutos, admite 5 intentos y sirve una sola vez.
 */
function asegurarRecuperacion(PDO $pdo): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['encuestadores', 'email']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE encuestadores ADD COLUMN email VARCHAR(100) NULL');
        error_log('[esquema] columna encuestadores.email creada automáticamente');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS recuperaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_encuestador INT NOT NULL,
        codigo_hash VARCHAR(255) NOT NULL,
        creado_en BIGINT NOT NULL,
        expira_en BIGINT NOT NULL,
        intentos INT NOT NULL DEFAULT 0,
        usado TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_recuperaciones_cuenta (id_encuestador, creado_en)
    )');

    $verificado = true;
}
