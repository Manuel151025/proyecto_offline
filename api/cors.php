<?php
/**
 * CORS centralizado con lista blanca de orígenes.
 *
 * Reemplaza el `Access-Control-Allow-Origin: *` que estaba repetido en cada
 * endpoint. Los orígenes permitidos se declaran en la variable de entorno
 * ALLOWED_ORIGINS, separados por comas. Ejemplo:
 *
 *   ALLOWED_ORIGINS=https://encuestas.manuelcardenas.online,http://localhost:8898
 *
 * Nota importante: CORS es una política del navegador. NO sustituye a la
 * autenticación — clientes como curl, Postman o la app Android la ignoran.
 * La protección real de los endpoints de escritura está en auth_token.php.
 */

function aplicarCors(string $metodos = 'GET, POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Vary: Origin');

    $permitidos = array_filter(array_map(
        'trim',
        explode(',', getenv('ALLOWED_ORIGINS') ?: '')
    ));

    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origen !== '' && in_array($origen, $permitidos, true)) {
        header("Access-Control-Allow-Origin: $origen");
        header('Access-Control-Allow-Credentials: true');
    }

    header("Access-Control-Allow-Methods: $metodos");
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Respuesta JSON uniforme de error, sin filtrar detalles internos. */
function responderError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'message' => $mensaje]);
    exit;
}
