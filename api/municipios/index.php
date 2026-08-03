<?php
require_once __DIR__ . '/../cors.php';
aplicarCors('GET, OPTIONS');

require_once __DIR__ . '/../db.php';

try {
    $stmt = $pdo->query('SELECT codigo, nombre, departamento FROM municipios');
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    // El detalle va al log del servidor, nunca al cliente: $e->getMessage()
    // expondría estructura de la base de datos y rutas internas.
    error_log('[municipios] ' . $e->getMessage());
    responderError(500, 'No se pudo obtener el listado de municipios');
}
