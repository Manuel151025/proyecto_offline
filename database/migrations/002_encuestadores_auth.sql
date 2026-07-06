-- Migración manual para bases de datos ya desplegadas (p. ej. producción en el VPS).
-- Las instalaciones nuevas ya obtienen estas columnas desde database/schema.sql.
-- Ejecutar una sola vez contra minsalud_encuestas, por ejemplo:
--   docker exec -i encuestas_offline_db mysql -u root -p minsalud_encuestas < database/migrations/002_encuestadores_auth.sql

USE minsalud_encuestas;

ALTER TABLE encuestadores
    ADD COLUMN numero_documento VARCHAR(20) NULL UNIQUE AFTER nombre,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER numero_documento;
