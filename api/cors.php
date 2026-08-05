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

/**
 * Cabeceras de seguridad comunes a todo lo que sirve la API.
 *
 * Se aplican también al panel admin, que devuelve HTML y sin X-Frame-Options
 * podía incrustarse en un iframe ajeno para engañar al administrador y que
 * hiciera clic sin saberlo sobre los controles reales (clickjacking).
 */
function aplicarCabecerasDeSeguridad(): void
{
    // Impide que el navegador adivine el tipo de contenido e interprete como
    // script algo que se sirvió como texto.
    header('X-Content-Type-Options: nosniff');
    // Nada de esta API debe mostrarse dentro de un marco.
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    // La API no necesita cámara, micrófono ni ubicación.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // HSTS solo bajo HTTPS: enviarlo por HTTP no tiene efecto y en desarrollo
    // local forzaría al navegador a exigir TLS donde no lo hay.
    if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function aplicarCors(string $metodos = 'GET, POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Vary: Origin');
    aplicarCabecerasDeSeguridad();

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

/**
 * Cuerpo crudo de la petición.
 *
 * file_get_contents devuelve `string|false`, y pasar `false` a json_decode es
 * un error de tipo en PHP 8. Aquí se normaliza a cadena vacía, que json_decode
 * interpreta como payload inválido: justo lo que queremos que ocurra.
 */
function leerCuerpo(): string
{
    return file_get_contents('php://input') ?: '';
}

/** Respuesta JSON uniforme de error, sin filtrar detalles internos. */
function responderError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'message' => $mensaje]);
    exit;
}
