<?php
/**
 * Genera api/setup/municipios_seed.php a partir de database/seeds/municipios.sql.
 *
 * Existe porque .dockerignore excluye database/ de la imagen: el docroot es
 * /var/www/html, así que cualquier .sql que entrara quedaría descargable por
 * HTTP. El endpoint de migración necesita la semilla en runtime, y un .php sí
 * viaja en la imagen sin exponer su contenido (una petición GET lo ejecuta).
 *
 * Ejecutar desde la raíz del proyecto:
 *   php scripts/generar-seed-municipios.php
 */

$raiz = dirname(__DIR__);
$origen = $raiz . '/database/seeds/municipios.sql';
$destino = $raiz . '/api/setup/municipios_seed.php';

if (!is_file($origen)) {
    fwrite(STDERR, "No se encontró $origen\n");
    exit(1);
}

// is_file() no garantiza que se pueda leer: puede fallar por permisos.
$contenido = file_get_contents($origen);
if ($contenido === false) {
    fwrite(STDERR, "No se pudo leer $origen\n");
    exit(1);
}

$sql = trim($contenido);

if ($sql === '') {
    fwrite(STDERR, "El archivo de semilla está vacío\n");
    exit(1);
}

// Nowdoc: el SQL se emite literal, sin interpolación de variables.
$cabecera = <<<'PHP'
<?php
/**
 * Semilla de municipios (DIVIPOLA/DANE) para el endpoint de migración.
 *
 * ARCHIVO GENERADO — no editar a mano. La fuente de verdad es
 * database/seeds/municipios.sql; este archivo existe únicamente porque
 * .dockerignore excluye database/ de la imagen (el docroot es
 * /var/www/html y cualquier .sql que entrara quedaría descargable por
 * HTTP). Al ser PHP, una petición GET lo ejecuta y no revela su fuente.
 *
 * Se elimina junto con migrate.php una vez aplicada la migración.
 * Regenerar con: php scripts/generar-seed-municipios.php
 */

return <<<'SQL'
PHP;

if (!is_dir(dirname($destino))) {
    mkdir(dirname($destino), 0755, true);
}

file_put_contents($destino, $cabecera . "\n" . $sql . "\nSQL;\n");

$municipios = substr_count($sql, "('");
echo "Generado api/setup/municipios_seed.php con ~$municipios municipios.\n";
