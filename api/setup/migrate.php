<?php
/**
 * Endpoint de migración para actualizar la BD de producción sin acceso SSH.
 *
 * Uso: abrir https://TU_DOMINIO/api/setup/migrate.php en el navegador e ingresar
 * la contraseña de ADMIN_PASSWORD en el formulario.
 *
 * A diferencia de la versión anterior, la contraseña viaja por POST y no como
 * ?key= en la URL: un parámetro de consulta queda registrado en los logs de
 * acceso de Apache, en el historial del navegador y en la cabecera Referer.
 *
 * Es IDEMPOTENTE: se puede ejecutar varias veces sin causar daño.
 *
 * El SQL va embebido a propósito. El .dockerignore excluye database/ de la
 * imagen (si no, los .sql quedarían publicados por HTTP bajo /var/www/html),
 * así que este archivo no puede leer los .sql desde disco en producción.
 *
 * BORRAR ESTE ARCHIVO después de usarlo.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');

$adminPassword = getenv('ADMIN_PASSWORD');

/** Página mínima para no depender de nada externo. */
function render(string $cuerpo): void
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex">'
       . '<title>Migración de base de datos</title><style>'
       . 'body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#1a2027}'
       . 'h1{font-size:1.3rem} code{background:#eef1f4;padding:.1rem .35rem;border-radius:4px}'
       . 'input{font-size:1rem;padding:.6rem;width:100%;box-sizing:border-box;border:1px solid #c3cad1;border-radius:6px}'
       . 'button{font-size:1rem;padding:.6rem 1.2rem;margin-top:.8rem;border:0;border-radius:6px;background:#0E7A41;color:#fff;cursor:pointer}'
       . '.ok{color:#0E7A41} .err{color:#b3261e} li{margin:.25rem 0}'
       . '.aviso{background:#fff4e5;border-left:4px solid #e8a33d;padding:.8rem 1rem;border-radius:4px}'
       . '</style></head><body>' . $cuerpo . '</body></html>';
    exit;
}

if (!$adminPassword) {
    http_response_code(500);
    render('<h1 class="err">ADMIN_PASSWORD no está configurada</h1>'
         . '<p>Defínela en las variables de entorno del servicio antes de migrar.</p>');
}

// GET: mostrar el formulario.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render('<h1>Migración de base de datos</h1>'
         . '<p>Aplica los cambios de esquema pendientes. Es seguro ejecutarlo varias veces.</p>'
         . '<form method="post">'
         . '<label for="p">Contraseña de administrador</label>'
         . '<input type="password" id="p" name="password" autocomplete="current-password" autofocus>'
         . '<button type="submit">Ejecutar migración</button>'
         . '</form>');
}

if (!hash_equals($adminPassword, $_POST['password'] ?? '')) {
    http_response_code(403);
    render('<h1 class="err">No autorizado</h1><p><a href="migrate.php">Volver</a></p>');
}

require_once __DIR__ . '/../db.php';

$pasos = [];

/** ¿Existe la columna en la base de datos actual? */
function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabla, $columna]);
    return (int)$stmt->fetchColumn() > 0;
}

