<?php
/**
 * Paso 1 de «¿Olvidaste tu contraseña?»: envía un código de 6 dígitos al
 * correo de la cuenta.
 *
 * La respuesta es la MISMA exista o no la cuenta, y tenga o no correo: así no
 * sirve para averiguar qué documentos están registrados. Cada documento puede
 * pedir 5 códigos cada 15 minutos.
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
$documento = is_array($data) ? trim((string)($data['numero_documento'] ?? '')) : '';
if (!documentoDeCuentaValido($documento)) {
    responderError(400, 'Escribe tu número de documento: solo letras, dígitos o guiones.');
}

if (!correoConfigurado()) {
    responderError(503, 'La recuperación por correo no está activada. Pide a tu administrador que te asigne una contraseña nueva.');
}

$pdo = conectarBD();
try {
    asegurarRolEncuestador($pdo);
    asegurarRecuperacion($pdo);

    $clave = claveLimiteRecuperacion('pedir', $documento);
    exigirLimiteIntentos($pdo, $clave);
    registrarIntentoFallido($pdo, $clave); // cuenta cada pedido, no solo los fallos

    $stmt = $pdo->prepare('SELECT id, nombre, email FROM encuestadores WHERE numero_documento = ? AND activo = 1');
    $stmt->execute([$documento]);
    $cuenta = $stmt->fetch();

    if ($cuenta && !empty($cuenta['email'])) {
        $idCuenta = (int)$cuenta['id'];
        // Un código nuevo anula los anteriores.
        $pdo->prepare('UPDATE recuperaciones SET usado = 1 WHERE id_encuestador = ? AND usado = 0')->execute([$idCuenta]);

        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ahora = time();
        $pdo->prepare('INSERT INTO recuperaciones (id_encuestador, codigo_hash, creado_en, expira_en) VALUES (?, ?, ?, ?)')
            ->execute([$idCuenta, password_hash($codigo, PASSWORD_BCRYPT), $ahora, $ahora + MINUTOS_CODIGO_RECUPERACION * 60]);

        [$texto, $html] = plantillaCodigo((string)$cuenta['nombre'], $codigo, MINUTOS_CODIGO_RECUPERACION);
        if (!enviarCorreo((string)$cuenta['email'], "Tu código de ColOffline: $codigo", $texto, $html)) {
            error_log("[recuperar] no se pudo enviar el código a la cuenta $idCuenta");
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Si tu cuenta tiene un correo registrado, te enviamos un código de 6 dígitos. '
                   . 'Revisa también la carpeta de spam. Si no te llega en unos minutos, pide a tu administrador '
                   . 'que te asigne una contraseña nueva.',
        'minutos' => MINUTOS_CODIGO_RECUPERACION,
    ]);
} catch (Exception $e) {
    error_log('[recuperar] ' . $e->getMessage());
    responderError(500, 'Error de servidor');
}
