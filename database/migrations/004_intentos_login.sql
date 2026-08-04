-- Migración manual para bases de datos ya desplegadas.
-- Las instalaciones nuevas ya obtienen esta tabla desde database/schema.sql.
--
-- NO es obligatorio ejecutarla a mano: api/rate_limit.php crea la tabla la
-- primera vez que la necesita si no existe, porque el despliegue en producción
-- no tiene acceso SSH. Este archivo existe para dejar el esquema documentado y
-- versionado, y para poder crear la tabla por adelantado si se prefiere.
--
--   docker exec -i encuestas_offline_db mysql -u root -p minsalud_encuestas < database/migrations/004_intentos_login.sql

USE minsalud_encuestas;

-- Intentos fallidos de inicio de sesión, para frenar la fuerza bruta.
-- Se cuenta por documento, no por IP: detrás del proxy inverso todas las
-- peticiones comparten la misma IP y limitar por ella bloquearía a todos.
CREATE TABLE IF NOT EXISTS intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento VARCHAR(20) NOT NULL,
    creado_en BIGINT NOT NULL,
    INDEX idx_intentos_documento (documento, creado_en)
);
