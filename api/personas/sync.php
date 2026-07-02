<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once '../db.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['personas']) || !isset($data['encuestas'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid payload"]);
    exit;
}

$processedEncuestas = [];
$pdo->beginTransaction();

try {
    // 1. Sincronizar Personas mediante Last-Write-Wins
    $stmtPersonaCheck = $pdo->prepare("SELECT updated_at FROM personas WHERE tipo_documento = ? AND numero_documento = ? FOR UPDATE");
    $stmtPersonaInsert = $pdo->prepare("
        INSERT INTO personas (
            tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento, 
            telefono, email, direccion, eps, ocupacion, estrato, municipio_codigo, 
            updated_at, device_id, deleted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtPersonaUpdate = $pdo->prepare("
        UPDATE personas SET 
            nombres = ?, apellidos = ?, fecha_nacimiento = ?, telefono = ?, email = ?, 
            direccion = ?, eps = ?, ocupacion = ?, estrato = ?, municipio_codigo = ?, 
            updated_at = ?, device_id = ?, deleted_at = ?
        WHERE tipo_documento = ? AND numero_documento = ?
    ");

    foreach ($data['personas'] as $p) {
        $stmtPersonaCheck->execute([$p['tipo_documento'], $p['numero_documento']]);
        $existing = $stmtPersonaCheck->fetch();

        // ALGORITMO LAST-WRITE-WINS (LWW)
        if ($existing) {
            // Ya existe. ¿El registro entrante es más reciente que el de la BD?
            if ($p['updated_at'] > $existing['updated_at']) {
                $stmtPersonaUpdate->execute([
                    $p['nombres'], $p['apellidos'], $p['fecha_nacimiento'], $p['telefono'],
                    $p['email'], $p['direccion'], $p['eps'], $p['ocupacion'], $p['estrato'],
                    $p['municipio_codigo'], $p['updated_at'], $p['device_id'], $p['deleted_at'],
                    $p['tipo_documento'], $p['numero_documento']
                ]);
            }
            // Si el entrante es más viejo (updated_at menor o igual), lo ignoramos pacíficamente.
        } else {
            // No existe, insertar
            $stmtPersonaInsert->execute([
                $p['tipo_documento'], $p['numero_documento'], $p['nombres'], $p['apellidos'], 
                $p['fecha_nacimiento'], $p['telefono'], $p['email'], $p['direccion'], 
                $p['eps'], $p['ocupacion'], $p['estrato'], $p['municipio_codigo'], 
                $p['updated_at'], $p['device_id'], $p['deleted_at']
            ]);
        }
    }

    // 2. Registrar las Encuestas (Trazabilidad)
    $stmtEncuestaInsert = $pdo->prepare("
        INSERT IGNORE INTO encuestas (
            id, tipo_documento, numero_documento, id_encuestador, 
            fecha_encuesta, device_id, accion, server_sync_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $now = round(microtime(true) * 1000);

    foreach ($data['encuestas'] as $e) {
        $stmtEncuestaInsert->execute([
            $e['id'], $e['tipo_documento'], $e['numero_documento'], $e['id_encuestador'],
            $e['fecha_encuesta'], $e['device_id'], $e['accion'], $now
        ]);
        $processedEncuestas[] = $e['id'];
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Sincronización completada. Conflictos resueltos vía LWW.",
        "processed_encuestas" => $processedEncuestas
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Error durante sincronización: " . $e->getMessage()
    ]);
}
?>
