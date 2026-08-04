<?php
require_once __DIR__ . '/../cors.php';
aplicarCors('POST, OPTIONS');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_token.php';
require_once __DIR__ . '/../rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError(405, 'Método no permitido');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    responderError(400, 'Payload inválido');
}

$documento = trim((string)($data['numero_documento'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($documento === '' || $password === '') {
    responderError(400, 'Documento y contraseña son requeridos');
}

try {
    // Se comprueba antes de tocar la contraseña, y para cualquier documento
    // exista o no, para que el bloqueo no delate qué cuentas son reales.
    exigirLimiteIntentos($pdo, $documento);

    $stmt = $pdo->prepare('SELECT id, nombre, password_hash, activo FROM encuestadores WHERE numero_documento = ?');
    $stmt->execute([$documento]);
    $encuestador = $stmt->fetch();

    if (!$encuestador || !$encuestador['activo'] || !$encuestador['password_hash']
        || !password_verify($password, $encuestador['password_hash'])) {
        registrarIntentoFallido($pdo, $documento);
        // Mensaje genérico: no revelamos si el documento existe o no.
        responderError(401, 'Documento o contraseña incorrectos');
    }

    // Credenciales correctas: se borra el historial de fallos.
    limpiarIntentos($pdo, $documento);

    $sesion = emitirToken($pdo, (int)$encuestador['id']);

    echo json_encode([
        'success' => true,
        'token' => $sesion['token'],
        'expira_en' => $sesion['expira_en'],
        'encuestador' => [
            'id' => (int)$encuestador['id'],
            'nombre' => $encuestador['nombre'],
            'numero_documento' => $documento,
        ],
    ]);
} catch (Exception $e) {
    error_log('[login] ' . $e->getMessage());
    responderError(500, 'Error de servidor');
}
