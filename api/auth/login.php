<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../db.php';

$data = json_decode(file_get_contents('php://input'), true);
$documento = trim($data['numero_documento'] ?? '');
$password = (string)($data['password'] ?? '');

if ($documento === '' || $password === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Documento y contraseña son requeridos"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nombre, password_hash, activo FROM encuestadores WHERE numero_documento = ?");
    $stmt->execute([$documento]);
    $encuestador = $stmt->fetch();

    if (!$encuestador || !$encuestador['activo'] || !$encuestador['password_hash']
        || !password_verify($password, $encuestador['password_hash'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Documento o contraseña incorrectos"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "encuestador" => [
            "id" => (int)$encuestador['id'],
            "nombre" => $encuestador['nombre'],
            "numero_documento" => $documento
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error de servidor"]);
}
?>
