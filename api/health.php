<?php
/**
 * Comprobación de salud para monitoreo externo.
 *
 * 200 si la API responde y la base de datos acepta consultas; 503 si no.
 * No revela detalles internos: solo el estado, la hora del servidor y la
 * versión del esquema que necesitan los clientes para diagnosticar.
 */
require_once __DIR__ . '/cors.php';
aplicarCors('GET, OPTIONS');

$estado = ['success' => true, 'api' => 'ok', 'base_de_datos' => 'ok', 'hora_servidor' => (int)round(microtime(true) * 1000)];

try {
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: 'minsalud_encuestas';
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    error_log('[health] ' . $e->getMessage());
    http_response_code(503);
    $estado['success'] = false;
    $estado['base_de_datos'] = 'sin conexión';
}

echo json_encode($estado);
