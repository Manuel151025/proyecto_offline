<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';

try {
    $stmt = $pdo->query("SELECT codigo, nombre, departamento FROM municipios");
    $municipios = $stmt->fetchAll();
    echo json_encode($municipios);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
