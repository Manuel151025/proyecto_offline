<?php
/**
 * Conexión a la base de datos.
 *
 * Antes este archivo creaba una variable global `$pdo` al ser incluido, de modo
 * que cada endpoint la usaba sin que se viera de dónde salía. El análisis
 * estático lo señalaba en los 20 usos: una variable que aparece por el mero
 * hecho de hacer `require` es implícita y frágil — basta reordenar los require
 * para romperla en silencio.
 *
 * Ahora es una función explícita: se ve quién abre la conexión y cuándo, y se
 * puede pasar otra distinta en pruebas.
 */

/** Conexión única por petición: llamarla dos veces no abre dos conexiones. */
function conectarBD(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = getenv('DB_HOST') ?: 'localhost';
    $db      = getenv('DB_NAME') ?: 'minsalud_encuestas';
    $user    = getenv('DB_USER') ?: 'root';
    $pass    = getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$db;charset=$charset",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        // El detalle va al log; al cliente solo un mensaje genérico, porque el
        // texto de la excepción revela host, usuario y nombre de la base.
        error_log('[db] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'No se pudo conectar con la base de datos']);
        exit;
    }

    return $pdo;
}
