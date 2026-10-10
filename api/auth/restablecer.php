<?php
/**
 * Paso 2 de «¿Olvidaste tu contraseña?»: con el código del correo, fija una
 * contraseña nueva.
 *
 * Al cambiarla se cierran las sesiones de la cuenta en todos los celulares
 * (quien tuviera un celular perdido queda fuera), se desbloquea el inicio de
 * sesión y se avisa por correo del cambio.
 */
require_once __DIR__ . '/../cors.php';
aplicarCors('POST, OPTIONS');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rate_limit.php';
require_once __DIR__ . '/../esquema.php';
require_once __DIR__ . '/../correo.php';
require_once __DIR__ . '/../politica.php';
require_once __DIR__ . '/recuperacion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError(405, 'Método no permitido');
}

$data = json_decode(leerCuerpo(), true);
if (!is_array($data)) {
    responderError(400, 'Payload inválido');
}
$documento = trim((string)($data['numero_documento'] ?? ''));
$codigo = trim((string)($data['codigo'] ?? ''));
$password = (string)($data['password'] ?? '');

if (!documentoDeCuentaValido($documento)) {
    responderError(400, 'Escribe tu número de documento: solo letras, dígitos o guiones.');
}
if (preg_match('/^\d{6}$/', $codigo) !== 1) {
    responderError(400, 'El código tiene 6 dígitos.');
}
if (mb_strlen($password) < MIN_LONGITUD_PASSWORD || mb_strlen($password) > 200) {
    responderError(400, 'La contraseña nueva debe tener al menos ' . MIN_LONGITUD_PASSWORD . ' caracteres.');
}

$pdo = conectarBD();
try {
    asegurarRolEncuestador($pdo);
    asegurarRecuperacion($pdo);

    $clave = claveLimiteRecuperacion('usar', $documento);
    exigirLimiteIntentos($pdo, $clave);

    $stmt = $pdo->prepare('SELECT id, nombre, email FROM encuestadores WHERE numero_documento = ? AND activo = 1');
    $stmt->execute([$documento]);
    $cuenta = $stmt->fetch();

    $pendiente = null;
    if ($cuenta) {
        $stmt = $pdo->prepare('SELECT id, codigo_hash FROM recuperaciones
                                WHERE id_encuestador = ? AND usado = 0 AND expira_en > ?
                                ORDER BY creado_en DESC, id DESC LIMIT 1');
        $stmt->execute([(int)$cuenta['id'], time()]);
        $pendiente = $stmt->fetch() ?: null;
    }

    if (!$cuenta || $pendiente === null || !password_verify($codigo, (string)$pendiente['codigo_hash'])) {
        if ($pendiente !== null) {
            // Cada error gasta un intento; al quinto el código deja de servir.
            $pdo->prepare('UPDATE recuperaciones SET intentos = intentos + 1 WHERE id = ?')
                ->execute([(int)$pendiente['id']]);
            $pdo->prepare('UPDATE recuperaciones SET usado = 1 WHERE id = ? AND intentos >= ?')
                ->execute([(int)$pendiente['id'], INTENTOS_CODIGO_RECUPERACION]);
        }
        registrarIntentoFallido($pdo, $clave);
        responderError(400, 'El código no es válido o ya venció. Pide uno nuevo.');
    }

    $idCuenta = (int)$cuenta['id'];
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE encuestadores SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_BCRYPT), $idCuenta]);
    $pdo->prepare('UPDATE recuperaciones SET usado = 1 WHERE id_encuestador = ?')->execute([$idCuenta]);
    // Cualquier celular con la sesión abierta (por ejemplo, uno perdido) queda fuera.
    $pdo->prepare('DELETE FROM sesiones WHERE id_encuestador = ?')->execute([$idCuenta]);
    $pdo->commit();

    // Si la cuenta estaba bloqueada por intentos fallidos, ya puede entrar.
    limpiarIntentos($pdo, $documento);
    limpiarIntentos($pdo, $clave);
    error_log("[restablecer] contraseña cambiada con código para la cuenta $idCuenta");

    [$texto, $html] = plantillaContrasenaCambiada((string)$cuenta['nombre']);
    enviarCorreo((string)$cuenta['email'], 'Tu contraseña de ColOffline cambió', $texto, $html);

    echo json_encode(['success' => true, 'message' => 'Listo. Ya puedes entrar con tu contraseña nueva.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[restablecer] ' . $e->getMessage());
    responderError(500, 'Error de servidor');
}
