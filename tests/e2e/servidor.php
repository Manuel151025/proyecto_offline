<?php
/**
 * Servidor desechable para las pruebas de punta a punta.
 *
 * Reutiliza el entorno de las pruebas de integración (tests/php/Entorno.php):
 * crea una base vacía con database/schema.sql y levanta el servidor embebido
 * de PHP apuntando a ella, con ADMIN_PASSWORD de arranque.
 *
 * Imprime "LISTO" cuando acepta conexiones y se queda esperando: al cerrarse
 * su entrada estándar (lo hace tests/e2e/e2e.test.mjs al terminar) detiene el
 * servidor y borra la base.
 *
 * Variables: las de tests/php/bootstrap.php. Por defecto usa la base
 * colo_e2e y el puerto 8782, para no chocar con PHPUnit.
 */

if (getenv('PRUEBAS_DB_NAME') === false) {
    putenv('PRUEBAS_DB_NAME=colo_e2e');
}
if (getenv('PRUEBAS_PUERTO') === false) {
    putenv('PRUEBAS_PUERTO=8782');
}

require_once __DIR__ . '/../php/Entorno.php';

Entorno::preparar();
// La ruta del archivo de correos permite leer los códigos que «envía» la API.
echo 'LISTO ' . Entorno::url('') . ' ' . Entorno::archivoCorreos() . PHP_EOL;
flush();

while (fgets(STDIN) !== false) {
    // Espera a que el proceso padre cierre la entrada.
}
Entorno::limpiar();
