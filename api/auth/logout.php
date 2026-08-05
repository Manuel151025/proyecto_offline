<?php
/**
 * Revoca el token de la sesión actual.
 *
 * Sin esto, "cerrar sesión" solo borraba la sesión en el dispositivo y el
 * token seguía siendo válido en el servidor durante sus 30 días de vigencia:
 * un token filtrado no se podía invalidar de ninguna forma.
 *
 * Es IDEMPOTENTE y siempre responde 200, aunque el token no exista o ya haya
 * vencido. Distinguir esos casos solo serviría para que alguien averiguara
 * qué tokens son válidos, y para el cliente el resultado es el mismo: la
 * sesión queda cerrada.
 */

require_once __DIR__ . '/../cors.php';
aplicarCors('POST, OPTIONS');

require_once __DIR__ . '/../db.php';
$pdo = conectarBD();
require_once __DIR__ . '/../auth_token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError(405, 'Método no permitido');
}

$token = leerHeaderAutorizacion();

if ($token !== '') {
    try {
        $stmt = $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    } catch (Exception $e) {
        // El cliente ya borró su sesión local; que falle el registro del
        // servidor no debe dejarlo atrapado en la aplicación.
        error_log('[logout] ' . $e->getMessage());
    }
}

echo json_encode(['success' => true, 'message' => 'Sesión cerrada']);
