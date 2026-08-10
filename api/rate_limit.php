<?php
/**
 * Limitación de intentos de inicio de sesión (anti fuerza bruta).
 *
 * Se cuenta POR DOCUMENTO, no por IP, y es una decisión deliberada: la app
 * corre detrás de un proxy inverso, así que REMOTE_ADDR es la IP del proxy y
 * es la misma para todo el mundo. Limitar por esa IP bloquearía a todos los
 * encuestadores a la vez — una denegación de servicio autoinfligida. Confiar
 * en X-Forwarded-For tampoco sirve sin conocer la configuración exacta del
 * proxy, porque un cliente puede falsificar esa cabecera.
 *
 * Contar por documento protege lo que realmente importa: adivinar la
 * contraseña de una cuenta concreta. Un atacante puede rotar documentos para
 * esquivarlo, pero entonces ya no está haciendo fuerza bruta contra nadie.
 *
 * Los intentos se registran aunque el documento no exista, para que el
 * bloqueo no revele qué cuentas son reales.
 */

const MAX_INTENTOS_FALLIDOS = 5;
const VENTANA_SEGUNDOS = 900; // 15 minutos

/**
 * Clave del contador para el panel de administración.
 *
 * El panel tiene un solo usuario y no pide documento, así que el contador es
 * global: no hay a quién distinguir. Comparte tabla con los encuestadores
 * porque el mecanismo es idéntico.
 *
 * Lleva '#' a propósito. login.php rechaza los documentos que no encajen en
 * [A-Za-z0-9\-], de modo que ningún cliente puede enviar esta clave y bloquear
 * el panel desde fuera. Si algún día se relaja esa validación, se abre ese
 * agujero: las dos cosas van juntas.
 */
const CLAVE_PANEL_ADMIN = '#admin';

/** Crea la tabla si falta (permite desplegar sin acceso SSH a la base). */
function crearTablaIntentos(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS intentos_login (
            id INT AUTO_INCREMENT PRIMARY KEY,
            documento VARCHAR(20) NOT NULL,
            creado_en BIGINT NOT NULL,
            INDEX idx_intentos_documento (documento, creado_en)
        )'
    );
}

/**
 * Ejecuta una operación y, si falla porque la tabla no existe todavía,
 * la crea y reintenta una sola vez. Evita hacer DDL en cada petición.
 */
function conTablaIntentos(PDO $pdo, callable $operacion): mixed
{
    try {
        return $operacion();
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { // 42S02 = tabla inexistente
            throw $e;
        }
        crearTablaIntentos($pdo);
        return $operacion();
    }
}

/**
 * Segundos que quedan de bloqueo para esa clave. 0 si no está bloqueada.
 *
 * Devuelve el dato en vez de responder, porque hay dos consumidores con
 * formatos incompatibles: la API contesta JSON con 429, y el panel de
 * administración es HTML y necesita pintar el aviso dentro de la página.
 */
function segundosDeBloqueo(PDO $pdo, string $clave): int
{
    $desde = time() - VENTANA_SEGUNDOS;

    $marcas = conTablaIntentos($pdo, function () use ($pdo, $clave, $desde) {
        $stmt = $pdo->prepare(
            'SELECT creado_en FROM intentos_login
             WHERE documento = ? AND creado_en > ?
             ORDER BY creado_en DESC
             LIMIT ' . MAX_INTENTOS_FALLIDOS
        );
        $stmt->execute([$clave, $desde]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    });

    if (count($marcas) < MAX_INTENTOS_FALLIDOS) {
        return 0;
    }

    // El bloqueo se levanta cuando el más antiguo de esos intentos sale de la
    // ventana; así reintentar no extiende el castigo indefinidamente.
    $masAntiguo = (int)end($marcas);
    return max(1, ($masAntiguo + VENTANA_SEGUNDOS) - time());
}

/**
 * Corta con 429 si el documento acumula demasiados fallos recientes.
 * Requiere cors.php cargado (usa responderError).
 */
function exigirLimiteIntentos(PDO $pdo, string $documento): void
{
    $segundosRestantes = segundosDeBloqueo($pdo, $documento);
    if ($segundosRestantes === 0) {
        return;
    }

    $minutos = (int)ceil($segundosRestantes / 60);
    header('Retry-After: ' . $segundosRestantes);
    responderError(429, "Demasiados intentos fallidos. Espera $minutos minuto(s) antes de volver a intentar.");
}

/** Registra un intento fallido y limpia los que ya salieron de la ventana. */
function registrarIntentoFallido(PDO $pdo, string $documento): void
{
    conTablaIntentos($pdo, function () use ($pdo, $documento) {
        $pdo->prepare('INSERT INTO intentos_login (documento, creado_en) VALUES (?, ?)')
            ->execute([$documento, time()]);
        $pdo->prepare('DELETE FROM intentos_login WHERE creado_en < ?')
            ->execute([time() - VENTANA_SEGUNDOS]);
        return null;
    });
}

/** Un inicio de sesión correcto borra el historial de fallos del documento. */
function limpiarIntentos(PDO $pdo, string $documento): void
{
    conTablaIntentos($pdo, function () use ($pdo, $documento) {
        $pdo->prepare('DELETE FROM intentos_login WHERE documento = ?')->execute([$documento]);
        return null;
    });
}
