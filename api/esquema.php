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
