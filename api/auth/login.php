<?php
require_once __DIR__ . '/../cors.php';
aplicarCors('POST, OPTIONS');

require_once __DIR__ . '/../db.php';
$pdo = conectarBD();
require_once __DIR__ . '/../auth_token.php';
require_once __DIR__ . '/../rate_limit.php';
require_once __DIR__ . '/../esquema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError(405, 'Método no permitido');
}

$data = json_decode(leerCuerpo(), true);
if (!is_array($data)) {
    responderError(400, 'Payload inválido');
}

$documento = trim((string)($data['numero_documento'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($documento === '' || $password === '') {
    responderError(400, 'Documento y contraseña son requeridos');
}

// El documento es la CLAVE del contador anti fuerza bruta, así que aquí no se
// puede aceptar cualquier cadena. Sin esta comprobación, un cliente podía
// enviar como "documento" la clave interna que usa el panel de administración
// y dejarlo bloqueado sin llegar a tocarlo.
//
// El formato es el mismo que exige sync.php: letras, dígitos y guiones. Se
// admiten letras porque los pasaportes y algunas cédulas de extranjería las
// llevan, y rechazarlas dejaría fuera a personas reales.
if (mb_strlen($documento) > 20 || !preg_match('/^[A-Za-z0-9\-]+$/', $documento)) {
    responderError(400, 'Formato de documento inválido');
}

try {
    // La consulta de abajo lee `rol`; sin esto fallaría contra una base que
    // todavía no tenga la columna, que es el estado antes del despliegue.
    asegurarRolEncuestador($pdo);

    // Se comprueba antes de tocar la contraseña, y para cualquier documento
    // exista o no, para que el bloqueo no delate qué cuentas son reales.
    exigirLimiteIntentos($pdo, $documento);

    $stmt = $pdo->prepare('SELECT id, nombre, password_hash, activo, rol FROM encuestadores WHERE numero_documento = ?');
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
            // El cliente puede adaptar lo que muestra sin consultar otra vez.
            'rol' => (string)($encuestador['rol'] ?? 'encuestador'),
        ],
    ]);
} catch (Exception $e) {
    error_log('[login] ' . $e->getMessage());
    responderError(500, 'Error de servidor');
}
