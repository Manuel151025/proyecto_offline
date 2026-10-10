<?php
require_once __DIR__ . '/../cors.php';
aplicarCors('GET, OPTIONS');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../esquema.php';
$pdo = conectarBD();

try {
    asegurarCatalogoMunicipios($pdo);
    $stmt = $pdo->query('SELECT codigo, nombre, departamento FROM municipios ORDER BY codigo');
    if ($stmt === false) {
        throw new RuntimeException('No se pudo preparar la consulta de municipios');
    }
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    // El detalle va al log del servidor, nunca al cliente: $e->getMessage()
    // expondría estructura de la base de datos y rutas internas.
    error_log('[municipios] ' . $e->getMessage());
    responderError(500, 'No se pudo obtener el listado de municipios');
}
