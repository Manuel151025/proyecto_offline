-- Migración manual para bases ya desplegadas.
-- Las instalaciones nuevas la obtienen desde database/schema.sql.
--
--   docker exec -i encuestas_offline_db mysql -u root -p minsalud_encuestas < database/migrations/005_server_updated_at.sql

USE minsalud_encuestas;

-- Marca de tiempo del SERVIDOR, imprescindible para la descarga incremental.
--
-- `updated_at` lo genera el dispositivo y sirve para resolver conflictos por
-- Last-Write-Wins, que es su función correcta. Pero NO sirve para preguntar
-- "dame lo que cambió desde X": un teléfono con el reloj atrasado escribiría
-- registros con fecha vieja que los demás dispositivos ya habrían superado, y
-- no se descargarían nunca. El dato se perdería en silencio.
--
-- Con un sello puesto por el reloj del servidor, la marca de agua avanza de
-- forma monótona y ningún registro se queda atrás por un reloj mal puesto.
ALTER TABLE personas
    ADD COLUMN server_updated_at BIGINT NULL AFTER updated_at,
    ADD INDEX idx_personas_server_updated (server_updated_at);

-- Las filas que ya existían no tienen sello. Se les pone el momento de la
-- migración: así entran en la primera descarga de cualquier dispositivo en
-- vez de quedar invisibles para siempre.
UPDATE personas
   SET server_updated_at = UNIX_TIMESTAMP() * 1000
 WHERE server_updated_at IS NULL;
