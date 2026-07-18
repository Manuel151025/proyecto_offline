<?php
// Endpoint de migración de UN SOLO USO para actualizar la BD de producción sin SSH.
// Uso: https://TU_DOMINIO/api/setup/migrate.php?key=LA_CONTRASENA_ADMIN
// Reutiliza ADMIN_PASSWORD (ya configurada). Es idempotente: se puede repetir sin daño.
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';

$admin = getenv('ADMIN_PASSWORD');
if (!$admin || !hash_equals($admin, $_GET['key'] ?? '')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "No autorizado"]);
    exit;
}

$done = [];
try {
    // 1) Columnas de autenticación en encuestadores (por si falta la migración 002).
    foreach ([
        "ALTER TABLE encuestadores ADD COLUMN numero_documento VARCHAR(20) NULL UNIQUE",
        "ALTER TABLE encuestadores ADD COLUMN password_hash VARCHAR(255) NULL",
        "ALTER TABLE encuestadores ADD COLUMN activo TINYINT(1) DEFAULT 1"
    ] as $sql) {
        try { $pdo->exec($sql); $done[] = "columna añadida"; }
        catch (PDOException $e) { /* ya existe: ignorar */ }
    }

    // 2) Reseed de municipios (INSERT ... ON DUPLICATE KEY UPDATE, idempotente).
    $seed = @file_get_contents(__DIR__ . '/../../database/seeds/municipios.sql');
    if ($seed !== false && trim($seed) !== '') {
        $pdo->exec($seed);
        $done[] = "municipios sembrados";
    } else {
        $done[] = "AVISO: no se encontró database/seeds/municipios.sql";
    }

    // 3) Cuenta de prueba (docente) para el login de la PWA.
    $hash = password_hash('Demo2026Salud', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        "INSERT INTO encuestadores (id, nombre, numero_documento, password_hash, activo)
         VALUES (1, 'Docente Demo', '1000000001', ?, 1)
         ON DUPLICATE KEY UPDATE numero_documento = VALUES(numero_documento),
             password_hash = VALUES(password_hash), activo = 1"
    );
    $stmt->execute([$hash]);
    $done[] = "cuenta demo lista";

    $muni = (int)$pdo->query("SELECT COUNT(*) FROM municipios")->fetchColumn();
    $dept = (int)$pdo->query("SELECT COUNT(DISTINCT departamento) FROM municipios")->fetchColumn();

    echo json_encode([
        "success" => true,
        "pasos" => $done,
        "municipios" => $muni,
        "departamentos" => $dept,
        "message" => "Migración completada. Elimina este archivo o cambia ADMIN_PASSWORD."
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
