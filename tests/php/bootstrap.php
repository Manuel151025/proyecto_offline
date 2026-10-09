<?php
/**
 * Arranque de las pruebas de integración.
 *
 * 1. Crea una base desechable con database/schema.sql.
 * 2. Levanta el servidor embebido de PHP apuntando a esa base.
 * 3. Al terminar, detiene el servidor y borra la base.
 *
 * Variables de entorno (todas opcionales):
 *   PRUEBAS_DB_HOST (localhost) · PRUEBAS_DB_USER (root) · PRUEBAS_DB_PASS ('')
 *   PRUEBAS_DB_NAME (colo_pruebas) · PRUEBAS_PUERTO (8781)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Entorno.php';
require_once __DIR__ . '/Cliente.php';
require_once __DIR__ . '/IntegracionTestCase.php';

Entorno::preparar();
register_shutdown_function([Entorno::class, 'limpiar']);
