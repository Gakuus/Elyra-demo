-- ============================================================
-- Elyra - Normalización de base de datos (1FN, 2FN, 3FN)
-- Fecha: 2026-08-12
-- Base: elyra (MariaDB 11.8)
--
-- Respaldo previo: database/backups/elyra_before_normalizacion_20260812.sql
--
-- Cambios:
--   1FN: pregunta.opciones (JSON)  -> pregunta_opcion
--   2FN: respuesta                 -> respuesta_sesion + respuesta_pregunta
--   3FN: categoria                 -> tipo_documento + especialidad
--   Limpieza: funcionario.licencia_conducir (duplicada de licencia)
--   Integridad: FK faltantes en historial_ubicacion.traslado_id y noticias.autor_id
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) 1FN: extraer opciones de pregunta (JSON) a pregunta_opcion
-- ============================================================

CREATE TABLE IF NOT EXISTS pregunta_opcion (
  id INT NOT NULL AUTO_INCREMENT,
  pregunta_id INT NOT NULL,
  texto VARCHAR(500) NOT NULL,
  orden INT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pregunta_opcion_orden (pregunta_id, orden),
  KEY idx_pregunta_opcion_pregunta (pregunta_id),
  CONSTRAINT fk_pregunta_opcion_pregunta FOREIGN KEY (pregunta_id)
    REFERENCES pregunta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pregunta_opcion (pregunta_id, texto, orden)
SELECT p.id,
       JSON_UNQUOTE(JSON_EXTRACT(p.opciones, CONCAT('$[', s.n, ']'))),
       s.n
FROM pregunta p
JOIN (
  WITH RECURSIVE seq AS (SELECT 0 AS n UNION ALL SELECT n + 1 FROM seq WHERE n < 100)
  SELECT n FROM seq
) s ON s.n < JSON_LENGTH(p.opciones)
WHERE p.tipo = 'multiple_choice'
  AND p.opciones IS NOT NULL
  AND JSON_VALID(p.opciones);

ALTER TABLE pregunta DROP COLUMN opciones;

-- ============================================================
-- 2) 2FN: descomponer respuesta
--    - respuesta_sesion  : la sesión anónima por encuesta
--      (encuesta_id y token_paciente dependen de la sesión completa)
--    - respuesta_pregunta: una fila por pregunta respondida
--      (valor_opcion es FK real a pregunta_opcion)
-- ============================================================

CREATE TABLE IF NOT EXISTS respuesta_sesion (
  id INT NOT NULL AUTO_INCREMENT,
  encuesta_id INT NOT NULL,
  sesion_token VARCHAR(64) NOT NULL,
  token_paciente VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_respuesta_sesion_token (sesion_token),
  KEY idx_respuesta_sesion_encuesta (encuesta_id),
  CONSTRAINT fk_respuesta_sesion_encuesta FOREIGN KEY (encuesta_id)
    REFERENCES encuesta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO respuesta_sesion (encuesta_id, sesion_token, token_paciente, created_at)
SELECT encuesta_id, sesion_token, token_paciente, MIN(created_at)
FROM respuesta
GROUP BY encuesta_id, sesion_token, token_paciente;

CREATE TABLE IF NOT EXISTS respuesta_pregunta (
  id INT NOT NULL AUTO_INCREMENT,
  respuesta_id INT NOT NULL,
  pregunta_id INT NOT NULL,
  valor_opcion INT DEFAULT NULL,
  valor_texto TEXT DEFAULT NULL,
  valor_numerico INT DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_respuesta_pregunta (respuesta_id, pregunta_id),
  KEY idx_respuesta_pregunta_pregunta (pregunta_id),
  CONSTRAINT fk_respuesta_pregunta_sesion FOREIGN KEY (respuesta_id)
    REFERENCES respuesta_sesion (id) ON DELETE CASCADE,
  CONSTRAINT fk_respuesta_pregunta_pregunta FOREIGN KEY (pregunta_id)
    REFERENCES pregunta (id) ON DELETE CASCADE,
  CONSTRAINT fk_respuesta_pregunta_opcion FOREIGN KEY (valor_opcion)
    REFERENCES pregunta_opcion (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO respuesta_pregunta (respuesta_id, pregunta_id, valor_opcion, valor_texto, valor_numerico, created_at)
SELECT s.id,
       r.pregunta_id,
       (SELECT po.id FROM pregunta_opcion po
        WHERE po.pregunta_id = r.pregunta_id AND po.orden = r.valor_opcion),
       r.valor_texto,
       r.valor_numerico,
       r.created_at
FROM respuesta r
JOIN respuesta_sesion s
  ON s.sesion_token = r.sesion_token AND s.encuesta_id = r.encuesta_id;

DROP TABLE respuesta;

-- ============================================================
-- 3) 3FN: separar categoria en tipo_documento y especialidad
--    (se preservan los id originales para no tocar documento)
-- ============================================================

CREATE TABLE IF NOT EXISTS tipo_documento (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tipo_documento_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS especialidad (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_especialidad_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tipo_documento (id, nombre, descripcion)
SELECT id, nombre, descripcion FROM categoria WHERE tipo = 'tipo_documento';

INSERT INTO especialidad (id, nombre, descripcion)
SELECT id, nombre, descripcion FROM categoria WHERE tipo = 'especialidad';

-- documento: reconectar FKs a las tablas nuevas
ALTER TABLE documento
  RENAME COLUMN categoria_id TO tipo_documento_id;

ALTER TABLE documento
  DROP FOREIGN KEY documento_ibfk_2,
  DROP INDEX categoria_id;

-- tipo_documento_id conserva NOT NULL (heredado de categoria_id);
-- la FK usa RESTRICT (misma semántica que la original documento_ibfk_2).
ALTER TABLE documento
  ADD KEY idx_documento_tipo_documento (tipo_documento_id),
  ADD CONSTRAINT fk_documento_tipo_documento FOREIGN KEY (tipo_documento_id)
    REFERENCES tipo_documento (id);

ALTER TABLE documento
  DROP FOREIGN KEY documento_ibfk_especialidad,
  DROP INDEX documento_ibfk_especialidad;

ALTER TABLE documento
  ADD CONSTRAINT fk_documento_especialidad FOREIGN KEY (especialidad_id)
    REFERENCES especialidad (id) ON DELETE SET NULL;

DROP TABLE categoria;

-- ============================================================
-- 4) Limpieza: licencia_conducir es duplicado de licencia
-- ============================================================

ALTER TABLE funcionario DROP COLUMN licencia_conducir;

-- ============================================================
-- 5) Integridad referencial faltante
-- ============================================================

ALTER TABLE historial_ubicacion
  ADD CONSTRAINT fk_historial_ubicacion_traslado FOREIGN KEY (traslado_id)
    REFERENCES traslado (id) ON DELETE SET NULL;

-- noticias.autor_id=1 es huérfano (no existe en funcionario);
-- se reasigna al admin existente (id=2) antes de crear la FK.
UPDATE noticias SET autor_id = 2 WHERE autor_id = 1;

ALTER TABLE noticias
  ADD CONSTRAINT fk_noticias_autor FOREIGN KEY (autor_id)
    REFERENCES funcionario (id);

SET FOREIGN_KEY_CHECKS = 1;
