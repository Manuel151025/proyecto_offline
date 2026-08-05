<?php
/**
 * Autenticación por token opaco persistido en la tabla `sesiones`.
 *
 * Modelo:
 *  - login.php genera un token aleatorio de 32 bytes y devuelve el valor en claro
 *    UNA sola vez. En la base de datos solo se guarda su hash SHA-256, de modo que
 *    una filtración de la tabla no permite suplantar a nadie.
 *  - Los endpoints de escritura exigen `Authorization: Bearer <token>`.
 *  - La vigencia es larga (30 días) porque los encuestadores trabajan en campo
 *    sin conectividad: el token se emite en la oficina y viaja con el dispositivo.
 *  - Es revocable: basta borrar la fila (o marcar el encuestador como inactivo).
 */

const TOKEN_VIGENCIA_DIAS = 30;

/** Genera un token nuevo para el encuestador y persiste su hash. */
/** @return array{token: string, expira_en: int} El token en claro solo se devuelve aquí. */
function emitirToken(PDO $pdo, int $idEncuestador): array
{
    $token = bin2hex(random_bytes(32));
    $expiraEn = time() + (TOKEN_VIGENCIA_DIAS * 86400);

    $stmt = $pdo->prepare(
        'INSERT INTO sesiones (token_hash, id_encuestador, creado_en, expira_en)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([hash('sha256', $token), $idEncuestador, time(), $expiraEn]);

    // Limpieza oportunista de tokens vencidos para que la tabla no crezca sin control.
    $pdo->prepare('DELETE FROM sesiones WHERE expira_en < ?')->execute([time()]);

    return ['token' => $token, 'expira_en' => $expiraEn];
}

/** Lee el header Authorization de forma portable entre servidores. */
function leerHeaderAutorizacion(): string
{
    $candidatos = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $nombre => $valor) {
            if (strcasecmp($nombre, 'Authorization') === 0) {
                $candidatos[] = $valor;
            }
        }
    }

    foreach ($candidatos as $valor) {
        if (is_string($valor) && stripos($valor, 'Bearer ') === 0) {
            return trim(substr($valor, 7));
        }
    }

    return '';
}

/**
 * Exige un token válido. Devuelve el encuestador autenticado o corta con 401.
 * Requiere que cors.php esté cargado (usa responderError).
 */
/** @return array{id_encuestador: int, nombre: string} */
function requerirAutenticacion(PDO $pdo): array
{
    $token = leerHeaderAutorizacion();

    if ($token === '') {
        responderError(401, 'Falta el token de autenticación');
    }

    $stmt = $pdo->prepare(
        'SELECT s.id, s.id_encuestador, s.expira_en, e.nombre, e.activo
         FROM sesiones s
         JOIN encuestadores e ON e.id = s.id_encuestador
         WHERE s.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $token)]);
    $sesion = $stmt->fetch();

    if (!$sesion) {
        responderError(401, 'Token inválido');
    }

    if ((int)$sesion['expira_en'] < time()) {
        $pdo->prepare('DELETE FROM sesiones WHERE id = ?')->execute([$sesion['id']]);
        responderError(401, 'Token expirado, inicia sesión de nuevo');
    }

    if (!$sesion['activo']) {
        responderError(403, 'La cuenta está desactivada');
    }

    $pdo->prepare('UPDATE sesiones SET ultimo_uso = ? WHERE id = ?')
        ->execute([time(), $sesion['id']]);

    return [
        'id_encuestador' => (int)$sesion['id_encuestador'],
        'nombre' => $sesion['nombre'],
    ];
}