function tablaExiste(PDO $pdo, string $tabla): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabla]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // --- Migración 002: columnas de autenticación en encuestadores ---
    $columnas = [
        'numero_documento' => 'ALTER TABLE encuestadores ADD COLUMN numero_documento VARCHAR(20) NULL UNIQUE',
        'password_hash'    => 'ALTER TABLE encuestadores ADD COLUMN password_hash VARCHAR(255) NULL',
        'activo'           => 'ALTER TABLE encuestadores ADD COLUMN activo TINYINT(1) DEFAULT 1',
    ];

    foreach ($columnas as $columna => $sql) {
        if (columnaExiste($pdo, 'encuestadores', $columna)) {
            $pasos[] = ['ok', "encuestadores.$columna ya existía"];
        } else {
            $pdo->exec($sql);
            $pasos[] = ['nuevo', "encuestadores.$columna añadida"];
        }
    }

    // --- Migración 003: tabla de sesiones (tokens de API) ---
    // Sin esta tabla, login.php falla y sync.php rechaza todo con 401.
    if (tablaExiste($pdo, 'sesiones')) {
        $pasos[] = ['ok', 'tabla sesiones ya existía'];
    } else {
        $pdo->exec(
            'CREATE TABLE sesiones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL UNIQUE,
                id_encuestador INT NOT NULL,
                creado_en BIGINT NOT NULL,
                expira_en BIGINT NOT NULL,
                ultimo_uso BIGINT NULL,
                INDEX idx_sesiones_expira (expira_en),
                FOREIGN KEY (id_encuestador) REFERENCES encuestadores(id) ON DELETE CASCADE
            )'
        );
        $pasos[] = ['nuevo', 'tabla sesiones creada'];
    }

    // --- Cuenta de prueba (docente) para el login de la PWA ---
    $stmt = $pdo->prepare(
        "INSERT INTO encuestadores (id, nombre, numero_documento, password_hash, activo)
         VALUES (1, 'Docente Demo', '1000000001', ?, 1)
         ON DUPLICATE KEY UPDATE numero_documento = VALUES(numero_documento),
             password_hash = VALUES(password_hash), activo = 1"
    );
    $stmt->execute([password_hash('Demo2026Salud', PASSWORD_BCRYPT)]);
    $pasos[] = ['ok', 'cuenta demo lista (1000000001 / Demo2026Salud)'];

    // --- Semilla de municipios ---
    // El seed viaja como .php (municipios_seed.php) porque .dockerignore
    // excluye database/ de la imagen. Es una sola sentencia INSERT con
    // ON DUPLICATE KEY UPDATE, así que repetirla no duplica ni pierde filas.
    $antes = (int)$pdo->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
    $archivoSeed = __DIR__ . '/municipios_seed.php';

    if (is_file($archivoSeed)) {
        $sqlSeed = require $archivoSeed;
        $pdo->exec($sqlSeed);
        $despues = (int)$pdo->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
        $nuevos = $despues - $antes;
        $pasos[] = [$nuevos > 0 ? 'nuevo' : 'ok', "municipios sembrados (+$nuevos, total $despues)"];
    } else {
        $pasos[] = ['ok', 'AVISO: no se encontró municipios_seed.php, no se sembraron municipios'];
    }

    $municipios = (int)$pdo->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
    $departamentos = (int)$pdo->query('SELECT COUNT(DISTINCT departamento) FROM municipios')->fetchColumn();

    $html = '<h1 class="ok">Migración completada</h1><ul>';
    foreach ($pasos as [$tipo, $texto]) {
        $marca = $tipo === 'nuevo' ? '✚' : '✓';
        $html .= "<li>$marca " . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>';

    $html .= "<p><strong>Municipios:</strong> $municipios en $departamentos departamentos.</p>";
    if ($municipios < 100) {
        $html .= '<p class="aviso">El catálogo de municipios sigue incompleto. '
               . 'La sincronización falla con error de clave foránea si un registro '
               . 'usa un <code>municipio_codigo</code> que no existe aquí. '
               . 'Verifica que <code>api/setup/municipios_seed.php</code> se haya desplegado.</p>';
    }

    $html .= '<p class="aviso"><strong>Siguiente paso:</strong> borra del repositorio '
           . '<code>api/setup/migrate.php</code> y <code>api/setup/municipios_seed.php</code>, '
           . 'vuelve a desplegar, y cambia <code>ADMIN_PASSWORD</code>.</p>';

    render($html);

} catch (Exception $e) {
    // El detalle va al log del servidor, no al navegador.
    error_log('[migrate] ' . $e->getMessage());
    http_response_code(500);
    render('<h1 class="err">La migración falló</h1>'
         . '<p>No se aplicaron todos los cambios. Revisa los logs del contenedor '
         . 'para ver el detalle del error.</p>');
}
