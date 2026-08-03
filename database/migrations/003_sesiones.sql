-- Migración manual para bases de datos ya desplegadas (p. ej. producción en el VPS).
-- Las instalaciones nuevas ya obtienen esta tabla desde database/schema.sql.
-- Ejecutar una sola vez contra minsalud_encuestas, por ejemplo:
--   docker exec -i encuestas_offline_db mysql -u root -p minsalud_encuestas < database/migrations/003_sesiones.sql

USE minsalud_encuestas;

-- Tokens de API para autenticar /api/personas/sync.php.
-- Solo se almacena el hash SHA-256 del token, nunca el valor en claro.
CREATE TABLE IF NOT EXISTS sesiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    id_encuestador INT NOT NULL,
    creado_en BIGINT NOT NULL,
    expira_en BIGINT NOT NULL,
    ultimo_uso BIGINT NULL,
    INDEX idx_sesiones_expira (expira_en),
    FOREIGN KEY (id_encuestador) REFERENCES encuestadores(id) ON DELETE CASCADE
);
