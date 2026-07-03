CREATE DATABASE IF NOT EXISTS minsalud_encuestas;
USE minsalud_encuestas;

CREATE TABLE IF NOT EXISTS municipios (
    codigo VARCHAR(10) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    departamento VARCHAR(100) NOT NULL
);

-- Inserción base de algunos municipios para prueba
INSERT IGNORE INTO municipios (codigo, nombre, departamento) VALUES 
('11001', 'Bogotá D.C.', 'Cundinamarca'),
('05001', 'Medellín', 'Antioquia'),
('76001', 'Cali', 'Valle del Cauca');

CREATE TABLE IF NOT EXISTS encuestadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) DEFAULT 1
);

INSERT IGNORE INTO encuestadores (id, nombre) VALUES (1, 'Usuario Demo Local');

-- Tabla personas (Clave compuesta: tipo_documento, numero_documento)
-- Esta es la tabla central del Last-Write-Wins
CREATE TABLE IF NOT EXISTS personas (
    tipo_documento VARCHAR(10) NOT NULL,
    numero_documento VARCHAR(20) NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    fecha_nacimiento BIGINT NULL,
    telefono VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    direccion VARCHAR(150) NULL,
    vereda VARCHAR(100) NULL,
    eps VARCHAR(50) NULL,
    ocupacion VARCHAR(100) NULL,
    estrato INT NULL,
    municipio_codigo VARCHAR(10) NULL,
    updated_at BIGINT NOT NULL,  -- CRÍTICO PARA EL ALGORITMO LWW
    device_id VARCHAR(50) NOT NULL,
    deleted_at BIGINT NULL,
    PRIMARY KEY (tipo_documento, numero_documento),
    FOREIGN KEY (municipio_codigo) REFERENCES municipios(codigo)
);

CREATE TABLE IF NOT EXISTS encuestas (
    id VARCHAR(50) PRIMARY KEY, -- UUID generado en el dispositivo
    tipo_documento VARCHAR(10) NOT NULL,
    numero_documento VARCHAR(20) NOT NULL,
    id_encuestador INT NOT NULL,
    fecha_encuesta BIGINT NOT NULL,
    device_id VARCHAR(50) NOT NULL,
    accion VARCHAR(20) NOT NULL,
    server_sync_time BIGINT NOT NULL, -- Hora en que el servidor la procesó
    FOREIGN KEY (tipo_documento, numero_documento) REFERENCES personas(tipo_documento, numero_documento),
    FOREIGN KEY (id_encuestador) REFERENCES encuestadores(id)
);
